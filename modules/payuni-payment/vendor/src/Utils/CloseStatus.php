<?php
/**
 * CloseStatus class file.
 *
 * @package payuni
 */

namespace WPBrewer\Payuni\Payment\Utils;

/**
 * Class CloseStatus (請款狀態)
 */
class CloseStatus {

	const CAPTURE_APPLING    = '1'; // 請款申請中.
	const CAPTURE_OK         = '2'; // 請款成功.
	const CAPTURE_CANCELLED  = '3'; // 請款取消.
	const CAPTURE_PROCESSING = '7'; // 請款處理中.
	const CAPTURE_UNAPPLY    = '9'; // 未申請.
}
