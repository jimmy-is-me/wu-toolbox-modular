(function(){
  'use strict';
  function onReady(run){ if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',run);} else {run();} }
  onReady(function(){
    if (typeof window.confetti !== 'function') return;
    try {
      window.confetti({ origin: { y: 0.6 } });
      setTimeout(function(){
        window.confetti({ particleCount: 50, angle: 60, spread: 55, origin: { x: 0 } });
        window.confetti({ particleCount: 50, angle: 120, spread: 55, origin: { x: 1 } });
      }, 300);
    } catch(e) { /* noop */ }
  });
})();
