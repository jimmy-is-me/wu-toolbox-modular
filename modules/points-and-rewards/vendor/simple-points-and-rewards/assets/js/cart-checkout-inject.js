(function($){
  function mapCurrencyToSymbol(code){
    try {
      code = (code || '').toUpperCase();
      switch(code){
        case 'USD': case 'AUD': case 'CAD': case 'NZD': case 'HKD': case 'SGD': return '$';
        case 'EUR': return '€';
        case 'GBP': return '£';
        case 'JPY': case 'CNY': return '¥';
        case 'INR': return '₹';
        case 'KRW': return '₩';
        case 'RUB': return '₽';
        case 'CHF': return 'CHF ';
        case 'SEK': return 'kr ';
        case 'NOK': return 'kr ';
        case 'DKK': return 'kr ';
        case 'PLN': return 'zł ';
        case 'CZK': return 'Kč ';
        case 'HUF': return 'Ft ';
        case 'TRY': return '₺';
        case 'ILS': return '₪';
        default: return code ? (code + ' ') : '';
      }
    } catch(e){ return ''; }
  }
  function ready(fn){ if(document.readyState!=='loading'){fn();} else {document.addEventListener('DOMContentLoaded', fn);} }
  ready(function(){
    if (!window.sparCheckoutInject) return;
    var isBlocksCheckout = !!document.querySelector('.wc-block-checkout, .wp-block-woocommerce-checkout');
    var isBlocksCart = !!document.querySelector('.wc-block-cart, .wp-block-woocommerce-cart');
    // Ensure stylesheet present on both Checkout and Cart blocks
    if ((isBlocksCheckout || isBlocksCart) && window.sparCheckoutInject.cssHref) {
      var styleEl = document.getElementById('spar-cart-checkout-rewards-css') || document.querySelector('link[href*="cart-checkout-rewards.css"]');
      if (!styleEl) {
        var link = document.createElement('link');
        link.rel = 'stylesheet';
        link.id = 'spar-cart-checkout-rewards-css';
        link.href = window.sparCheckoutInject.cssHref;
        document.head.appendChild(link);
      }
    }
    // Inject the large Rewards box only on Checkout block
    // if (!isBlocksCheckout) {
      // still allow compact injection on Cart page later below
    // }
    var attempts = 0, maxAttempts = 20;
    function injectRewardsBox(){
      attempts++;
      if (document.querySelector('.spar-cart-rewards-box')) return;
      var tempDiv = document.createElement('div');
      tempDiv.innerHTML = window.sparCheckoutInject.html || '';
      var rewardsBox = tempDiv.firstElementChild;
      var root = document.querySelector('.wc-block-checkout') || document.querySelector('.wp-block-woocommerce-checkout') || document.querySelector('.wc-block-cart') || document.querySelector('.wp-block-woocommerce-cart');
      if (root){
        var mainInRoot = root.querySelector('.wc-block-components-main') || root.querySelector('.wc-block-checkout__main') || root.querySelector('.wc-block-cart__main') || root.querySelector('[class*="__main"]');
        if (mainInRoot){ mainInRoot.insertAdjacentElement('afterbegin', rewardsBox); return; }
        root.insertAdjacentElement('afterbegin', rewardsBox); return;
      }
      var lastResort = document.querySelector('.entry-content');
      if (lastResort){ lastResort.insertAdjacentElement('afterbegin', rewardsBox); return; }
      if (attempts < maxAttempts) setTimeout(injectRewardsBox, 500);
    }
    if (isBlocksCheckout || isBlocksCart) {
      injectRewardsBox();
      if (typeof wp !== 'undefined' && wp.hooks) {
        wp.hooks.addAction('experimental__woocommerce_blocks-checkout-render-checkout-form', 'spar', function(){
          setTimeout(injectRewardsBox, 100);
        });
        // Also try hooking into cart render
        if (isBlocksCart) {
            try {
                wp.hooks.addAction('experimental__woocommerce_blocks-cart-render-cart', 'spar', function(){
                    setTimeout(injectRewardsBox, 100);
                });
            } catch(e){}
        }
      }
    }

    // Also inject a compact Redeem Points UI under the "Add coupons" row in the Order Summary (Blocks)
  function buildRedeemCompactHTML(){
      if (typeof window.sparCartRewards === 'undefined') return '';
      var cfg = window.sparCartRewards;
      var display = (cfg.redeemDisplay || 'totals').toLowerCase();
      if (display !== 'totals' && display !== 'both') return '';
      if (!cfg.redeem || !cfg.redeem.enabled) return '';
      // Respect per-page visibility for the points discount tool (cart vs checkout).
      if (cfg.redeemShowTotals === false) return '';
      var userPoints = parseInt(cfg.userPoints || 0, 10) || 0;
      if (userPoints <= 0) return '';
      var base = cfg.redeem.base || { points: 100, amount: 1, currency: '' };
      var current = (cfg.redeem.current && typeof cfg.redeem.current === 'object') ? cfg.redeem.current : {};
      var curPts = parseInt(current.points || 0, 10) || 0;
      var limitCfg = (cfg.redeem && cfg.redeem.limits) ? cfg.redeem.limits : {};
      var minPts = parseInt(limitCfg.min, 10);
      minPts = isNaN(minPts) ? 0 : Math.max(0, minPts);
      var maxPtsLimit = parseInt(limitCfg.max, 10);
      maxPtsLimit = isNaN(maxPtsLimit) ? 0 : Math.max(0, maxPtsLimit);
      var maxAttr = userPoints;
      if (maxPtsLimit > 0) {
        maxAttr = Math.min(maxPtsLimit, userPoints);
      }
      var sliderMin = minPts > 0 ? minPts : 0;
      if (sliderMin > 0 && maxAttr > 0 && sliderMin > maxAttr) {
        sliderMin = maxAttr;
      }
      var val = curPts > 0 ? curPts : 0;
      if (val > 0) {
        if (sliderMin > 0 && val < sliderMin) {
          val = sliderMin;
        }
        if (maxAttr > 0 && val > maxAttr) {
          val = maxAttr;
        }
      } else if (sliderMin > 0) {
        val = sliderMin;
      }
      var nonce = cfg.pointsRedeemNonce || '';
    var symbol = mapCurrencyToSymbol(base.currency || '');
      var taxMul = parseFloat(cfg.taxMultiplier || '1');
      if (!(taxMul > 0)) taxMul = 1;
      var taxRateVal = parseFloat(cfg.taxRate || '0');
      if (!(taxRateVal >= 0)) taxRateVal = 0;

      var esc = function(s){ return String(s == null ? '' : s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;'); };
      var t = (cfg.strings||{});
      var tRedeem = t.redeemPoints || 'Redeem';
      var tHave = 'You have';
      var tPtsLabel = cfg.pointsLabel || 'Points';
      var tPtsPrefix = cfg.pointsPrefix || '';
      var tPtsIcon = cfg.pointsIconProminent || '';
      var tValue = t.value || 'Discount Value';
  var tAdd = t.add || 'Add Discount to Cart';
      var tRemove = t.remove || 'Remove';
      
      // Get max cart percentage if set
      var maxCartPercentage = (limitCfg && limitCfg.maxCartPercentage) ? parseInt(limitCfg.maxCartPercentage, 10) : 0;
      var redeemTextColor = (cfg.redeemTextColor && cfg.redeemTextColor !== '') ? cfg.redeemTextColor : '';
      var noteColorStyle = redeemTextColor ? 'color:'+redeemTextColor+';' : 'color:#666;';
      var textColorVar = redeemTextColor ? '--spar-redeem-text-color:'+redeemTextColor : '';
      var percentageNote = '';
      if (maxCartPercentage > 0 && t.maxPercentageNote) {
        var noteText = t.maxPercentageNote.replace('%d%%', maxCartPercentage + '%');
        percentageNote = '<p class="spar-redeem-percentage-note" style="font-size:10px;'+noteColorStyle+'margin:10px 0 0 0;text-align:center;font-style:italic;width:100%;display:block;clear:both;padding:0;grid-column:1/-1;">'+esc(noteText)+'</p>';
      }

      // Premium: minimum cart total gate. When the cart is below the minimum, block
      // the slider, input and main apply button and show the notice inside the panel
      // beside the percentage note. The "Redeem" toggle stays clickable so the section
      // can still be opened, mirroring the server-rendered checkout rewards box.
      var belowMin = !!(limitCfg && limitCfg.belowMin);
      var minNoteText = (limitCfg && limitCfg.minCartNote) ? String(limitCfg.minCartNote) : '';
      var blockedClass = belowMin ? ' spar-redeem-blocked' : '';
      var inputDisabled = belowMin ? ' disabled' : '';
      var applyDisabled = ( belowMin || curPts > 0 ) ? ' disabled' : '';
      var minNote = ( belowMin && minNoteText ) ? '<p class="spar-redeem-min-note" style="font-size:10px;'+noteColorStyle+'margin:10px 0 0 0;text-align:center;font-style:italic;width:100%;display:block;clear:both;padding:0;grid-column:1/-1;">'+esc(minNoteText)+'</p>' : '';

      var containerStyle = textColorVar ? ' style="'+textColorVar+'"' : '';
      var html = ''+
        '<div id="spar-redeem-compact-totals" class="spar-redeem-compact spar-redeem-compact--totals'+blockedClass+'" data-currency="'+esc(base.currency)+'" data-currency-symbol="'+esc(symbol)+'"'+containerStyle+'>'
          +'<div class="spar-compact-row wc-block-components-totals-coupon">'
            +'<div class="spar-compact-left">'+esc(tHave)+' '+tPtsIcon+esc(tPtsPrefix + userPoints.toLocaleString())+' '+esc(tPtsLabel)+'</div>'
            +'<button type="button" class="button spar-compact-toggle-btn" aria-expanded="false">'+esc(tRedeem)+'<span class="spar-caret" aria-hidden="true"></span></button>'
          +'</div>'
          +'<div class="spar-redeem-panel wc-block-components-totals-coupon" style="display:none">'
            +'<div class="spar-panel-row">'
              +'<input type="range" class="spar-redeem-slider" min="'+esc(sliderMin)+'" step="1" value="'+esc(val)+'" max="'+esc(maxAttr)+'"'+inputDisabled+' />'
              +'<div class="spar-panel-right">'
                +'<input type="number" class="spar-redeem-input" min="'+esc(sliderMin)+'" step="1" value="'+esc(val)+'" max="'+esc(maxAttr)+'"'+inputDisabled+' />'
              +'</div>'
            +'</div>'
            +'<div class="spar-redeem-actions">'
              +'<div class="spar-discount-box">'
                +'<span class="spar-redeem-value-label">'+esc(tValue)+':</span>'
                +'<span class="spar-redeem-value" data-ppp="'+esc(base.points)+'" data-ppa="'+esc(base.amount)+'" data-currency-symbol="'+esc(symbol)+'" data-tax-multiplier="'+esc(String(taxMul))+'" data-tax-rate="'+esc(String(taxRateVal))+'">'+esc(symbol)+'0.00</span>'
              +'</div>'
              +'<div class="spar-redeem-buttons">'
                +'<button type="button" class="button spar-redeem-apply-btn" data-nonce="'+esc(nonce)+'"'+applyDisabled+'>'+esc(tAdd)+'</button>'
              +'</div>'
            +'</div>'
            +percentageNote
            +minNote
          +'</div>'
        +'</div>';
      return html;
    }

    function placeRedeemCompactUnderCoupons(){
      if (!document.querySelector('.wc-block-checkout, .wp-block-woocommerce-checkout, .wc-block-cart, .wp-block-woocommerce-cart')) return;
      if (document.getElementById('spar-redeem-compact-totals')) return; // already placed
      var html = buildRedeemCompactHTML();
      if (!html) return;
      var wrapper = document.createElement('div');
      wrapper.innerHTML = html;
      var el = wrapper.firstElementChild;
      // Try preferred location: after Coupons row in totals sidebar
      var couponsRow = document.querySelector('.wc-block-components-totals-coupon');
      if (couponsRow && couponsRow.parentNode) {
        couponsRow.parentNode.insertBefore(el, couponsRow.nextSibling);
      } else {
        // Fallback: after Subtotal row
        var subtotalRow = document.querySelector('.wc-block-components-totals-item--subtotal, .wc-block-components-totals-item');
        if (subtotalRow && subtotalRow.parentNode){
          subtotalRow.parentNode.insertBefore(el, subtotalRow.nextSibling);
        } else {
          // Last resort: append to totals wrapper
          var totals = document.querySelector('.wc-block-components-totals');
          if (totals){ totals.appendChild(el); }
        }
      }
      // Trigger preview calc once
      if (window.jQuery) {
        window.jQuery(el).find('.spar-redeem-input').trigger('change');
      }
    }

    // Try immediately and on Blocks render hook
    placeRedeemCompactUnderCoupons();
    var tryCount = 0;
    var tryPlace = function(){ tryCount++; placeRedeemCompactUnderCoupons(); if (tryCount<20) setTimeout(tryPlace, 500); };
    setTimeout(tryPlace, 500);
    if (typeof wp !== 'undefined' && wp.hooks) {
      wp.hooks.addAction('experimental__woocommerce_blocks-checkout-render-order-summary', 'spar', function(){
        setTimeout(placeRedeemCompactUnderCoupons, 100);
      });
      // Best-effort hooks for Cart block (names may change across versions)
      if (wp.hooks.doAction) {
        try {
          wp.hooks.addAction('experimental__woocommerce_blocks-cart-render-cart-items', 'spar', function(){
            setTimeout(placeRedeemCompactUnderCoupons, 100);
          });
        } catch(e){}
        try {
          wp.hooks.addAction('experimental__woocommerce_blocks-cart-render-cart', 'spar', function(){
            setTimeout(placeRedeemCompactUnderCoupons, 100);
          });
        } catch(e){}
      }
    }
    // Observe totals containers for re-renders (both Checkout and Cart blocks)
    var observeTotals = function(){
      var targets = document.querySelectorAll('.wc-block-components-totals');
      targets.forEach(function(t){
        try {
          var mo = new MutationObserver(function(){ placeRedeemCompactUnderCoupons(); });
          mo.observe(t, { childList: true, subtree: true });
        } catch(e){}
      });
    };
    observeTotals();

    // Refresh the injected compact tool's limits/blocked state via AJAX when the
    // block cart total changes, so it blocks/unblocks as the cart crosses the
    // minimum-cart-total threshold (and reflects the current max) without a reload.
    var stateRefreshTimer = null;
    function refreshRedeemCompactState(){
      if (typeof window.sparCartRewards === 'undefined' || !window.jQuery) return;
      var cfg = window.sparCartRewards;
      if (!cfg.ajaxUrl || !cfg.nonce) return;
      window.jQuery.ajax({
        url: cfg.ajaxUrl,
        type: 'POST',
        data: { action: 'spar_get_redeem_state', nonce: cfg.nonce },
        success: function(resp){
          if (!resp || !resp.success || !resp.data) return;
          if (!cfg.redeem) cfg.redeem = {};
          if (resp.data.limits) cfg.redeem.limits = resp.data.limits;
          cfg.redeem.current = resp.data.current || {};
          if (typeof resp.data.userPoints !== 'undefined') cfg.userPoints = resp.data.userPoints;
          // Preserve the open/closed state across the rebuild.
          var existing = document.getElementById('spar-redeem-compact-totals');
          var wasOpen = false;
          if (existing) {
            var oldToggle = existing.querySelector('.spar-compact-toggle-btn');
            wasOpen = !!(oldToggle && oldToggle.getAttribute('aria-expanded') === 'true');
            if (existing.parentNode) existing.parentNode.removeChild(existing);
          }
          placeRedeemCompactUnderCoupons();
          if (wasOpen) {
            var fresh = document.getElementById('spar-redeem-compact-totals');
            if (fresh) {
              var btn = fresh.querySelector('.spar-compact-toggle-btn');
              var panel = fresh.querySelector('.spar-redeem-panel');
              if (btn && panel) {
                btn.setAttribute('aria-expanded', 'true');
                btn.classList.add('is-open');
                panel.style.display = 'block';
                if (window.jQuery) { window.jQuery(fresh).find('.spar-redeem-input').trigger('change'); }
              }
            }
          }
        }
      });
    }
    function scheduleRedeemStateRefresh(){
      if (stateRefreshTimer) clearTimeout(stateRefreshTimer);
      stateRefreshTimer = setTimeout(refreshRedeemCompactState, 450);
    }

    // Detect block cart changes via the WooCommerce Blocks data store and refresh
    // only when the cart contents/total actually change (avoids refresh loops).
    if (typeof wp !== 'undefined' && wp.data && wp.data.subscribe && wp.data.select) {
      var lastCartSig = null;
      wp.data.subscribe(function(){
        try {
          var store = wp.data.select('wc/store/cart');
          if (!store || !store.getCartData) return;
          var cart = store.getCartData();
          if (!cart) return;
          var totals = cart.totals || {};
          var sig = String(totals.total_items || '') + '|' + String(totals.total_items_tax || '') + '|' + String((cart.items || []).length);
          if (lastCartSig === null) { lastCartSig = sig; return; }
          if (sig !== lastCartSig) { lastCartSig = sig; scheduleRedeemStateRefresh(); }
        } catch(e){}
      });
    }
    // Fallback for classic/jQuery-driven cart updates.
    if (window.jQuery) {
      window.jQuery(document.body).on('wc-blocks_added_to_cart wc-blocks_removed_from_cart wc-blocks_cart_updated updated_cart_totals updated_checkout applied_coupon_in_cart removed_coupon_in_cart', function(){
        scheduleRedeemStateRefresh();
      });
    }
  });
})(jQuery);
