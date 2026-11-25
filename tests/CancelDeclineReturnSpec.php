<?php

declare(strict_types=1);

require_once __DIR__ . '/../controllers/front/cancel.php';

/**
 * Harness for the cancel return: Two's order is scripted, and every Two call, attempt write and
 * local order sync is recorded.
 */
class TwoCancelDeclineReturnHarness extends TwopaymentTestHarness
{
    /** @var array */
    public $attempt = [];
    /** @var array|null Two's order as GET returns it; null for a failed read */
    public $twoOrder = null;
    public $cancelHttpStatus = 200;
    public $latestToken = '';
    public $authorized = true;
    public $cartOrderId = 0;
    /** @var array|false */
    public $cartOrderPaymentRow = false;
    /** @var array<int,string> */
    public $twoCalls = [];
    /** @var array<int,array> */
    public $statusWrites = [];
    /** @var array<int,int> */
    public $localCancels = [];

    public function getTwoCheckoutAttempt($attempt_token)
    {
        return $this->attempt;
    }

    public function getTwoLatestAttemptTokenForCart($id_cart)
    {
        return $this->latestToken;
    }

    public function getTwoOrderIdByCart($id_cart)
    {
        return $this->cartOrderId;
    }

    public function getTwoOrderPaymentData($id_order)
    {
        return (int) $id_order > 0 && (int) $id_order === $this->cartOrderId ? $this->cartOrderPaymentRow : false;
    }

    public function setTwoPaymentRequest($endpoint, $payload = [], $method = 'POST', $additional_headers = [], $timeout = null)
    {
        $this->twoCalls[] = $method . ' ' . $endpoint;

        return $this->twoOrder === null
            ? ['http_status' => 500, 'message' => 'upstream down']
            : array_merge(['http_status' => 200, 'id' => 'two-own'], $this->twoOrder);
    }

    public function sendTwoOrderRequest($requestType, $trigger, $endpoint, array $payload, $method, $cart = null, $order = null, array $headers = array(), &$sentPayload = null, $checks = null, $twoOrder = null, $finish = null)
    {
        $this->twoCalls[] = $method . ' ' . $endpoint;

        return ['http_status' => $this->cancelHttpStatus];
    }

    public function updateTwoCheckoutAttemptStatus($attempt_token, $status, $extra_data = array())
    {
        $this->statusWrites[] = [
            (string) $status,
            isset($extra_data['two_order_state']) ? (string) $extra_data['two_order_state'] : '',
            isset($extra_data['id_order']) ? (int) $extra_data['id_order'] : 0,
        ];
        $this->attempt['status'] = (string) $status;

        return true;
    }

    public function syncLocalOrderStatusFromTwoState($id_order, $two_state, $confirmed_by_two = true)
    {
        if ((int) $id_order > 0) {
            $this->localCancels[] = (int) $id_order;
        }

        return true;
    }

    public function stampTwoPendingAttempt($attempt_token, array $fields)
    {
        return true;
    }

    /** With $authorized false, the module's own check judges the request. */
    public function isTwoAttemptCallbackAuthorized($attempt, $provided_secure_key = '', $context_customer_id = 0, $context_customer_secure_key = '')
    {
        return $this->authorized || parent::isTwoAttemptCallbackAuthorized($attempt, $provided_secure_key, $context_customer_id, $context_customer_secure_key);
    }
}

/**
 * TWO-27390: Two's checkout sends a declined buyer to the cancel URL. The buyer wants to pay for
 * the same basket another way, so the return reads Two's state rather than trusting the click,
 * closes the attempt the way that state asks, and shows the buyer the decline message. The cart
 * goes back into the session only when it is the buyer's own, and then the buyer is on the payment
 * step; a session with no basket goes to the cart page, so the message survives (TWO-27410).
 */
final class CancelDeclineReturnSpec
{
    private const CART_ID = 27390;
    private const CUSTOMER_ID = 27391;
    private const ORDER_ID = 27392;
    private const OTHER_CART_ID = 99;
    private const EMPTY_CART_ID = 98;
    private const TOKEN = 'attempt-27390';

    public static function runAll(): void
    {
        self::testDeclineReturnTable();
        self::testRefusedCancelIsSentAgainOnTheNextReturn();
        self::testWrongKeyChangesNothing();
    }

    private static function testDeclineReturnTable(): void
    {
        $get = 'GET /v1/order/two-own';
        $post = 'POST /v1/order/two-own/cancel';
        $order = 'index.php?controller=order';
        $confirm = 'https://shop.local/module/twopayment/confirmation?attempt_token=' . self::TOKEN . '&key=key';
        $declined = 'Two could not approve this purchase. Please choose another payment method.';
        $stale = 'This payment attempt has already been finalized.';
        $lookupFailed = 'Unable to retrieve the order payment information please contact store owner.';
        $unverified = 'Payment has not been verified yet. Please try again or contact support.';
        $closed = [['CANCELLED', 'CANCELLED', 0]];
        $own = self::CUSTOMER_ID;
        $cart = self::CART_ID;
        $other = self::OTHER_CART_ID;
        $empty = self::EMPTY_CART_ID;
        $cartPage = 'index.php?controller=cart&action=show';
        $linked = ['id_order' => self::ORDER_ID, 'status' => 'CONFIRMED'];
        $linkedRow = ['two_order_id' => 'two-own'];

        $cases = [
            // [attempt overrides, Two order (null: read fails), cancel HTTP, latest token for cart, cart order payment row, session customer, session cart,
            //  Two calls, attempt writes, local orders cancelled, redirect, buyer messages, session cart after, description]
            [[], ['state' => 'VERIFIED', 'status' => 'APPROVED'], 200, '', null, $own, $cart, [$get], [], [], $confirm, [], $cart, 'VERIFIED: handed to the confirmation route, nothing cancelled'],
            [[], ['state' => 'CONFIRMED', 'status' => 'APPROVED'], 200, '', null, $own, $cart, [$get], [], [], $confirm, [], $cart, 'CONFIRMED: handed to the confirmation route, nothing cancelled'],
            [[], ['state' => 'CANCELLED', 'status' => 'CANCELLED'], 200, '', null, $own, $cart, [$get], $closed, [], $order, [$declined], $cart, 'CANCELLED at Two: closed here, no cancel sent'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'REJECTED'], 200, '', null, $own, $cart, [$get], $closed, [], $order, [$declined], $cart, 'REJECTED: closed here, no cancel sent'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'DECLINED'], 200, '', null, $own, $cart, [$get], $closed, [], $order, [$declined], $cart, 'DECLINED: closed here, no cancel sent'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'APPROVED'], 200, '', null, $own, $cart, [$get, $post], $closed, [], $order, [$declined], $cart, 'UNVERIFIED: cancelled at Two, then closed'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'APPROVED'], 500, '', null, $own, $cart, [$get, $post], [], [], $order, [$declined], $cart,
                'UNVERIFIED, cancel refused: the attempt stays waiting, so a later return or the scheduled check settles it'],
            [[], null, 200, '', null, $own, $other, [$get], [], [], $order, [$lookupFailed], $other, 'Two cannot be read: nothing written, no decline claimed, nothing restored'],
            [[], ['state' => 'REFUNDED', 'status' => 'APPROVED'], 200, '', null, $own, $other, [$get], [], [], $order, [$unverified], $other,
                'a state the module does not act on: nothing written, no decline claimed, nothing restored'],
            [['status' => 'CANCELLED', 'two_order_state' => 'CANCELLED'], null, 200, '', null, $own, $other, [], [], [], $order, [$declined], $cart, 'second click: the closed attempt changes nothing at Two or here'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'APPROVED'], 200, 'attempt-newer', null, $own, $other, [], [], [], $order, [$stale], $other, 'stale: a newer attempt holds the cart, so nothing changes'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'APPROVED'], 200, '', ['two_order_id' => 'two-other'], $own, $other, [], [], [], $order, [$stale], $other, 'stale: another checkout ordered the cart, so nothing changes'],
            [$linked, ['state' => 'CANCELLED', 'status' => 'CANCELLED'], 200, '', $linkedRow, $own, $other, [$get], [['CANCELLED', 'CANCELLED', self::ORDER_ID]], [self::ORDER_ID], $order, [$declined], $cart,
                'placed order Two holds cancelled: the shop order is closed, no cancel sent'],
            [$linked, ['state' => 'FULFILLED', 'status' => 'APPROVED'], 200, '', $linkedRow, $own, $other, [$get], [], [], $confirm, [], $other, 'placed order Two holds fulfilled: handed to the confirmation route'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'DECLINED'], 200, '', null, 0, $other, [$get], $closed, [], $order, [$declined], $other, 'no session: closed and told, the cart is not restored'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'DECLINED'], 200, '', null, 555, $other, [$get], $closed, [], $order, [$declined], $other, 'another customer\'s session: closed and told, the cart is not restored'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'DECLINED'], 200, '', null, $own, $other, [$get], $closed, [], $order, [$declined], $cart, 'the buyer\'s own session on another cart: the attempt\'s cart is restored'],
            // TWO-27410: with no basket to land on, the message goes straight to the cart page.
            [[], ['state' => 'UNVERIFIED', 'status' => 'APPROVED'], 200, '', null, 0, 0, [$get, $post], $closed, [], $cartPage, [$declined], 0,
                'another browser, no storefront cookie: cancelled at Two, told on the cart page'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'DECLINED'], 200, '', null, 555, $empty, [$get], $closed, [], $cartPage, [$declined], $empty,
                'another customer\'s session with an empty cart: told on the cart page'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'DECLINED'], 200, '', null, $own, $empty, [$get], $closed, [], $order, [$declined], $cart,
                'the buyer\'s own session with an empty cart: the attempt\'s cart is restored, so the payment step'],
            [[], null, 200, '', null, 0, 0, [$get], [], [], $cartPage, [$lookupFailed], 0, 'Two cannot be read, no session cart: told on the cart page'],
        ];

        foreach ($cases as [$overrides, $twoOrder, $cancelHttp, $latest, $cartOrderRow, $sessionCustomer, $sessionCart, $twoCalls, $writes, $localCancels, $redirect, $messages, $cartAfter, $description]) {
            $context = self::resetStubs($sessionCustomer, $sessionCart, $cartOrderRow !== null);
            $module = new TwoCancelDeclineReturnHarness();
            $module->attempt = array_merge(self::attempt(), $overrides);
            $module->twoOrder = $twoOrder;
            $module->cancelHttpStatus = $cancelHttp;
            $module->latestToken = $latest;
            $module->cartOrderId = $cartOrderRow !== null ? self::ORDER_ID : 0;
            $module->cartOrderPaymentRow = $cartOrderRow !== null ? $cartOrderRow : false;

            [$redirectedTo, $errors] = self::returnOnce($module);

            TinyAssert::same($twoCalls, $module->twoCalls, $description . ': Two calls');
            TinyAssert::same($writes, $module->statusWrites, $description . ': attempt writes');
            TinyAssert::same($localCancels, $module->localCancels, $description . ': local orders cancelled');
            TinyAssert::same($redirect, $redirectedTo, $description . ': redirect');
            TinyAssert::same($messages, $errors, $description . ': buyer message');
            TinyAssert::same($cartAfter, (int) $context->cookie->id_cart, $description . ': session cart');
            TinyAssert::same([], array_keys(array_filter(StubStore::$dbLocks)), $description . ': the cart lock is released');
        }
    }

    /**
     * A cancel Two refuses leaves the attempt waiting, so the next return reads Two again and sends
     * the cancel again; once Two takes it, a further return changes nothing.
     */
    private static function testRefusedCancelIsSentAgainOnTheNextReturn(): void
    {
        self::resetStubs(self::CUSTOMER_ID, self::CART_ID, false);
        $module = new TwoCancelDeclineReturnHarness();
        $module->attempt = self::attempt();
        $module->twoOrder = ['state' => 'UNVERIFIED', 'status' => 'APPROVED'];
        $get = 'GET /v1/order/two-own';
        $post = 'POST /v1/order/two-own/cancel';

        $module->cancelHttpStatus = 503;
        self::returnOnce($module);
        TinyAssert::same([$get, $post], $module->twoCalls, 'refused: the cancel is sent once');
        TinyAssert::same('REDIRECTED', $module->attempt['status'], 'refused: the attempt stays waiting');

        $module->cancelHttpStatus = 200;
        self::returnOnce($module);
        TinyAssert::same([$get, $post, $get, $post], $module->twoCalls, 'next return: Two is read and the cancel sent again');
        TinyAssert::same([['CANCELLED', 'CANCELLED', 0]], $module->statusWrites, 'next return: the attempt is closed');

        self::returnOnce($module);
        TinyAssert::same(4, count($module->twoCalls), 'a return after the close sends Two nothing');
    }

    /** A link whose key is not the attempt's changes nothing. */
    private static function testWrongKeyChangesNothing(): void
    {
        $context = self::resetStubs(self::CUSTOMER_ID, self::OTHER_CART_ID, false);
        Tools::setTestValue('key', 'wrong');
        $module = new TwoCancelDeclineReturnHarness();
        $module->authorized = false;
        $module->attempt = self::attempt();
        $module->twoOrder = ['state' => 'UNVERIFIED', 'status' => 'DECLINED'];

        [, $errors] = self::returnOnce($module);
        TinyAssert::same([], $module->twoCalls, 'wrong key: Two is not called');
        TinyAssert::same([], $module->statusWrites, 'wrong key: nothing written');
        TinyAssert::same(['Unable to validate this cancellation callback. Please retry checkout.'], $errors, 'wrong key: the buyer is told');
        TinyAssert::same(self::OTHER_CART_ID, (int) $context->cookie->id_cart, 'wrong key: nothing restored');
    }

    private static function resetStubs(int $sessionCustomer, int $sessionCart, bool $withOrder): Context
    {
        StubStore::reset();
        Tools::resetTestValues();
        StubStore::$customers[self::CUSTOMER_ID] = ['email' => 'buyer@example.com', 'firstname' => 'Eva', 'lastname' => 'Martin', 'secure_key' => 'key', 'loaded' => true];
        StubStore::$customers[555] = ['email' => 'other@example.com', 'firstname' => 'Ola', 'lastname' => 'Berg', 'secure_key' => 'other', 'loaded' => true];
        StubStore::$carts[self::CART_ID] = ['id_customer' => self::CUSTOMER_ID, 'id_currency' => 978, 'id_address_invoice' => 0, 'id_address_delivery' => 0, 'id_carrier' => 0, 'id_lang' => 1];
        StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
        if ($withOrder) {
            StubStore::$orders[self::ORDER_ID] = ['id_cart' => self::CART_ID, 'id_customer' => self::CUSTOMER_ID, 'total_paid' => 100.0, 'module' => 'twopayment'];
        }
        StubStore::$carts[self::OTHER_CART_ID] = ['id_customer' => self::CUSTOMER_ID];
        StubStore::$carts[self::EMPTY_CART_ID] = ['id_customer' => 555];
        $line = ['id_product' => 1, 'cart_quantity' => 1];
        StubStore::$cartProducts = [self::CART_ID => [$line], self::OTHER_CART_ID => [$line]];
        $context = Context::getContext();
        $context->cookie = new Cookie();
        $context->cookie->id_cart = $sessionCart;
        // Session cart 0 is a browser with no storefront cookie: core hands the request an unsaved cart.
        $context->cart = new Cart($sessionCart);
        $context->customer = new Customer($sessionCustomer);

        return $context;
    }

    /**
     * @return array{0:string|null,1:array<int,string>} where the controller redirected, and the buyer's messages
     */
    private static function returnOnce(TwoCancelDeclineReturnHarness $module): array
    {
        $controller = new TwopaymentCancelModuleFrontController();
        $controller->module = $module;
        try {
            (new ReflectionMethod($controller, 'handleAttemptCancel'))->invokeArgs($controller, [self::TOKEN]);
        } catch (StubRedirect $e) {
            return [$e->getMessage(), $controller->errors];
        }

        return [null, $controller->errors];
    }

    private static function attempt(): array
    {
        return [
            'attempt_token' => self::TOKEN,
            'id_cart' => self::CART_ID,
            'id_customer' => self::CUSTOMER_ID,
            'id_order' => 0,
            'two_order_id' => 'two-own',
            'status' => 'REDIRECTED',
            'customer_secure_key' => 'key',
            'merchant_order_id' => 'm-27390',
        ];
    }
}
