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

    public function isTwoAttemptCallbackAuthorized($attempt, $provided_secure_key = '', $context_customer_id = 0, $context_customer_secure_key = '')
    {
        return true;
    }
}

/**
 * TWO-27390: Two's checkout sends a declined buyer to the cancel URL. The buyer wants to pay for
 * the same basket another way, so the return reads Two's state rather than trusting the click,
 * closes the attempt the way that state asks, and puts the buyer on the payment step with the
 * decline message. The cart goes back into the session only when it is the buyer's own.
 */
final class CancelDeclineReturnSpec
{
    private const CART_ID = 27390;
    private const CUSTOMER_ID = 27391;
    private const ORDER_ID = 27392;
    private const OTHER_CART_ID = 99;
    private const TOKEN = 'attempt-27390';

    public static function runAll(): void
    {
        self::testDeclineReturnTable();
    }

    private static function testDeclineReturnTable(): void
    {
        $get = 'GET /v1/order/two-own';
        $post = 'POST /v1/order/two-own/cancel';
        $order = 'index.php?controller=order';
        $confirm = 'https://shop.local/module/twopayment/confirmation?attempt_token=' . self::TOKEN . '&key=key';
        $declined = 'Two could not approve this purchase. Please choose another payment method.';
        $stale = 'This payment attempt has already been finalized.';
        $closed = [['CANCELLED', 'CANCELLED', 0]];
        $own = self::CUSTOMER_ID;
        $cart = self::CART_ID;
        $other = self::OTHER_CART_ID;
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
            [[], ['state' => 'UNVERIFIED', 'status' => 'APPROVED'], 500, '', null, $own, $cart, [$get, $post], [['CANCELLED', 'UNVERIFIED', 0]], [], $order, [$declined], $cart,
                'UNVERIFIED, cancel refused: closed with Two\'s last state, so a later return cancels again'],
            [[], null, 200, '', null, $own, $cart, [$get], [], [], $order, [$declined], $cart, 'Two cannot be read: nothing cancelled or written'],
            [[], ['state' => 'REFUNDED', 'status' => 'APPROVED'], 200, '', null, $own, $cart, [$get], [], [], $order, [$declined], $cart, 'a state the module does not act on changes nothing'],
            [['status' => 'CANCELLED', 'two_order_state' => 'CANCELLED'], null, 200, '', null, $own, $other, [], [], [], $order, [$declined], $cart, 'second click: the closed attempt changes nothing at Two or here'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'APPROVED'], 200, 'attempt-newer', null, $own, $other, [], [], [], $order, [$stale], $other, 'stale: a newer attempt holds the cart, so nothing changes'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'APPROVED'], 200, '', ['two_order_id' => 'two-other'], $own, $other, [], [], [], $order, [$stale], $other, 'stale: another checkout ordered the cart, so nothing changes'],
            [$linked, ['state' => 'CANCELLED', 'status' => 'CANCELLED'], 200, '', $linkedRow, $own, $other, [$get], [['CANCELLED', 'CANCELLED', self::ORDER_ID]], [self::ORDER_ID], $order, [$declined], $cart,
                'placed order Two holds cancelled: the shop order is closed, no cancel sent'],
            [$linked, ['state' => 'FULFILLED', 'status' => 'APPROVED'], 200, '', $linkedRow, $own, $other, [$get], [], [], $confirm, [], $other, 'placed order Two holds fulfilled: handed to the confirmation route'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'DECLINED'], 200, '', null, 0, $other, [$get], $closed, [], $order, [$declined], $other, 'no session: closed and told, the cart is not restored'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'DECLINED'], 200, '', null, 555, $other, [$get], $closed, [], $order, [$declined], $other, 'another customer\'s session: closed and told, the cart is not restored'],
            [[], ['state' => 'UNVERIFIED', 'status' => 'DECLINED'], 200, '', null, $own, $other, [$get], $closed, [], $order, [$declined], $cart, 'the buyer\'s own session on another cart: the attempt\'s cart is restored'],
        ];

        foreach ($cases as [$overrides, $twoOrder, $cancelHttp, $latest, $cartOrderRow, $sessionCustomer, $sessionCart, $twoCalls, $writes, $localCancels, $redirect, $messages, $cartAfter, $description]) {
            StubStore::reset();
            Tools::resetTestValues();
            StubStore::$customers[self::CUSTOMER_ID] = ['email' => 'buyer@example.com', 'firstname' => 'Eva', 'lastname' => 'Martin', 'secure_key' => 'key', 'loaded' => true];
            StubStore::$customers[555] = ['email' => 'other@example.com', 'firstname' => 'Ola', 'lastname' => 'Berg', 'secure_key' => 'other', 'loaded' => true];
            StubStore::$carts[self::CART_ID] = ['id_customer' => self::CUSTOMER_ID, 'id_currency' => 978, 'id_address_invoice' => 0, 'id_address_delivery' => 0, 'id_carrier' => 0, 'id_lang' => 1];
            StubStore::$currencies[978] = ['iso_code' => 'EUR', 'loaded' => true];
            if ($cartOrderRow !== null) {
                StubStore::$orders[self::ORDER_ID] = ['id_cart' => self::CART_ID, 'id_customer' => self::CUSTOMER_ID, 'total_paid' => 100.0, 'module' => 'twopayment'];
            }
            $context = Context::getContext();
            $context->cookie = new Cookie();
            $context->cookie->id_cart = $sessionCart;
            $context->customer = new Customer($sessionCustomer);

            $module = new TwoCancelDeclineReturnHarness();
            $module->attempt = array_merge(self::attempt(), $overrides);
            $module->twoOrder = $twoOrder;
            $module->cancelHttpStatus = $cancelHttp;
            $module->latestToken = $latest;
            $module->cartOrderId = $cartOrderRow !== null ? self::ORDER_ID : 0;
            $module->cartOrderPaymentRow = $cartOrderRow !== null ? $cartOrderRow : false;

            $controller = new TwopaymentCancelModuleFrontController();
            $controller->module = $module;
            $redirectedTo = null;
            try {
                (new ReflectionMethod($controller, 'handleAttemptCancel'))->invokeArgs($controller, [self::TOKEN]);
            } catch (StubRedirect $e) {
                $redirectedTo = $e->getMessage();
            }

            TinyAssert::same($twoCalls, $module->twoCalls, $description . ': Two calls');
            TinyAssert::same($writes, $module->statusWrites, $description . ': attempt writes');
            TinyAssert::same($localCancels, $module->localCancels, $description . ': local orders cancelled');
            TinyAssert::same($redirect, $redirectedTo, $description . ': redirect');
            TinyAssert::same($messages, $controller->errors, $description . ': buyer message');
            TinyAssert::same($cartAfter, (int) $context->cookie->id_cart, $description . ': session cart');
            TinyAssert::same([], array_keys(array_filter(StubStore::$dbLocks)), $description . ': the cart lock is released');
        }
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
