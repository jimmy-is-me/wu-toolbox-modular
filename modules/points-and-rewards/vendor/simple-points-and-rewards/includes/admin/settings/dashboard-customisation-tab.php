<?php
/**
 * Dashboard Customisation Settings Tab
 */
if ( ! defined( 'ABSPATH' ) ) exit;

function spar_settings_tab_dashboard() {
    $defaults = spar_settings_default();
    $options  = get_option( 'spar_options', $defaults );
    $options  = array_merge( $defaults, $options );

    $available_tabs = array(
        'earn'     => esc_html__( 'Earn Points', 'simple-points-and-rewards' ),
        'claim'    => esc_html__( 'Claim Rewards', 'simple-points-and-rewards' ),
        'levels'   => esc_html__( 'Levels', 'simple-points-and-rewards' ),
        'history'  => esc_html__( 'History', 'simple-points-and-rewards' ),
        'vouchers' => esc_html__( 'Your Vouchers', 'simple-points-and-rewards' ),
    );

    $saved_order = isset( $options['dashboard_tabs_order'] ) && is_array( $options['dashboard_tabs_order'] )
        ? array_values( array_unique( array_filter( $options['dashboard_tabs_order'], 'strlen' ) ) )
    : array( 'earn', 'claim', 'vouchers', 'levels', 'history' );

    // Ensure we only keep known keys, and append any new ones not yet saved
    $saved_order = array_values( array_intersect( $saved_order, array_keys( $available_tabs ) ) );

    // Build current list with enabled flag based on presence in saved_order
    $enabled_map = array_fill_keys( $saved_order, true );

    foreach ( $available_tabs as $key => $label ) {
        if ( ! in_array( $key, $saved_order, true ) ) {
            $saved_order[] = $key;
        }
    }

    // Load custom labels if any
    $saved_labels = array();
    if ( isset( $options['dashboard_tab_labels'] ) && is_array( $options['dashboard_tab_labels'] ) ) {
        $saved_labels = $options['dashboard_tab_labels'];
    }
    ?>
    <div class="spar-dashboard-customisation">
        <h3><?php esc_html_e( 'Dashboard Customisation', 'simple-points-and-rewards' ); ?></h3>
    <p><?php esc_html_e( 'Choose which tabs are shown on the customer rewards dashboard and set their order. Drag to reorder; uncheck to hide. You can also rename each tab by editing the text field.', 'simple-points-and-rewards' ); ?></p>

        <ul id="spar-dashboard-tabs-sortable" class="spar-sortable-list">
            <?php foreach ( $saved_order as $key ) :
                $default_label = isset( $available_tabs[ $key ] ) ? $available_tabs[ $key ] : $key;
                $current_label = isset( $saved_labels[ $key ] ) && '' !== $saved_labels[ $key ] ? $saved_labels[ $key ] : $default_label; ?>
                <li class="spar-sortable-item" data-key="<?php echo esc_attr( $key ); ?>">
                    <span class="dashicons dashicons-move"></span>
                    <label class="spar-tab-toggle">
                        <input type="checkbox" name="dashboard_tabs_order[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( ! empty( $enabled_map[ $key ] ) ); ?> />
                        <span class="spar-tab-toggle-text"><?php echo esc_html( $default_label ); ?></span>
                    </label>
                    <input type="text" class="regular-text spar-tab-label-input" name="dashboard_tab_labels[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $current_label ); ?>" placeholder="<?php echo esc_attr( $default_label ); ?>" />
                </li>
            <?php endforeach; ?>
        </ul>

        <p class="description">
            <?php esc_html_e( 'Tabs left checked will appear for customers under the rewards dashboard. You can reorder them using the drag handles.', 'simple-points-and-rewards' ); ?>
        </p>

        <style>
            /* Minimal styles for sortable list */
            .spar-sortable-list { list-style: none; margin: 0; padding: 0; max-width: 520px; }
            .spar-sortable-item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border: 1px solid #dcdcde; border-radius: 4px; background: #fff; margin-bottom: 8px; }
            .spar-sortable-item .dashicons-move { cursor: move; color: #646970; }
            .spar-sortable-item .spar-tab-toggle { display: inline-flex; align-items: center; gap: 6px; margin: 0; }
            .spar-sortable-item .spar-tab-label-input { flex: 1 1 auto; max-width: 280px; }
            .spar-sortable-item.is-disabled .spar-tab-label-input { opacity: .7; }
        </style>
    </div>
    <?php
}
