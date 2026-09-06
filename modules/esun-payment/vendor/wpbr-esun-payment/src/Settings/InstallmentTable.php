<?php
/**
 * Account List Table
 *
 * @package WPBrewer\Sinopac\Payment\Multiaccount\Settings
 */

namespace WPBrewer\ESun\Payment\Settings;

use WPBrewer\ESun\Payment\ESunPayment;
use WPBrewer\ESun\Payment\Utils\InstallmentNumbers;

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class ESunInstallsSettingTable
 */
class InstallmentTable extends \WP_List_Table {

	/**
	 * Constructor
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'install',
				'plural'   => 'installs',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Get columns
	 *
	 * @return array
	 */
	public function get_columns() {
		return array(
			'name'        => __( 'Installment', 'wpbr-esun-payment' ),
			'active'      => __( 'Active', 'wpbr-esun-payment' ),
			'min_amount'  => __( 'Min Amount', 'wpbr-esun-payment' ),
		);
	}

	/**
	 * Prepare items
	 *
	 * @return void
	 */
	public function prepare_items() {
		$columns = $this->get_columns();
		$hidden  = array();
		$this->_column_headers = array( $columns, $hidden, $this->get_sortable_columns() );

		$installs = $this->get_installs();
		$this->items = $installs;
	}

	/**
	 * Get accounts
	 *
	 * @return array
	 */
	private function get_installs() {
		$saved_installs   = get_option( 'wpbr_esun_installments_settings', array() );
		$installment_numbers  = InstallmentNumbers::INSTALLMENT_LIST;
		$default_installs = array();
		foreach ($installment_numbers as $number => $value) {
			$default_installs[$number] = array(
				'active' => 'no',
				'min_amount' => 0,
			);
		}

		// Merge with defaults if empty
		if ( empty( $saved_installs ) ) {
			$saved_installs = $default_installs;
			update_option('wpbr_esun_installments_settings', $saved_installs);
		}

		$installs = array();
		foreach ( $saved_installs as $number => $data ) {
			// Handle both old and new data structure
			if ( is_array( $data ) ) {
				$installs[] = array(
					'name' => $number,
					'active' => $data['active'],
					'min_amount' => $data['min_amount']
				);
			} else {
				// Convert old format to new
				$installs[] = array(
					'name' => $number,
					'active' => $data,
					'min_amount' => 0
				);
				
				// Update stored data to new format
				$saved_installs[$number] = array(
					'active' => $data,
					'min_amount' => 0
				);
			}
		}

		// Update if any old format data was converted
		if ( array_filter( $saved_installs, 'is_string' ) ) {
			update_option('wpbr_esun_installments_settings', $saved_installs );
		}

		return $installs;
	}

	/**
	 * Column name
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	public function column_name( $item ) {
		$actions = array(
			'edit'   => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page' => 'wc-settings',
							'tab' => 'esun',
							'section' => 'installment',
							'edit-installment' => $item['name'],
						),
						admin_url( 'admin.php' )
					)
				),
				__( 'Edit', 'wpbr-esun-payment' )
			),
		);

		return sprintf(
			'%1$s %2$s',
			$item['name'],
			$this->row_actions( $actions )
		);
	}

	/**
	 * Column active
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	public function column_active( $item ) {
		$is_active = 'yes' === $item['active'];
		$nonce = wp_create_nonce( 'esun_toggle_installment' );
		
		return sprintf(
			'<div class="esun-toggle-wrap">
				<label class="esun-toggle">
					<input type="checkbox" class="esun-toggle-checkbox" 
						data-install="%s" 
						data-nonce="%s" 
						%s>
					<span class="esun-toggle-slider"></span>
				</label>
			</div>',
			esc_attr( $item['name'] ),
			esc_attr( $nonce ),
			checked( $is_active, true, false )
		);
	}

	/**
	 * Column min_amount
	 *
	 * @param array $item Item data.
	 * @return string
	 */
	public function column_min_amount( $item ) {
		$min_amount = isset($item['min_amount']) ? $item['min_amount'] : 0;
		return wc_price($min_amount);
	}

	/**
	 * Handle AJAX toggle
	 */
	public static function ajax_toggle_installment() {
		check_ajax_referer( 'esun_toggle_installment', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( 'Permission denied' );
		}

		$install_number = isset( $_POST['install'] ) ? sanitize_text_field( $_POST['install'] ) : '';
		$active = isset( $_POST['active'] ) ? wc_string_to_bool( $_POST['active'] ) : false;

		// Save to options
		$installs = get_option( 'wpbr_esun_installments_settings', array() );
		
		// Get existing min_amount or set default to 0
		$current_data = isset($installs[$install_number]) ? $installs[$install_number] : array();
		$min_amount = 0;
		
		if (is_array($current_data)) {
			$min_amount = isset($current_data['min_amount']) ? $current_data['min_amount'] : 0;
		}

		// Update with both active status and min_amount
		$installs[$install_number] = array(
			'active' => $active ? 'yes' : 'no',
			'min_amount' => $min_amount
		);

		ESunPayment::log( 'Updating installment settings: ' . wc_print_r($installs, true) );
		update_option( 'wpbr_esun_installments_settings', $installs );

		wp_send_json_success();
	}

	/**
	 * Handle form submission
	 */
	public static function handle_form_submission() {
		if ( ! isset( $_POST['esun_save_installment'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'esun_save_installment' ) ) {
			wp_die( __( 'Action failed. Please refresh the page and retry.', 'wpbr-esun-payment' ) );
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( __( 'You don\'t have permission to do this.', 'wpbr-esun-payment' ) );
		}

		$installment_number = isset( $_POST['installment_number'] ) ? sanitize_text_field( $_POST['installment_number'] ) : '';
		if ( empty( $installment_number ) ) {
			wp_die( __( 'Invalid installment number.', 'wpbr-esun-payment' ) );
		}

		$installs = get_option( 'wpbr_esun_installments_settings', array() );
		
		// Update install installment settings
		$installs[$installment_number] = array(
			'active' => isset( $_POST['is_active'] ) ? 'yes' : 'no',
			'min_amount' => isset( $_POST['min_amount'] ) ? absint( $_POST['min_amount'] ) : 0
		);

		ESunPayment::log( 'Saving installment settings: ' . wc_print_r( $installs, true ) );
		update_option( 'wpbr_esun_installments_settings', $installs );

		wp_safe_redirect( admin_url( 'admin.php?page=wc-settings&tab=esun&section=installment' ) );
		exit;
	}

	/**
	 * Register AJAX handlers
	 */
	public static function register_ajax_handlers() {
		add_action( 'wp_ajax_esun_toggle_installment', array( __CLASS__, 'ajax_toggle_installment' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_form_submission' ) );
	}

} 