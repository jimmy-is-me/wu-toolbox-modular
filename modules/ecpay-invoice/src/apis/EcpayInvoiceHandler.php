<?php

namespace WUECPAYINVOICE\APIs;

use WUECPAYINVOICE\APIs\EcpayInvoiceFiels;

defined( 'ABSPATH' ) || exit;

class EcpayInvoiceHandler {


	/**
	 * 開立發票
	 */
	public function generate_invoice( $order_id ) {

		$order       = \wc_get_order( $order_id );
		$order_total = $order->get_total();
		$order_info  = $order->get_address();

		if ( '0' === $order_total ) {
			return;
		}

		// 訂購人資料.
		$customerName = $order_info['last_name'] . $order_info['first_name'];
		$orderEmail   = $order_info['email'];
		$orderPhone   = preg_replace( '/[^0-9]/', '', str_replace( '+886', '0', $order_info['phone'] ) ); // 清成純數字，避免海外號碼的 + 破壞 CheckMacValue
		$orderAddress = $order_info['country'] . $order_info['state'] . $order_info['city'] . $order_info['address_1'] . $order_info['address_2'];

		$customerIdentifier = EcpayInvoiceFields::get_meta( $order_id, 'tax_id' ); // 統一編號
		$invoiceType        = EcpayInvoiceFields::get_meta( $order_id, 'type' );

		$donation = ( $invoiceType == 'donate' ) ? 1 : 0; // 捐贈
		$donation = ( empty( $customerIdentifier ) ) ? $donation : 0; // 如果有寫統一發票號碼則無法捐贈
		$print    = 0;

		$loveCode    = EcpayInvoiceFields::get_meta( $order_id, 'donate' ); // 捐贈碼
		$carruerType = EcpayInvoiceFields::get_meta( $order_id, 'individual' ); // 載具
		$carruerType = ( $carruerType == 0 ) ? '' : $carruerType;

		// 有打統一編號 強制列印發票
		if ( ! empty( $customerIdentifier ) ) {

			$print = 0;

			// // 有統一編號 則取得公司名稱
			$sCompany_Name = EcpayInvoiceFields::get_meta( $order_id, 'company_name' ); // 公司名稱
			$customerName  = ( ! empty( $sCompany_Name ) ) ? $sCompany_Name : $customerName;
		}

		// 無載具 強制列印
		if ( empty( $carruerType ) ) {
			$print = 1;
		}

		// 有捐贈項目 不允許列印
		if ( $donation == 1 ) {
			$print = 0;
		}

		$carruerNum = '';
		if ( 2 === $carruerType || 3 === $carruerType ) {
			$carruerNum = EcpayInvoiceFields::get_meta( $order_id, 'carrier' ); // 載具編號
		}

		// 付款成功次數 第一次付款或沒有此欄位則設定為空值
		$totalSuccessTimes = '';
		if ( isset( $order_info['_total_success_times'][0] ) ) {
			$totalSuccessTimes = ( $orderInfo['_total_success_times'][0] == '' ) ? '' : $orderInfo['_total_success_times'][0];
		}

		$invoiceRemark = '';

		// 無載具 強制列印
		if ( empty( $carruerType ) ) {

			$print = 1;
		}

		// 有捐贈項目 不允許列印
		if ( $donation == 1 ) {

			$print = 0;
		}

		try {

			// 1.載入SDK程式
			$ecpay_invoice = new \WUTMInvoiceSDK_EcpayInvoice();

			// 2.寫入基本介接參數
			$ecpay_invoice->Invoice_Method = 'INVOICE';
			$ecpay_invoice->Invoice_Url    = $this->get_api_key()['request_url_issue'];
			$ecpay_invoice->MerchantID     = $this->get_api_key()['merchant_id'];
			$ecpay_invoice->HashKey        = $this->get_api_key()['hashkey'];
			$ecpay_invoice->HashIV         = $this->get_api_key()['hashiv'];

			// 組合發票明細（明細加總必須等於 SalesAmount）
			$invoice_items                = $this->build_invoice_items( $order );
			$ecpay_invoice->Send['Items'] = $invoice_items['items'];

			// 自訂編號必須唯一：時間(秒) + 訂單編號 + 亂數，避免同一秒批次開立時撞號。
			$relateNumber = date( 'YmdHis' ) . $order_id . wp_rand( 10, 99 );

			$ecpay_invoice->Send['RelateNumber']       = get_option( 'wc_woomp_ecpay_invoice_order_prefix' ) . $relateNumber;
			$ecpay_invoice->Send['CustomerID']         = '';
			$ecpay_invoice->Send['CustomerIdentifier'] = $customerIdentifier;
			$ecpay_invoice->Send['CustomerName']       = $customerName;
			$ecpay_invoice->Send['CustomerAddr']       = $orderAddress;
			$ecpay_invoice->Send['CustomerPhone']      = $orderPhone;
			$ecpay_invoice->Send['CustomerEmail']      = $orderEmail;
			$ecpay_invoice->Send['ClearanceMark']      = '';
			$ecpay_invoice->Send['Print']              = $print;
			$ecpay_invoice->Send['Donation']           = $donation;
			$ecpay_invoice->Send['LoveCode']           = $loveCode;
			$ecpay_invoice->Send['CarruerType']        = $carruerType;
			$ecpay_invoice->Send['CarruerNum']         = $carruerNum;
			$ecpay_invoice->Send['TaxType']            = 1;
			$ecpay_invoice->Send['SalesAmount']        = round( (float) $invoice_items['sales_amount'] );
			$ecpay_invoice->Send['InvoiceRemark']      = $invoiceRemark;
			$ecpay_invoice->Send['InvType']            = '07';
			$ecpay_invoice->Send['vat']                = '';

			// 紀錄 log
			$log = new \WC_Logger();
			ob_start();
			var_dump($ecpay_invoice->Send);
			$msg = ob_get_clean();
			$log->info(
				'送給綠界前的資料: ' . $msg,
				[
					'source' => 'wu-ecpay-invoice',
				]
				);

			// 4.送出

			$result = $ecpay_invoice->Check_Out();

			if ( is_string( $result ) ) {
				$return_info = [];
				parse_str( $result, $return_info );
			} else {
				$return_info = $result;
			}

			// 紀錄 log
			$log = new \WC_Logger();
			ob_start();
			var_dump( $return_info );
			$msg = ob_get_clean();
			$log->info(
				'綠界回傳的資料: ' . $msg,
				[
					'source' => 'wu-ecpay-invoice',
				]
				);

			// 於備註區寫入發票資訊
			$invoice_date    = $return_info['InvoiceDate'] ?? '';
			$invoice_number  = $return_info['InvoiceNumber'] ?? '';
			$invoice_message = match (\is_string( $return_info )) {
				true => $return_info,
				default => $return_info['RtnMsg'] ?? array_keys( $return_info )[0],
			};

			$invocie_result = ( $invoice_date ) ? __( '<b>Invoice issue result</b>', 'wu' ) : __( '<b>Invoice issue faild</b>', 'wu' );

			$invocie_time   = ( $invoice_date ) ? __( '<br>Generate Time: ', 'wu' ) . $invoice_date : '';
			$invocie_number = ( $invoice_date ) ? __( '<br>Invoice Number: ', 'wu' ) . $invoice_number : '';
			$invoice_msg    = __( '<br>Invoice Message: ', 'wu' ) . $invoice_message;

			$order->add_order_note( $invocie_result . $invocie_time . $invocie_number . $invoice_msg );

			// 寫入發票回傳資訊
			if ( isset( $return_info['RtnCode'] ) && $return_info['RtnCode'] == 1 ) {

				// 異動已經開立發票的狀態 1.已經開立 0.尚未開立
				$order->update_meta_data( '_ecpay_invoice_status', 1 );
				// 寫入發票號碼
				$order->update_meta_data( '_ecpay_invoice_number', $return_info['InvoiceNumber'] );
				$order->save();
			}

			return $invoice_message;
			// return __('Generate Ecpay invoice issue finish!', 'wu');
		} catch ( \Exception $e ) {
			// 例外錯誤處理.
			return new \WP_Error( 'error', $e->getMessage() );
		}
	}

	/**
	 * 作廢發票
	 */
	public function invalid_invoice( $order_id ) {

		global $woocommerce;

		$order        = \wc_get_order( $order_id );
		if ( ! $order ) {
			return __( 'Invalid order', 'wu' );
		}
		$orderStatus  = $order->get_status();
		$orderInfo    = [];
		$relateNumber = date( 'YmdHis' ) . $order_id . wp_rand( 10, 99 );

		// 付款成功最後的次 第一次付款或沒有此欄位則設定為空值
		// $totalSuccessTimes = ( isset( $orderInfo['_total_success_times'][0] ) && $orderInfo['_total_success_times'][0] == '' ) ? '' : $orderInfo['_total_success_times'][0];
		$totalSuccessTimes = '';

		// 已經開立發票才允許(找出最後一次)
		$_ecpay_invoice_status = '_ecpay_invoice_status' . $totalSuccessTimes;

		$invoice_status_value = $order->get_meta( $_ecpay_invoice_status );
		if ( $invoice_status_value == 1 ) {

			// 發票號碼
			$_ecpay_invoice_number = '_ecpay_invoice_number' . $totalSuccessTimes;
			$invoice_number        = $order->get_meta( $_ecpay_invoice_number );

			// 呼叫SDK 作廢發票
			try {

				$msg = '';

				$ecpayInvoice = new ECPay_Woo_EcpayInvoice();

				// 2.介接參數
				$ecpayInvoice->Invoice_Method = 'INVOICE_VOID';
				$ecpayInvoice->Invoice_Url    = $this->get_api_key()['request_url_invalid'];
				$ecpayInvoice->MerchantID     = $this->get_api_key()['merchant_id'];
				$ecpayInvoice->HashKey        = $this->get_api_key()['hashkey'];
				$ecpayInvoice->HashIV         = $this->get_api_key()['hashiv'];

				// 3.寫入發票相關資訊
				$ecpayInvoice->Send['InvoiceNumber'] = $invoice_number;
				$ecpayInvoice->Send['Reason']        = '發票作廢';

				// 4.送出
				$result = $ecpayInvoice->Check_Out();
			} catch ( \Exception $e ) {

				// 例外錯誤處理。
				$msg = $e->getMessage();
			}

			if ( \is_string( $result ) ) {
				$return_info = [];
				parse_str( $result, $return_info );
			} else {
				$return_info = $result;
			}

			// 於備註區寫入發票資訊
			$invoice_number  = $return_info['InvoiceNumber'];
			$invoice_message = $return_info['RtnMsg'];
			$invoiceNote     = __( '<b>Ecpay invalid invoice result</b>', 'wu' ) . __( '<br>Invoice Number: ', 'wu' ) . $invoice_number . __( '<br>Invoice Message: ', 'wu' ) . $invoice_message;
			$order->add_order_note( $invoiceNote );

			if ( isset( $return_info['RtnCode'] ) && in_array( $return_info['RtnCode'], [ '1', '5070453' ] ) ) {

				if ( empty( $totalSuccessTimes ) ) {
					$_ecpay_invoice_stauts = '_ecpay_invoice_status';     // 欄位名稱 記錄狀態
					$_ecpay_invoice_number = '_ecpay_invoice_number';     // 欄位名稱 記錄發票號碼
				} else {
					$_ecpay_invoice_stauts = '_ecpay_invoice_status' . $totalSuccessTimes;  // 欄位 記錄狀態
					$_ecpay_invoice_number = '_ecpay_invoice_number' . $totalSuccessTimes;  // 欄位名稱 記錄發票號碼
				}

				// 異動已經開立發票的狀態 1.已經開立 0.尚未開立
				$order->update_meta_data( $_ecpay_invoice_stauts, 0 );

				// 清除發票號碼
				$order->update_meta_data( $_ecpay_invoice_number, '' );

				$order->save();
			}

			return $invoiceNote;
		} else {
			return __( 'Invalid Ecpay invoice error!', 'wu' );
		}
	}

	/**
	 * 組合發票商品明細與發票總金額
	 *
	 * 綠界要求明細加總等於 SalesAmount，否則回 RtnCode 1200031。
	 * 商品、運費、費用一律列「含稅」金額；折扣行不自行重算稅額，
	 * 而是用「訂單總額 − 已列出的明細」的差額，涵蓋折價券、後台手改金額等情境（issue #137）。
	 *
	 * @param \WC_Order $order 訂單.
	 * @return array{items: array<int, array<string, mixed>>, sales_amount: float}
	 */
	public function build_invoice_items( \WC_Order $order ): array {
		$invoice_items = [];
		$items_total   = 0.0;

		// 商品（折扣前的含稅小計）
		foreach ( $order->get_items() as $item ) {
			$item_amount     = round( (float) $item->get_subtotal() + (float) $item->get_subtotal_tax(), 2 );
			$item_count      = $item->get_quantity();
			$invoice_items[] = [
				'ItemName'    => wp_strip_all_tags( str_replace( '|', '-', $item->get_name() ), true ),
				'ItemCount'   => $item_count,
				'ItemWord'    => '批',
				'ItemPrice'   => round( $item_amount / (float) $item_count, 2 ),
				'ItemTaxType' => 1,
				'ItemAmount'  => $item_amount,
			];
			$items_total    += $item_amount;
		}

		// 運費（含稅）
		$shipping_total = round( round( (float) $order->get_shipping_total() + (float) $order->get_shipping_tax(), wc_get_price_decimals() ), 2 );
		if ( 0.0 !== $shipping_total ) {
			$invoice_items[] = [
				'ItemName'    => '運費',
				'ItemCount'   => 1,
				'ItemWord'    => '式',
				'ItemPrice'   => $shipping_total,
				'ItemTaxType' => 1,
				'ItemAmount'  => $shipping_total,
			];
			$items_total    += $shipping_total;
		}

		// 費用（含稅）
		$fee_amount = 0.0;
		foreach ( $order->get_fees() as $fee ) {
			$fee_amount += (float) $fee->get_total() + (float) $fee->get_total_tax();
		}
		$fee_amount = round( $fee_amount, 2 );
		if ( 0.0 !== $fee_amount ) {
			$invoice_items[] = [
				'ItemName'    => '費用',
				'ItemCount'   => 1,
				'ItemWord'    => '式',
				'ItemPrice'   => $fee_amount,
				'ItemTaxType' => 1,
				'ItemAmount'  => $fee_amount,
			];
			$items_total    += $fee_amount;
		}

		// 折扣：訂單總額低於已列明細時，以差額補一行（折價券、後台手改金額都歸在這裡）
		$order_total = (float) $order->get_total();
		$diff_price  = round( $order_total - $items_total, 2 );
		if ( $diff_price < 0 ) {
			$invoice_items[] = [
				'ItemName'    => '折扣',
				'ItemCount'   => 1,
				'ItemWord'    => '式',
				'ItemPrice'   => $diff_price,
				'ItemTaxType' => 1,
				'ItemAmount'  => $diff_price,
			];
			$items_total    += $diff_price;
		}

		// 差距在 2 元內（四捨五入誤差）就以明細加總為準，確保兩者一致
		if ( abs( $order_total - $items_total ) < 2 ) {
			$order_total = $items_total;
		}

		return [
			'items'        => $invoice_items,
			'sales_amount' => $order_total,
		];
	}

	/**
	 * 取得 API 資料
	 */
	private function get_api_key() {
		$api_data = [];

		if ( wc_string_to_bool( get_option( 'wc_woomp_ecpay_invoice_testmode_enabled' ) ) ) {
			$api_data['request_url_issue']   = 'https://einvoice-stage.ecpay.com.tw/Invoice/Issue';
			$api_data['request_url_invalid'] = 'https://einvoice-stage.ecpay.com.tw/Invoice/IssueInvalid';
			$api_data['merchant_id']         = '2000132';
			$api_data['hashkey']             = 'ejCk326UnaZWKisg';
			$api_data['hashiv']              = 'q9jcZX8Ib9LM8wYk';
		} else {
			$api_data['request_url_issue']   = 'https://einvoice.ecpay.com.tw/Invoice/Issue';
			$api_data['request_url_invalid'] = 'https://einvoice.ecpay.com.tw/Invoice/IssueInvalid';

			if ( get_option( 'RY_WEI_ecpay_MerchantID' ) && get_option( 'RY_WEI_ecpay_HashKey' ) && get_option( 'RY_WEI_ecpay_HashIV' ) ) {
				$api_data['merchant_id'] = get_option( 'RY_WEI_ecpay_MerchantID' );
				$api_data['hashkey']     = get_option( 'RY_WEI_ecpay_HashKey' );
				$api_data['hashiv']      = get_option( 'RY_WEI_ecpay_HashIV' );
			} else {
				$api_data['merchant_id'] = get_option( 'wc_woomp_ecpay_invoice_merchant_id' );
				$api_data['hashkey']     = get_option( 'wc_woomp_ecpay_invoice_hashkey' );
				$api_data['hashiv']      = get_option( 'wc_woomp_ecpay_invoice_hashiv' );
			}
		}

		return $api_data;
	}
}
