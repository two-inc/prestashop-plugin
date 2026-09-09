<?php

/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

/**
 * Scheduled merchant-record refresh (ABN-519), called by the shop's own crontab - see README.
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

        $status = $this->module->runTwoScheduledRefresh();
        if ($status === Twopayment::CRON_STATUS_THROTTLED) {
            // 429, not 200: a correct daily cron never trips the floor, so this is worth surfacing.
            $this->respond(429, array('success' => false, 'status' => $status));

            return;
        }

        $failed = $status === Twopayment::CRON_STATUS_FAILED;
        PrestaShopLogger::addLog('TwoPayment: Scheduled refresh ran - merchant record ' . $status, $failed ? 2 : 1);

        // A shop with no key yet is not an error; a fetch that failed is, and a crontab only sees the exit code.
        $this->respond($failed ? 503 : 200, array('success' => !$failed, 'status' => $status));
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
