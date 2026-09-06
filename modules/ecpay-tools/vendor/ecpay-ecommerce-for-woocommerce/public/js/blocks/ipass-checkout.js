const ipass_settings = window.wc.wcSettings.getSetting('Wooecpay_Gateway_Ipass_data', {});

const Ipass_Content = () => {
    return window.wp.htmlEntities.decodeEntities(ipass_settings.description || '');
};
const Ipass_Block_Gateway = {
    name: 'Wooecpay_Gateway_Ipass',
    label: window.wp.htmlEntities.decodeEntities(ipass_settings.title || ''),
    content: Object(window.wp.element.createElement)(Ipass_Content, null),
    edit: Object(window.wp.element.createElement)(Ipass_Content, null),
    canMakePayment: () => true,
    ariaLabel: window.wp.htmlEntities.decodeEntities(ipass_settings.title || ''),
    supports: {
        features: ipass_settings.supports,
    },
};
window.wc.wcBlocksRegistry.registerPaymentMethod(Ipass_Block_Gateway);
