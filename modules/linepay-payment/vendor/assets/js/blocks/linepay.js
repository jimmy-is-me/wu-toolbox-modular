const linepay_settings = window.wc.wcSettings.getSetting( 'linepay-tw_data', {} );
const linepay_label = window.wp.htmlEntities.decodeEntities( linepay_settings.title )
	|| window.wp.i18n.__( 'LINE Pay', 'wpbr-linepay-tw' );

const linepay_content = () => {
	return window.wp.htmlEntities.decodeEntities( linepay_settings.description || '' );
};

const LINEPay_Options = {
	name: 'linepay-tw',
	label: linepay_label,
	content: Object( window.wp.element.createElement )( linepay_content, null ),
	edit: Object( window.wp.element.createElement )( linepay_content, null ),
	canMakePayment: () => true,
	placeOrderButtonLabel: window.wp.i18n.__( 'Pay with LINE Pay', 'wpbr-linepay-tw' ),
	ariaLabel: linepay_label,
	supports: {
		features: linepay_settings.supports,
	},
};
window.wc.wcBlocksRegistry.registerPaymentMethod( LINEPay_Options );
