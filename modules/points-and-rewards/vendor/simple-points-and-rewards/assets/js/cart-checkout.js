(function($) {
    'use strict';
    
    /**
     * Update points notice when cart changes
     */
    function updatePointsNotice() {
        $.ajax({
            url: sparCartCheckout.ajaxUrl,
            type: 'POST',
            data: {
                action: 'spar_update_cart_points',
                nonce: sparCartCheckout.nonce,
                include_user_level: true
            },
            success: function(response) {
                if (response.success && response.data.notice) {
                    // Remove existing notice
                    $('.spar-block-points-notice').remove();
                    
                    // Add new notice if cart has items
                    if (response.data.notice.trim()) {
                        if ($('.woocommerce-cart-form').length) {
                            $('.woocommerce-cart-form').before(response.data.notice);
                        }
                        if ($('.woocommerce-checkout').length) {
                            $('.woocommerce-checkout').before(response.data.notice);
                        }
                    }
                }
            },
            error: function() {
                // Silently fail - don't show errors for this feature
            }
        });
    }
    
    // Listen for WooCommerce cart update events
    $(document.body).on('updated_cart_totals updated_checkout', function() {
        setTimeout(updatePointsNotice, 100);
    });
    
    // Listen for quantity changes
    $(document).on('change', '.qty', function() {
        setTimeout(updatePointsNotice, 500);
    });
    
})(jQuery);
