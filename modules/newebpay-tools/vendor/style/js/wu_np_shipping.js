/* WU NewebPay: only control our own selected shipping view; never alter another provider's fields. */
jQuery(function ($) {
    const style=document.createElement('style');
    style.textContent='.woocommerce-shipping-fields__field-wrapper.wu-np-selected .form-row{display:none!important}';
    document.head.appendChild(style);
    function sync() {
        const selected=$('input[name^="shipping_method"]:checked,input[type="hidden"][name^="shipping_method"]').toArray()
            .some(input=>input.value.split(':')[0]==='wu_np_newebpay_shipping_cvs');
        $('.woocommerce-shipping-fields__field-wrapper').toggleClass('wu-np-selected',selected);
    }
    $(document.body).on('updated_checkout',sync).on('change','input[name^="shipping_method"]',sync);
    sync();
});
