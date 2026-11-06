<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * The scheduled check, called by the shop's own crontab every 15 minutes - see README. Each accepted
 * call checks payments still waiting for the buyer (TWO-26300) and refreshes the merchant record
 * once it is a day old.
 *
 * A shop in maintenance mode needs the calling IP in PS_MAINTENANCE_IP, or FrontController::init()
 * serves the maintenance page before this runs.
 */
class TwopaymentCronModuleFrontController extends ModuleFrontController
{
    public $auth = false;
    public $ajax = true;

    public function postProcess()
    {
        if (!$this->module->isTwoCronTokenValid($this->module->readTwoCronTokenFromRequest())) {
            $this->module->logTwoCronRejection();
            // 404 and nothing else: a rejection must not say whether the token
            // was wrong, missing, or for another shop.
            $this->respond(404, array('success' => false, 'error' => 'not_found'));

            return;
        }

        // First, so a slow merchant fetch never delays a payment the buyer verified.
        $pending = $this->module->runTwoScheduledPendingCheck();
        $status = $this->module->runTwoScheduledRefresh();
        if ($status === Twopayment::CRON_STATUS_THROTTLED) {
            // 429, not 200: a correct 15-minute cron never trips the floor, so this is worth surfacing.
            $this->respond(429, array('success' => false, 'status' => $status, 'pending' => $pending));

            return;
        }

        $failed = $status === Twopayment::CRON_STATUS_FAILED;
        // A quiet 15-minute call (fresh record, nothing waiting) leaves no line, or the log fills up.
        if ($status !== Twopayment::CRON_STATUS_FRESH || !empty($pending['checked']) || !empty($pending['error'])) {
            PrestaShopLogger::addLog(
                'TwoPayment: Scheduled refresh ran - merchant record ' . $status
                    . ($pending === null ? '' : ', pending check ' . json_encode($pending)),
                $failed ? 2 : 1
            );
        }

        // A shop with no key yet is not an error; a fetch that failed is, and a crontab only sees the exit code.
        $this->respond($failed ? 503 : 200, array('success' => !$failed, 'status' => $status, 'pending' => $pending));
    }

    /**
     * Protected so a spec can capture the response instead of dying on it.
     *
     * @param int $status
     * @param array<string,mixed> $payload
     * @return void
     */
    protected function respond($status, array $payload)
    {
        http_response_code((int) $status);
        header('Content-Type: application/json');
        die(json_encode($payload));
    }
}
