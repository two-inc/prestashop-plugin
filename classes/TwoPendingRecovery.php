<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * Recovers a Two payment the buyer verified but never returned from (TWO-26300).
 *
 * PrestaShop creates no order until the buyer comes back to the confirmation URL, so a buyer who
 * verifies with Two and closes the tab leaves only a checkout attempt behind. One resolver serves
 * the three ways an attempt is settled: the buyer's return, the scheduled check the cron URL runs,
 * and the admin "Check status with Two" button. All three take the same per-attempt lock and
 * re-read the attempt after taking it, so whichever arrives second finds the work done.
 *
 * The resolver creates the order from the stored cart outside the buyer's session: it sets the
 * context's cart, customer, currency and language from the cart before the snapshot check, so the
 * payload it rebuilds is the one the buyer's checkout built, and restores the caller's context
 * afterwards. validateOrder() sets the shop, language and currency from the cart itself.
 *
 * Two's state decides what happens (classifyTwoState()): VERIFIED is confirmed and placed,
 * CONFIRMED and later are placed without confirming again, CANCELLED (and a REJECTED or DECLINED
 * status) leaves the attempt unplaced and CANCELLED without sending Two a cancel, UNVERIFIED waits,
 * and anything else changes nothing. README "Orders the buyer verified but never returned from"
 * is the specification.
 */
class TwoPendingRecovery
{
    const TRIGGER_BUYER_RETURN = 'buyer_return';
    const TRIGGER_SCHEDULED = 'scheduled_check';
    const TRIGGER_ADMIN = 'admin_check';

    const OUTCOME_PLACED = 'placed';
    const OUTCOME_ALREADY_PLACED = 'already_placed';
    const OUTCOME_WAITING = 'waiting';
    const OUTCOME_CANCELLED = 'cancelled';
    const OUTCOME_DECLINED = 'declined';
    const OUTCOME_NOT_FOUND = 'not_found';
    const OUTCOME_UNCHANGED = 'unchanged';
    const OUTCOME_LOOKUP_FAILED = 'lookup_failed';
    const OUTCOME_CART_CHANGED = 'cart_changed';
    const OUTCOME_PLACE_FAILED = 'place_failed';
    const OUTCOME_OTHER_SHOP = 'other_shop';
    const OUTCOME_FAILED = 'failed';
    const OUTCOME_BUSY = 'busy';
    const OUTCOME_NOT_WAITING = 'not_waiting';
    const OUTCOME_DUPLICATE = 'duplicate';
    const OUTCOME_DUPLICATE_PENDING = 'duplicate_pending';
    const OUTCOME_CONFIRM_FAILED = 'confirm_failed';
    const OUTCOME_SINGLE_SHOP = 'single_shop';

    const ACTION_CONFIRM = 'confirm';
    const ACTION_PLACE = 'place';
    const ACTION_CANCEL = 'cancel';
    const ACTION_DECLINED = 'declined';
    const ACTION_WAIT = 'wait';
    const ACTION_UNKNOWN = 'unknown';

    /** The scheduled check leaves an attempt alone for this long after it was created. */
    const MIN_AGE_SECONDS = 1800;
    /** Two cancels an unverified order after 2 days by default; seven leaves room for a longer setting. */
    const MAX_AGE_SECONDS = 604800;
    /** An attempt checked by any trigger more recently than this is skipped by the schedule. */
    const RECHECK_AFTER_SECONDS = 900;
    const BATCH_SIZE = 50;
    /** No new check starts after this many seconds of a scheduled run. */
    const TIME_BUDGET_SECONDS = 20;
    /** Two's "no such order" must repeat on checks at least this far apart before it counts. */
    const NOT_FOUND_CONFIRM_AFTER_SECONDS = 900;
    /** A scheduled check that keeps failing is logged at most once per this interval, per attempt. */
    const FAILURE_LOG_INTERVAL_SECONDS = 86400;
    /** The buyer's return and the admin button wait this long for a check already running. */
    const LOCK_WAIT_SECONDS = 10;
    /** Diagnostics warns when attempts are waiting and the cron URL has been silent this long. */
    const SCHEDULE_SILENT_AFTER_SECONDS = 3600;

    /** States Two holds an order in once it is confirmed: placed here without confirming again. */
    const PLACED_STATES = array('CONFIRMED', 'FULFILLING', 'FULFILLED', 'DELIVERED', 'FULFILMENT_NEEDS_MANUAL_RESOLUTION');

    /** @var Twopayment */
    private $module;

    /**
     * @param Twopayment $module
     */
    public function __construct($module)
    {
        $this->module = $module;
    }

    /**
     * What a Two order's state asks of a waiting attempt. A VERIFIED or confirmed state wins over
     * the status field, so a placed order is never cancelled for its status.
     *
     * @param string $state
     * @param string $status
     * @return string one of the ACTION_* values
     */
    public static function classifyTwoState($state, $status)
    {
        $state = strtoupper(trim((string) $state));
        $status = strtoupper(trim((string) $status));
        if ($state === 'CANCELLED') {
            return self::ACTION_CANCEL;
        }
        if ($state === 'VERIFIED') {
            return self::ACTION_CONFIRM;
        }
        if (in_array($state, self::PLACED_STATES, true)) {
            return self::ACTION_PLACE;
        }
        if ($status === 'REJECTED' || $status === 'DECLINED') {
            return self::ACTION_DECLINED;
        }
        if ($state === 'UNVERIFIED') {
            return self::ACTION_WAIT;
        }

        return self::ACTION_UNKNOWN;
    }

    /**
     * Whether an attempt is still waiting for the buyer: redirected to Two, with a Two order, and
     * no local order yet.
     *
     * @param mixed $attempt
     * @return bool
     */
    public static function isWaiting($attempt)
    {
        return is_array($attempt)
            && isset($attempt['status'], $attempt['two_order_id'])
            && strtoupper(trim((string) $attempt['status'])) === 'REDIRECTED'
            && trim((string) $attempt['two_order_id']) !== ''
            && (empty($attempt['id_order']) || (int) $attempt['id_order'] <= 0);
    }

    /**
     * The scheduled check: the oldest-checked waiting attempts of this shop that are 30 minutes to
     * 7 days old and were not checked in the last 15 minutes, 50 per run, within a time budget.
     *
     * @param int|null $now
     * @return array{checked:int,placed:int,cancelled:int,failed:int,busy:int}
     */
    public function runScheduledCheck($now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $started = microtime(true);
        $summary = array('checked' => 0, 'placed' => 0, 'cancelled' => 0, 'failed' => 0, 'busy' => 0);
        $tokens = $this->module->getTwoPendingAttemptCandidates(
            date('Y-m-d H:i:s', $now - self::MIN_AGE_SECONDS),
            date('Y-m-d H:i:s', $now - self::MAX_AGE_SECONDS),
            date('Y-m-d H:i:s', $now - self::RECHECK_AFTER_SECONDS),
            self::BATCH_SIZE
        );
        foreach ($tokens as $token) {
            if (microtime(true) - $started >= self::TIME_BUDGET_SECONDS) {
                break;
            }
            $result = $this->resolveAttempt($token, self::TRIGGER_SCHEDULED);
            $summary['checked']++;
            $outcome = $result['outcome'];
            if ($outcome === self::OUTCOME_PLACED) {
                $summary['placed']++;
            } elseif (in_array($outcome, array(self::OUTCOME_CANCELLED, self::OUTCOME_DECLINED, self::OUTCOME_NOT_FOUND, self::OUTCOME_DUPLICATE), true)) {
                $summary['cancelled']++;
            } elseif ($outcome === self::OUTCOME_BUSY) {
                $summary['busy']++;
            } elseif (in_array($outcome, array(self::OUTCOME_LOOKUP_FAILED, self::OUTCOME_CART_CHANGED, self::OUTCOME_PLACE_FAILED, self::OUTCOME_CONFIRM_FAILED, self::OUTCOME_DUPLICATE_PENDING, self::OUTCOME_FAILED), true)) {
                $summary['failed']++;
            }
        }

        return $summary;
    }

    /**
     * Settle one attempt under its lock.
     *
     * @param string $attempt_token
     * @param string $trigger one of the TRIGGER_* values
     * @return array{outcome:string,message:string,id_order:int,two_state:string}
     */
    public function resolveAttempt($attempt_token, $trigger)
    {
        $attempt_token = trim((string) $attempt_token);
        // Keyed on the cart: two attempts for one cart must never race through validateOrder().
        $lock = self::lockName($this->module->getTwoCheckoutAttempt($attempt_token), $attempt_token);
        $wait = $trigger === self::TRIGGER_SCHEDULED ? 0 : self::LOCK_WAIT_SECONDS;
        if (!$this->module->acquireTwoAttemptLock($lock, $wait)) {
            return $this->result(self::OUTCOME_BUSY);
        }

        $context = Context::getContext();
        $saved = array(
            'cart' => isset($context->cart) ? $context->cart : null,
            'customer' => isset($context->customer) ? $context->customer : null,
            'currency' => isset($context->currency) ? $context->currency : null,
            'language' => isset($context->language) ? $context->language : null,
        );
        try {
            $result = $this->resolveLocked($attempt_token, $trigger);
        } finally {
            if ($trigger !== self::TRIGGER_BUYER_RETURN) {
                // The admin page renders after the check in the employee's own language and context.
                foreach ($saved as $key => $value) {
                    $context->$key = $value;
                }
            }
            $this->module->releaseTwoAttemptLock($lock);
        }

        return $result;
    }

    /**
     * The lock every trigger takes: per cart, since a cart can carry one order and several
     * attempts; per token only for an attempt that cannot be read.
     *
     * @param mixed $attempt
     * @param string $attempt_token
     * @return string
     */
    public static function lockName($attempt, $attempt_token)
    {
        if (is_array($attempt) && !empty($attempt['id_cart'])) {
            return 'two_cart_' . (int) $attempt['id_cart'];
        }

        // GET_LOCK names are capped at 64 characters; an attempt token may be longer.
        return 'two_attempt_' . md5((string) $attempt_token);
    }

    /**
     * @param string $attempt_token
     * @param string $trigger
     * @return array{outcome:string,message:string,id_order:int,two_state:string}
     */
    private function resolveLocked($attempt_token, $trigger)
    {
        $buyer = $trigger === self::TRIGGER_BUYER_RETURN;
        $attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
        if (!is_array($attempt)) {
            return $this->result(self::OUTCOME_FAILED, $this->module->l('Unable to find the requested payment attempt. Please try checkout again.'));
        }
        if (!$buyer && !self::isWaiting($attempt)) {
            return $this->result(self::OUTCOME_NOT_WAITING, '', isset($attempt['id_order']) ? (int) $attempt['id_order'] : 0);
        }

        $customer = new Customer((int) $attempt['id_customer']);
        if (!Validate::isLoadedObject($customer)) {
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');

            return $this->result(self::OUTCOME_FAILED, $this->module->l('Unable to load customer for this payment attempt.'));
        }

        if ($this->abortIfCancelled($attempt_token, $attempt)) {
            return $this->result(self::OUTCOME_CANCELLED, $this->module->l('Your order is cancelled.'));
        }

        $existing_order_id = (int) $attempt['id_order'];
        if ($existing_order_id > 0) {
            $existing_order = new Order($existing_order_id);
            if (Validate::isLoadedObject($existing_order)) {
                $existing_payment_data = $this->module->getTwoOrderPaymentData($existing_order->id);
                if ($existing_payment_data && isset($existing_payment_data['two_order_id'])) {
                    if ($this->syncTwoMerchantOrderId($existing_order, $existing_payment_data)) {
                        $this->module->setTwoCheckoutAttemptMerchantOrderId($attempt_token, (string) $existing_order->id);
                    }
                }
                if ($this->rereadAbortsAsCancelled($attempt_token, $attempt)) {
                    return $this->result(self::OUTCOME_CANCELLED, $this->module->l('Your order is cancelled.'));
                }

                return $this->result(self::OUTCOME_ALREADY_PLACED, '', (int) $existing_order->id);
            }
        }

        $cart = new Cart((int) $attempt['id_cart']);
        if (!Validate::isLoadedObject($cart)) {
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');

            return $this->result(self::OUTCOME_FAILED, $this->module->l('Unable to load cart for this payment attempt.'));
        }

        // A newer attempt already placed this cart's order: this attempt can never be placed, and
        // its Two order is a duplicate (TWO-26300). Checked before the snapshot and the confirm.
        $duplicate_order_id = $this->foreignCartOrderId($cart, $attempt);
        if ($duplicate_order_id > 0) {
            return $this->refuseDuplicate($attempt_token, $attempt, $cart, $duplicate_order_id, $trigger);
        }

        // An order of this module on the cart that validateOrder() left part way is never adopted.
        $cart_order_id = (int) $this->module->getTwoOrderIdByCart((int) $cart->id);
        if ($cart_order_id > 0 && !$this->module->isTwoOrderCompletelyPlaced($cart_order_id)) {
            PrestaShopLogger::addLog(
                'TwoPayment: Order ' . $cart_order_id . ' for cart ' . (int) $cart->id . ' is incomplete, so attempt '
                    . $attempt_token . ' does not use it. Check it by hand.',
                3,
                null,
                'Order',
                $cart_order_id
            );
            if (!$buyer) {
                return $this->placeFailed($attempt_token, $attempt, $trigger);
            }
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');

            return $this->result(self::OUTCOME_FAILED, $this->module->l('Unable to create local order for this payment attempt.'));
        }

        // Ensure checkout context matches the attempt before creating a local order.
        $context->cart = $cart;
        $context->customer = $customer;
        $context->currency = new Currency((int) $cart->id_currency);
        if ($buyer) {
            $context->cookie->id_cart = (int) $cart->id;
            $context->cookie->id_customer = (int) $customer->id;
            $context->cookie->write();
        } else {
            // The confirmation URL inside the snapshot carries the buyer's language.
            $context->language = new Language((int) $cart->id_lang);
        }

        // Snapshot the buyer company HERE, not beside the write that persists
        // it (TWO-40): this is the last point where context->cart is provably
        // the attempt's cart. Taken any later, a rotated context->cart would
        // make readTwoCartScopedCompany() report absent (and discard the
        // record), leaving the resolver to fall back to the address's
        // identifier fields and produce exactly the empty organisation number
        // this snapshot exists to prevent.
        $company_snapshot = $this->module->getTwoOrderCompanySnapshot($cart);

        if (empty($attempt['two_order_id'])) {
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');

            return $this->result(self::OUTCOME_FAILED, $this->module->l('Missing provider order reference for this attempt.'));
        }

        $stored_snapshot_hash = isset($attempt['cart_snapshot_hash']) ? trim((string) $attempt['cart_snapshot_hash']) : '';
        // Records which URL variant (with/without secure key) the stored hash
        // matched, so the final pre-validateOrder re-check compares like with
        // like. Null until a variant matches.
        $snapshot_includes_secure_key = null;
        if (!Tools::isEmpty($stored_snapshot_hash)) {
            try {
                // EXPLICIT CHOICE: this initial check self-heals the cart's
                // surcharge line (sync=true) before hashing, so a missed
                // frontend sync (broken theme JS) reconverges to the state
                // the stored snapshot was built from instead of failing the
                // whole checkout on a healable divergence.
                $current_snapshot_hash = $this->buildAttemptSnapshotHash($attempt_token, $attempt, $cart, true, true);
            } catch (Exception $e) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Failed to build cart snapshot for attempt ' . $attempt_token . ' - ' . $e->getMessage(),
                    3
                );
                if (!$buyer) {
                    return $this->cartChanged($attempt_token, $attempt, $trigger);
                }
                $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');

                return $this->result(self::OUTCOME_FAILED, $this->module->l('Unable to validate cart consistency for this payment. Please try again.'));
            }

            if (hash_equals($stored_snapshot_hash, $current_snapshot_hash)) {
                $snapshot_includes_secure_key = true;
            } else {
                // Compatibility fallback: allow old attempts created before callback key was added.
                // Comparison-only rebuild: the sync above already ran, do not
                // mutate the cart again just to compute a hash.
                try {
                    $legacy_snapshot_hash = $this->buildAttemptSnapshotHash($attempt_token, $attempt, $cart, false, false);
                } catch (Exception $e) {
                    $legacy_snapshot_hash = '';
                }

                if (!Tools::isEmpty($legacy_snapshot_hash) && hash_equals($stored_snapshot_hash, $legacy_snapshot_hash)) {
                    $current_snapshot_hash = $legacy_snapshot_hash;
                    $snapshot_includes_secure_key = false;
                } else {
                    PrestaShopLogger::addLog(
                        'TwoPayment: Cart snapshot mismatch for attempt ' . $attempt_token . '. Stored=' . $stored_snapshot_hash . ', Current=' . $current_snapshot_hash,
                        3
                    );
                    if (!$buyer) {
                        return $this->cartChanged($attempt_token, $attempt, $trigger);
                    }
                    // Cart changed between provider order creation and callback finalization.
                    $this->module->sendTwoOrderRequest(TwoOrderPostprocessing::REQUEST_CANCEL, 'snapshot_mismatch', '/v1/order/' . $attempt['two_order_id'] . '/cancel', [], 'POST', $cart);
                    $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');

                    return $this->result(self::OUTCOME_FAILED, $this->module->l('Your cart changed during payment verification. Please review your cart and try again.'));
                }
            }
        }

        $two_order_id = $attempt['two_order_id'];
        $response = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
        if ($this->module->getTwoErrorMessage($response)) {
            return $this->lookupFailed($attempt_token, $attempt, $cart, $response, $trigger);
        }
        $two_state = isset($response['state']) ? strtoupper((string) $response['state']) : '';
        $two_status = isset($response['status']) ? (string) $response['status'] : '';
        $action = self::classifyTwoState($two_state, $two_status);

        if ($action === self::ACTION_CANCEL || $action === self::ACTION_DECLINED) {
            $outcome = $action === self::ACTION_CANCEL ? self::OUTCOME_CANCELLED : self::OUTCOME_DECLINED;

            return $this->cancelLocally($attempt_token, $attempt, $two_state, $two_status, $outcome, $trigger);
        }
        if ($action === self::ACTION_WAIT || $action === self::ACTION_UNKNOWN) {
            return $this->leaveWaiting($attempt_token, $attempt, $two_state, $action, $trigger);
        }

        $final_state = isset($response['state']) ? $response['state'] : 'VERIFIED';
        $final_status = isset($response['status']) ? $response['status'] : null;
        $confirmed_here = false;
        if ($action === self::ACTION_CONFIRM) {
            $confirm_result = $this->module->confirmTwoOrder($two_order_id, $buyer ? 'confirmation' : $trigger, $cart);
            if ($confirm_result['success']) {
                $confirmed_here = true;
                $final_state = $confirm_result['state'];
                $final_status = $confirm_result['status'] ?: $final_status;
            } elseif (!$buyer) {
                // No buyer is waiting: place nothing Two has not confirmed. The next check confirms
                // again, places it if Two confirmed it meanwhile, or cancels it if Two cancelled it.
                $this->stampWithDailyLog($attempt_token, $attempt, $trigger, self::OUTCOME_CONFIRM_FAILED, 'confirming with Two failed; nothing placed, the next check tries again');

                return $this->result(self::OUTCOME_CONFIRM_FAILED, '', 0, $two_state);
            }
        }

        $provider_gross_amount = $this->module->extractTwoProviderGrossAmountForValidation($response);
        if ($provider_gross_amount === null) {
            PrestaShopLogger::addLog(
                'TwoPayment: Missing or invalid provider gross_amount in callback response for attempt ' . $attempt_token,
                3
            );
            if (!$buyer) {
                return $this->placeFailed($attempt_token, $attempt, $trigger);
            }
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');

            return $this->result(self::OUTCOME_FAILED, $this->module->l('Unable to retrieve the order payment information please contact store owner.'));
        }

        // Re-read latest attempt status to close cancel/confirm race windows.
        if ($this->rereadAbortsAsCancelled($attempt_token, $attempt)) {
            return $this->result(self::OUTCOME_CANCELLED, $this->module->l('Your order is cancelled.'));
        }

        $id_order = (int) $attempt['id_order'];
        if ($id_order <= 0) {
            $id_order = (int) $this->module->getTwoOrderIdByCart((int) $cart->id);
        }

        if ($id_order > 0) {
            $existing_payment_data = $this->module->getTwoOrderPaymentData((int) $id_order);
            if ($this->module->hasTwoOrderRebindingConflict($existing_payment_data, (string) $attempt['two_order_id'])) {
                $existing_two_order_id = isset($existing_payment_data['two_order_id']) ? (string) $existing_payment_data['two_order_id'] : '';
                PrestaShopLogger::addLog(
                    'TwoPayment: Rebinding guard blocked callback for attempt ' . $attempt_token .
                    '. Existing order ' . (int) $id_order . ' already linked to Two order ' . $existing_two_order_id .
                    ', incoming Two order ' . (string) $attempt['two_order_id'],
                    3
                );

                // Best effort: cancel incoming duplicate provider order to avoid orphaned external state.
                if (!Tools::isEmpty($attempt['two_order_id'])) {
                    $this->module->sendTwoOrderRequest(TwoOrderPostprocessing::REQUEST_CANCEL, 'rebinding_guard', '/v1/order/' . $attempt['two_order_id'] . '/cancel', [], 'POST', $cart);
                }

                $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
                $existing_order = new Order((int) $id_order);
                if (Validate::isLoadedObject($existing_order)) {
                    return $this->result(self::OUTCOME_ALREADY_PLACED, '', (int) $existing_order->id);
                }

                return $this->result(self::OUTCOME_FAILED, $this->module->l('This payment attempt has already been finalized.'));
            }
        }

        if ($id_order <= 0) {
            if ($this->rereadAbortsAsCancelled($attempt_token, $attempt)) {
                return $this->result(self::OUTCOME_CANCELLED, $this->module->l('Your order is cancelled.'));
            }

            // FINAL PARITY RE-CHECK, adjacent to the charge: the earlier
            // snapshot check is separated from validateOrder by two provider
            // round-trips, during which the cart could theoretically drift.
            // Re-run the self-healing snapshot build (sync=true also enforces
            // cart-vs-payload fee parity, failing closed) so the gate's last
            // word is issued immediately before the money moves.
            if (!Tools::isEmpty($stored_snapshot_hash) && $snapshot_includes_secure_key !== null) {
                try {
                    $final_snapshot_hash = $this->buildAttemptSnapshotHash(
                        $attempt_token,
                        $attempt,
                        $cart,
                        $snapshot_includes_secure_key,
                        true
                    );
                } catch (Exception $e) {
                    PrestaShopLogger::addLog(
                        'TwoPayment: Final pre-order snapshot rebuild failed for attempt ' . $attempt_token . ' - ' . $e->getMessage(),
                        3
                    );
                    if (!$buyer) {
                        return $this->cartChanged($attempt_token, $attempt, $trigger);
                    }
                    $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');

                    return $this->result(self::OUTCOME_FAILED, $this->module->l('Unable to validate cart consistency for this payment. Please try again.'));
                }

                if (!hash_equals($stored_snapshot_hash, $final_snapshot_hash)) {
                    PrestaShopLogger::addLog(
                        'TwoPayment: Cart snapshot drifted between verification and order creation for attempt ' . $attempt_token .
                        '. Stored=' . $stored_snapshot_hash . ', Final=' . $final_snapshot_hash,
                        3
                    );
                    if (!$buyer) {
                        return $this->cartChanged($attempt_token, $attempt, $trigger);
                    }
                    $this->module->sendTwoOrderRequest(TwoOrderPostprocessing::REQUEST_CANCEL, 'snapshot_drift', '/v1/order/' . $attempt['two_order_id'] . '/cancel', [], 'POST', $cart);
                    $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');

                    return $this->result(self::OUTCOME_FAILED, $this->module->l('Your cart changed during payment verification. Please review your cart and try again.'));
                }
            } else {
                // Legacy attempt without a stored snapshot hash: still run the
                // same fail-closed parity gate as the hashed path above, since
                // core validateOrder performs no such check itself. The hash
                // result is discarded - there is nothing to compare it against.
                try {
                    $this->buildAttemptSnapshotHash($attempt_token, $attempt, $cart, false, true);
                } catch (Exception $e) {
                    PrestaShopLogger::addLog(
                        'TwoPayment: Legacy (no stored hash) pre-order parity gate failed for attempt ' . $attempt_token . ' - ' . $e->getMessage(),
                        3
                    );
                    if (!$buyer) {
                        return $this->cartChanged($attempt_token, $attempt, $trigger);
                    }
                    $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');

                    return $this->result(self::OUTCOME_FAILED, $this->module->l('Unable to validate cart consistency for this payment. Please try again.'));
                }
            }

            $create_result = $this->module->createTwoLocalOrderAfterProviderVerification(
                $cart,
                $customer,
                (int) $this->module->getTwoInitialAwaitingStatusId(),
                (float) $provider_gross_amount
            );

            if (!(bool) $create_result['success']) {
                if (!$buyer) {
                    return $this->placeFailed($attempt_token, $attempt, $trigger);
                }
                $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
                $error_code = isset($create_result['error']) ? (string) $create_result['error'] : '';

                return $this->result(
                    self::OUTCOME_FAILED,
                    $error_code === 'currency_invalid'
                        ? $this->module->l('Unable to load currency for this payment attempt.')
                        : $this->module->l('Unable to create local order for this payment attempt.')
                );
            }
            $id_order = isset($create_result['id_order']) ? (int) $create_result['id_order'] : 0;
            if (!empty($create_result['recovered_existing']) && $this->isForeignOrder($id_order, $attempt)) {
                // validateOrder() refused the cart because another checkout ordered it.
                return $this->refuseDuplicate($attempt_token, $attempt, $cart, $id_order, $trigger);
            }
            if ((bool) (isset($create_result['recovered_existing']) ? $create_result['recovered_existing'] : false)) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Recovered existing local order ' . $id_order .
                    ' for callback attempt ' . $attempt_token . ' after validateOrder race/duplicate.',
                    2
                );
            }
        }

        $order = new Order((int) $id_order);
        if ($id_order <= 0 || !Validate::isLoadedObject($order)) {
            if (!$buyer) {
                return $this->placeFailed($attempt_token, $attempt, $trigger);
            }
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');

            return $this->result(
                self::OUTCOME_FAILED,
                $id_order <= 0
                    ? $this->module->l('Unable to create local order for this payment attempt.')
                    : $this->module->l('Unable to load the created order. Please contact support.')
            );
        }

        if ($this->rereadAbortsAsCancelled($attempt_token, $attempt)) {
            return $this->result(self::OUTCOME_CANCELLED, $this->module->l('Your order is cancelled.'));
        }

        $invoice_id = isset($response['invoice_details']['id']) ? $response['invoice_details']['id'] : $attempt['two_invoice_id'];
        $invoice_url = isset($response['invoice_url']) ? $response['invoice_url'] : $attempt['two_invoice_url'];
        $resolved_terms = $this->module->resolveTwoPaymentTermsFromOrderResponse(
            $response,
            isset($attempt['two_day_on_invoice']) ? (string) $attempt['two_day_on_invoice'] : (string) $this->module->getSelectedPaymentTerm(),
            isset($attempt['two_payment_term_type']) ? $attempt['two_payment_term_type'] : Configuration::get('PS_TWO_PAYMENT_TERM_TYPE')
        );
        $payment_data = array(
            'two_order_id' => isset($response['id']) ? $response['id'] : $attempt['two_order_id'],
            'two_order_reference' => isset($response['merchant_reference']) ? $response['merchant_reference'] : $attempt['two_order_reference'],
            'two_order_state' => $final_state,
            'two_order_status' => $final_status,
            'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
            'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
            'two_invoice_url' => $invoice_url,
            'two_invoice_id' => $invoice_id,
            // The only setTwoOrderPaymentData() caller that carries these two
            // keys, and that is the design: they are presence-conditional there,
            // so every later status/webhook write leaves the stored value alone
            // instead of blanking it.
            'two_company_name' => $company_snapshot['two_company_name'],
            'two_organization_number' => $company_snapshot['two_organization_number'],
        );
        // The rates the pre-order re-check just declared for this cart; an update falls back to them (TWO-26085).
        // With the prepaid amount Two holds for the order, which its later edits and refunds declare (TWO-26326).
        $declared_rates = $this->module->getTwoDeclaredChargeRates($response);
        if ($declared_rates !== null) {
            $payment_data['two_declared_rates'] = $declared_rates;
        }
        $this->module->setTwoOrderPaymentData($order->id, $payment_data);

        // Best effort: replace provisional merchant_order_id with real PrestaShop id_order in Two.
        if ($this->syncTwoMerchantOrderId($order, $payment_data, $response)) {
            $this->module->setTwoCheckoutAttemptMerchantOrderId($attempt_token, (string) $order->id);
        }

        $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'CONFIRMED', array(
            'id_order' => (int) $order->id,
            'two_order_state' => $payment_data['two_order_state'],
            'two_order_status' => $payment_data['two_order_status'],
            'two_day_on_invoice' => $payment_data['two_day_on_invoice'],
            'two_payment_term_type' => $payment_data['two_payment_term_type'],
            'two_invoice_url' => $payment_data['two_invoice_url'],
            'two_invoice_id' => $payment_data['two_invoice_id'],
        ));

        if ($this->rereadAbortsAsCancelled($attempt_token, $attempt)) {
            return $this->result(self::OUTCOME_CANCELLED, $this->module->l('Your order is cancelled.'));
        }

        $this->module->changeOrderStatus($order->id, $this->module->getTwoVerifiedStatusIdForConfirmation());
        if ($buyer) {
            // This order consumed the verdict; nothing after it may be
            // priced against an approval the buyer got for a cart now placed.
            $this->module->clearTwoOrderIntentSession();
        } else {
            $this->module->addTwoPendingRecoveryNote((int) $order->id, $this->placedNote($trigger, $confirmed_here));
            $this->stamp($attempt_token, self::OUTCOME_PLACED);
            PrestaShopLogger::addLog(
                'TwoPayment: ' . $trigger . ' placed order ' . (int) $order->id . ' for attempt ' . $attempt_token
                    . ', Two order ' . $two_order_id . ', Two state ' . $two_state . ($confirmed_here ? ', confirmed with Two' : ''),
                1,
                null,
                'Order',
                (int) $order->id
            );
        }
        $this->module->clearTwoCartRecords((int) $cart->id);

        return $this->result(self::OUTCOME_PLACED, '', (int) $order->id, $two_state);
    }

    /**
     * The cart's order when it belongs to another checkout: linked to a different Two order, or
     * placed with another payment module. An unlinked order of this module is this attempt's own,
     * left by a confirmation that stopped before writing its Two data.
     *
     * @param Cart $cart
     * @param array $attempt
     * @return int
     */
    private function foreignCartOrderId($cart, array $attempt)
    {
        $id_order = (int) $this->module->getTwoOrderIdByCart((int) $cart->id);

        return $id_order > 0 && $this->isForeignOrder($id_order, $attempt) ? $id_order : 0;
    }

    /**
     * @param int $id_order
     * @param array $attempt
     * @return bool
     */
    private function isForeignOrder($id_order, array $attempt)
    {
        $order = new Order((int) $id_order);
        if (!Validate::isLoadedObject($order)) {
            return false;
        }
        if ((string) $order->module !== (string) $this->module->name) {
            return true;
        }

        return $this->module->hasTwoOrderRebindingConflict(
            $this->module->getTwoOrderPaymentData((int) $id_order),
            (string) $attempt['two_order_id']
        );
    }

    /**
     * This attempt's cart was ordered by another checkout, so its Two order is a duplicate: it is
     * cancelled at Two, unless Two already holds it cancelled, and the attempt is closed. The other
     * order is never touched. A returning buyer is sent to the order that was placed.
     *
     * @return array{outcome:string,message:string,id_order:int,two_state:string}
     */
    private function refuseDuplicate($attempt_token, array $attempt, $cart, $other_order_id, $trigger)
    {
        $two_order_id = (string) $attempt['two_order_id'];
        $two_order = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
        if ($this->module->isTwoOrderCancelledResponse($two_order)) {
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'CANCELLED', array('two_order_state' => 'CANCELLED'));
            $sent = 'nothing sent to Two, which holds it cancelled';
        } else {
            $this->module->sendTwoOrderRequest(TwoOrderPostprocessing::REQUEST_CANCEL, 'rebinding_guard', '/v1/order/' . $two_order_id . '/cancel', [], 'POST', $cart);
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'FAILED');
            $sent = 'cancelled the duplicate at Two';
        }
        PrestaShopLogger::addLog(
            'TwoPayment: Rebinding guard: cart ' . (int) $cart->id . ' already has order ' . (int) $other_order_id
                . ' from another checkout; attempt ' . $attempt_token . ', Two order ' . $two_order_id . ' not placed, ' . $sent . ' (' . $trigger . ')',
            3
        );
        if ($trigger !== self::TRIGGER_BUYER_RETURN) {
            $this->stamp($attempt_token, self::OUTCOME_DUPLICATE);

            return $this->result(self::OUTCOME_DUPLICATE, '', (int) $other_order_id);
        }

        return $this->result(self::OUTCOME_ALREADY_PLACED, '', (int) $other_order_id);
    }

    /**
     * Two holds the order cancelled, or declined it: the attempt is left unplaced and CANCELLED, and
     * no cancel is sent back to Two. A local order is touched only when it is linked to this very
     * Two order: a cart can carry an order a later attempt placed.
     *
     * @return array{outcome:string,message:string,id_order:int,two_state:string}
     */
    private function cancelLocally($attempt_token, array $attempt, $two_state, $two_status, $outcome, $trigger)
    {
        $extra_data = array(
            // Recorded so a later buyer return on this attempt never echoes the cancel to Two.
            'two_order_state' => $two_state !== '' ? $two_state : 'CANCELLED',
            'two_order_status' => $two_status,
        );
        $id_order = $this->linkedOrderId($attempt);
        if ($id_order > 0) {
            $extra_data['id_order'] = $id_order;
        }
        if ($outcome === self::OUTCOME_DECLINED) {
            $extra_data['two_order_state'] = 'CANCELLED';
        }
        $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'CANCELLED', $extra_data);
        $this->module->syncLocalOrderStatusFromTwoState($id_order, 'CANCELLED');
        if ($trigger !== self::TRIGGER_BUYER_RETURN) {
            $this->stamp($attempt_token, $outcome);
            PrestaShopLogger::addLog(
                'TwoPayment: ' . $trigger . ' found Two order ' . $attempt['two_order_id'] . ' ' . $two_state
                    . ($two_status !== '' ? '/' . $two_status : '') . ' for attempt ' . $attempt_token . '; marked it cancelled here, nothing sent to Two',
                1
            );
        }

        return $this->result($outcome, $this->module->l('Your order is cancelled.'), $id_order, $two_state);
    }

    /**
     * UNVERIFIED waits for the buyer; REFUNDED or a state this module does not know changes nothing,
     * and is logged as a warning once per state.
     *
     * @return array{outcome:string,message:string,id_order:int,two_state:string}
     */
    private function leaveWaiting($attempt_token, array $attempt, $two_state, $action, $trigger)
    {
        $outcome = $action === self::ACTION_WAIT ? self::OUTCOME_WAITING : self::OUTCOME_UNCHANGED;
        $note = $outcome === self::OUTCOME_UNCHANGED ? self::OUTCOME_UNCHANGED . ':' . $two_state : $outcome;
        $previous = isset($attempt['pending_outcome']) ? (string) $attempt['pending_outcome'] : '';
        if ($outcome === self::OUTCOME_UNCHANGED && $previous !== $note) {
            PrestaShopLogger::addLog(
                'TwoPayment: Two order ' . $attempt['two_order_id'] . ' for attempt ' . $attempt_token . ' is in state '
                    . ($two_state !== '' ? $two_state : '(none)') . ', which the module does not act on; nothing was changed (' . $trigger . ')',
                2
            );
        }
        $this->stamp($attempt_token, $note, array('pending_not_found_at' => null));

        return $this->result(
            $outcome,
            $this->module->l('Payment has not been verified yet. Please try again or contact support.'),
            0,
            $two_state
        );
    }

    /**
     * The read failed. Nothing changes and the next run retries, except that Two's own "no such
     * order", seen again on a check at least 15 minutes after the first, cancels the attempt here.
     *
     * @return array{outcome:string,message:string,id_order:int,two_state:string}
     */
    private function lookupFailed($attempt_token, array $attempt, $cart, $response, $trigger)
    {
        $now = time();
        $fields = array();
        if (self::isTwoOrderNotFound($response)) {
            $first_seen = isset($attempt['pending_not_found_at']) ? strtotime((string) $attempt['pending_not_found_at']) : false;
            if ($trigger !== self::TRIGGER_BUYER_RETURN && $first_seen && $now - $first_seen >= self::NOT_FOUND_CONFIRM_AFTER_SECONDS) {
                $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'CANCELLED', array('two_order_state' => 'NOT_FOUND'));
                $this->stamp($attempt_token, self::OUTCOME_NOT_FOUND);
                PrestaShopLogger::addLog(
                    'TwoPayment: Two has no order ' . $attempt['two_order_id'] . ' for attempt ' . $attempt_token
                        . ' on two checks at least ' . self::NOT_FOUND_CONFIRM_AFTER_SECONDS . 's apart; marked it cancelled here (' . $trigger . ')',
                    2
                );

                return $this->result(self::OUTCOME_NOT_FOUND, '', 0, 'NOT_FOUND');
            }
            if (!$first_seen) {
                $fields['pending_not_found_at'] = date('Y-m-d H:i:s', $now);
            }
        } else {
            $fields['pending_not_found_at'] = null;
        }

        $logged_at = isset($attempt['pending_logged_at']) ? strtotime((string) $attempt['pending_logged_at']) : false;
        if ($trigger !== self::TRIGGER_SCHEDULED || !$logged_at || $now - $logged_at >= self::FAILURE_LOG_INTERVAL_SECONDS) {
            // Two's reason is for the merchant, never the buyer (TWO-26611).
            $this->logTwoOrderLookupFailure(
                'cart ' . (int) $cart->id . ', attempt ' . $attempt_token . ', Two order ' . $attempt['two_order_id'],
                $response,
                (int) $cart->id
            );
            if ($trigger === self::TRIGGER_SCHEDULED) {
                $fields['pending_logged_at'] = date('Y-m-d H:i:s', $now);
            }
        }
        if ($trigger !== self::TRIGGER_BUYER_RETURN) {
            $this->stamp($attempt_token, self::OUTCOME_LOOKUP_FAILED, $fields);
        } elseif ($fields !== array()) {
            $this->module->stampTwoPendingAttempt($attempt_token, $fields);
        }

        return $this->result(
            self::OUTCOME_LOOKUP_FAILED,
            $this->module->l('Unable to retrieve the order payment information please contact store owner.')
        );
    }

    /**
     * The cart no longer builds the payload the buyer verified. A buyer is present to start again;
     * an unattended check is not, so it places nothing, sends Two nothing and leaves the attempt for
     * the merchant, logging it at most once a day.
     *
     * @return array{outcome:string,message:string,id_order:int,two_state:string}
     */
    private function cartChanged($attempt_token, array $attempt, $trigger)
    {
        $this->stampWithDailyLog($attempt_token, $attempt, $trigger, self::OUTCOME_CART_CHANGED, 'the cart no longer matches what the buyer verified; nothing placed and nothing sent to Two');

        return $this->result(self::OUTCOME_CART_CHANGED);
    }

    /**
     * Two holds the order but the local order could not be created: the attempt stays waiting, so
     * the next check places it without confirming again.
     *
     * @return array{outcome:string,message:string,id_order:int,two_state:string}
     */
    private function placeFailed($attempt_token, array $attempt, $trigger)
    {
        $this->stampWithDailyLog($attempt_token, $attempt, $trigger, self::OUTCOME_PLACE_FAILED, 'the local order could not be created; the next check retries');

        return $this->result(self::OUTCOME_PLACE_FAILED);
    }

    private function stampWithDailyLog($attempt_token, array $attempt, $trigger, $outcome, $reason)
    {
        $now = time();
        $fields = array();
        $logged_at = isset($attempt['pending_logged_at']) ? strtotime((string) $attempt['pending_logged_at']) : false;
        if ($trigger !== self::TRIGGER_SCHEDULED || !$logged_at || $now - $logged_at >= self::FAILURE_LOG_INTERVAL_SECONDS) {
            PrestaShopLogger::addLog(
                'TwoPayment: ' . $trigger . ' for attempt ' . $attempt_token . ', Two order ' . $attempt['two_order_id'] . ': ' . $reason,
                3,
                null,
                'Cart',
                (int) $attempt['id_cart']
            );
            if ($trigger === self::TRIGGER_SCHEDULED) {
                $fields['pending_logged_at'] = date('Y-m-d H:i:s', $now);
            }
        }
        $this->stamp($attempt_token, $outcome, $fields);
    }

    /**
     * A stamp-only write: the check time and its outcome, never the attempt's status or updated_at.
     *
     * @param string $attempt_token
     * @param string $outcome
     * @param array<string,string|null> $fields
     * @return void
     */
    private function stamp($attempt_token, $outcome, array $fields = array())
    {
        $fields['pending_checked_at'] = date('Y-m-d H:i:s');
        $fields['pending_outcome'] = $outcome;
        $this->module->stampTwoPendingAttempt($attempt_token, $fields);
    }

    /**
     * Two's own "no such order": a 404 carrying its ORDER_NOT_FOUND code, not any 404 a proxy answers.
     *
     * @param mixed $response
     * @return bool
     */
    public static function isTwoOrderNotFound($response)
    {
        return is_array($response)
            && isset($response['http_status']) && (int) $response['http_status'] === 404
            && isset($response['error_code']) && strtoupper((string) $response['error_code']) === 'ORDER_NOT_FOUND';
    }

    /**
     * The local order this attempt's cart carries, when it is linked to this attempt's Two order.
     *
     * @param array $attempt
     * @return int
     */
    private function linkedOrderId(array $attempt)
    {
        $id_order = (int) $this->module->resolveTwoAttemptOrderIdForCancellation($attempt);
        if ($id_order <= 0) {
            return 0;
        }
        $payment_data = $this->module->getTwoOrderPaymentData($id_order);
        $linked = is_array($payment_data) && isset($payment_data['two_order_id']) ? trim((string) $payment_data['two_order_id']) : '';

        return $linked !== '' && $linked === trim((string) $attempt['two_order_id']) ? $id_order : 0;
    }

    /**
     * Re-read the attempt, and abort when it was cancelled meanwhile.
     *
     * @param string $attempt_token
     * @param array $attempt updated in place with the latest row
     * @return bool
     */
    private function rereadAbortsAsCancelled($attempt_token, array &$attempt)
    {
        $latest_attempt = $this->module->getTwoCheckoutAttempt($attempt_token);
        if (!is_array($latest_attempt)) {
            return false;
        }
        $attempt = $latest_attempt;

        return $this->abortIfCancelled($attempt_token, $attempt);
    }

    /**
     * Stop when the attempt is already cancelled.
     *
     * @param string $attempt_token
     * @param array $attempt
     * @return bool True when the flow was aborted
     */
    public function abortIfCancelled($attempt_token, $attempt)
    {
        $attempt_status = isset($attempt['status']) ? (string) $attempt['status'] : '';
        if (!$this->module->shouldBlockTwoAttemptConfirmationByStatus($attempt_status)) {
            return false;
        }

        // Cancel at Two first, then mirror it locally. Only a cancellation Two confirmed (the cancel succeeded, or
        // Two's order reads CANCELLED) is recorded on the order, so the status hook sends no second cancel. Otherwise
        // the order keeps Two's last known state and the status hook sends the cancel itself.
        // An attempt cancelled because Two cancelled it, or has no such order, sends Two nothing (TWO-26300).
        $recorded = isset($attempt['two_order_state']) ? strtoupper(trim((string) $attempt['two_order_state'])) : '';
        $cancelled_at_two = $recorded === 'CANCELLED' || $recorded === 'NOT_FOUND';
        $two_order_id = isset($attempt['two_order_id']) ? trim((string) $attempt['two_order_id']) : '';
        if ($two_order_id !== '' && !$cancelled_at_two) {
            $cancelled_at_two = $this->module->cancelTwoOrderBestEffort($two_order_id, 'confirmation_after_cancelled_attempt');
            if (!$cancelled_at_two) {
                $two_order = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
                $cancelled_at_two = $this->module->isTwoOrderCancelledResponse($two_order);
            }
        }

        $resolved_order_id = $this->linkedOrderId($attempt);
        if ($resolved_order_id > 0) {
            $this->module->updateTwoCheckoutAttemptStatus($attempt_token, 'CANCELLED', array(
                'id_order' => $resolved_order_id,
            ));
            $this->module->syncLocalOrderStatusFromTwoState($resolved_order_id, 'CANCELLED', $cancelled_at_two);
        }

        return true;
    }

    /**
     * The merchant-only note on an order this check placed.
     *
     * @param string $trigger
     * @param bool $confirmed_here
     * @return string
     */
    private function placedNote($trigger, $confirmed_here)
    {
        $brand = $this->module->getTwoBrandConfig('product_name');
        if ($trigger === self::TRIGGER_ADMIN) {
            $employee = Context::getContext()->employee;
            $who = isset($employee->firstname) ? trim($employee->firstname . ' ' . $employee->lastname) : '';

            return $confirmed_here
                ? sprintf($this->module->l('The buyer verified this order with %1$s but did not return to the shop. Confirmed with %1$s by %2$s.'), $brand, $who)
                : sprintf($this->module->l('The buyer verified this order with %1$s but did not return to the shop. Placed by %2$s; %1$s had already confirmed it.'), $brand, $who);
        }

        return $confirmed_here
            ? sprintf($this->module->l('The buyer verified this order with %1$s but did not return to the shop. Confirmed with %1$s by the scheduled check.'), $brand)
            : sprintf($this->module->l('The buyer verified this order with %1$s but did not return to the shop. Placed by the scheduled check; %1$s had already confirmed it.'), $brand);
    }

    /**
     * What a check found, for the merchant: the admin button's message and the Diagnostics row.
     *
     * @param string $outcome an OUTCOME_* value, or OUTCOME_UNCHANGED . ':' . the Two state
     * @return string
     */
    public function describeOutcome($outcome)
    {
        $brand = $this->module->getTwoBrandConfig('product_name');
        $outcome = (string) $outcome;
        if (strpos($outcome, self::OUTCOME_UNCHANGED . ':') === 0) {
            return sprintf(
                $this->module->l('%1$s reports state %2$s; nothing was changed. Check the order with %1$s.'),
                $brand,
                substr($outcome, strlen(self::OUTCOME_UNCHANGED) + 1)
            );
        }
        switch ($outcome) {
            case self::OUTCOME_PLACED:
                return sprintf($this->module->l('The buyer had verified the order with %s. It is now placed.'), $brand);
            case self::OUTCOME_ALREADY_PLACED:
            case self::OUTCOME_NOT_WAITING:
                return $this->module->l('This payment is no longer waiting: it was placed or cancelled meanwhile.');
            case self::OUTCOME_WAITING:
                return sprintf($this->module->l('Checked with %s: the buyer has not finished verification.'), $brand);
            case self::OUTCOME_CANCELLED:
                return sprintf($this->module->l('%s reports this order as cancelled. It was marked cancelled here, and no order was placed.'), $brand);
            case self::OUTCOME_DECLINED:
                return sprintf($this->module->l('%s declined this order. It was marked cancelled here, and no order was placed.'), $brand);
            case self::OUTCOME_NOT_FOUND:
                return sprintf($this->module->l('%s has no such order. It was marked cancelled here, and no order was placed.'), $brand);
            case self::OUTCOME_LOOKUP_FAILED:
                return sprintf($this->module->l('Could not read the order from %s. Nothing was changed; see the module log.'), $brand);
            case self::OUTCOME_CART_CHANGED:
                return sprintf($this->module->l('The cart changed after the buyer verified it, so no order was placed and nothing was sent to %1$s. Check with the buyer, then cancel the order with %1$s.'), $brand);
            case self::OUTCOME_PLACE_FAILED:
                return $this->module->l('The order could not be created here. Nothing else was changed; see the module log. The next check tries again.');
            case self::OUTCOME_DUPLICATE:
                return sprintf($this->module->l('Another checkout already placed an order for this cart, so this payment was not placed and was cancelled with %s.'), $brand);
            case self::OUTCOME_DUPLICATE_PENDING:
                return sprintf($this->module->l('Another checkout already placed an order for this cart, so this payment was not placed. Cancelling it with %s failed; the next check tries again.'), $brand);
            case self::OUTCOME_CONFIRM_FAILED:
                return sprintf($this->module->l('Confirming the order with %s failed, so nothing was placed. The next check tries again; see the module log.'), $brand);
            case self::OUTCOME_SINGLE_SHOP:
                return $this->module->l('Switch to the order\'s shop to check it.');
            case self::OUTCOME_OTHER_SHOP:
                return $this->module->l('This payment belongs to another shop. Switch to that shop to check it.');
            case self::OUTCOME_BUSY:
                return $this->module->l('Another check of this payment is running. Try again in a moment.');
            default:
                return $this->module->l('The payment could not be checked; see the module log.');
        }
    }

    /**
     * The admin check of a legacy order, placed before checkout attempts existed and still in the
     * awaiting-verification status. It shares the per-order lock name space with nothing else: no
     * other path settles a legacy order now.
     *
     * @param int $id_order
     * @return string an OUTCOME_* value, or OUTCOME_UNCHANGED . ':' . the Two state
     */
    public function resolveLegacyOrder($id_order)
    {
        $id_order = (int) $id_order;
        $lock = 'two_legacy_order_' . $id_order;
        if (!$this->module->acquireTwoAttemptLock($lock, self::LOCK_WAIT_SECONDS)) {
            return self::OUTCOME_BUSY;
        }
        try {
            if (!$this->module->isTwoLegacyOrderAwaitingVerification($id_order)) {
                return self::OUTCOME_NOT_WAITING;
            }
            $payment_data = $this->module->getTwoOrderPaymentData($id_order);
            $two_order_id = (string) $payment_data['two_order_id'];
            $response = $this->module->setTwoPaymentRequest('/v1/order/' . $two_order_id, [], 'GET');
            if ($this->module->getTwoErrorMessage($response)) {
                $this->logTwoOrderLookupFailure('order ' . $id_order . ', Two order ' . $two_order_id, $response, 0);

                return self::OUTCOME_LOOKUP_FAILED;
            }
            $two_state = isset($response['state']) ? strtoupper(trim((string) $response['state'])) : '';
            $two_status = isset($response['status']) ? (string) $response['status'] : '';
            $action = self::classifyTwoState($two_state, $two_status);
            $brand = $this->module->getTwoBrandConfig('product_name');
            if ($action === self::ACTION_CANCEL || $action === self::ACTION_DECLINED) {
                // Recorded as cancelled at Two first, so the status hook sends no cancel back.
                $this->module->syncLocalOrderStatusFromTwoState($id_order, 'CANCELLED');
                $this->module->addTwoPendingRecoveryNote($id_order, sprintf($this->module->l('%s reports this order as cancelled. Cancelled here to match.'), $brand));

                return $action === self::ACTION_CANCEL ? self::OUTCOME_CANCELLED : self::OUTCOME_DECLINED;
            }
            if ($action === self::ACTION_WAIT) {
                return self::OUTCOME_WAITING;
            }
            if ($action === self::ACTION_UNKNOWN) {
                PrestaShopLogger::addLog('TwoPayment: Two order ' . $two_order_id . ' for order ' . $id_order . ' is in state ' . $two_state . '; nothing was changed (admin_check)', 2);

                return self::OUTCOME_UNCHANGED . ':' . $two_state;
            }

            $final_state = $two_state;
            $final_status = $two_status;
            $confirmed_here = false;
            if ($action === self::ACTION_CONFIRM) {
                $confirm_result = $this->module->confirmTwoOrder($two_order_id, self::TRIGGER_ADMIN, null, new Order($id_order));
                if (!$confirm_result['success']) {
                    // Nothing moves on an order Two has not confirmed.
                    return self::OUTCOME_CONFIRM_FAILED;
                }
                $confirmed_here = true;
                $final_state = $confirm_result['state'];
                $final_status = $confirm_result['status'] ?: $final_status;
            }
            $resolved_terms = $this->module->resolveTwoPaymentTermsFromOrderResponse(
                $response,
                (string) $payment_data['two_day_on_invoice'],
                (string) $payment_data['two_payment_term_type']
            );
            $this->module->setTwoOrderPaymentData($id_order, array(
                'two_order_id' => $two_order_id,
                'two_order_reference' => isset($response['merchant_reference']) ? $response['merchant_reference'] : $payment_data['two_order_reference'],
                'two_order_state' => $final_state,
                'two_order_status' => $final_status,
                'two_day_on_invoice' => $resolved_terms['two_day_on_invoice'],
                'two_payment_term_type' => $resolved_terms['two_payment_term_type'],
                'two_invoice_url' => isset($response['invoice_url']) ? $response['invoice_url'] : $payment_data['two_invoice_url'],
            ));
            $this->module->changeOrderStatus($id_order, $this->module->getTwoVerifiedStatusIdForConfirmation());
            $this->module->addTwoPendingRecoveryNote($id_order, $this->placedNote(self::TRIGGER_ADMIN, $confirmed_here));

            return self::OUTCOME_PLACED;
        } finally {
            $this->module->releaseTwoAttemptLock($lock);
        }
    }

    /**
     * Best effort sync of merchant_order_id in Two to the real PrestaShop order ID.
     * Never blocks confirmation if the provider update fails.
     *
     * @param Order $order
     * @param array $payment_data
     * @param array|null $two_order the order as Two's GET returned it earlier in this check, for the
     *   order postprocessing hook's order_lines (TWO-26282); null when this check did not read it
     * @return bool
     */
    private function syncTwoMerchantOrderId($order, $payment_data, $two_order = null)
    {
        if (!Validate::isLoadedObject($order) || !is_array($payment_data) || empty($payment_data['two_order_id'])) {
            return false;
        }

        try {
            // Also records the hash that lets later unchanged saves skip their PUT (TWO-26085).
            $update_payload = null;
            $update_response = $this->module->putTwoOrderUpdate($order, $payment_data, $update_payload, 'merchant_order_id', $two_order);
            if ($update_response === null) {
                // A repeated callback: Two already holds exactly this order, merchant_order_id included.
                return true;
            }

            $http_status = isset($update_response['http_status']) ? (int) $update_response['http_status'] : 0;
            if ($http_status === Twopayment::HTTP_STATUS_OK) {
                PrestaShopLogger::addLog(
                    'TwoPayment: Synced merchant_order_id=' . $order->id . ' to Two order ' . $payment_data['two_order_id'],
                    1,
                    null,
                    'Order',
                    $order->id
                );

                return true;
            }

            $sync_error = $this->module->getTwoApiErrorDetail($update_response);
            PrestaShopLogger::addLog(
                'TwoPayment: Failed to sync merchant_order_id for order ' . $order->id .
                ', Two order ' . $payment_data['two_order_id'] .
                ', ' . $sync_error,
                2,
                null,
                'Order',
                $order->id
            );
            $this->module->recordTwoOrderSync((int) $order->id, $sync_error);
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                'TwoPayment: Exception syncing merchant_order_id for order ' . $order->id .
                ' - ' . $e->getMessage(),
                2,
                null,
                'Order',
                $order->id
            );
            $this->module->recordTwoOrderSync((int) $order->id, $e->getMessage());
        }

        return false;
    }

    /**
     * Two's reason for a failed order lookup, for the merchant (TWO-26611). A
     * refusal (400 and above) is an error line; an answer below 400 whose body
     * still carries an error is a warning. No answer at all is already logged
     * as a connection error.
     *
     * @param string $reference
     * @param mixed $response
     * @param int $id_cart
     * @return void
     */
    public function logTwoOrderLookupFailure($reference, $response, $id_cart)
    {
        if ($this->module->logTwoApiRefusal('the order lookup', $reference, $response, $id_cart)) {
            return;
        }
        $status = is_array($response) && isset($response['http_status']) ? (int) $response['http_status'] : 0;
        if ($status > 0) {
            PrestaShopLogger::addLog(
                'TwoPayment: Two answered the order lookup with an error for ' . $reference . ' - ' . $this->module->getTwoApiErrorDetail($response),
                2,
                null,
                (int) $id_cart > 0 ? 'Cart' : null,
                (int) $id_cart > 0 ? (int) $id_cart : null
            );
        }
    }

    /**
     * Rebuild snapshot hash using current cart and expected callback URL format.
     *
     * @param string $attempt_token
     * @param array $attempt
     * @param Cart $cart
     * @param bool $include_secure_key
     * @param bool $sync_surcharge_cart_line default FALSE: computing a
     *        comparison hash must not mutate the cart as a side effect. The
     *        two self-healing call sites (initial check, final pre-order
     *        re-check) opt in explicitly - with sync enabled the underlying
     *        payload build also ENFORCES cart-vs-payload fee parity and
     *        throws on divergence.
     * @return string
     */
    private function buildAttemptSnapshotHash($attempt_token, $attempt, $cart, $include_secure_key, $sync_surcharge_cart_line = false)
    {
        $confirm_params = array('attempt_token' => $attempt_token);
        $cancel_params = array('attempt_token' => $attempt_token);
        if ($include_secure_key && !Tools::isEmpty($attempt['customer_secure_key'])) {
            $confirm_params['key'] = $attempt['customer_secure_key'];
            $cancel_params['key'] = $attempt['customer_secure_key'];
        }

        $link = Context::getContext()->link;
        $comparison_urls = array(
            'merchant_confirmation_url' => $link->getModuleLink($this->module->name, 'confirmation', $confirm_params, true),
            'merchant_cancel_order_url' => $link->getModuleLink($this->module->name, 'cancel', $cancel_params, true),
            'merchant_edit_order_url' => '',
            'merchant_order_verification_failed_url' => '',
            'merchant_invoice_url' => '',
            'merchant_shipping_document_url' => '',
        );
        $comparison_payload = $this->module->getTwoNewOrderData(
            $attempt['merchant_order_id'],
            $cart,
            $comparison_urls,
            (bool) $sync_surcharge_cart_line,
            'snapshot_hash'
        );

        return $this->module->calculateTwoCheckoutSnapshotHash($cart, $comparison_payload);
    }

    /**
     * @param string $outcome
     * @param string $message the buyer's message, when the buyer is the caller
     * @param int $id_order
     * @param string $two_state
     * @return array{outcome:string,message:string,id_order:int,two_state:string}
     */
    private function result($outcome, $message = '', $id_order = 0, $two_state = '')
    {
        return array(
            'outcome' => (string) $outcome,
            'message' => (string) $message,
            'id_order' => (int) $id_order,
            'two_state' => (string) $two_state,
        );
    }
}
