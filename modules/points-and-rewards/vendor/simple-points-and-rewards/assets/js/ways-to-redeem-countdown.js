(function($){
  'use strict';

  function updateRewardCountdowns(i18n){
    $('.spar-reward-countdown').each(function(){
      var $countdown = $(this);
      var expiry = parseInt($countdown.data('expiry'), 10);
      var now = Math.floor(Date.now()/1000);
      var timeLeft = expiry - now;
      if (isNaN(expiry)) return;
      if (timeLeft <= 0){
        $countdown.find('.spar-countdown-text').text((i18n && i18n.expired) || 'Expired');
        return;
      }
      var days = Math.floor(timeLeft/86400);
      var hours = Math.floor((timeLeft%86400)/3600);
      var minutes = Math.floor((timeLeft%3600)/60);
      var seconds = timeLeft%60;
      var timeString = '';
      if (days > 0) timeString = days + 'd ' + hours + 'h';
      else if (hours > 0) timeString = hours + 'h ' + minutes + 'm';
      else if (minutes > 0) timeString = minutes + 'm ' + seconds + 's';
      else timeString = seconds + 's';
      var prefix = (i18n && i18n.expiresIn) || 'Expires in';
      $countdown.find('.spar-countdown-text').text(prefix + ' ' + timeString);
    });
  }

  function onReady(run){ if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); } }
  onReady(function(){
    var cfg = window.sparCountdown || {};
    updateRewardCountdowns(cfg.i18n);
    setInterval(function(){ updateRewardCountdowns(cfg.i18n); }, 1000);
  });
})(jQuery);
