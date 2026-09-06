/**
 * Admin Tables JavaScript
 * Simple Points and Rewards Plugin
 */

(function($) {
    'use strict';
    
    $(document).ready(function() {
        
        // Enhanced table interactions
        $('.wp-list-table tbody tr').hover(
            function() {
                $(this).addClass('hover');
            },
            function() {
                $(this).removeClass('hover');
            }
        );

        // Points Log Filter Enhancement
        if ($('#spar-log-filter-form').length) {
            // Auto-submit when date fields change (with small delay)
            $('#date_from, #date_to').on('change', function() {
                // Small delay to allow for range selection
                setTimeout(function() {
                    if ($('#date_from').val() || $('#date_to').val()) {
                        $('#spar-log-filter-form').submit();
                    }
                }, 100);
            });

            // Enhanced text input filtering with debounce
            let filterTimeout;
            $('#username, #action_filter').on('input', function() {
                clearTimeout(filterTimeout);
                var $form = $('#spar-log-filter-form');
                filterTimeout = setTimeout(function() {
                    if ($('#username').val().length >= 3 || $('#action_filter').val().length >= 3 || 
                        $('#username').val() === '' || $('#action_filter').val() === '') {
                        $form.submit();
                    }
                }, 500); // Wait 500ms after user stops typing
            });

            // Submit when number inputs change
            $('#min_points, #max_points').on('change', function() {
                $('#spar-log-filter-form').submit();
            });

            // Submit when type filter changes
            $('#type_filter').on('change', function() {
                $('#spar-log-filter-form').submit();
            });

            // Prevent form submission on Enter key for text inputs (to allow debounce to work)
            $('#username, #action_filter').on('keypress', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    clearTimeout(filterTimeout);
                    $('#spar-log-filter-form').submit();
                }
            });

            // Quick filter buttons
            if ($('.spar-log-filters').length) {
                var quickFiltersHtml = '<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid #ddd;">' +
                    '<strong>Quick Filters: </strong>' +
                    '<button type="button" class="button button-small spar-quick-filter" data-action="Order">Orders</button> ' +
                    '<button type="button" class="button button-small spar-quick-filter" data-action="Signup">Signups</button> ' +
                    '<button type="button" class="button button-small spar-quick-filter" data-action="Voucher">Vouchers</button> ' +
                    '<button type="button" class="button button-small spar-quick-filter" data-action="Referral">Referrals</button> ' +
                    '<button type="button" class="button button-small spar-quick-filter" data-type="add">Points Added</button> ' +
                    '<button type="button" class="button button-small spar-quick-filter" data-type="remove">Points Removed</button>' +
                    '</div>';
                
                $('.spar-log-filters form').append(quickFiltersHtml);

                // Handle quick filter clicks
                $('.spar-quick-filter').on('click', function() {
                    var $this = $(this);
                    var action = $this.data('action');
                    var type = $this.data('type');
                    
                    if (action) {
                        $('#action_filter').val(action);
                    }
                    if (type) {
                        $('#type_filter').val(type);
                    }
                    
                    $('#spar-log-filter-form').submit();
                });
            }
        }
        
        // Confirm bulk delete actions
        $('input[name="action"], input[name="action2"]').on('change', function() {
            var action = $(this).val();
            if (action === 'delete') {
                var form = $(this).closest('form');
                form.on('submit', function(e) {
                    var checked = $('input[name="coupon[]"]:checked').length;
                    if (checked > 0) {
                        var message = checked === 1 
                            ? 'Are you sure you want to delete this coupon?' 
                            : 'Are you sure you want to delete ' + checked + ' coupons?';
                        
                        if (!confirm(message)) {
                            e.preventDefault();
                            return false;
                        }
                    }
                });
            }
        });

        // Select all functionality enhancement
        $('#cb-select-all-1, #cb-select-all-2').on('change', function() {
            var checked = $(this).is(':checked');
            $('input[name="coupon[]"]').prop('checked', checked);
        });

    // Customer Points Actions
    if (typeof window.sparCustomerPoints === 'undefined') {
    $('.spar-add-points, .spar-remove-points').on('click', function(e) {
            e.preventDefault();
            
            var $button = $(this);
            var $container = $button.closest('.spar-points-actions');
            var $input = $container.find('.spar-points-input');
            var $reasonInput = $container.find('.spar-points-reason');
            var $loading = $container.find('.spar-points-loading');
            var $pointsDisplay = $('tr').has($container).find('.spar-points-display');
            
            var userId = $container.data('user-id');
            var nonce = $container.data('nonce');
            var points = parseInt($input.val());
            var action = $button.hasClass('spar-add-points') ? 'add' : 'remove';
            var reason = $reasonInput.length ? $reasonInput.val() : '';
            
            if (!points || points <= 0) {
                alert('Please enter a valid number of points.');
                return;
            }
            
            // Show loading
            $container.find('.spar-points-controls').hide();
            $loading.show();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spar_update_customer_points',
                    user_id: userId,
                    points: points,
                    points_action: action,
                    reason: reason,
                    nonce: nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Update points display
                        var $valueSpan = $pointsDisplay.find('.spar-points-value');
                        if ($valueSpan.length) {
                            $valueSpan.text(response.data.new_points.toLocaleString());
                        } else {
                            // Fallback for old structure or if span not found
                             $pointsDisplay.html('<strong>' + response.data.new_points.toLocaleString() + '</strong>');
                        }

                        if (response.data.total_earned !== undefined) {
                            var $totalEarnedDisplay = $('tr').has($container).find('.spar-total-earned-display');
                            var $totalEarnedValue = $totalEarnedDisplay.find('.spar-total-earned-value');
                            if ($totalEarnedValue.length) {
                                $totalEarnedValue.text(response.data.total_earned.toLocaleString());
                            }
                        }

                        $input.val('');
                        if ($reasonInput.length) { $reasonInput.val(''); }
                        
                        // Show success message
                        var message = action === 'add' ? 
                            'Points added successfully!' :
                            'Points removed successfully!';
                        
                        // Create temporary success notice
                        var $notice = $('<div class="notice notice-success is-dismissible"><p>' + message + '</p></div>');
                        $('.wrap h1').after($notice);
                        
                        setTimeout(function() {
                            $notice.fadeOut();
                        }, 3000);
                        
                    } else {
                        alert(response.data || 'An error occurred while updating points.');
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                },
                complete: function() {
                    // Hide loading
                    $loading.hide();
                    $container.find('.spar-points-controls').show();
                }
            });
        });
        }

        // Copy referral code functionality
        $('.spar-copy-code').on('click', function(e) {
            e.preventDefault();
            
            var $this = $(this);
            var code = $this.siblings('.spar-referral-code').data('code');
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(code).then(function() {
                    var originalText = $this.text();
                    $this.text('Copied!');
                    setTimeout(function() {
                        $this.text(originalText);
                    }, 2000);
                });
            } else {
                // Fallback for older browsers
                var textArea = document.createElement('textarea');
                textArea.value = code;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                
                var originalText = $this.text();
                $this.text('Copied!');
                setTimeout(function() {
                    $this.text(originalText);
                }, 2000);
            }
        });

        // Copy referral URL functionality
        $('.spar-copy-url').on('click', function(e) {
            e.preventDefault();
            
            var $this = $(this);
            var url = $this.data('url');
            
            if (navigator.clipboard) {
                navigator.clipboard.writeText(url).then(function() {
                    var originalText = $this.text();
                    $this.text('Copied!');
                    setTimeout(function() {
                        $this.text(originalText);
                    }, 2000);
                });
            } else {
                // Fallback for older browsers
                var textArea = document.createElement('textarea');
                textArea.value = url;
                document.body.appendChild(textArea);
                textArea.select();
                document.execCommand('copy');
                document.body.removeChild(textArea);
                
                var originalText = $this.text();
                $this.text('Copied!');
                setTimeout(function() {
                    $this.text(originalText);
                }, 2000);
            }
        });

    });

})(jQuery);