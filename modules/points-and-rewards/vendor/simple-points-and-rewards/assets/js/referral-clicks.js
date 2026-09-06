(function($){
  'use strict';
  $(document).on('click', '#spar-referral-clicks-container .spar-pagination-btn:not([disabled])', function(e){
    e.preventDefault();
    var $btn = $(this);
    var page = parseInt($btn.data('page'), 10);
    if (!page || page < 1) return;
    var $container = $('#spar-referral-clicks-container');
    var $loading = $container.find('.spar-pagination-loading');
    var $controls = $container.find('.spar-pagination-controls');
    $loading.show();
    $controls.find('.spar-pagination-btn').prop('disabled', true);
    var ajaxUrl = (window.sparReferralClicks && sparReferralClicks.ajaxUrl) || (typeof ajaxurl !== 'undefined' ? ajaxurl : '');
    var nonce = window.sparReferralClicks && sparReferralClicks.nonce;
    $.ajax({
      url: ajaxUrl,
      type: 'POST',
      data: { action: 'spar_load_referral_clicks', page: page, context: 'dashboard', nonce: nonce }
    }).done(function(resp){
      if (resp && resp.success && resp.data){
        if (resp.data.table_html){ $('#spar-referral-clicks-tbody').html(resp.data.table_html); }
        if (resp.data.pagination_html){ $('#spar-referral-clicks-pagination').html(resp.data.pagination_html).show(); }
        else { $('#spar-referral-clicks-pagination').hide(); }
        var $target = $('#spar-referral-clicks-container');
        if ($target.length){ $('html, body').animate({ scrollTop: $target.offset().top - 20 }, 300); }
      } else {
        var msg = (resp && resp.data) ? (resp.data.message || resp.data) : (window.sparReferralClicks && sparReferralClicks.i18n && sparReferralClicks.i18n.error) || 'Error';
        alert(msg);
      }
    }).fail(function(){
      var msg = (window.sparReferralClicks && sparReferralClicks.i18n && sparReferralClicks.i18n.error) || 'Error';
      alert(msg);
    }).always(function(){
      $loading.hide();
      $controls.find('.spar-pagination-btn').prop('disabled', false);
    });
  });
})(jQuery);
