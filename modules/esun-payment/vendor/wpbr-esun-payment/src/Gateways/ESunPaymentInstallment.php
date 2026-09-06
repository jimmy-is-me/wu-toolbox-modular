<?php

namespace WPBrewer\ESun\Payment\Gateways;

use WPBrewer\ESun\Payment\Api\ESunPaymentRequest;
use WPBrewer\ESun\Payment\ESunPayment;
use WPBrewer\ESun\Payment\Lib\Esunacq\TxnType;
use WPBrewer\ESun\Payment\Utils\InstallmentNumbers;
use WPBrewer\ESun\Payment\Utils\OrderMeta;

class ESunPaymentInstallment extends AbstractESunPaymentGateway {

    const GATEWAY_ID = 'esun-installment';
    
    const TID = TxnType::INSTALLMENT;

    public function __construct() {

		parent::__construct();

        //woo
        $this->id                 = self::GATEWAY_ID;
        $this->method_title       = __('ESUN Payment Installments', 'wpbr-esun-payment');
		$this->method_description = __('ESUN Payment Installments', 'wpbr-esun-payment' );
		$this->order_button_text  = $this->get_option( 'order_button_text' );
        $this->has_fields         = true;

        //woo
        $this->init_form_fields();
        $this->init_settings();

        // Define user set variables
        $this->title             = $this->get_option( 'title' );
        $this->description       = $this->get_option( 'description' );

		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
        add_action( 'woocommerce_receipt_' . $this->id, array( $this, 'receipt_page' ) );
        add_action( 'woocommerce_order_details_after_order_table', array( $this, 'esun_payment_detail_after_order_table'), 10, 1 );
    }

    public function init_form_fields() {
        $this->form_fields = include ESUN_PLUGIN_DIR . 'includes/settings/settings-esun-credit-installment.php';
    }

    /**
	 * Payment method settings
	 *
	 * @return void
	 */
	public function admin_options() {
		echo '<h3>' . esc_html( $this->get_method_title() ) . '</h3>';
		echo '<p>' . sprintf(
			/* translators: 1: Payment method title 2: ESUN URL */
			esc_html__( '%1$s is a payment gateway provided by %2$s', 'wpbr-esun-payment' ),
			esc_html( $this->get_method_title() ),
			sprintf(
				'<a href="%s">%s</a>',
				esc_url( 'https://www.esun.com.tw/' ),
				esc_html__( 'E.SUN Bank', 'wpbr-esun-payment' )
			)
		) . '</p>';
        echo '<p>' . sprintf( __( 'To active/deactive each installment plans, please go to the %s page.', 'wpbr-esun-payment' ), sprintf( '<a href="%s">%s</a>', admin_url( 'admin.php?page=wc-settings&tab=esun&section=installment' ), __( 'Installment Settings', 'wpbr-esun-payment' ) ) ) . '</p>';
		echo '<table class="form-table">';
		$this->generate_settings_html();
		echo '</table>';
	}


    /**
     * redirect to esun payment page
     *
     * @access public
     * @return void
     */
    function receipt_page( $order ) {
        ESunPayment::log( 'receipt_page:' . wc_print_r( $_POST, true ) );
        WC()->cart->empty_cart();
		$request = new ESunPaymentRequest();
		$request->set_gateway( $this );
        $request->build_request_form( $order );
    }

    public function process_payment( $order_id ) {
        if ( isset( $_POST['esun-payment-installment-number'])) {
            $order = wc_get_order( $order_id );
            $order->update_meta_data( OrderMeta::INSTALLMENT_NUMBER, $_POST['esun-payment-installment-number']);
            $order->save();
        }

        return parent::process_payment( $order_id );
    }


    /**
	 * Display payment fields
	 */
	public function payment_fields() { ?>
		<div>
			<div class="esun_payment_installment_radio">
				<select name="esun-payment-installment-number" id="esun-payment-installment-number">
					<option value=""><?php _e( 'Select Installment', 'wpbr-esun-payment' ); ?></option>
					<?php 
					$saved_installs = get_option('wpbr_esun_installments_settings', array());
					$cart_total = WC()->cart->get_total('');
					foreach ( InstallmentNumbers::INSTALLMENT_LIST as $number => $value ) {
						if ( isset($saved_installs[$number]) && 
							$saved_installs[$number]['active'] === 'yes' &&
							$cart_total >= $saved_installs[$number]['min_amount']) {
							?>
							<option value="<?php echo esc_attr($number); ?>"><?php echo sprintf(__('%d installments', 'wpbr-esun-payment'), $number); ?></option>
							<?php
						}
					} 
					?>
				</select>
			</div>
		</div>
		<?php
	}

    public function is_available() {
        $is_available = parent::is_available();
        $active_installments = self::get_active_installments();
        if ( empty( $active_installments ) ) {
            $is_available = false;
            return $is_available;
        }

        if ( ! WC()->cart ) {
            return $is_available;
        }

        $cart_total = (float) WC()->cart->get_total('edit');
        $has_available_installments = false;
        foreach ( $active_installments as $number => $installment ) {
            if ( $cart_total >= (float) $installment['min_amount'] ) {
                $has_available_installments = true;
            }
        }

        $is_available = $has_available_installments;
        
        return $is_available;
    }

    public function esun_payment_detail_after_order_table( $order ) {

        if ( $order->get_payment_method() == $this->id ) {

            echo '<header><h2>'.__( 'ESUN Payment Detail', 'wpbr-esun-payment' ).'</h2></header><table class="shop_table esun_payment_details"><tbody>';

            echo '<tr><td><strong>' . __( 'Transaction Status', 'wpbr-esun-payment' ) . '</strong></td>';
            echo '<td>' . $order->get_meta( OrderMeta::TRANS_STATUS ) . '</td></tr>';

            echo '<tr><td><strong>' . __( 'Auth Code', 'wpbr-esun-payment' ) . '</strong></td>';
            echo '<td>' . $order->get_meta( OrderMeta::AIR ) . '</td></tr>'; //授權碼

            echo '<tr><td><strong>' . __( 'Installment Number', 'wpbr-esun-payment' ) . '</strong></td>';
            echo '<td>' . $order->get_meta( OrderMeta::INSTALLMENT_NUMBER ) . '</td></tr>';

            echo '<tr><td><strong>' . __( 'Installment Amount', 'wpbr-esun-payment' ) . '</strong></td>';
            echo '<td>' . $order->get_meta( OrderMeta::INSTALLMENT_TOTAL_AMOUNT ) . '</td></tr>';    

            echo '<tr><td><strong>' . __( 'Installment Each Amount', 'wpbr-esun-payment' ) . '</strong></td>';
            echo '<td>' . $order->get_meta( OrderMeta::INSTALLMENT_EACH_AMOUNT ) . '</td></tr>';

            echo '<tr><td><strong>' . __( 'Installment First Amount', 'wpbr-esun-payment' ) . '</strong></td>';
            echo '<td>' . $order->get_meta( OrderMeta::INSTALLMENT_FIRST_AMOUNT ) . '</td></tr>';

            echo '<tr><td><strong>' . __( 'Transaction Date', 'wpbr-esun-payment' ) . '</strong></td>';
            echo '<td>' . $order->get_meta( OrderMeta::TRADE_DATE ) . '</td></tr>';

            echo '<tr><td><strong>' . __( 'Transaction Time', 'wpbr-esun-payment' ) . '</strong></td>';
            echo '<td>' . $order->get_meta( OrderMeta::TRADE_TIME ) . '</td></tr>';

            $errorcode = $order->get_meta( OrderMeta::ERROR_NO );
            if ( ! empty( $errorcode ) ) {
                echo '<tr><td><strong>' . __( 'Error Code', 'wpbr-esun-payment' ) . '</strong></td>';
                echo '<td>' . $order->get_meta( OrderMeta::ERROR_NO ) . '</td></tr>';

                echo '<tr><td><strong>' . __( 'Error Description', 'wpbr-esun-payment' ) . '</strong></td>';
                echo '<td>' . $order->get_meta( OrderMeta::ERROR_DESC ) . '</td></tr>';
            }

            echo '</tbody></table>';

        }

    }

    public static function get_active_installments() {
        $saved_installs = get_option( 'wpbr_esun_installments_settings', array() );
        $available_installments = array();
        foreach ( $saved_installs as $number => $installment ) {
            if ( $installment['active'] === 'yes' ) {
                $available_installments[$number] = $installment;
            }
        }
        ESunPayment::log( 'active_installments:' . wc_print_r( $available_installments, true ) );

        return $available_installments;
    }
}

