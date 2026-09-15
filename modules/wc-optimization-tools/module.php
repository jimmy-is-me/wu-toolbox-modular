<?php
defined('ABSPATH') || exit;
require_once dirname(__DIR__, 2) . '/core/woocommerce-tools.php';
require_once __DIR__ . '/custom-features.php';
new WU_WooCommerce_Optimizer('commerce');
