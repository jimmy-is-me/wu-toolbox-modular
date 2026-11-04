<?php
/** WU 溫層限制物流；保留 WC 運送溫層限制 2.1.0 的資料與全部功能。 */
defined('ABSPATH') || exit;
if (!class_exists('WooCommerce')) return;
require_once __DIR__.'/temperature.php';
WUTM_Shipping_Temperature::init();
