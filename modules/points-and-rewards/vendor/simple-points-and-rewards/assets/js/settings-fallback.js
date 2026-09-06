jQuery(document).ready(function($){
  // Handle checkbox toggles with AJAX save
  $(document).on('change', 'input[data-ajax-save="true"]', function(){
    var $checkbox = $(this);
    var optionKey = $checkbox.attr('name');
    var value = $checkbox.is(':checked');

    // Show saving indicator
    var $saveIndicator = $checkbox.closest('tr, p, label').find('.save-indicator');
    if ($saveIndicator.length === 0) {
      $saveIndicator = $('<span class="save-indicator" style="margin-left: 10px; color: #666;"></span>').insertAfter($checkbox.closest('label'));
    }
    $saveIndicator.text(sparAjax.strings.saving).show();

    // Send AJAX request
    $.ajax({
      url: sparAjax.ajaxurl,
      type: 'POST',
      data: {
        action: 'spar_update_option',
        option_key: optionKey,
        value: value,
        is_checkbox: 'true',
        nonce: sparAjax.nonce
      }
    }).done(function(response){
      if (response && response.success) {
        $saveIndicator.text(sparAjax.strings.saved).fadeOut(2000);
        $(document).trigger('ajaxSuccess', { option_key: optionKey, value: value });
      } else {
        $saveIndicator.text(sparAjax.strings.error).addClass('error');
        $checkbox.prop('checked', !value);
      }
    }).fail(function(){
      $saveIndicator.text(sparAjax.strings.error).addClass('error');
      $checkbox.prop('checked', !value);
    });
  });
});
