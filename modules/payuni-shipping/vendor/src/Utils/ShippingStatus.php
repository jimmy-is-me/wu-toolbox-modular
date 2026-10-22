<?php

namespace WuDigital\Payuni\Shipping\Utils;

/**
 * ShippingStatus class file.
 */
class ShippingStatus {

	const READY_SHIPPING_HD  = '21'; // 待出貨，已產宅配單號，等待商店出貨
	const READY_SHIPPING_C2C = '92'; // 待出貨，處理中，等待商店寄貨，僅超商物流有此貨態。出貨單編號傳送至上游物流服務廠商確認中
	const AT_LOGISTIC_CENTER = '22'; // 物流驗收，物流中心驗收中(僅超商物流有此貨態)
	const DELIVERING         = '31'; // 配送中，安排出貨中.
	const AT_RECEIVER_CVS    = '32'; // 待取貨，取件門市配達.
	const CUSTOMER_PICKUP    = '11'; // 已取貨，買家已取件.

}
