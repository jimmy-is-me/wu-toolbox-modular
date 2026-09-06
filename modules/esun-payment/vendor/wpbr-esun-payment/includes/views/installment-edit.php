<?php
/**
 * Admin view: Edit Installment
 *
 * @package WPBrewer\ESun\Payment
 */

defined( 'ABSPATH' ) || exit;

$installment_number = isset( $_GET['edit-installment'] ) ? sanitize_text_field( $_GET['edit-installment'] ) : '';
$installs = get_option( 'wpbr_esun_installments_settings', array() );
$install_data = isset( $installs[$installment_number] ) ? $installs[$installment_number] : array();

if (is_string($install_data)) {
    $install_data = array(
        'active' => $install_data,
        'min_amount' => 0
    );
}

wp_enqueue_script( 'wpbr-esun-payment-admin' );
wp_enqueue_style( 'wpbr-esun-payment-admin' );
?>

<div class="wrap">
    <h1><?php echo esc_html( sprintf( __( 'Edit %d Month Installment', 'wpbr-esun-payment' ), $installment_number ) ); ?></h1>

    <form id="installment-edit" method="post" class="settings-panel">
        <?php wp_nonce_field( 'esun_save_installment' ); ?>
        <input type="hidden" name="installment_number" value="<?php echo esc_attr( $installment_number ); ?>" />

        <table class="form-table">
            <tbody>
                <tr valign="top">
                    <th scope="row" class="titledesc">
                        <label for="is_active"><?php esc_html_e( 'Status', 'wpbr-esun-payment' ); ?></label>
                    </th>
                    <td class="forminp">
                        <label class="esun-toggle">
                            <input 
                                type="checkbox" 
                                id="is_active" 
                                name="is_active" 
                                class="esun-toggle-checkbox"
                                <?php checked( isset( $install_data['active'] ) ? $install_data['active'] === 'yes' : false ); ?> 
                            />
                            <span class="esun-toggle-slider"></span>
                        </label>
                        <p class="description">
                            <?php esc_html_e( 'Enable/Disable this installment', 'wpbr-esun-payment' ); ?>
                        </p>
                    </td>
                </tr>
                <tr valign="top">
                    <th scope="row" class="titledesc">
                        <label for="min_amount"><?php esc_html_e( 'Minimum Order Amount', 'wpbr-esun-payment' ); ?></label>
                    </th>
                    <td class="forminp">
                        <input 
                            type="number" 
                            id="min_amount" 
                            name="min_amount" 
                            class="regular-text" 
                            min="0" 
                            step="1" 
                            value="<?php echo esc_attr( isset( $install_data['min_amount'] ) ? $install_data['min_amount'] : 0 ); ?>" 
                        />
                        <p class="description">
                            <?php esc_html_e( 'Minimum order amount required for this installment', 'wpbr-esun-payment' ); ?>
                        </p>
                    </td>
                </tr>
            </tbody>
        </table>

        <p class="submit">
            <?php submit_button( __( 'Save changes', 'wpbr-esun-payment' ), 'primary', 'esun_save_installment', false ); ?>
            <a href="<?php echo esc_url( admin_url( 'admin.php?page=wc-settings&tab=esun&section=installment' ) ); ?>" class="button-secondary">
                <?php esc_html_e( 'Back', 'wpbr-esun-payment' ); ?>
            </a>
        </p>
    </form>
</div>
