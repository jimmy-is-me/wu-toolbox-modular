<?php
/**
 * PRO feature sales/info pages shown in settings tabs for free users.
 *
 * Each function renders a self-contained informational page describing a PRO
 * feature — what it does, what settings are available, and a CTA to upgrade.
 * None of these functions render actual form fields or save any data.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Shared helpers
// ---------------------------------------------------------------------------

/**
 * Render the common upgrade CTA footer block.
 */
function spar_pro_sales_page_cta() {
	$upgrade_url = 'https://relywp.com/plugins/simple-points-rewards-woocommerce/#pricing';
	?>
	<div class="spar-pro-sales-cta">
		<div class="spar-pro-sales-cta-text">
			<strong><?php esc_html_e( 'Ready to unlock this feature?', 'simple-points-and-rewards' ); ?></strong>
			<p><?php esc_html_e( 'Upgrade to PRO and get a free 7-day trial + 25% off today.', 'simple-points-and-rewards' ); ?></p>
		</div>
		<a href="<?php echo esc_url( $upgrade_url ); ?>" target="_blank" rel="noopener" class="button button-primary button-hero" style="background: rgb(87, 182, 44); border: 2px solid rgb(87, 182, 44); color: #fff; font-weight: bold;">
			<?php esc_html_e( 'Start Free Trial', 'simple-points-and-rewards' ); ?>
		</a>
	</div>
	<?php
}

/**
 * Render a single feature highlight card.
 *
 * @param string $icon     Dashicons class name without the 'dashicons-' prefix.
 * @param string $title    Card heading (already escaped).
 * @param string $body     Card body text (already escaped).
 */
function spar_pro_sales_feature_card( $icon, $title, $body ) {
	?>
	<div class="spar-pro-sales-feature">
		<span class="dashicons dashicons-<?php echo esc_attr( $icon ); ?>" aria-hidden="true"></span>
		<div>
			<strong><?php echo esc_html( $title ); ?></strong>
			<p><?php echo esc_html( $body ); ?></p>
		</div>
	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Conditional Rules
// ---------------------------------------------------------------------------

function spar_pro_sales_page_conditional_rules() {
	?>
	<div class="spar-pro-sales-page">

		<div class="spar-pro-sales-hero">
			<div class="spar-pro-sales-hero-icon" aria-hidden="true">🎯</div>
			<div class="spar-pro-sales-hero-content">
				<h2><?php esc_html_e( 'Conditional Rules', 'simple-points-and-rewards' ); ?> <span class="spar-pro-badge-inline">PRO</span></h2>
				<p><?php esc_html_e( 'Fine-tune exactly when and how customers earn points. Create powerful rules that apply multipliers, adjustments, or restrictions based on what is in the cart, who the customer is, or how they shop.', 'simple-points-and-rewards' ); ?></p>
			</div>
		</div>

		<div class="spar-pro-sales-features">
			<?php
			spar_pro_sales_feature_card( 'controls-repeat', esc_html__( 'Point Multipliers', 'simple-points-and-rewards' ), esc_html__( 'Multiply points earned on any earning method - e.g. award 3× points on all orders containing a specific product or category.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'plus-alt', esc_html__( 'Fixed Point Adjustments', 'simple-points-and-rewards' ), esc_html__( 'Add or subtract a fixed number of points from an earning event when conditions are met, independently of the standard rate.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'editor-ul', esc_html__( 'Min & Max Point Caps', 'simple-points-and-rewards' ), esc_html__( 'Set a floor and/or ceiling on how many points a customer can earn in a single event, keeping rewards balanced.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'hidden', esc_html__( 'Disable Earning Methods', 'simple-points-and-rewards' ), esc_html__( 'Prevent points from being earned on specific orders - for example, disable points when a coupon is applied or for orders under a minimum value.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'filter', esc_html__( 'Multiple Conditions per Rule', 'simple-points-and-rewards' ), esc_html__( 'Stack multiple conditions in a single rule using AND logic - e.g. only apply if the order total is over £50 AND a specific product is in the cart.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'tag', esc_html__( 'Target Any Earning Method', 'simple-points-and-rewards' ), esc_html__( 'Rules can target any active earning method: order spend, signup bonus, daily login, birthday, reviews, referrals, social share, and more.', 'simple-points-and-rewards' ) );
			?>
		</div>

		<?php spar_pro_sales_page_cta(); ?>

	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Points Delay
// ---------------------------------------------------------------------------

function spar_pro_sales_page_points_delay() {
	?>
	<div class="spar-pro-sales-page">

		<div class="spar-pro-sales-hero">
			<div class="spar-pro-sales-hero-icon" aria-hidden="true">⏳</div>
			<div class="spar-pro-sales-hero-content">
				<h2><?php esc_html_e( 'Points Delay', 'simple-points-and-rewards' ); ?> <span class="spar-pro-badge-inline">PRO</span></h2>
				<p><?php esc_html_e( 'Delay earned points before they become available, with a global delay plus method-specific overrides for orders, referrals, signup bonuses, daily login rewards, reviews, birthdays, and more.', 'simple-points-and-rewards' ); ?></p>
			</div>
		</div>

		<div class="spar-pro-sales-features">
			<?php
			spar_pro_sales_feature_card( 'clock', esc_html__( 'Global Delay Window', 'simple-points-and-rewards' ), esc_html__( 'Set a store-wide delay in hours or days so newly earned points only become available after your chosen review period.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'filter', esc_html__( 'Method Overrides', 'simple-points-and-rewards' ), esc_html__( 'Use custom delays for each earning method, or mark selected methods as no-delay while the global delay remains active.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'update', esc_html__( 'Automatic Release', 'simple-points-and-rewards' ), esc_html__( 'Pending points are released by cron, customer login, and dashboard visits so balances stay current without manual work.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'shield', esc_html__( 'Refund Protection', 'simple-points-and-rewards' ), esc_html__( 'Pending order and referral points are cancelled when an order is refunded, cancelled, or failed before the delay ends.', 'simple-points-and-rewards' ) );
			?>
		</div>

		<?php spar_pro_sales_page_cta(); ?>

	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Points Expiry
// ---------------------------------------------------------------------------

function spar_pro_sales_page_expiry() {
	?>
	<div class="spar-pro-sales-page">

		<div class="spar-pro-sales-hero">
			<div class="spar-pro-sales-hero-icon" aria-hidden="true">⏰</div>
			<div class="spar-pro-sales-hero-content">
				<h2><?php esc_html_e( 'Points Expiry', 'simple-points-and-rewards' ); ?> <span class="spar-pro-badge-inline">PRO</span></h2>
				<p><?php esc_html_e( 'Keep your rewards program fresh and customers engaged. Automatically expire points after a period of inactivity, send advance warning emails, and clean up unused vouchers - all on autopilot.', 'simple-points-and-rewards' ); ?></p>
			</div>
		</div>

		<div class="spar-pro-sales-features">
			<?php
			spar_pro_sales_feature_card( 'clock', esc_html__( 'Inactivity Expiry', 'simple-points-and-rewards' ), esc_html__( 'Automatically reset a customer\'s point balance to zero if they haven\'t earned any new points within a configurable window - e.g. 365 days.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'email-alt', esc_html__( 'Expiry Reminder Emails', 'simple-points-and-rewards' ), esc_html__( 'Send a reminder email a set number of days before inactivity expiry kicks in, giving customers a nudge to shop and save their balance.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'visibility', esc_html__( 'Dashboard Countdown Notice', 'simple-points-and-rewards' ), esc_html__( 'Show a countdown message on the customer\'s Rewards Dashboard so they can see exactly how many days remain before their points expire.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'tickets-alt', esc_html__( 'Voucher Expiry Dates', 'simple-points-and-rewards' ), esc_html__( 'Automatically apply an expiry date to reward vouchers when they are generated, keeping your coupon list tidy.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'trash', esc_html__( 'Auto-delete Used Vouchers', 'simple-points-and-rewards' ), esc_html__( 'Automatically remove voucher coupons from WooCommerce once they have been redeemed, preventing clutter.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'remove', esc_html__( 'Auto-delete Expired Items', 'simple-points-and-rewards' ), esc_html__( 'Automatically clean up expired vouchers and expired reward coupons on a daily schedule so your coupon list stays organised.', 'simple-points-and-rewards' ) );
			?>
		</div>

		<?php spar_pro_sales_page_cta(); ?>

	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Referral Gift Coupons
// ---------------------------------------------------------------------------

function spar_pro_sales_page_referral_coupons() {
	?>
	<div class="spar-pro-sales-page">

		<div class="spar-pro-sales-hero">
			<div class="spar-pro-sales-hero-icon" aria-hidden="true">🎟️</div>
			<div class="spar-pro-sales-hero-content">
				<h2><?php esc_html_e( 'Referral Gift Coupons', 'simple-points-and-rewards' ); ?> <span class="spar-pro-badge-inline">PRO</span></h2>
				<p><?php esc_html_e( 'Turn every customer into a brand ambassador. When someone shares their referral link, their referred visitors are automatically offered a personalised discount coupon - creating a compelling reason to make that first purchase.', 'simple-points-and-rewards' ); ?></p>
			</div>
		</div>

		<div class="spar-pro-sales-features">
			<?php
			spar_pro_sales_feature_card( 'admin-network', esc_html__( 'Auto-generated Unique Coupons', 'simple-points-and-rewards' ), esc_html__( 'One unique gift coupon is created per referrer the first time their referral link is clicked - no manual work needed.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'cart', esc_html__( 'Cart & Checkout Banners', 'simple-points-and-rewards' ), esc_html__( 'Show the gift offer prominently on the cart page and/or checkout page so referred visitors don\'t miss it.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'clock', esc_html__( 'Countdown Timer', 'simple-points-and-rewards' ), esc_html__( 'Display a countdown timer on the offer widget to create urgency and encourage referred visitors to act quickly.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'shield', esc_html__( 'Flexible Tracking Modes', 'simple-points-and-rewards' ), esc_html__( 'Choose between Strict (referral link required), Hybrid (allow manual entry), or Flexible (track all usage) coupon tracking modes.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'groups', esc_html__( 'First-order Restriction', 'simple-points-and-rewards' ), esc_html__( 'Optionally limit gift coupons to brand-new customers only, so the offer targets new customer acquisition.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'admin-generic', esc_html__( 'Template Coupon', 'simple-points-and-rewards' ), esc_html__( 'Base gift coupons on an existing WooCommerce coupon to inherit product restrictions, minimum spend rules, and more.', 'simple-points-and-rewards' ) );
			?>
		</div>

		<?php spar_pro_sales_page_cta(); ?>

	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Gift Widget
// ---------------------------------------------------------------------------

function spar_pro_sales_page_gift_widget() {
	?>
	<div class="spar-pro-sales-page">

		<div class="spar-pro-sales-hero">
			<div class="spar-pro-sales-hero-icon" aria-hidden="true">🎁</div>
			<div class="spar-pro-sales-hero-content">
				<h2><?php esc_html_e( 'Referral Gift Widget', 'simple-points-and-rewards' ); ?> <span class="spar-pro-badge-inline">PRO</span></h2>
				<p><?php esc_html_e( 'A beautiful floating widget that appears on any page when a customer arrives via a referral link. It displays their personalised gift coupon and invites them to shop - making the offer impossible to miss.', 'simple-points-and-rewards' ); ?></p>
			</div>
		</div>

		<div class="spar-pro-sales-features">
			<?php
			spar_pro_sales_feature_card( 'slides', esc_html__( 'Floating Overlay', 'simple-points-and-rewards' ), esc_html__( 'The widget floats over any page automatically when a referral link is clicked - no shortcodes or page edits required.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'admin-appearance', esc_html__( 'Fully Customisable', 'simple-points-and-rewards' ), esc_html__( 'Choose the widget\'s position on the screen and colour to match your brand identity.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'format-chat', esc_html__( 'Custom Message', 'simple-points-and-rewards' ), esc_html__( 'Write your own message that appears above the discount amount - tailored to your brand voice.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'id-alt', esc_html__( 'Personalised with Referrer\'s Name', 'simple-points-and-rewards' ), esc_html__( 'Optionally replace "A customer" with the referrer\'s first name - e.g. "James has sent you a gift!" - for a personal touch.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'tickets-alt', esc_html__( 'Works with Referral Coupons', 'simple-points-and-rewards' ), esc_html__( 'Pairs with the Referral Gift Coupons feature to display the gift discount code directly in the widget.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'move', esc_html__( '7 Position Options', 'simple-points-and-rewards' ), esc_html__( 'Place the widget in any corner, the centre of either edge (vertical), or at the bottom-centre of the screen.', 'simple-points-and-rewards' ) );
			?>
		</div>

		<?php spar_pro_sales_page_cta(); ?>

	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Rewards Widget
// ---------------------------------------------------------------------------

function spar_pro_sales_page_rewards_widget() {
	?>
	<div class="spar-pro-sales-page">

		<div class="spar-pro-sales-hero">
			<div class="spar-pro-sales-hero-icon" aria-hidden="true">⭐</div>
			<div class="spar-pro-sales-hero-content">
				<h2><?php esc_html_e( 'Floating Rewards Widget', 'simple-points-and-rewards' ); ?> <span class="spar-pro-badge-inline">PRO</span></h2>
				<p><?php esc_html_e( 'Give every logged-in customer a floating panel on every page that shows their points balance, available earning methods, referral tools, current level, and claimable rewards - without ever leaving the page.', 'simple-points-and-rewards' ); ?></p>
			</div>
		</div>

		<div class="spar-pro-sales-features">
			<?php
			spar_pro_sales_feature_card( 'star-filled', esc_html__( 'Points Balance Tab', 'simple-points-and-rewards' ), esc_html__( 'Customers instantly see their current points balance whenever they visit your store - a constant reminder of their rewards.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'plus-alt2', esc_html__( 'Ways to Earn Tab', 'simple-points-and-rewards' ), esc_html__( 'Lists all active earning methods so customers always know how to earn more - driving repeat purchases and engagement.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'share', esc_html__( 'Referral Tab', 'simple-points-and-rewards' ), esc_html__( 'Customers can copy and share their referral link directly from the widget, turning every page visit into a potential referral.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'awards', esc_html__( 'Levels & Badges Tab', 'simple-points-and-rewards' ), esc_html__( 'Shows the customer\'s current level, badge, and progress toward the next tier - gamifying your loyalty program.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'tickets-alt', esc_html__( 'Redeem Tab', 'simple-points-and-rewards' ), esc_html__( 'Displays claimable rewards so customers can redeem points with a single click - reducing friction at the point of redemption.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'admin-appearance', esc_html__( 'Fully Customisable', 'simple-points-and-rewards' ), esc_html__( 'Choose size, position, colour, icon, tab titles, and display rules. Includes dark mode, pulse animation, and custom icon support.', 'simple-points-and-rewards' ) );
			?>
		</div>

		<?php spar_pro_sales_page_cta(); ?>

	</div>
	<?php
}

// ---------------------------------------------------------------------------
// Guest Points Tracking
// ---------------------------------------------------------------------------

function spar_pro_sales_page_guest_tracking() {
	?>
	<div class="spar-pro-sales-page">

		<div class="spar-pro-sales-hero">
			<div class="spar-pro-sales-hero-icon" aria-hidden="true">👤</div>
			<div class="spar-pro-sales-hero-content">
				<h2><?php esc_html_e( 'Guest Customer Points Tracking', 'simple-points-and-rewards' ); ?> <span class="spar-pro-badge-inline">PRO</span></h2>
				<p><?php esc_html_e( 'Don\'t let guest shoppers leave without a reason to come back. Track points earned on guest orders and seamlessly transfer them when the customer registers - turning one-time buyers into loyal members.', 'simple-points-and-rewards' ); ?></p>
			</div>
		</div>

		<div class="spar-pro-sales-features">
			<?php
			spar_pro_sales_feature_card( 'analytics', esc_html__( 'Automatic Points Tracking', 'simple-points-and-rewards' ), esc_html__( 'Points are calculated and saved against the guest\'s billing email address using the same earn rules as registered customers.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'update', esc_html__( 'Seamless Points Transfer', 'simple-points-and-rewards' ), esc_html__( 'When a guest registers or logs in with the same email address, all accumulated points and activity history are automatically migrated to their account.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'megaphone', esc_html__( 'Thank-you Page Prompt', 'simple-points-and-rewards' ), esc_html__( 'After checkout, guests see an invitation to register or log in to claim their points - instantly encouraging them to become loyal members.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'email-alt', esc_html__( 'Registration Reminder Emails', 'simple-points-and-rewards' ), esc_html__( 'Schedule automated follow-up emails to remind guests of their waiting points balance and encourage them to create an account.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'list-view', esc_html__( 'Guest Points Admin Table', 'simple-points-and-rewards' ), esc_html__( 'View and manage all guest customer point balances from a dedicated admin page, with full visibility over accumulated totals.', 'simple-points-and-rewards' ) );
			spar_pro_sales_feature_card( 'redo', esc_html__( 'Refund & Cancellation Handling', 'simple-points-and-rewards' ), esc_html__( 'Points earned on refunded or cancelled guest orders are automatically deducted, keeping balances accurate.', 'simple-points-and-rewards' ) );
			?>
		</div>

		<?php spar_pro_sales_page_cta(); ?>

	</div>
	<?php
}
