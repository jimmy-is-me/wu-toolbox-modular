<?php
/**
 * The permanent "About this plugin" help card shown on the plugin's own admin screens.
 *
 * @package cms-tree-page-view
 */

namespace CMS_Tree_Page_View\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders the small, non-dismissible "About this plugin" card: support forum,
 * review nudge, and a Simple History byline. PHP twin of the React
 * AboutPluginCard component (src/detail/AboutPluginCard.jsx) — same markup,
 * classes, and copy — used on the settings screen, which doesn't load the
 * React bundle's CSS. Replaces the old dismissible above_wrapper()/
 * annoying_box() promo boxes.
 */
class Promo {

	/**
	 * Render the "About this plugin" help card.
	 *
	 * Rendered on the settings screen (Settings\Options::render_settings_page()).
	 */
	public static function help_card() {
		?>
		<div class="cms-tpv-about-card">

			<h3 class="cms-tpv-about-card__title"><?php esc_html_e( 'About this plugin', 'cms-tree-page-view' ); ?></h3>

			<p class="cms-tpv-about-card__row">
			<?php
				self::link_row(
					/* translators: %1$s: URL to the plugin's WordPress.org support forum. */
					__( 'Need help or feedback? Visit <a href="%1$s" target="_blank" rel="noopener noreferrer">the support forum<span class="screen-reader-text"> (opens in a new tab)</span></a>.', 'cms-tree-page-view' ),
					'https://wordpress.org/support/plugin/cms-tree-page-view/'
				);
			?>
			</p>

			<p class="cms-tpv-about-card__row">
			<?php
				self::link_row(
					/* translators: %1$s: URL to the plugin's WordPress.org review page. */
					__( 'Enjoying the plugin? <a href="%1$s" target="_blank" rel="noopener noreferrer">Leave a review<span class="screen-reader-text"> (opens in a new tab)</span></a>.', 'cms-tree-page-view' ),
					'https://wordpress.org/support/plugin/cms-tree-page-view/reviews/#new-post'
				);
			?>
			</p>

			<?php // No "plugin settings" row here (unlike the React AboutPluginCard's settingsUrl row) — this card is rendered ON the settings screen, so linking to it would be self-referential. ?>

			<p class="cms-tpv-about-card__row cms-tpv-about-card__byline">
			<?php
				self::link_row(
					/* translators: %1$s: URL to the Simple History plugin's website. */
					__( 'Made by Pär Thernström, creator of the <a href="%1$s" target="_blank" rel="noopener noreferrer">Simple History<span class="screen-reader-text"> (opens in a new tab)</span></a> plugin.', 'cms-tree-page-view' ),
					'https://simple-history.com/?utm_source=cms-tree-page-view&utm_medium=plugin&utm_campaign=about_card'
				);
			?>
			</p>

		</div>
		<?php
	}

	/**
	 * Print one card row's sentence: kses-filter the translated string (which
	 * embeds a new-tab anchor and its screen-reader "(opens in a new tab)"
	 * suffix) and inject the escaped URL into its %1$s placeholder.
	 *
	 * @param string $message Translated sentence containing the anchor markup and a %1$s URL placeholder.
	 * @param string $url     Link URL.
	 */
	private static function link_row( $message, $url ) {

		$allowed_html = array(
			'a'    => array(
				'href'   => array(),
				'target' => array(),
				'rel'    => array(),
			),
			'span' => array(
				'class' => array(),
			),
		);

		printf( wp_kses( $message, $allowed_html ), esc_url( $url ) );
	}
}
