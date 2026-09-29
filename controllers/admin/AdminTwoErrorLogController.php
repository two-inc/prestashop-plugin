<?php
/**
 * @author Plugin Developer from Two <jgang@two.inc> <support@two.inc>
 * @copyright Since 2021 Two Team
 * @license Two Commercial License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Read-only admin view of the module's own log entries (TWO-25386).
 *
 * PrestaShopLogger::addLog() writes into core's `log` table with no
 * module-specific column, so every line this module has ever logged carries
 * the same 'TwoPayment: ' prefix (see grep across this module) - that prefix
 * is what this controller filters on, rather than adding a new column to a
 * core table.
 *
 * Registered through an invisible tab (id_parent -1, same as
 * AdminTwopaymentInvoiceController) so PrestaShop enforces employee
 * authentication, CSRF token and profile permissions - never reachable
 * without them.
 */
class AdminTwoErrorLogController extends ModuleAdminController
{
    const LOG_MESSAGE_PREFIX = 'TwoPayment:';
    const MAX_ROWS = 100;
    const MAX_SNAPSHOT_ROWS = 50;
    const DOWNLOAD_PARAM = 'downloadSnapshot';

    public function postProcess()
    {
        $id_log = (int) Tools::getValue(self::DOWNLOAD_PARAM);
        if ($id_log > 0 && $this->viewAccess()) {
            $this->sendTwoSnapshot($id_log);
        }

        return parent::postProcess();
    }

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        $rows = $this->getTwoLogRows();

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-file-text"></i> '
            . sprintf($this->l('%s payment gateway - last log records'), $this->module->getTwoBrandConfig('product_name')) . '</div>';

        if (empty($rows)) {
            $html .= '<p class="alert alert-info">' . $this->l('No log records found for this module.') . '</p>';
        } else {
            $html .= '<table class="table"><thead><tr>'
                . '<th>' . $this->l('Date') . '</th>'
                . '<th>' . $this->l('Severity') . '</th>'
                . '<th>' . $this->l('Message') . '</th>'
                . '</tr></thead><tbody>';
            foreach ($rows as $row) {
                $html .= '<tr>'
                    . '<td>' . htmlspecialchars((string) $row['date_add'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '<td>' . (int) $row['severity'] . '</td>'
                    . '<td>' . htmlspecialchars((string) $row['message'], ENT_QUOTES, 'UTF-8') . '</td>'
                    . '</tr>';
            }
            $html .= '</tbody></table>';
        }

        $html .= '</div>' . $this->renderTwoSnapshotPanel();

        // No custom .tpl: core's default content.tpl renders {$content}, so
        // setting it directly avoids registering a new template path just
        // for this one read-only panel.
        parent::initContent();
        $this->content = $html;
        $this->context->smarty->assign('content', $this->content);
    }

    /**
     * Discrepancy snapshots (TWO-26064): JSON, so listed apart from the prefixed lines above.
     *
     * @return string
     */
    protected function renderTwoSnapshotPanel()
    {
        $html = '<div class="panel"><div class="panel-heading"><i class="icon-download"></i> '
            . $this->l('Discrepancy snapshots') . '</div>';
        $rows = $this->getTwoSnapshotRows();
        if (empty($rows)) {
            return $html . '<p class="alert alert-info">' . $this->l('No discrepancy snapshots recorded.') . '</p></div>';
        }

        $base = $this->context->link->getAdminLink('AdminTwoErrorLog', true);
        $html .= '<table class="table"><thead><tr>'
            . '<th>' . $this->l('Date') . '</th>'
            . '<th>' . $this->l('Severity') . '</th>'
            . '<th>' . $this->l('Cart') . '</th>'
            . '<th></th>'
            . '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $href = $base . '&' . self::DOWNLOAD_PARAM . '=' . (int) $row['id_log'];
            $html .= '<tr>'
                . '<td>' . htmlspecialchars((string) $row['date_add'], ENT_QUOTES, 'UTF-8') . '</td>'
                . '<td>' . (int) $row['severity'] . '</td>'
                . '<td>' . (int) $row['object_id'] . '</td>'
                . '<td><a class="btn btn-default" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">'
                . '<i class="icon-download"></i> ' . $this->l('Download JSON') . '</a></td>'
                . '</tr>';
        }

        return $html . '</tbody></table></div>';
    }

    /**
     * @return array<int,array{id_log:int,date_add:string,severity:int,object_id:int}>
     */
    protected function getTwoSnapshotRows()
    {
        $result = Db::getInstance()->executeS(
            'SELECT id_log, date_add, severity, object_id FROM `' . _DB_PREFIX_ . 'log`'
            . " WHERE object_type = '" . pSQL(TwoDiscrepancySnapshot::LOG_OBJECT_TYPE) . "'"
            . ' ORDER BY id_log DESC'
            . ' LIMIT ' . (int) self::MAX_SNAPSHOT_ROWS
        );

        return is_array($result) ? $result : array();
    }

    /**
     * Only rows this module wrote as snapshots; any other log id is a 404.
     *
     * @param int $id_log
     * @return void
     */
    protected function sendTwoSnapshot($id_log)
    {
        $row = Db::getInstance()->getRow(
            'SELECT message, object_id FROM `' . _DB_PREFIX_ . 'log`'
            . ' WHERE id_log = ' . (int) $id_log
            . " AND object_type = '" . pSQL(TwoDiscrepancySnapshot::LOG_OBJECT_TYPE) . "'"
        );
        $json = is_array($row) ? TwoDiscrepancySnapshot::decodeStored($row['message']) : null;
        if ($json === null) {
            header('HTTP/1.1 404 Not Found');
            exit;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="two-discrepancy-cart-' . (int) $row['object_id'] . '-' . (int) $id_log . '.json"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo $json;
        exit;
    }

    /**
     * @return array<int,array{date_add:string,severity:int,message:string}>
     */
    protected function getTwoLogRows()
    {
        $sql = 'SELECT date_add, severity, message FROM `' . _DB_PREFIX_ . 'log`'
            . " WHERE message LIKE '" . pSQL(self::LOG_MESSAGE_PREFIX) . "%'"
            . ' ORDER BY date_add DESC'
            . ' LIMIT ' . (int) self::MAX_ROWS;

        $result = Db::getInstance()->executeS($sql);

        return is_array($result) ? $result : array();
    }
}
