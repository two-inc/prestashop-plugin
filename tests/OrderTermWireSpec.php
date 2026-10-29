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
 *
 * A term that is not offered is refused, never replaced by the default: the
 * save-term AJAX refuses to record it, and the submit refuses (no POST
 * /v1/order) when the saved term has since been withdrawn. A buyer who never
 * chose a term still gets the default.
 */
final class OrderTermWireSpec
{
    public static function runAll(): void
    {
        self::testSelectedTermIsWhatTheOrderBodyCarries();
        self::testBuyerWhoNeverChoseATermGetsTheDefault();
        self::testTermThatIsNotOfferedIsRefusedAtSave();
        self::testSavedTermThatIsNoLongerOfferedIsRefusedAtSubmit();
    }

    /**
     * A module whose only fakes are the wire answers. The body is captured
     * as the array handed to the request seam, before it is JSON-encoded onto POST /v1/order.
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
     * Offered {30, 60, 90}, default 30, a fully seeded cart and no term chosen yet.
     *
     * @return array{0:TwopaymentTestHarness,1:object}
     */
    private static function arrange(string $termType): array
    {
        $cart = OrderPostprocessingSpec::seed(true);
        self::offer([30, 60, 90]);
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

        return [$module, $context];
    }

    /**
     * The merchant's offered set, as the cached merchant record and the admin's own choices report it.
     *
     * @param int[] $offered
     */
    private static function offer(array $offered): void
    {
        foreach (Twopayment::PAYMENT_TERMS_OPTIONS as $term) {
            Configuration::updateValue('PS_TWO_PAYMENT_TERMS_' . $term, in_array($term, $offered, true) ? 1 : 0);
        }
        Configuration::updateValue(Twopayment::CONFIG_MERCHANT_AVAILABLE_TERMS, json_encode($offered));
    }

    /**
     * The buyer picks a term: the checkout posts it and the module answers.
     *
     * @return array<int,array> the JSON bodies the save-term AJAX emitted
     */
    private static function saveTerm(TwopaymentTestHarness $module, int $days): array
    {
        $saver = new class extends TwopaymentOrderintentModuleFrontController {
            /** @var array<int,array> */
            public array $emitted = [];

            public function sendJsonResponse($content)
            {
                $this->emitted[] = (array) json_decode((string) $content, true);
            }
        };
        $saver->module = $module;
        Tools::setTestValue('days', (string) $days);
        $saver->ajaxProcessSavePaymentTerm();

        return $saver->emitted;
    }

    /**
     * The buyer submits payment: the controller builds and sends the order.
     *
     * @return string[] the refusal text(s) shown to the buyer, empty when none was raised
     */
    private static function submitPayment(TwopaymentTestHarness $module): array
    {
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
            // A refusal ends the submit here. So does the refused create answer, after the body had been sent.
        }

        return array_map('strval', (array) $payer->errors);
    }

    /**
     * @return array{0:TwopaymentTestHarness,1:object}
     */
    private static function placeOrderSelecting(int $selectedDays, string $termType): array
    {
        [$module, $context] = self::arrange($termType);
        TinyAssert::same([['success' => true]], self::saveTerm($module, $selectedDays), 'the selection is recorded by the save-term AJAX');
        self::submitPayment($module);

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

    private static function testBuyerWhoNeverChoseATermGetsTheDefault(): void
    {
        [$module] = self::arrange('STANDARD');
        $errors = self::submitPayment($module);

        // The faked create answer is a refusal, so errors are expected here: only the unoffered-term text must be absent.
        TinyAssert::true(!in_array($module->getUnofferedPaymentTermBuyerMessage(), $errors, true), 'no term chosen: the submit is not refused as an unoffered term');
        TinyAssert::count(1, $module->sent, 'no term chosen: exactly one POST /v1/order');
        TinyAssert::same(30, $module->sent[0]['payload']['terms']['duration_days'] ?? null, 'no term chosen: the default 30 is sent');
    }

    /**
     * A selection outside the offered set is refused at save: the answer is a
     * failure with the buyer-facing text and nothing is recorded, so a stale
     * page cannot leave a term in the cookie that was never on offer.
     */
    private static function testTermThatIsNotOfferedIsRefusedAtSave(): void
    {
        // [previously saved term or 0, selected days, description]
        $cases = [
            [0, 45, 'a 45-day selection against {30,60,90} is refused and not recorded'],
            [60, 45, 'a refused selection leaves the earlier valid choice in place'],
            [0, 0, 'zero days is refused as before'],
            [0, 120, 'a day count above every offered term is refused'],
        ];
        foreach ($cases as [$previous, $selected, $description]) {
            [$module, $context] = self::arrange('STANDARD');
            if ($previous) {
                TinyAssert::same([['success' => true]], self::saveTerm($module, $previous), $description . ': setup save');
            }

            $emitted = self::saveTerm($module, $selected);

            TinyAssert::count(1, $emitted, $description . ': one answer');
            TinyAssert::same(false, $emitted[0]['success'] ?? null, $description . ': success is false');
            if ($selected > 0) {
                TinyAssert::same($module->getUnofferedPaymentTermBuyerMessage(), $emitted[0]['error'] ?? null, $description . ': the buyer-facing text');
            }
            TinyAssert::same($previous ? (string) $previous : '', (string) $context->cookie->two_payment_term, $description . ': the cookie is unchanged');
            TinyAssert::count(0, $module->sent, $description . ': no POST /v1/order from a save');
        }
    }

    /**
     * The buyer's saved term must still be offered at submit. When it is not
     * (the merchant withdrew it, or the cookie never came from an offer), the
     * submit is refused before anything is sent to Two, and the saved choice is
     * cleared so a retry books what the reloaded page shows.
     */
    private static function testSavedTermThatIsNoLongerOfferedIsRefusedAtSubmit(): void
    {
        // [term saved while offered, offered set at submit, still offered?, description]
        $cases = [
            [60, [30, 90], false, 'a saved 60 withdrawn since is refused'],
            [90, [30, 60], false, 'a saved 90 withdrawn since is refused'],
            [60, [30, 60, 90], true, 'a saved 60 still offered is sent as 60 (control)'],
        ];
        foreach ($cases as [$saved, $offeredAtSubmit, $stillOffered, $description]) {
            [$module, $context] = self::arrange('STANDARD');
            TinyAssert::same([['success' => true]], self::saveTerm($module, $saved), $description . ': setup save');
            self::offer($offeredAtSubmit);

            $errors = self::submitPayment($module);

            if ($stillOffered) {
                TinyAssert::count(1, $module->sent, $description . ': one POST /v1/order');
                TinyAssert::same($saved, $module->sent[0]['payload']['terms']['duration_days'] ?? null, $description . ': terms.duration_days');
                continue;
            }
            TinyAssert::count(0, $module->sent, $description . ': no POST /v1/order');
            TinyAssert::same([$module->getUnofferedPaymentTermBuyerMessage()], $errors, $description . ': the buyer is told to choose again');
            TinyAssert::same('', (string) $context->cookie->two_payment_term, $description . ': the saved choice is cleared');
        }

        // A cookie holding a term that was never offered (set outside the save) is refused alike.
        [$module, $context] = self::arrange('STANDARD');
        $context->cookie->two_payment_term = '45';
        $errors = self::submitPayment($module);
        TinyAssert::count(0, $module->sent, 'a cookie holding a never-offered 45: no POST /v1/order');
        TinyAssert::same([$module->getUnofferedPaymentTermBuyerMessage()], $errors, 'a cookie holding a never-offered 45: the buyer is told to choose again');

        // After the refusal the choice is cleared, so the retry is the never-chose default, not a second refusal.
        $errors = self::submitPayment($module);
        TinyAssert::true(!in_array($module->getUnofferedPaymentTermBuyerMessage(), $errors, true), 'the retry after a refusal is not refused again');
        TinyAssert::same(30, $module->sent[0]['payload']['terms']['duration_days'] ?? null, 'the retry books the default the page shows');
    }
}
