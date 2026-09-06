<?php

namespace WPBrewer\ESun\Payment\Api;

use WPBrewer\ESun\Payment\ESunPayment;
use WPBrewer\ESun\Payment\Lib\Esunacq\AuthRequestBuilder;
use WPBrewer\ESun\Payment\Lib\Esunacq\TxnType;
use WPBrewer\ESun\Payment\Utils\InstallmentNumbers;
use WPBrewer\ESun\Payment\Utils\OrderMeta;

class ESunPaymentRequest {

    protected $auth_builder;

    protected $gateway;
 
    public function get_transaction_args( $order ) {

        $order = wc_get_order( $order );
    
        $this->auth_builder = new AuthRequestBuilder( ESunPayment::$MAC, [
            'MID' => ESunPayment::$MID,  // 特店代碼 char(15)
            'TID' => $this->gateway::TID,//終端機代號, 一般交易:EC000001, 分期交易:EC000002
            'U'   => $this->gateway->order_result_url,
        ]);

        //將woo訂單id轉成玉山訂單編號，避免訂單編號重複造成交易失敗
        $esun_order_no = ESunPayment::build_esun_order_no( $order->get_id() );

        if ( $this->gateway::TID == TxnType::GENERAL ) {
            $formFields = $this->auth_builder->formFields( $esun_order_no, $order->get_total() );
        } elseif ( $this->gateway::TID == TxnType::INSTALLMENT ) {
            $installment_number = $order->get_meta( OrderMeta::INSTALLMENT_NUMBER );//3, 6
            $ic = InstallmentNumbers::get_installment( $installment_number );
            $formFields = $this->auth_builder->formFields( $esun_order_no, $order->get_total(), $this->gateway::TID, $ic );
        }
		
        $data       = json_encode($formFields);
		$mac        = hash('sha256', $data.ESunPayment::$MAC );

		$formFields['data'] = $data;
		$formFields['mac']  = $mac;
		$formFields['ksn']  = '1';

		ESunPayment::log( 'prepare request fields:' . wc_print_r( $formFields, true ) );

        return $formFields;
    }

    /**
     * 
     * @param mixed $order 
     * @return void 
     */
    public function build_request_form( $order ) {
        try {

            ?>
              <div><?php echo __( 'Redirecting...', 'wpbr-esun-payment' ); ?></div>
              <form method="post" id="esun-form" action="<?php echo ESunPayment::$api_url; ?>" accept-charset="big5" enctype="application/x-www-form-urlencoded">
                  <?php
                      $formFields = $this->get_transaction_args( $order );
                      foreach ( $formFields as $key => $value ) {
                          echo '<input type="hidden" name="' . esc_html( $key ) . '" value="' . esc_html( $value ) . '">';
                      }
                  ?>
              </form>
              <script type="text/javascript">
                  document.getElementById('esun-form').submit();
              </script>
          <?php
      
              } catch ( \Exception $e ) {
                  ESunPayment::log( $e->getMessage() . ' ' . $e->getTraceAsString() );
              }
    }

    public function set_gateway( $gateway ) {
        $this->gateway = $gateway;
    }
}