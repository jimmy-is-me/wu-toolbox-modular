const jkopay_settings = window.wc.wcSettings.getSetting('Wooecpay_Gateway_Jkopay_data', {});

const Jkopay_Content = () => {
    return window.wp.htmlEntities.decodeEntities(jkopay_settings.description || '');
};
const Jkopay_Block_Gateway = {
    name: 'Wooecpay_Gateway_Jkopay',
    label: window.wp.htmlEntities.decodeEntities(jkopay_settings.title || ''),
    content: Object(window.wp.element.createElement)(Jkopay_Content, null),
    edit: Object(window.wp.element.createElement)(Jkopay_Content, null),
    canMakePayment: () => true,
    ariaLabel: window.wp.htmlEntities.decodeEntities(jkopay_settings.title || ''),
    supports: {
        features: jkopay_settings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod(Jkopay_Block_Gateway);