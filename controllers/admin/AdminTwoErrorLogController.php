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
 * Read-only admin view of the module's own log entries (TWO-25386 #7, ported
 * from magento-plugin's Block/Adminhtml/System/Config/Button/ErrorCheck.php
 * "Check last 100 error log records" action).
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

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        $rows = $this->getTwoLogRows();

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-file-text"></i> '
            . $this->l('Two payment gateway - last log records') . '</div>';

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

        $html .= '</div>';

        // No custom .tpl: core's default content.tpl renders {$content}, so
        // setting it directly avoids registering a new template path just
        // for this one read-only panel.
        parent::initContent();
        $this->content = $html;
        $this->context->smarty->assign('content', $this->content);
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
