(function ($) {
 'use strict';
 if (window.wcrmUEBadges) return;
 window.wcrmUEBadges = true;
 function mount(scope) {
  if (!scope || !scope.querySelectorAll) return;
  const sources = Array.from(scope.querySelectorAll('.wcrm-ue-source'));
  if (scope.matches && scope.matches('.wcrm-ue-source')) sources.unshift(scope);
  sources.forEach(source => {
   const card = source.closest('.ue-item, .uc_product_grid_item, .uc-product-item, li.product');
   // A custom template without an identifiable item keeps the server-rendered fallback.
   if (!card) return;
   const image = Array.from(card.querySelectorAll('img')).find(img => !img.closest('.wcrm-marks, .uc-product-variation'));
   if (!image) return;
   let anchor = image.parentElement;
   if (anchor.tagName === 'PICTURE') anchor = anchor.parentElement;
   if (!anchor || anchor === card || !card.contains(anchor)) return;
   const marks = source.querySelector('.wcrm-marks');
   if (!marks) return;
   // Deduplicate only our automatic loop output; other plugins' labels remain intact.
   card.querySelectorAll('.wcrm-marks--auto-loop, .wcrm-marks--ue').forEach(node => node.remove());
   source.querySelectorAll('style').forEach(style => {
    if (style.id === 'wcrm-product-marks-inline' && document.head.querySelector('#wcrm-product-marks-inline')) style.remove();
    else document.head.appendChild(style);
   });
   anchor.classList.add('wcrm-mark-anchor');
   if (getComputedStyle(anchor).display === 'inline') anchor.classList.add('wcrm-ue-image-inline');
   marks.classList.add('wcrm-marks--ue');
   marks.dataset.wcrmProduct = source.dataset.wcrmProduct;
   anchor.appendChild(marks);
   source.remove();
  });
 }
 let elementorBound = false;
 function bindElementor() {
  if (elementorBound) return;
  if (!window.elementorFrontend || !elementorFrontend.hooks) return;
  elementorBound = true;
  elementorFrontend.hooks.addAction('frontend/element_ready/global', scope => mount(scope[0]));
 }
 $(function () { mount(document); bindElementor(); });
 $(window).on('elementor/frontend/init.wcrmBadges', bindElementor);
 // UE dispatches this after AJAX filtering/pagination. No polling or site-wide observer.
 $(document).on('uc_ajax_refreshed.wcrmBadges', function (event) { mount(event.target); });
 $('body').on('uc_ajax_refreshed_body.wcrmBadges', function (event, grid) { if (grid) mount(grid.jquery ? grid[0] : grid); });
})(jQuery);
