(function($) {
    'use strict';

    $(document).ready(function() {
        var $productSelect = $('#spar_product_id');
        
        // Destroy any existing Select2/SelectWoo instance first
        if ($productSelect.hasClass('select2-hidden-accessible') || $productSelect.data('selectWoo')) {
            $productSelect.selectWoo('destroy');
        }
        
        // Initialize SelectWoo for product selection
        if (typeof $.fn.selectWoo === 'function') {
            $productSelect.selectWoo({
                ajax: {
                    url: sparRedeemParams.ajax_url,
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            action: 'spar_search_products',
                            q: params.term,
                            nonce: sparRedeemParams.nonce
                        };
                    },
                    processResults: function(data) {
                        return data;
                    },
                    cache: true
                },
                placeholder: 'Type to search for a product...',
                minimumInputLength: 2,
                allowClear: true,
                width: '300px',
                dropdownParent: $('#spar_product_fields')
            });
        } else {
            console.warn('SelectWoo not available. Product search will be limited.');
        }
        
        // Handle reward type change
        $('#spar_type').on('change', function() {
            $('#spar_voucher_fields').toggle(this.value === 'voucher');
            $('#spar_product_fields').toggle(this.value === 'product');
        });
    });

})(jQuery);