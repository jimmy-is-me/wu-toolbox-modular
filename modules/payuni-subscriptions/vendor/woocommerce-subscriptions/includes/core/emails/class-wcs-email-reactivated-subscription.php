<?php

/**
 * Reactivated Subscription Email
 *
 * An email sent to the admin when a subscription is reactivated (in the sense that a customer reactivates a
 * subscription which was pending cancellation).
 *
 * @since 8.4.0
 */
class WCS_Email_Reactivated_Subscription extends WC_Email {
	/**
	 * Sets up the email object.
	 */
	public function __construct() {
		$this->id          = 'reactivated_subscription';
		$this->title       = __( 'Reactivated Subscription', 'woocommerce-subscriptions' );
		$this->description = __( 'Reactivated Subscription emails are sent when a customer\'s subscription is reactivated by the customer.', 'woocommerce-subscriptions' );
		$this->heading     = __( 'Subscription Reactivated', 'woocommerce-subscriptions' );
		$this->subject     = sprintf(
			// translators: placeholder is {site_title}, a variable that will be substituted when email is sent out
			_x( '[%s] Subscription Reactivated', 'default email subject for reactivated emails sent to the admin', 'woocommerce-subscriptions' ),
			'{site_title}'
		);

		$this->template_html  = 'emails/reactivated-subscription.php';
		$this->template_plain = 'emails/plain/reactivated-subscription.php';
		$this->template_base  = WC_Subscriptions_Plugin::instance()->get_plugin_directory( 'templates/' );

		add_action( 'reactivated_subscription_notification', array( $this, 'trigger' ) );

		parent::__construct();

		$this->recipient = $this->get_option( 'recipient' );

		if ( ! $this->recipient ) {
			$this->recipient = get_option( 'admin_email' );
		}
	}

	/**
	 * Get the default e-mail subject.
	 *
	 * @return string
	 */
	public function get_default_subject() {
		return $this->subject;
	}

	/**
	 * Get the default e-mail heading.
	 *
	 * @return string
	 */
	public function get_default_heading() {
		return $this->heading;
	}

	/**
	 * Runs when the email is triggered.
	 *
	 * @return void
	 */
	public function trigger( $subscription ) {
		$this->object = $subscription;

		if ( ! is_object( $subscription ) ) {
			_deprecated_argument( __METHOD__, '2.0', 'The subscription key is deprecated. Use a subscription post ID' );
			$subscription = wcs_get_subscription_from_key( $subscription );
		}

		if ( ! $this->is_enabled() || ! $this->get_recipient() || ! $subscription->has_status( 'active' ) ) {
			return;
		}

		$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
	}

	/**
	 * Get the HTML version of the content.
	 *
	 * @return string
	 */
	public function get_content_html() {
		return wc_get_template_html(
			$this->template_html,
			array(
				'subscription'       => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => true,
				'plain_text'         => false,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Get the plain-text version of the content.
	 *
	 * @return string
	 */
	public function get_content_plain() {
		return wc_get_template_html(
			$this->template_plain,
			array(
				'subscription'       => $this->object,
				'email_heading'      => $this->get_heading(),
				'additional_content' => $this->get_additional_content(),
				'sent_to_admin'      => true,
				'plain_text'         => true,
				'email'              => $this,
			),
			'',
			$this->template_base
		);
	}

	/**
	 * Initialise fields used to configure the email.
	 *
	 * @return void
	 */
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled'    => array(
				'title'   => _x( 'Enable/Disable', 'an email notification', 'woocommerce-subscriptions' ),
				'type'    => 'checkbox',
				'label'   => __( 'Enable this email notification', 'woocommerce-subscriptions' ),
				'default' => 'no',
			),
			'recipient'  => array(
				'title'       => _x( 'Recipient(s)', 'of an email', 'woocommerce-subscriptions' ),
				'type'        => 'text',
				// translators: placeholder is admin email
				'description' => sprintf( __( 'Enter recipients (comma separated) for this email. Defaults to %s.', 'woocommerce-subscriptions' ), '<code>' . esc_attr( get_option( 'admin_email' ) ) . '</code>' ),
				'placeholder' => '',
				'default'     => '',
			),
			'subject'    => array(
				'title'       => _x( 'Subject', 'of an email', 'woocommerce-subscriptions' ),
				'type'        => 'text',
				// translators: %s: default e-mail subject.
				'description' => sprintf( __( 'This controls the email subject line. Leave blank to use the default subject: %s.', 'woocommerce-subscriptions' ), '<code>' . $this->subject . '</code>' ),
				'placeholder' => $this->get_default_subject(),
				'default'     => '',
			),
			'heading'    => array(
				'title'       => _x( 'Email Heading', 'Name the setting that controls the main heading contained within the email notification', 'woocommerce-subscriptions' ),
				'type'        => 'text',
				// translators: %s: default e-mail heading.
				'description' => sprintf( __( 'This controls the main heading contained within the email notification. Leave blank to use the default heading: %s.', 'woocommerce-subscriptions' ), '<code>' . $this->heading . '</code>' ),
				'placeholder' => $this->get_default_heading(),
				'default'     => '',
			),
			'email_type' => array(
				'title'       => _x( 'Email type', 'text, html or multipart', 'woocommerce-subscriptions' ),
				'type'        => 'select',
				'description' => __( 'Choose which format of email to send.', 'woocommerce-subscriptions' ),
				'default'     => 'html',
				'class'       => 'email_type wc-enhanced-select',
				'options'     => array(
					'plain'     => _x( 'Plain text', 'email type', 'woocommerce-subscriptions' ),
					'html'      => _x( 'HTML', 'email type', 'woocommerce-subscriptions' ),
					'multipart' => _x( 'Multipart', 'email type', 'woocommerce-subscriptions' ),
				),
			),
		);
	}
}
