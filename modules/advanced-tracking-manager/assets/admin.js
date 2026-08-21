/* WU Toolbox Advanced Tracking Manager v2.4.0 - Admin JS */
(function($){
  'use strict';

  // Tab switching
  $(document).on('click', '.atm-tab', function(){
    var tab = $(this).data('tab');
    $('.atm-tab').removeClass('active');
    $(this).addClass('active');
    $('.atm-tab-content').removeClass('active');
    $('#atm-tab-' + tab).addClass('active');
  });

  // Run diagnostics
  $('#atm-run-diag').on('click', function(){
    var $btn     = $(this);
    var $spinner = $('#atm-diag-spinner');
    var $output  = $('#atm-diagnostic-output');
    $btn.prop('disabled', true);
    $spinner.show();
    $output.html('<p style="color:#888;">診斷中，請稍候...</p>');

    $.post(atmData.ajax_url, {
      action: 'atm_run_diagnostics',
      nonce:  atmData.nonce
    }, function(res){
      $btn.prop('disabled', false);
      $spinner.hide();

      if (!res.success) {
        $output.html('<p style="color:red;">診斷失敗：' + (res.data && res.data.message ? res.data.message : '未知錯誤') + '</p>');
        return;
      }

      var d = res.data;
      var html = '<div class="atm-diag-meta">診斷時間：' + d.checked_at
        + '｜前台：' + d.frontend_url
        + '｜狀態碼：' + d.status_code
        + '｜錯誤：' + d.errors
        + '｜警告：' + d.warnings + '</div>';

      var icons = { success: '✅', warning: '⚠️', error: '❌', info: 'ℹ️' };
      $.each(d.items, function(i, item){
        var icon = icons[item.status] || 'ℹ️';
        var cls  = 'atm-diag-' + item.status;
        html += '<div class="atm-diag-item ' + cls + '">';
        html += '<strong>' + icon + ' ' + $('<div>').text(item.title).html() + '</strong><br>';
        html += $('<div>').text(item.message).html();
        if (item.details) {
          html += '<br><span class="atm-diag-detail">' + $('<div>').text(item.details).html() + '</span>';
        }
        html += '</div>';
      });
      $output.html(html);
    }).fail(function(){
      $btn.prop('disabled', false);
      $spinner.hide();
      $output.html('<p style="color:red;">AJAX 請求失敗，請重新整理頁面再試。</p>');
    });
  });

  // Clear diagnostics
  $('#atm-clear-diag').on('click', function(){
    $.post(atmData.ajax_url, {
      action: 'atm_clear_diagnostics',
      nonce:  atmData.nonce
    }, function(){
      $('#atm-diagnostic-output').html('<p style="color:#888;">診斷結果已清除。</p>');
    });
  });

})(jQuery);
