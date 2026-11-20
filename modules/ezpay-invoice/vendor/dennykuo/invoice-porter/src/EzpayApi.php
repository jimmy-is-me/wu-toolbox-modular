<?php

namespace WUTMInvoiceDeps\InvoicePorter;

use \WUTMInvoiceDeps\InvoicePorter\Contracts\ApiRequest;

class EzpayApi implements ApiRequest
{
	private $instance;

	public function __construct($instance)
	{
		$this->instance = $instance;
	}

	public function send(string $apiUri, array $postData, $doCheckCode = true, $invoiceData = null)
	{
		$apiGate = $this->instance->isProduction
			? 'https://inv.ezpay.com.tw/Api/'
			: 'https://cinv.ezpay.com.tw/Api/';
		if (!preg_match('/^[A-Za-z0-9_]+$/D', $apiUri)) {
			throw new \InvalidArgumentException('無效的發票服務路徑');
		}
		$response = wp_safe_remote_post($apiGate . $apiUri, [
			'timeout' => 25,
			'redirection' => 0,
			'limit_response_size' => 1048576,
			'body' => [
				'MerchantID_' => $this->instance->merchantID,
				'PostData_' => $this->encryptPostData($postData),
			],
		]);


		if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
			throw new \Exception('串接回應錯誤');
		}

		$response = json_decode(wp_remote_retrieve_body($response));
		if (!is_object($response) || !isset($response->Status, $response->Message) || !property_exists($response, 'Result')) {
			throw new \RuntimeException('無法讀取發票服務回應');
		}


		$response = (object) [
			'status' => $response->Status,
			'message' => $response->Message,
			'result' => is_array($response->Result) && !count($response->Result)
				? null
				: json_decode($response->Result), // 原回應只為 string，需再次 json_decode
		];

		if ($response->status == 'SUCCESS' && $doCheckCode) {
			$this->validateCheckCode($response->result, $invoiceData);
		};

		return $response;
	}

	/**
	 * 加密所需
	 *
	 * @return string
	 */
	public function encryptPostData(array $postData)
	{
		$postDataStr = http_build_query($postData); // 轉成字串排列

		if (phpversion() > 7) {
			// php 7 以上版本加密
			$postData = trim(bin2hex(openssl_encrypt(
				$this->strAddPadding($postDataStr),
				'AES-256-CBC',
				$this->instance->hashKey,
				OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
				$this->instance->hashIV
			)));
		} else {
			// php 7 之前版本加密
			$postData = trim(bin2hex(mcrypt_encrypt(
				MCRYPT_RIJNDAEL_128,
				$this->instance->hashKey,
				$this->instance->strAddPadding($postDataStr),
				MCRYPT_MODE_CBC,
				$this->instance->hashIV
			)));
		}

		return $postData;
	}

	/**
	 * 加密所需
	 *
	 * @return string
	 */
	private function strAddPadding($string, $blocksize = 32)
	{
		$len = strlen($string);
		$pad = $blocksize - ($len % $blocksize);
		$string .= str_repeat(chr($pad), $pad);

		return $string;
	}

	private function validateCheckCode($result, $invoiceData)
	{
		$responseChcekCode = $result->CheckCode;

		$result = $invoiceData ? $invoiceData : $result; // 若經由查詢發票帶入，使用查詢發票的資料

		$checkCode = [
			'MerchantID' => $this->instance->merchantID,
			'MerchantOrderNo' => $result->MerchantOrderNo,
			'InvoiceTransNo' => $result->InvoiceTransNo,
			'TotalAmt' => $result->TotalAmt,
			'RandomNum' => $result->RandomNum,
		];

		ksort($checkCode);
		$checkStr = http_build_query($checkCode);
		$checkCode = strtoupper(hash(
			'sha256',
			"HashIV={$this->instance->hashIV}&" . $checkStr . "&HashKey={$this->instance->hashKey}"
		));

		if ($checkCode !== $responseChcekCode) {
			throw new \Exception('check code 檢查錯誤');
		}
	}

	// 🙏 by J7
	public function decryptPostData($encryptedData)
	{
		$encryptedData = hex2bin($encryptedData); // 將十六進位字串轉換回二進位
		if (phpversion() > 7) {
			// 使用 OpenSSL 解密
			$decryptedData = openssl_decrypt(
				$encryptedData,
				'AES-256-CBC',
				$this->instance->hashKey,
				OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
				$this->instance->hashIV
			);
		} else {
			// 使用 Mcrypt 解密（較舊版本的 PHP）
			$decryptedData = mcrypt_decrypt(
				MCRYPT_RIJNDAEL_128,
				$this->instance->hashKey,
				$encryptedData,
				MCRYPT_MODE_CBC,
				$this->instance->hashIV
			);
		}

		// 解除填充
		$decryptedData = $this->removePadding($decryptedData);

		// 解析為 array
		parse_str($decryptedData, $postData);

		return $postData;
	}

	private function removePadding($data)
	{
		$padding = ord($data[strlen($data) - 1]);
		return substr($data, 0, -$padding);
	}
}
