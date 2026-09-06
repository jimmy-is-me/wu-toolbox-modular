<?php

/**
 * Admin Header Template
 * 
 * @package Simple Points and Rewards
 */
if ( !defined( 'ABSPATH' ) ) {
    exit;
}
$spar_current_admin_page = filter_input( INPUT_GET, 'page', FILTER_SANITIZE_FULL_SPECIAL_CHARS );
// Use direct Freemius premium check
$spar_admin_active_page_map = array(
    'spar-customer'             => 'spar-customer-points',
    'spar-customer-points-bulk' => 'spar-customer-points',
);
// Define all admin pages
$spar_admin_pages = array(
    'spar-settings'        => array(
        'title' => esc_html__( 'Settings', 'simple-points-and-rewards' ),
        'icon'  => 'admin-settings',
    ),
    'reward-options'       => array(
        'title'    => esc_html__( 'Rewards', 'simple-points-and-rewards' ),
        'icon'     => 'star-filled',
        'redirect' => 'admin.php?page=spar-settings&tab=rewards',
    ),
    'spar-reward-vouchers' => array(
        'title' => esc_html__( 'Vouchers', 'simple-points-and-rewards' ),
        'icon'  => 'tickets-alt',
    ),
    'spar-customer-points' => array(
        'title' => esc_html__( 'Customers', 'simple-points-and-rewards' ),
        'icon'  => 'groups',
    ),
    'spar-log'             => array(
        'title' => esc_html__( 'Activity', 'simple-points-and-rewards' ),
        'icon'  => 'list-view',
    ),
);
?>
<div class="spar-admin-header">
	<div class="spar-header-content">
		<div class="spar-header-left">
			<div class="spar-plugin-branding">
				<div class="spar-plugin-info">
					<h1 class="spar-plugin-name"><?php 
esc_html_e( 'Simple Points and Rewards', 'simple-points-and-rewards' );
?>
					<h2 class="spar-page-title"><?php 
echo esc_html( $page_title );
?></h2>
				</div>
			</div>
		</div>
		
		<div class="spar-header-right">
			<div class="spar-header-nav">
				<!-- Page Navigation -->
				<div class="spar-nav-pages">
					<?php 
foreach ( $spar_admin_pages as $spar_page_slug => $spar_page_data ) {
    $spar_active_page = ( isset( $spar_admin_active_page_map[$spar_current_admin_page] ) ? $spar_admin_active_page_map[$spar_current_admin_page] : $spar_current_admin_page );
    $spar_is_current_page = $spar_active_page === $spar_page_slug;
    $spar_admin_url = ( isset( $spar_page_data['redirect'] ) ? admin_url( $spar_page_data['redirect'] ) : admin_url( 'admin.php?page=' . $spar_page_slug ) );
    ?>
						<a href="<?php 
    echo esc_url( $spar_admin_url );
    ?>" 
						   class="spar-nav-link<?php 
    echo ( $spar_is_current_page ? ' active' : '' );
    ?>"
						   title="<?php 
    echo esc_attr( $spar_page_data['title'] );
    ?>">
							<span class="dashicons dashicons-<?php 
    echo esc_attr( $spar_page_data['icon'] );
    ?>"></span>
							<span class="spar-nav-text"><?php 
    echo esc_html( $spar_page_data['title'] );
    ?></span>
						</a>
					<?php 
}
?>
				</div>

				<!-- Tools row: stats bar (left) + action links (right) -->
				<div class="spar-header-tools">
					<?php 
// Show compact analytics bar to the left of action links
if ( function_exists( 'spar_get_admin_analytics_snapshot' ) && current_user_can( 'manage_woocommerce' ) ) {
    $spar_snapshot = spar_get_admin_analytics_snapshot();
    $spar_points_label = spar_get_option( 'general', 'points_label' );
    if ( !$spar_points_label ) {
        $spar_points_label = esc_html__( 'Points', 'simple-points-and-rewards' );
    }
    // Formatter returns a raw string; escape at output to satisfy PHPCS.
    $spar_admin_number_formatter = function ( $v ) {
        if ( function_exists( 'spar_format_compact_number' ) ) {
            return spar_format_compact_number( $v );
        }
        return number_format_i18n( (int) $v );
    };
    ?>
					<div class="spar-stats-bar" aria-label="<?php 
    echo esc_attr__( 'Points & Rewards Statistics', 'simple-points-and-rewards' );
    ?>">
						<div class="spar-stats-item" title="<?php 
    echo esc_attr__( 'Net Points (Earned minus Redeemed)', 'simple-points-and-rewards' );
    ?>">
							<span class="spar-stats-label"><?php 
    echo esc_html__( 'Available Points', 'simple-points-and-rewards' );
    ?></span>
							<span class="spar-stats-value"><?php 
    echo esc_html( number_format_i18n( (int) ($spar_snapshot['net'] ?? 0) ) );
    ?></span>
						</div>
						<div class="spar-stats-item" title="<?php 
    echo esc_attr__( 'Total Redeemed Points (All-time)', 'simple-points-and-rewards' );
    ?>">
							<span class="spar-stats-label"><?php 
    echo esc_html__( 'Redeemed Points', 'simple-points-and-rewards' );
    ?></span>
							<span class="spar-stats-value"><?php 
    echo esc_html( number_format_i18n( (int) ($spar_snapshot['redeemed'] ?? 0) ) );
    ?></span>
						</div>
						<div class="spar-stats-item" title="<?php 
    echo esc_attr__( 'Users With > 0 Total Earned Points', 'simple-points-and-rewards' );
    ?>">
							<span class="spar-stats-label"><?php 
    echo esc_html__( 'Total Users', 'simple-points-and-rewards' );
    ?></span>
							<span class="spar-stats-value"><?php 
    echo esc_html( $spar_admin_number_formatter( $spar_snapshot['users_with_points'] ?? 0 ) );
    ?></span>
						</div>
						<div class="spar-stats-item" title="<?php 
    echo esc_attr__( 'Active Users (Any Points Activity Last 30 Days)', 'simple-points-and-rewards' );
    ?>">
							<span class="spar-stats-label"><?php 
    echo esc_html__( 'Active Users', 'simple-points-and-rewards' );
    ?></span>
							<span class="spar-stats-value"><?php 
    echo esc_html( $spar_admin_number_formatter( $spar_snapshot['active_customers_30'] ?? 0 ) );
    ?></span>
						</div>
					</div>
					<?php 
}
?>

					<!-- Action Links -->
					<div class="spar-action-links">
					<?php 
?>
							<a href="https://relywp.com/plugins/simple-points-rewards-woocommerce/#pricing" class="spar-action-link spar-link-upgrade">
								<span class="dashicons dashicons-superhero"></span>
								<span><?php 
esc_html_e( 'Try PRO for free!', 'simple-points-and-rewards' );
?></span>
							</a>
					<?php 
?>
					
					<a href="https://wordpress.org/support/plugin/simple-points-and-rewards/" 
					   class="spar-action-link" 
					   target="_blank"
					   rel="noopener noreferrer">
						<span class="dashicons dashicons-sos"></span>
						<span><?php 
esc_html_e( 'Support', 'simple-points-and-rewards' );
?></span>
					</a>
					<a href="https://relywp.com/" class="spar-action-link spar-relywp-logo-link"
					title="Developed by RelyWP"
					target="_blank" rel="noopener noreferrer">
						<img src="<?php 
echo esc_url( SPAR_PLUGIN_URL . 'assets/images/relywp.png' );
?>" alt="RelyWP" class="spar-relywp-logo" />
					</a>
					</div><!-- /.spar-action-links -->
				</div><!-- /.spar-header-tools -->
			</div>
		</div>
	</div>
</div>