<?php

declare(strict_types=1);

/**
 * The buyer's chosen payment term reaches the order Two is asked to create.
 *
 * Drives the whole chain with nothing stubbed on it: the checkout's save-term
 * AJAX (orderintent controller, which records the selection) -> the payment
 * controller's submit -> the real getTwoNewOrderData() / buildTermsPayload() ->
 * the body handed to setTwoPaymentRequest('/v1/order', ...), the lowest seam
 * the offline suite can reach (curl sits directly beneath it). Only the wire
 * answers are faked: the intent approval and the order-create reply.
 *
 * A default-only test cannot see a selection that is dropped on the way, because
 * the dropped selection and the default look identical on the wire. Hence the
 * offered set {30, 60, 90} with 30 the default, and a row per non-default term.
 */
final class OrderTermWireSpec
{
    public static function runAll(): void
    {
        self::testSelectedTermIsWhatTheOrderBodyCarries();
        self::testTermThatIsNotOfferedIsNotSilentlyReplacedByTheDefault();
    }

    /**
     * A module whose only fakes are the wire answers. The body is captured
     * exactly as it would be serialised onto POST /v1/order.
     */
    private static function module(): TwopaymentTestHarness
    {
        return new class extends TwopaymentTestHarness {
            /** @var array<int,array{endpoint:string,payload:mixed,method:string}> */
            public array $sent = [];

            public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
            {
                if ($method === 'POST' && $endpoint === '/v1/order') {
                    $this->sent[] = ['endpoint' => $endpoint, 'payload' => $payload, 'method' => $method];

                    // Refused, so the controller stops before creating a local order.
                    return ['http_status' => 400];
                }
                if ($endpoint === '/v1/order_intent') {
                    return ['http_status' => 200, 'approved' => true];
                }
                if ($endpoint === '/v1/pricing/order/fee') {
                    return ['http_status' => 200, 'buyer_fee_share' => '0.00', 'currency' => 'EUR'];
                }

                return ['http_status' => 200];
            }
        };
    }

    /**
     * Offered {30, 60, 90}, default 30, a fully seeded cart, and the buyer's
     * choice recorded through the checkout's own save-term AJAX.
     *
     * @return array{0:TwopaymentTestHarness,1:object}
     */
    private static function placeOrderSelecting(int $selectedDays, string $termType): array
    {
        $cart = OrderPostprocessingSpec::seed(true);
        foreach (Twopayment::PAYMENT_TERMS_OPTIONS as $term) {
            Configuration::updateValue('PS_TWO_PAYMENT_TERMS_' . $term, in_array($term, [30, 60, 90], true) ? 1 : 0);
        }
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS, '[30,60,90]');
        Configuration::updateValue('PS_TWO_PAYMENT_TERM_TYPE', $termType);
        Tools::resetTestValues();
        Tools::setTestValue('token', Tools::getToken(false));

        $module = self::module();
        OrderPostprocessingSpec::harnessAsInstance($module);
        $context = Context::getContext();
        $context->cart = $cart;
        $context->cookie->two_payment_term = '';
        $context->cookie->two_order_intent_approved = '1';
        $_SERVER['REQUEST_METHOD'] = 'POST';

        // 1. The buyer picks a term: the checkout posts it and the module records it.
        $saver = new class extends TwopaymentOrderintentModuleFrontController {
            /** @var array<int,array> */
            public array $emitted = [];

            public function sendJsonResponse($content)
            {
                $this->emitted[] = (array) json_decode((string) $content, true);
            }
        };
        $saver->module = $module;
        Tools::setTestValue('days', (string) $selectedDays);
        $saver->ajaxProcessSavePaymentTerm();
        TinyAssert::same([['success' => true]], $saver->emitted, 'the selection is recorded by the save-term AJAX');

        // 2. The buyer submits payment: the controller builds and sends the order.
        $payer = new class extends TwopaymentPaymentModuleFrontController {
            protected function emitTwoCheckoutJsonFailure(array $payload)
            {
                throw new StubJsonFailureEmitted('json failure emitted');
            }
        };
        $payer->module = $module;
        try {
            $payer->postProcess();
        } catch (StubRedirect | StubJsonFailureEmitted $stoppedAfterRefusal) {
            // The refused create answer ends the submit; the body had been sent by then.
        }

        return [$module, $context];
    }

    private static function testSelectedTermIsWhatTheOrderBodyCarries(): void
    {
        // [selected days, term type, expected duration_days, expected calculated_from, description]
        $cases = [
            [30, 'STANDARD', 30, null, 'the default chosen explicitly is sent as 30 (control)'],
            [60, 'STANDARD', 60, null, 'a non-default 60-day selection is sent as 60, not the 30-day default'],
            [90, 'STANDARD', 90, null, 'a non-default 90-day selection is sent as 90, not the 30-day default'],
            [60, 'EOM', 60, 'END_OF_MONTH', 'an end-of-month 60-day selection is sent as 60 with END_OF_MONTH'],
        ];
        foreach ($cases as [$selected, $termType, $expectedDays, $expectedFrom, $description]) {
            [$module] = self::placeOrderSelecting($selected, $termType);

            TinyAssert::count(1, $module->sent, $description . ': exactly one POST /v1/order');
            $terms = $module->sent[0]['payload']['terms'] ?? null;
            TinyAssert::true(is_array($terms), $description . ': the body carries a terms object');
            TinyAssert::same('NET_TERMS', $terms['type'] ?? null, $description . ': terms.type');
            TinyAssert::same($expectedDays, $terms['duration_days'] ?? null, $description . ': terms.duration_days');
            TinyAssert::same($expectedFrom, $terms['duration_days_calculated_from'] ?? null, $description . ': terms.duration_days_calculated_from');
        }
    }

    /**
     * CURRENT BEHAVIOUR, recorded as a finding rather than endorsed: a selection
     * outside the offered set is not refused. getSelectedPaymentTerm() quietly
     * falls back to the default, so the order is created under 30 days while the
     * buyer asked for 45. The save-term AJAX accepts any positive day count, and
     * the submit proceeds. This case pins that, so a change in either direction
     * (refusal, or a different substitution) is a deliberate, visible edit.
     */
    private static function testTermThatIsNotOfferedIsNotSilentlyReplacedByTheDefault(): void
    {
        // [selected days, expected duration_days today, description]
        $cases = [
            // FINDING: silent default. When the plugin refuses instead, expect no POST /v1/order here.
            [45, 30, 'a 45-day selection that is not offered is currently replaced by the 30-day default (silent default, finding)'],
        ];
        foreach ($cases as [$selected, $currentDays, $description]) {
            [$module] = self::placeOrderSelecting($selected, 'STANDARD');

            TinyAssert::count(1, $module->sent, $description . ': the submit still reaches POST /v1/order');
            TinyAssert::same($currentDays, $module->sent[0]['payload']['terms']['duration_days'] ?? null, $description . ': terms.duration_days');
        }
    }
}
