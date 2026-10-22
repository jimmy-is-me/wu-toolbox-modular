<?php
/** Full ECPay source runtime; common Toolbox settings presentation. */
defined('ABSPATH') || exit;
// wutm-ui-root is provided by wutm_invoice_register's shared page callback.
require_once WUTM_PATH . 'core/invoice-modules.php';
wutm_invoice_register('ecpay');
