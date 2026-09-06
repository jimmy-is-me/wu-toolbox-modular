(function(){
  'use strict';

  function onReady(run){ if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',run);} else {run();} }

  function initProductSelects(context, cfg){
    if (typeof jQuery === 'undefined') return;
    var $ = jQuery;
    var $scope = context ? $(context) : $(document);
    var $selects = $scope.find('.spar-product-select:not([disabled])');
    if (!$selects.length) return;
    var options = {
      ajax: {
        url: (window.ajaxurl || (cfg && cfg.ajaxUrl) || ''),
        dataType: 'json',
        delay: 250,
        data: function (params) {
          return {
            action: 'spar_search_products',
            q: params.term,
            nonce: (cfg && cfg.searchProductsNonce) || ''
          };
        },
        processResults: function (data) {
          return { results: (data && data.data) || [] };
        },
        cache: true
      },
      placeholder: (cfg && cfg.i18n && cfg.i18n.searchProduct) || 'Search for a product...',
      minimumInputLength: 2,
      allowClear: true,
      width: '100%'
    };
    $selects.each(function(){
      var $sel = $(this);
      if ($sel.hasClass('select2-hidden-accessible')) {
        try { $sel.select2('destroy'); } catch(e) {}
        try { $sel.selectWoo('destroy'); } catch(e) {}
      }
      if (typeof $.fn.selectWoo === 'function') $sel.selectWoo(options);
      else if (typeof $.fn.select2 === 'function') $sel.select2(options);
    });
  }

  function initCategorySelects(context, cfg){
    if (typeof jQuery === 'undefined') return;
    var $ = jQuery;
    var $scope = context ? $(context) : $(document);
    var $selects = $scope.find('.spar-category-select:not([disabled])');
    if (!$selects.length) return;
    var options = {
      ajax: {
        url: (window.ajaxurl || (cfg && cfg.ajaxUrl) || ''),
        dataType: 'json',
        delay: 250,
        data: function (params) {
          return {
            action: 'spar_cr_search_categories',
            q: params.term,
            nonce: (cfg && cfg.searchCategoriesNonce) || ''
          };
        },
        processResults: function (data) {
          var results = [];
          if (data && data.success && data.data && data.data.results && jQuery.isArray(data.data.results)) {
            results = data.data.results;
          }
          return { results: results };
        },
        cache: true
      },
      placeholder: (cfg && cfg.i18n && cfg.i18n.searchCategory) || 'Search for a category...',
      minimumInputLength: 1,
      allowClear: true,
      width: '100%'
    };
    $selects.each(function(){
      var $sel = $(this);
      if ($sel.hasClass('select2-hidden-accessible')) {
        try { $sel.select2('destroy'); } catch(e) {}
        try { $sel.selectWoo('destroy'); } catch(e) {}
      }
      if (typeof $.fn.selectWoo === 'function') $sel.selectWoo(options);
      else if (typeof $.fn.select2 === 'function') $sel.select2(options);
    });
  }

  onReady(function(){
  var cfg = window.sparRewardsTab || {};
    var i18n = cfg.i18n || {};
    var badgeIcons = cfg.badgeIcons || {};
    var rewardTypeDefaults = cfg.rewardTypeDefaults || { voucher: 'fa-solid fa-ticket', product: 'fa-solid fa-gift', product_bundle: 'fa-solid fa-gifts', custom: 'fa-solid fa-gear' };
    var isPro = !!cfg.isPro;
  var rewardsPanel = document.getElementById('spar-rewards-settings-panel');
  var disabledNotice = document.getElementById('spar-rewards-disabled-notice');
  var vouchersToggle = document.getElementById('spar-rewards-vouchers-enabled');
  var vouchersLabel = document.querySelector('label[for="spar-rewards-vouchers-enabled"]');

    function escapeHtml(value){
      return String(value == null ? '' : value).replace(/[&<>"']/g, function(char){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
      });
    }

    function getFontAwesomeIconClass(value){
      var raw = String(value || '').trim().replace(/\s+/g, ' ');
      if (!raw) return '';
      var classes = raw.split(' ');
      var safe = [];
      var hasType = false;
      var hasIcon = false;
      classes.forEach(function(className){
        className = String(className || '').toLowerCase();
        if (!/^fa-[a-z0-9-]+$/.test(className)) return;
        if (className === 'fa-solid' || className === 'fa-regular' || className === 'fa-brands') {
          hasType = true;
        } else {
          hasIcon = true;
        }
        if (safe.indexOf(className) === -1) {
          safe.push(className);
        }
      });
      return (hasType && hasIcon) ? safe.join(' ') : '';
    }

    function getSafeHexColor(value){
      var color = String(value || '').trim();
      return /^#(?:[a-f0-9]{3}){1,2}$/i.test(color) ? color : '';
    }

    function getBadgeOptionLabel(icon, label){
      return getFontAwesomeIconClass(icon) ? label : (icon + ' ' + label);
    }

    function getBadgePreviewHtml(icon, iconClass, iconColor, useGradient){
      var faClass = getFontAwesomeIconClass(icon);
      if (faClass) {
        if (useGradient) {
          return '<span class="' + escapeHtml((iconClass || 'spar-level-badge-icon') + ' spar-fa-icon ' + faClass + ' spar-badge-icon--gradient') + '" aria-hidden="true"></span>';
        }
        var color = getSafeHexColor(iconColor);
        var style = color ? ' style="color:' + escapeHtml(color) + ';"' : '';
        return '<span class="' + escapeHtml(iconClass || 'spar-level-badge-icon') + ' spar-fa-icon ' + escapeHtml(faClass) + '" aria-hidden="true"' + style + '></span>';
      }
      return escapeHtml(icon || '');
    }

    function setBadgePreview(preview, icon, iconClass, iconColor, useGradient){
      if (!preview) return;
      preview.innerHTML = getBadgePreviewHtml(icon, iconClass, iconColor, useGradient);
    }

    var didInitialSync = false;
    function syncRewardsPanelState(enabled){
      var hasJQ = typeof jQuery !== 'undefined';
      // Panel
      if (rewardsPanel){
        if (!didInitialSync){
          // First paint: no animation to avoid layout shift
          rewardsPanel.classList.toggle('spar-hidden', !enabled);
          rewardsPanel.style.display = enabled ? '' : 'none';
        } else if (hasJQ){
          var $panel = jQuery(rewardsPanel);
          if (enabled){
            $panel.stop(true,true).hide().removeClass('spar-hidden').slideDown(200, function(){ this.style.display=''; });
          } else {
            $panel.stop(true,true).slideUp(200, function(){ this.classList.add('spar-hidden'); this.style.display='none'; });
          }
        } else {
          // Fallback without jQuery
          rewardsPanel.classList.toggle('spar-hidden', !enabled);
          rewardsPanel.style.display = enabled ? '' : 'none';
        }
      }
      // Notice
      if (disabledNotice){
        if (!didInitialSync){
          disabledNotice.classList.toggle('spar-hidden', enabled);
          disabledNotice.style.display = enabled ? 'none' : '';
        } else if (hasJQ){
          var $notice = jQuery(disabledNotice);
          if (enabled){
            $notice.stop(true,true).slideUp(200, function(){ this.classList.add('spar-hidden'); this.style.display='none'; });
          } else {
            $notice.stop(true,true).hide().removeClass('spar-hidden').slideDown(200, function(){ this.style.display=''; });
          }
        } else {
          disabledNotice.classList.toggle('spar-hidden', enabled);
          disabledNotice.style.display = enabled ? 'none' : '';
        }
      }
      didInitialSync = true;
    }

    // Initial sync; if toggle not yet present, attempt delayed binding
    syncRewardsPanelState(!vouchersToggle || vouchersToggle.checked);
    document.addEventListener('change', function(event){
      var target = event.target;
      if (target && target.id === 'spar-rewards-vouchers-enabled') {
        syncRewardsPanelState(!!target.checked);
      }
    }, true);

    document.addEventListener('click', function(event){
      var target = event.target;
      if (target && target.id === 'spar-rewards-vouchers-enabled') {
        setTimeout(function(){
          syncRewardsPanelState(!!target.checked);
        }, 0);
      }
    }, true);

    var lastToggleState = vouchersToggle ? !!vouchersToggle.checked : null;

    if (vouchersToggle){
      vouchersToggle.addEventListener('change', function(){
        syncRewardsPanelState(!!this.checked);
      });
      vouchersToggle.addEventListener('input', function(){
        syncRewardsPanelState(!!this.checked);
      });
      vouchersToggle.addEventListener('click', function(){
        var toggle = this;
        setTimeout(function(){
          syncRewardsPanelState(!!toggle.checked);
        }, 0);
      });
    } else {
      // Retry a few times in case markup loads late
      var tries = 0;
      var retryBind = setInterval(function(){
        vouchersToggle = document.getElementById('spar-rewards-vouchers-enabled');
        if (vouchersToggle){
          clearInterval(retryBind);
          syncRewardsPanelState(!!vouchersToggle.checked);
          vouchersToggle.addEventListener('change', function(){
            syncRewardsPanelState(!!this.checked);
          });
          vouchersToggle.addEventListener('input', function(){
            syncRewardsPanelState(!!this.checked);
          });
          vouchersToggle.addEventListener('click', function(){
            var toggle = this;
            setTimeout(function(){
              syncRewardsPanelState(!!toggle.checked);
            }, 0);
          });
        } else if (++tries > 20){
          clearInterval(retryBind);
        }
      }, 200);
    }

    // Poll for state changes in case events are blocked elsewhere.
    setInterval(function(){
      var current = document.getElementById('spar-rewards-vouchers-enabled');
      if (!current) {
        return;
      }
      var checked = !!current.checked;
      if (lastToggleState === null) {
        lastToggleState = checked;
        return;
      }
      if (checked !== lastToggleState) {
        lastToggleState = checked;
        syncRewardsPanelState(checked);
      }
    }, 250);

    // Also listen to label clicks to ensure state reflects immediately
    if (vouchersLabel){
      vouchersLabel.addEventListener('click', function(){
        setTimeout(function(){
          var t = document.getElementById('spar-rewards-vouchers-enabled');
          if (t) syncRewardsPanelState(!!t.checked);
        }, 0);
      });
    }

    function syncRedeemDiscountTaxSettings(){
      var taxableToggle = document.getElementById('spar-redeem-discount-fee-taxable');
      var settings = document.getElementById('spar-redeem-discount-fee-tax-settings');
      var modeSelect = document.getElementById('spar-redeem-discount-fee-tax-class-mode');
      var classWrap = document.getElementById('spar-redeem-discount-fee-tax-class-wrap');
      var showSettings = !!(taxableToggle && taxableToggle.checked);
      var showClass = !!(showSettings && modeSelect && modeSelect.value === 'custom');

      if (settings) {
        settings.classList.toggle('spar-hidden', !showSettings);
        settings.style.display = showSettings ? '' : 'none';
      }
      if (classWrap) {
        classWrap.classList.toggle('spar-hidden', !showClass);
        classWrap.style.display = showClass ? '' : 'none';
      }
    }

    syncRedeemDiscountTaxSettings();
    document.addEventListener('change', function(event){
      var target = event.target;
      if (
        target &&
        (target.id === 'spar-redeem-discount-fee-taxable' || target.id === 'spar-redeem-discount-fee-tax-class-mode')
      ) {
        syncRedeemDiscountTaxSettings();
      }
    });

    document.addEventListener('click', function(event){
      var toggle = event.target.closest ? event.target.closest('.spar-toggle-switch') : null;
      if (!toggle || !toggle.querySelector('#spar-redeem-discount-fee-taxable')) return;
      setTimeout(syncRedeemDiscountTaxSettings, 0);
    });

    function updateAddRewardButton(){
      var addButton = document.getElementById('spar-add-reward');
      if (!addButton) return;
      var maxRewards = parseInt(cfg.maxRewards, 10);
      if (isNaN(maxRewards)) { maxRewards = 3; }
      var list = document.getElementById('spar-rewards-list');
      var count = list ? list.querySelectorAll('.spar-reward-item').length : 0;
      var atMax = count >= maxRewards;
      addButton.disabled = atMax;
      addButton.title = atMax ? (i18n.maxRewardsReached || '') : '';
      addButton.classList.toggle('spar-disabled-button', atMax);
      var baseLabel = addButton.textContent.replace(/\s*\(PRO\)\s*$/, '').trim();
      addButton.textContent = atMax ? baseLabel + ' (PRO)' : baseLabel;
    }

    function getRewardSelectedBadgeType(value){
      if (value === 'custom_url') return 'custom_url';
      if (value === 'custom_text') return 'custom_text';
      if (value === 'product_image') return 'product_image';
      if (value !== '') return 'preset';
      return '';
    }

    // Show/hide the "Product Image" option in the Reward Icon select depending on
    // whether the reward type is a product / product bundle. When hidden and it
    // was selected, fall back to the default icon option.
    function syncProductImageOption(rewardItem, isProductLike){
      if (!rewardItem) return;
      var container = rewardItem.querySelector('.spar-reward-content');
      if (!container) return;
      var select = container.querySelector('.spar-reward-badge-select');
      if (!select) return;
      var opt = select.querySelector('option.spar-product-image-option');
      if (!opt) return;
      if (isProductLike) {
        opt.disabled = false;
        opt.hidden = false;
      } else {
        if (select.value === 'product_image') {
          select.value = '';
        }
        opt.disabled = true;
        opt.hidden = true;
      }
    }

    function updateRewardBadgeControls(rewardItem){
      if (!rewardItem) return;
      var container = rewardItem.querySelector('.spar-reward-content');
      if (!container) return;
      var select    = container.querySelector('.spar-reward-badge-select');
      var typeField = container.querySelector('.spar-reward-badge-type-field');
      var selectedValue = select ? select.value : '';
      var badgeType = getRewardSelectedBadgeType(selectedValue);
      var effectiveIcon = (badgeType === 'preset' && selectedValue) ? selectedValue : getRewardTypeDefaultIcon(rewardItem);
      var colorWrap = container.querySelector('.spar-reward-badge-color-col');
      var colorInput = container.querySelector('.spar-reward-badge-color-input');
      var modeSelect = container.querySelector('.spar-reward-badge-color-mode-select');
      var showColor = badgeType !== 'product_image' && (badgeType === 'custom_text' || (badgeType !== 'custom_url' && !!getFontAwesomeIconClass(effectiveIcon)));
      var colorMode = (modeSelect && showColor) ? modeSelect.value : 'default';
      var useGradient = (colorMode !== 'custom');
      var iconColor = (showColor && !useGradient && colorInput) ? getSafeHexColor(colorInput.value) : '';

      if (typeField) {
        // "product_image" is a UI-only sentinel; the stored badge stays default.
        typeField.value = (badgeType === 'product_image') ? '' : badgeType;
      }

      if (colorWrap) {
        colorWrap.classList.toggle('spar-hidden', !showColor);
        colorWrap.style.display = showColor ? '' : 'none';
      }
      if (modeSelect) {
        modeSelect.disabled = !showColor;
      }
      if (colorInput) {
        var showPicker = showColor && !useGradient;
        colorInput.style.display = showPicker ? '' : 'none';
        colorInput.disabled = !showPicker;
      }

      var urlWrap  = container.querySelector('.spar-reward-badge-url-row');
      var textWrap = container.querySelector('.spar-reward-badge-text-row');
      if (urlWrap) {
        urlWrap.classList.toggle('spar-hidden', badgeType !== 'custom_url');
        urlWrap.style.display = (badgeType === 'custom_url') ? '' : 'none';
      }
      if (textWrap) {
        textWrap.classList.toggle('spar-hidden', badgeType !== 'custom_text');
        textWrap.style.display = (badgeType === 'custom_text') ? '' : 'none';
      }

      var typeBadge = rewardItem.querySelector('.spar-reward-type-badge');
      if (!typeBadge) return;

      if (badgeType === 'product_image') {
        // Keep any existing (server-rendered) preview visible while the image
        // request resolves to avoid a flash back to the default icon.
        typeBadge.style.color = '';
        typeBadge.className = 'spar-reward-type-badge';
        renderRewardProductImagePreview(rewardItem, typeBadge, effectiveIcon, iconColor, useGradient);
        return;
      }

      while (typeBadge.firstChild) {
        typeBadge.removeChild(typeBadge.firstChild);
      }
      typeBadge.style.color = '';
      typeBadge.className = 'spar-reward-type-badge';
      // Invalidate any in-flight product-image preview request for this badge.
      if (typeBadge.dataset) { delete typeBadge.dataset.sparPreviewToken; }

      if (badgeType === 'custom_url') {
        var urlInput = container.querySelector('.spar-reward-badge-url-input');
        var url = urlInput ? urlInput.value.trim() : '';
        if (url && /^https?:\/\//i.test(url)) {
          var img = document.createElement('img');
          img.src = url;
          img.alt = '';
          img.className = 'spar-level-badge-img';
          typeBadge.appendChild(img);
        } else {
          typeBadge.textContent = '🖼️';
        }
      } else if (badgeType === 'custom_text') {
        var textInput = container.querySelector('.spar-reward-badge-text-input');
        if (useGradient) {
          typeBadge.className = 'spar-reward-type-badge spar-badge-icon--gradient';
          typeBadge.style.color = '';
        } else {
          typeBadge.className = 'spar-reward-type-badge';
          typeBadge.style.color = iconColor || '';
        }
        typeBadge.textContent = (textInput && textInput.value) ? textInput.value : '\u270F\uFE0F';
      } else if (badgeType === 'preset' && select) {
        setBadgePreview(typeBadge, effectiveIcon, 'spar-level-badge-icon', iconColor, useGradient);
      } else {
        // No icon selected – fall back to type default
        setBadgePreview(typeBadge, effectiveIcon, 'spar-level-badge-icon', iconColor, useGradient);
      }
    }

    function getRewardTypeDefaultIcon(rewardItem){
      var typeSelect = rewardItem.querySelector('.reward-type-select');
      var type = typeSelect ? typeSelect.value : 'voucher';
      return rewardTypeDefaults[type] || 'fa-solid fa-ticket';
    }

    // Collect the selected product IDs for a reward item (single product or bundle).
    function getRewardSelectedProductIds(rewardItem){
      var typeSelect = rewardItem.querySelector('.reward-type-select');
      var type = typeSelect ? typeSelect.value : '';
      var ids = [];
      if (type === 'product') {
        var single = rewardItem.querySelector('.spar-product-settings select[name*="[product_id]"]');
        if (single && single.value) { ids.push(single.value); }
      } else if (type === 'product_bundle') {
        var bundle = rewardItem.querySelector('.spar-bundle-settings select[name*="[bundle_product_ids]"]');
        if (bundle) {
          Array.prototype.forEach.call(bundle.options, function(opt){
            if (opt.selected && opt.value) { ids.push(opt.value); }
          });
        }
      }
      return { type: type, ids: ids };
    }

    // Auto-rotating carousel for the bundle image preview (mirrors the dashboard).
    function initRewardPreviewCarousel(scope){
      if (!scope || !scope.querySelectorAll) return;
      var carousels = scope.querySelectorAll('[data-spar-bundle-carousel]');
      Array.prototype.forEach.call(carousels, function(carousel){
        if (carousel.dataset.sparCarouselInit) return;
        carousel.dataset.sparCarouselInit = '1';
        var slides = carousel.querySelectorAll('.spar-bundle-carousel-slide');
        var dots   = carousel.querySelectorAll('.spar-bundle-carousel-dot');
        if (slides.length < 2) return;
        var current = 0;
        function show(index){
          current = (index + slides.length) % slides.length;
          for (var i = 0; i < slides.length; i++){
            slides[i].classList.toggle('is-active', i === current);
            if (dots[i]) { dots[i].classList.toggle('is-active', i === current); }
          }
        }
        Array.prototype.forEach.call(dots, function(dot){
          dot.addEventListener('click', function(){
            var idx = parseInt(dot.getAttribute('data-slide'), 10) || 0;
            show(idx); restart();
          });
        });
        var timer = null;
        function restart(){
          if (timer) { clearInterval(timer); }
          timer = setInterval(function(){ show(current + 1); }, 2500);
        }
        restart();
      });
    }

    // Render the selected product image (or bundle carousel) into the accordion
    // preview badge, matching the rewards dashboard output. Falls back to the
    // default type icon when no product is selected or the request fails.
    function renderRewardProductImagePreview(rewardItem, typeBadge, fallbackIcon, iconColor, useGradient){
      var selection = getRewardSelectedProductIds(rewardItem);
      if (!selection.ids.length || typeof jQuery === 'undefined') {
        setBadgePreview(typeBadge, fallbackIcon, 'spar-level-badge-icon', iconColor, useGradient);
        return;
      }
      var token = String(Date.now()) + '-' + Math.random();
      typeBadge.dataset.sparPreviewToken = token;
      jQuery.ajax({
        url: (cfg.ajaxUrl || window.ajaxurl || ''),
        method: 'POST',
        dataType: 'json',
        data: {
          action: 'spar_get_reward_preview_image',
          nonce: cfg.searchProductsNonce || '',
          type: selection.type,
          product_ids: selection.ids
        }
      }).done(function(resp){
        // Ignore stale responses (selection changed before this returned).
        if (typeBadge.dataset.sparPreviewToken !== token) return;
        var html = (resp && resp.data && resp.data.html) ? resp.data.html : '';
        if (html) {
          typeBadge.innerHTML = html;
          initRewardPreviewCarousel(typeBadge);
        } else {
          setBadgePreview(typeBadge, fallbackIcon, 'spar-level-badge-icon', iconColor, useGradient);
        }
      }).fail(function(){
        if (typeBadge.dataset.sparPreviewToken !== token) return;
        setBadgePreview(typeBadge, fallbackIcon, 'spar-level-badge-icon', iconColor, useGradient);
      });
    }

    function initRewardBadgeControls(root){
      var scope = root || document;
      var items = scope.querySelectorAll ? scope.querySelectorAll('.spar-reward-item') : [];
      Array.prototype.forEach.call(items, function(rewardItem){
        var container = rewardItem.querySelector('.spar-reward-content');
        if (!container) return;
        var select = container.querySelector('.spar-reward-badge-select');
        if (select && !select.dataset.sparBadgeBound) {
          select.dataset.sparBadgeBound = '1';
          select.addEventListener('change', function(){ updateRewardBadgeControls(rewardItem); });
        }
        var urlInput = container.querySelector('.spar-reward-badge-url-input');
        if (urlInput && !urlInput.dataset.sparBadgeBound) {
          urlInput.dataset.sparBadgeBound = '1';
          urlInput.addEventListener('input', function(){ updateRewardBadgeControls(rewardItem); });
        }
        var textInput = container.querySelector('.spar-reward-badge-text-input');
        if (textInput && !textInput.dataset.sparBadgeBound) {
          textInput.dataset.sparBadgeBound = '1';
          textInput.addEventListener('input', function(){ updateRewardBadgeControls(rewardItem); });
        }
        var colorInput = container.querySelector('.spar-reward-badge-color-input');
        if (colorInput && !colorInput.dataset.sparBadgeBound) {
          colorInput.dataset.sparBadgeBound = '1';
          colorInput.addEventListener('input', function(){ updateRewardBadgeControls(rewardItem); });
          colorInput.addEventListener('change', function(){ updateRewardBadgeControls(rewardItem); });
        }
        var modeSelect = container.querySelector('.spar-reward-badge-color-mode-select');
        if (modeSelect && !modeSelect.dataset.sparBadgeBound) {
          modeSelect.dataset.sparBadgeBound = '1';
          modeSelect.addEventListener('change', function(){ updateRewardBadgeControls(rewardItem); });
        }
        // Refresh the preview when the selected product(s) change while the
        // "Product Image" icon option is active.
        var productSelects = container.querySelectorAll('.spar-product-select');
        Array.prototype.forEach.call(productSelects, function(ps){
          if (ps.dataset.sparBadgeBound) return;
          ps.dataset.sparBadgeBound = '1';
          if (typeof jQuery !== 'undefined') {
            jQuery(ps).on('change', function(){ updateRewardBadgeControls(rewardItem); });
          } else {
            ps.addEventListener('change', function(){ updateRewardBadgeControls(rewardItem); });
          }
        });
        updateRewardBadgeControls(rewardItem);
      });
    }

    // Sanitise a URL for safe use inside an HTML attribute (href).
    // Only allows absolute http(s) URLs. Returns the URL HTML-attribute-encoded,
    // or an empty string if the scheme is not http(s).
    function sanitiseUrl(url){
      if (typeof url !== 'string') return '';
      var trimmed = url.replace(/^\s+|\s+$/g, '');
      if (!/^https?:\/\//i.test(trimmed)) return '';
      return trimmed.replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function initTemplateCouponEditLinks(context) {
      var $ = (typeof jQuery !== 'undefined') ? jQuery : null;
      var scope = context || document;
      var selects = scope.querySelectorAll ? scope.querySelectorAll('.spar-reward-template-coupon') : [];
      if (!selects.length) return;
      Array.prototype.forEach.call(selects, function(select){
        // Derive the target link div from the select's id, e.g. spar-reward-template-coupon-0 -> spar-reward-template-coupon-link-0
        var targetId = select.id ? select.id.replace('spar-reward-template-coupon-', 'spar-reward-template-coupon-link-') : '';
        var target = targetId ? document.getElementById(targetId) : null;
        if (!target) return;
        function updateCreateBtn(){
          var createBtn = select.closest('.spar-field-group') ? select.closest('.spar-field-group').querySelector('.spar-create-template-coupon') : null;
          if (createBtn) {
            var wrapper = createBtn.parentNode;
            if (wrapper) {
              wrapper.style.display = (select.value && select.value !== '0') ? 'none' : '';
            }
          }
        }
        function updateLink(){
          var id = select.value;
          updateCreateBtn();
          if (!id || id === '0') {
            target.innerHTML = '<em>' + (i18n.templateCouponNoSelected || 'No template selected.') + '</em>';
            return;
          }
          target.innerHTML = '<em>' + (i18n.templateCouponLoading || 'Loading…') + '</em>';
          if (!$) return;
          $.ajax({
            url: (cfg.ajaxUrl || window.ajaxurl || ''),
            type: 'POST',
            dataType: 'json',
            data: {
              action: 'spar_get_coupon_edit_link',
              coupon_id: id,
              nonce: (cfg.couponEditLinkNonce || '')
            }
          }).done(function(resp){
            if (resp && resp.success && resp.data && resp.data.url) {
              var safeUrl  = sanitiseUrl(resp.data.url);
              var safeText = resp.data.text || 'Edit coupon';
              if (safeUrl) {
                target.innerHTML = '<a href="' + safeUrl + '" target="_blank" rel="noopener noreferrer">' + $('<div>').text(safeText).html() + '</a>';
              } else {
                target.innerHTML = '<em>' + $('<div>').text(safeText).html() + '</em>';
              }
            } else if (resp && resp.data && resp.data.text) {
              target.innerHTML = '<em>' + $('<div>').text(resp.data.text).html() + '</em>';
            } else {
              target.innerHTML = '<em>' + (i18n.templateCouponError || 'Error.') + '</em>';
            }
          }).fail(function(){
            target.innerHTML = '<em>' + (i18n.templateCouponError || 'Error.') + '</em>';
          });
        }
        select.addEventListener('change', updateLink);
        updateLink();
      });
    }

    // Init edit links for existing reward items
    initTemplateCouponEditLinks(null);

    // Delegated handler for "Enable Template Coupon" checkbox
    function handleTemplateCouponToggle(cb) {
      if (!cb || !cb.classList.contains('spar-enable-template-coupon')) return;
      var fieldGroup = cb.closest('.spar-field-group');
      if (!fieldGroup) return;
      var section = fieldGroup.nextElementSibling;
      if (!section || !section.classList.contains('spar-template-coupon-section')) return;
      if (cb.checked) {
        section.classList.remove('spar-hidden');
        section.style.display = '';
      } else {
        section.classList.add('spar-hidden');
        section.style.display = 'none';
      }
    }
    document.addEventListener('change', function(e){
      handleTemplateCouponToggle(e.target);
    });
    // Also handle click on the toggle slider/label in case change doesn't fire
    document.addEventListener('click', function(e){
      var slider = e.target.closest('.spar-toggle-switch');
      if (!slider) return;
      var cb = slider.querySelector('.spar-enable-template-coupon');
      if (!cb) return;
      // Use setTimeout so the checked state has updated before we read it
      setTimeout(function(){ handleTemplateCouponToggle(cb); }, 0);
    });

    // Delegated handler for "Create New Template" buttons
    document.addEventListener('click', function(e){
      var btn = e.target.closest('.spar-create-template-coupon');
      if (!btn) return;
      e.preventDefault();
      var $ = (typeof jQuery !== 'undefined') ? jQuery : null;
      if (!$) return;
      var rewardItem = btn.closest('.spar-reward-item');
      if (!rewardItem) return;
      var select = rewardItem.querySelector('.spar-reward-template-coupon');
      if (!select) return;
      btn.disabled = true;
      btn.textContent = i18n.creatingTemplate || 'Creating…';
      $.ajax({
        url: (cfg.ajaxUrl || window.ajaxurl || ''),
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'spar_create_template_coupon',
          nonce: (cfg.createTemplateCouponNonce || '')
        }
      }).done(function(resp){
        if (resp && resp.success && resp.data) {
          var opt = document.createElement('option');
          opt.value = String(resp.data.coupon_id);
          opt.textContent = resp.data.coupon_title + ' (#' + resp.data.coupon_id + ')';
          select.appendChild(opt);
          select.value = String(resp.data.coupon_id);
          // Trigger change so the edit link updates
          var evt = document.createEvent('HTMLEvents');
          evt.initEvent('change', true, false);
          select.dispatchEvent(evt);
        } else {
          var msg = (resp && resp.data && typeof resp.data === 'string') ? resp.data : (i18n.templateCouponError || 'Error.');
          alert(msg);
        }
      }).fail(function(){
        alert(i18n.templateCouponError || 'Error.');
      }).always(function(){
        btn.disabled = false;
        btn.textContent = i18n.createNewTemplate || 'Create New Template';
      });
    });

    function updateFieldValidation(rewardItem){
      var typeSelect = rewardItem.querySelector('.reward-type-select');
      if (!typeSelect) return;
      var voucherSettings = rewardItem.querySelector('.spar-voucher-settings');
      var productSettings = rewardItem.querySelector('.spar-product-settings');
      var bundleSettings  = rewardItem.querySelector('.spar-bundle-settings');
      var customSettings  = rewardItem.querySelector('.spar-custom-settings');
      var showVoucher = typeSelect.value === 'voucher';
      var showProduct = typeSelect.value === 'product';
      var showBundle  = typeSelect.value === 'product_bundle';
      var showCustom  = typeSelect.value === 'custom';
      if (voucherSettings) {
        voucherSettings.classList.toggle('spar-hidden', !showVoucher);
        voucherSettings.style.display = showVoucher ? '' : 'none';
      }
      if (productSettings) {
        productSettings.classList.toggle('spar-hidden', !showProduct);
        productSettings.style.display = showProduct ? '' : 'none';
      }
      if (bundleSettings) {
        bundleSettings.classList.toggle('spar-hidden', !showBundle);
        bundleSettings.style.display = showBundle ? '' : 'none';
      }
      if (customSettings) {
        customSettings.classList.toggle('spar-hidden', !showCustom);
        customSettings.style.display  = showCustom  ? '' : 'none';
      }
      syncProductImageOption(rewardItem, showProduct || showBundle);
      var productValueRow = rewardItem.querySelector('.spar-reward-product-value-row');
      var showProductValueRow = showProduct || showBundle;
      if (productValueRow) {
        productValueRow.classList.toggle('spar-hidden', !showProductValueRow);
        productValueRow.style.display = showProductValueRow ? '' : 'none';
      }
      // Update header badge icon – if no custom icon is set, auto-reflect the new type default
      updateRewardBadgeControls(rewardItem);
      // Enable/disable required fields based on type
      var voucherAmountField = voucherSettings && voucherSettings.querySelector('input[name*="[voucher_amount]"]');
      var productSelect = productSettings && productSettings.querySelector('select[name*="[product_id]"]');
      var bundleSelect = bundleSettings && bundleSettings.querySelector('select[name*="[bundle_product_ids]"]');
      var developerIdField = customSettings && customSettings.querySelector('input[name*="[developer_id]"]');
      if (voucherAmountField){
        if (showVoucher){ voucherAmountField.removeAttribute('disabled'); voucherAmountField.setAttribute('required','required'); }
        else { voucherAmountField.setAttribute('disabled','disabled'); voucherAmountField.removeAttribute('required'); }
      }
      if (productSelect){
        if (showProduct){ productSelect.removeAttribute('disabled'); productSelect.setAttribute('required','required'); }
        else { productSelect.setAttribute('disabled','disabled'); productSelect.removeAttribute('required'); }
      }
      if (bundleSelect){
        if (showBundle){ bundleSelect.removeAttribute('disabled'); bundleSelect.setAttribute('required','required'); }
        else { bundleSelect.setAttribute('disabled','disabled'); bundleSelect.removeAttribute('required'); }
      }
      if (developerIdField){
        if (showCustom){ developerIdField.removeAttribute('disabled'); }
        else { developerIdField.removeAttribute('required'); }
      }
    }

    // Initialize existing
    document.querySelectorAll('#spar-rewards-list .spar-reward-item').forEach(updateFieldValidation);
    if (typeof updateAddRewardButton === 'function') {} // no-op linter
    updateAddRewardButton();
    initProductSelects(null, cfg);
    initCategorySelects(null, cfg);
    initRewardBadgeControls(null);

    // Add new reward
    var addBtn = document.getElementById('spar-add-reward');
    if (addBtn) addBtn.addEventListener('click', function(){
      var list = document.getElementById('spar-rewards-list');
      if (!list) return;
      var rewardIndex = list.querySelectorAll('.spar-reward-item').length;
      var rewardId = 'reward_' + Math.random().toString(36).substr(2,8);
      var badgeIconOptions = '';
      Object.keys(badgeIcons).forEach(function(icon){
        var label = badgeIcons[icon] || icon;
        badgeIconOptions += '<option value="'+escapeHtml(icon)+'">'+escapeHtml(getBadgeOptionLabel(icon, label))+'</option>';
      });
      var html = ''+
      '<div class="spar-reward-item" data-index="'+rewardIndex+'">'+
        '<div class="spar-reward-header clickable-header">'+
          '<span class="spar-drag-handle dashicons dashicons-move" aria-label="Drag to reorder" title="Drag to reorder"></span>'+
          '<span class="spar-reward-type-badge">'+getBadgePreviewHtml(rewardTypeDefaults.voucher || 'fa-solid fa-ticket', 'spar-level-badge-icon')+'</span>'+
          '<h4>'+ (i18n.newReward || 'New Reward') +'</h4>'+
          '<div class="spar-reward-actions">'+
            '<button type="button" class="button spar-toggle-reward">'+(i18n.edit||'Edit')+'</button>'+
            '<button type="button" class="button spar-duplicate-reward">'+(i18n.duplicate||'Duplicate')+'</button>'+
            '<button type="button" class="button spar-delete-reward">'+(i18n.delete||'Delete')+'</button>'+
          '</div>'+
        '</div>'+
        '<div class="spar-reward-content" style="display:block;">'+
          '<input type="hidden" name="rewards['+rewardIndex+'][id]" value="'+rewardId+'" />'+
          '<div class="spar-reward-row">'+
            '<div class="spar-reward-col">'+
              '<label>'+(i18n.rewardName||'Reward Name:')+'</label>'+
              '<input type="text" name="rewards['+rewardIndex+'][name]" value="" placeholder="'+(i18n.rewardNamePlaceholder||'$10 Off Voucher')+'" required />'+
            '</div>'+
            '<div class="spar-reward-col">'+
              '<label>'+(i18n.pointsRequired||'Points Required:')+'</label>'+
              '<input type="number" name="rewards['+rewardIndex+'][points]" value="" min="1" placeholder="100" required />'+
            '</div>'+
          '</div>'+
          '<div class="spar-reward-row">'+
            '<div class="spar-reward-col">'+
              '<label>'+(i18n.rewardType||'Reward Type:')+'</label>'+
              '<select name="rewards['+rewardIndex+'][type]" class="reward-type-select" required>'+
                '<option value="voucher">'+(i18n.voucher||'Discount Voucher/Coupon')+'</option>'+
                '<option value="product">'+(i18n.product||'Free Product')+'</option>'+
                (isPro ? '<option value="product_bundle">'+(i18n.productBundle||'Free Product Bundle')+'</option>' : '')+
                '<option value="custom">'+(i18n.custom||'Custom (Developer Hook)')+'</option>'+
              '</select>'+
            '</div>'+
            '<div class="spar-reward-col">'+
              '<label>'+(i18n.status||'Status:')+'</label>'+
              '<select name="rewards['+rewardIndex+'][status]" required>'+
                '<option value="active">'+(i18n.active||'Active')+'</option>'+
                '<option value="inactive">'+(i18n.inactive||'Inactive')+'</option>'+
              '</select>'+
            '</div>'+
          '</div>'+
          '<div class="spar-reward-row spar-reward-icon-row">'+
            '<div class="spar-reward-col spar-reward-icon-select-col">'+
              '<label style="margin-top: 0;">'+(i18n.rewardIcon||'Reward Icon:')+'</label>'+
              '<select name="rewards['+rewardIndex+'][badge_icon]" class="spar-reward-badge-select">'+
                '<option value="">'+(i18n.selectDefaultIcon||'Default (based on type)')+'</option>'+
                '<option value="product_image" class="spar-product-image-option" disabled>'+(i18n.productImage||'Product Image')+'</option>'+
                '<option value="custom_url">'+(i18n.customOptionUrl||'Custom (Image URL)')+'</option>'+
                '<option value="custom_text">'+(i18n.customOptionText||'Custom (Text/Emoji)')+'</option>'+
                badgeIconOptions+
              '</select>'+
              '<input type="hidden" name="rewards['+rewardIndex+'][badge_type]" class="spar-reward-badge-type-field" value="" />'+
            '</div>'+
            '<div class="spar-reward-col spar-reward-badge-color-col">'+
              '<label>'+(i18n.rewardIconColor||'Icon Color:')+'</label>'+
              '<select name="rewards['+rewardIndex+'][badge_color_mode]" class="spar-reward-badge-color-mode-select">'+
                '<option value="default">'+(i18n.colorModeDefault||'Default (Theme Colors)')+'</option>'+
                '<option value="custom">'+(i18n.colorModeCustom||'Custom')+'</option>'+
              '</select>'+
              '<input type="color" name="rewards['+rewardIndex+'][badge_color]" class="spar-reward-badge-color-input" value="#667eea" style="display:none;" />'+
            '</div>'+
          '</div>'+
          '<div class="spar-reward-row spar-reward-badge-url-row spar-hidden" data-badge-field="url" style="display:none;">'+
            '<div class="spar-reward-col">'+
              '<label>'+(i18n.customIconUrl||'Custom Icon (Image URL):')+'</label>'+
              '<span class="spar-media-upload-field">'+
                '<input type="url" name="rewards['+rewardIndex+'][badge_url]" class="spar-reward-badge-url-input" value="" placeholder="https://example.com/icon.png" />'+
                '<button type="button" class="button spar-media-upload-button">'+(i18n.uploadSelect||'Upload / Select')+'</button>'+
              '</span>'+
              '<small>'+(i18n.customIconUrlHelp||'Provide an image URL to use as the reward icon.')+'</small>'+
            '</div>'+
          '</div>'+
          '<div class="spar-reward-row spar-reward-badge-text-row spar-hidden" data-badge-field="text" style="display:none;">'+
            '<div class="spar-reward-col">'+
              '<label>'+(i18n.customIconText||'Custom Icon (Text/Emoji):')+'</label>'+
              '<input type="text" name="rewards['+rewardIndex+'][badge_text]" class="spar-reward-badge-text-input" value="" placeholder="'+(i18n.customIconTextPlaceholder||'e.g. \uD83C\uDF81 or VIP')+'" />'+
              '<small>'+(i18n.customIconTextHelp||'Shown instead of the preset icon when Custom (Text/Emoji) is selected.')+'</small>'+
            '</div>'+
          '</div>'+
          '<div class="spar-voucher-settings">'+
            '<h5>'+(i18n.voucherSettings||'Voucher Settings')+'</h5>'+
            '<div class="spar-reward-row">'+
              '<div class="spar-reward-col">'+
                '<label>'+(i18n.voucherAmount||'Voucher Amount:')+'</label>'+
                '<input type="number" step="0.01" name="rewards['+rewardIndex+'][voucher_amount]" value="" min="0" placeholder="10.00" required />'+
              '</div>'+
              '<div class="spar-reward-col">'+
                '<label>'+(i18n.discountType||'Discount Type:')+'</label>'+
                '<select name="rewards['+rewardIndex+'][discount_type]">'+
                  '<option value="fixed_cart">'+(i18n.fixedAmount||'Fixed Amount')+'</option>'+
                  '<option value="percent">'+(i18n.percentage||'Percentage')+'</option>'+
                '</select>'+
              '</div>'+
            '</div>'+
          '<div class="spar-field-group">'+
            '<label class="spar-toggle-label" style="display:flex; align-items:center; gap:8px;">'+
              '<input type="checkbox" name="rewards['+rewardIndex+'][free_shipping]" value="1" />'+
              '<span>'+(i18n.freeShipping||'Free Shipping')+'</span>'+
            '</label>'+
          '</div>'+
          '</div>'+
          '<div class="spar-product-settings" style="display:none;">'+
            '<h5>'+(i18n.productSettings||'Product Settings')+'</h5>'+
            '<div class="spar-reward-row">'+
              '<div class="spar-reward-col">'+
                '<label>'+(i18n.productLabel||'Product:')+'</label>'+
                '<select name="rewards['+rewardIndex+'][product_id]" class="spar-product-select" style="width:100%;" disabled></select>'+
                '<small>'+(i18n.productHelp||'Search and select a WooCommerce product for the free product reward')+'</small>'+
              '</div>'+
            '</div>'+
          '</div>'+
          (isPro ?
          '<div class="spar-bundle-settings" style="display:none;">'+
            '<div class="spar-reward-row">'+
              '<div class="spar-reward-col">'+
                '<label>'+(i18n.bundleProducts||'Bundle Products:')+'</label>'+
                '<select name="rewards['+rewardIndex+'][bundle_product_ids][]" class="spar-product-select spar-bundle-product-select" style="width:100%;" multiple disabled></select>'+
                '<small>'+(i18n.bundleHelp||'Search and select two or more products. All of them are added to the cart for free when the bundle is redeemed.')+'</small>'+
              '</div>'+
            '</div>'+
          '</div>' : '')+
          '<div class="spar-field-group spar-reward-product-value-row spar-hidden" style="display:none;">'+
            '<label class="spar-toggle-label spar-flex-center-gap8">'+
              '<input type="checkbox" name="rewards['+rewardIndex+'][show_product_value]" value="1" />'+
              '<span>'+(i18n.showProductValue||'Show total value')+'</span>'+
            '</label>'+
            '<small>'+(i18n.showProductValueHelp||'Display the total value of the product(s) under the name on the rewards/claim display.')+'</small>'+
          '</div>'+
          '<div class="spar-field-group">'+
            '<label>'+(i18n.expiryDate||'Expiry Date:')+'</label>'+
            '<input type="date" name="rewards['+rewardIndex+'][expiry_date]" value="" style="width:200px;" />'+
          '</div>'+
          '<div class="spar-field-group">'+
            '<label class="spar-toggle-label" style="display:flex;align-items:center;gap:8px;">'+
              '<div class="spar-toggle-switch">'+
                '<input type="checkbox" class="spar-enable-template-coupon" />'+
                '<span class="spar-toggle-slider"></span>'+
              '</div>'+
              (i18n.enableTemplateCoupon||'Enable Template Coupon')+
            '</label>'+
          '</div>'+
          '<div class="spar-template-coupon-section spar-hidden">'+
            '<div class="spar-field-group">'+
              '<label>'+(i18n.templateCoupon||'Template Coupon (optional):')+'</label>'+
              '<select name="rewards['+rewardIndex+'][template_coupon_id]" id="spar-reward-template-coupon-'+rewardIndex+'" class="spar-reward-template-coupon spar-minw-280">'+
                '<option value="0">'+(i18n.templateCouponNone||'No template \u2013 use basic settings above')+'</option>'+
              '</select>'+
              '<small>'+(i18n.templateCouponHelp||'Select an existing coupon to use as a template. All coupon settings will be copied to the generated voucher.')+'</small>'+
              '<div style="margin-top:6px;"><button type="button" class="button button-secondary spar-create-template-coupon" data-index="'+rewardIndex+'">'+(i18n.createNewTemplate||'Create New Template')+'</button></div>'+
              '<div id="spar-reward-template-coupon-link-'+rewardIndex+'" class="spar-my-5"><em>'+(i18n.templateCouponNoSelected||'No template selected.')+'</em></div>'+
            '</div>'+
          '</div>'+
          '<div class="spar-custom-settings" style="display:none;">'+
            '<h5>'+(i18n.customSettings||'Custom Reward Settings')+'</h5>'+
            '<div class="spar-field-group">'+
              '<label>'+(i18n.customDesc||'Short Description:')+'</label>'+
              '<textarea name="rewards['+rewardIndex+'][custom_description]" rows="3" placeholder="'+(i18n.customDescPlaceholder||'Explain what this custom reward grants the user')+'" style="width:100%;"></textarea>'+
            '</div>'+
            '<div class="spar-field-group">'+
              '<label>'+(i18n.developerId||'Developer Reward ID:')+'</label>'+
              '<input type="text" name="rewards['+rewardIndex+'][developer_id]" value="" placeholder="reward_internal_key" pattern="[a-z0-9\-_]{3,60}" />'+
              '<small>'+(i18n.developerIdHelp||'Unique lowercase identifier used in spar_custom_reward_claimed hook.')+'</small>'+
            '</div>'+
          '</div>'+
        '</div>'+
      '</div>';
      list.insertAdjacentHTML('beforeend', html);
      var newItem = list.querySelector('.spar-reward-item[data-index="'+rewardIndex+'"]');
      if (newItem){ updateFieldValidation(newItem); initProductSelects(newItem, cfg); initTemplateCouponEditLinks(newItem); initRewardBadgeControls(newItem); }
      updateAddRewardButton();
      // After adding, ensure sortable is aware of new item
      if (typeof jQuery !== 'undefined' && jQuery.fn.sortable) {
        jQuery('#spar-rewards-list').sortable('refresh');
      }
      if (typeof jQuery !== 'undefined') {
        jQuery('#spar-settings-form').trigger('spar-save-settings');
      }
    });

    // Delegated events
    var rewardsList = document.getElementById('spar-rewards-list');
    function toggleRewardContent(content, toggleBtn){
      if (!content) return;
      var isHidden = content.classList.contains('spar-hidden') || content.style.display === 'none';
      content.classList.toggle('spar-hidden', !isHidden);
      content.style.display = isHidden ? 'block' : 'none';
      if (toggleBtn) {
        toggleBtn.textContent = isHidden ? (i18n.close || 'Close') : (i18n.edit || 'Edit');
      }
    }

    if (rewardsList) rewardsList.addEventListener('click', function(e){
      if (e.target.closest('.spar-reward-header') && !e.target.closest('.spar-reward-actions') && !e.target.closest('.spar-drag-handle')){
        var header = e.target.closest('.spar-reward-header');
        var content = header.nextElementSibling;
        var toggleBtn = header.querySelector('.spar-toggle-reward');
        if (content && content.classList.contains('spar-reward-content')){
          toggleRewardContent(content, toggleBtn);
        }
        return;
      }
      if (e.target.classList.contains('spar-toggle-reward')){
        var content2 = e.target.closest('.spar-reward-item').querySelector('.spar-reward-content');
        toggleRewardContent(content2, e.target);
      }
      if (e.target.classList.contains('spar-duplicate-reward')){
        var srcItem = e.target.closest('.spar-reward-item');
        if (srcItem) { duplicateReward(srcItem); }
      }
      if (e.target.classList.contains('spar-delete-reward')){
        if (confirm(i18n.confirmDelete || 'Are you sure you want to delete this reward?')){
          e.target.closest('.spar-reward-item').remove();
          updateAddRewardButton();
          // Reindex after deletion to keep names tidy
          if (typeof reindexRewards === 'function') { reindexRewards(); }
          if (typeof jQuery !== 'undefined') {
            jQuery('#spar-settings-form').trigger('spar-save-settings');
          }
        }
      }
    });

    if (rewardsList) rewardsList.addEventListener('input', function(e){
      if (e.target.name && e.target.name.indexOf('[name]') !== -1){
        var header = e.target.closest('.spar-reward-item').querySelector('.spar-reward-header h4');
        if (header) header.textContent = e.target.value || (i18n.untitledReward || 'Untitled Reward');
      }
    });

    if (rewardsList) rewardsList.addEventListener('change', function(e){
      if (e.target.classList.contains('reward-type-select')){
        var rewardItem = e.target.closest('.spar-reward-item');
        updateFieldValidation(rewardItem);
        if (e.target.value === 'product' || e.target.value === 'product_bundle') {
          // Auto-select the Product Image icon option when switching to a product type.
          var badgeSelect = rewardItem.querySelector('.spar-reward-badge-select');
          if (badgeSelect) { badgeSelect.value = 'product_image'; }
          initProductSelects(rewardItem, cfg);
        }
        updateRewardBadgeControls(rewardItem);
      }
      if (e.target.classList.contains('spar-reward-badge-select') || e.target.classList.contains('spar-reward-badge-color-mode-select')){
        var rewardItem2 = e.target.closest('.spar-reward-item');
        if (rewardItem2) updateRewardBadgeControls(rewardItem2);
      }
    });

    if (rewardsList) rewardsList.addEventListener('input', function(e){
      if (e.target.classList.contains('spar-reward-badge-url-input') || e.target.classList.contains('spar-reward-badge-text-input') || e.target.classList.contains('spar-reward-badge-color-input')){
        var rewardItem3 = e.target.closest('.spar-reward-item');
        if (rewardItem3) updateRewardBadgeControls(rewardItem3);
      }
    });

    // Copy live field values from a source reward item to a cloned one. cloneNode
    // does not reliably copy user-modified input/select/textarea values, so mirror
    // them explicitly. Source and clone share identical structure at this point.
    function copyRewardFieldValues(src, dst){
      var s = src.querySelectorAll('input, select, textarea');
      var d = dst.querySelectorAll('input, select, textarea');
      var len = Math.min(s.length, d.length);
      for (var i = 0; i < len; i++){
        var sf = s[i], df = d[i];
        if (sf.type === 'checkbox' || sf.type === 'radio'){
          df.checked = sf.checked;
        } else if (sf.tagName === 'SELECT' && sf.multiple){
          for (var j = 0; j < sf.options.length && j < df.options.length; j++){
            df.options[j].selected = sf.options[j].selected;
          }
        } else {
          df.value = sf.value;
        }
      }
    }

    // Remove Select2/SelectWoo rendered artifacts from a cloned item so the product
    // selects can be re-initialised cleanly.
    function cleanupClonedSelect2(item){
      var containers = item.querySelectorAll('.select2-container');
      Array.prototype.forEach.call(containers, function(c){ if (c.parentNode){ c.parentNode.removeChild(c); } });
      var selects = item.querySelectorAll('select.select2-hidden-accessible');
      Array.prototype.forEach.call(selects, function(sel){
        sel.classList.remove('select2-hidden-accessible');
        sel.removeAttribute('aria-hidden');
        sel.removeAttribute('tabindex');
        sel.style.display = '';
      });
      var marked = item.querySelectorAll('[data-select2-id]');
      Array.prototype.forEach.call(marked, function(el){ el.removeAttribute('data-select2-id'); });
    }

    // Apply a new index to every field name and index-based id/attribute of a reward item.
    function setRewardItemIndex(item, newIndex){
      item.setAttribute('data-index', String(newIndex));
      var fields = item.querySelectorAll('[name^="rewards["]');
      Array.prototype.forEach.call(fields, function(field){
        var name = field.getAttribute('name');
        if (!name) return;
        field.setAttribute('name', name.replace(/rewards\[(?:\d+)\]/, 'rewards['+ newIndex +']'));
      });
      var tcSelect = item.querySelector('.spar-reward-template-coupon');
      if (tcSelect){ tcSelect.id = 'spar-reward-template-coupon-' + newIndex; }
      var tcLink = item.querySelector('[id^="spar-reward-template-coupon-link-"]');
      if (tcLink){ tcLink.id = 'spar-reward-template-coupon-link-' + newIndex; }
      var tcBtn = item.querySelector('.spar-create-template-coupon');
      if (tcBtn){ tcBtn.setAttribute('data-index', String(newIndex)); }
      var fs = item.querySelector('input[id^="spar-free-shipping-"]');
      if (fs){
        var lbl = item.querySelector('label[for^="spar-free-shipping-"]');
        fs.id = 'spar-free-shipping-' + newIndex;
        if (lbl){ lbl.setAttribute('for', 'spar-free-shipping-' + newIndex); }
      }
    }

    // Duplicate an existing reward item, copying all of its settings into a new item.
    function duplicateReward(sourceItem){
      var list = document.getElementById('spar-rewards-list');
      if (!list || !sourceItem) return;
      var maxRewards = parseInt(cfg.maxRewards, 10);
      if (isNaN(maxRewards)) { maxRewards = 3; }
      var count = list.querySelectorAll('.spar-reward-item').length;
      if (count >= maxRewards){
        if (i18n.maxRewardsReached) { window.alert(i18n.maxRewardsReached); }
        return;
      }
      var newIndex = count;
      var newId = 'reward_' + Math.random().toString(36).substr(2,8);

      var clone = sourceItem.cloneNode(true);
      // Reset the "bound" markers so controls re-attach their listeners on the clone.
      var bound = clone.querySelectorAll('[data-spar-badge-bound]');
      Array.prototype.forEach.call(bound, function(el){ el.removeAttribute('data-spar-badge-bound'); });
      // Mirror the live values, then strip Select2 markup before re-initialising.
      copyRewardFieldValues(sourceItem, clone);
      cleanupClonedSelect2(clone);
      setRewardItemIndex(clone, newIndex);

      var idField = clone.querySelector('input[name="rewards['+newIndex+'][id]"]');
      if (idField){ idField.value = newId; }
      var nameField = clone.querySelector('input[name="rewards['+newIndex+'][name]"]');
      if (nameField){
        var baseName = nameField.value || (i18n.untitledReward || 'Untitled Reward');
        nameField.value = baseName + (i18n.copySuffix || ' (Copy)');
      }
      // Open the duplicate so it is immediately visible/editable.
      var content = clone.querySelector('.spar-reward-content');
      if (content){ content.classList.remove('spar-hidden'); content.style.display = 'block'; }
      var toggleBtn = clone.querySelector('.spar-toggle-reward');
      if (toggleBtn){ toggleBtn.textContent = (i18n.close || 'Close'); }

      list.appendChild(clone);

      var header = clone.querySelector('.spar-reward-header h4');
      if (header && nameField){ header.textContent = nameField.value || (i18n.untitledReward || 'Untitled Reward'); }

      updateFieldValidation(clone);
      initProductSelects(clone, cfg);
      initTemplateCouponEditLinks(clone);
      initRewardBadgeControls(clone);
      updateAddRewardButton();
      if (typeof jQuery !== 'undefined' && jQuery.fn.sortable){ jQuery('#spar-rewards-list').sortable('refresh'); }
      if (typeof jQuery !== 'undefined'){ jQuery('#spar-settings-form').trigger('spar-save-settings'); }
      if (clone.scrollIntoView){ clone.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
    }

    function reindexRewards(){
      var list = document.getElementById('spar-rewards-list');
      if (!list) return;
      var items = list.querySelectorAll('.spar-reward-item');
      items.forEach(function(item, newIndex){
        // Update data-index
        item.setAttribute('data-index', String(newIndex));
        // Update all name attributes from rewards[<old>][field] to rewards[newIndex][field]
    var fields = item.querySelectorAll('input[name^="rewards["], select[name^="rewards["], textarea[name^="rewards["]');
        fields.forEach(function(field){
          var name = field.getAttribute('name');
          if (!name) return;
          var newName = name.replace(/rewards\[(?:\d+)\]/, 'rewards['+ newIndex +']');
          field.setAttribute('name', newName);
        });
        // Update any IDs that include the old index (currently free shipping checkbox)
        var fs = item.querySelector('input[id^="spar-free-shipping-"]');
        var label = item.querySelector('label[for^="spar-free-shipping-"]');
        if (fs){
          fs.id = 'spar-free-shipping-' + newIndex;
        }
        if (label){
          label.setAttribute('for', 'spar-free-shipping-' + newIndex);
        }
      });
    }

    function initSortable(){
      if (typeof jQuery === 'undefined') return;
      var $ = jQuery;
      if (!$.fn.sortable) return;
      var $list = $('#spar-rewards-list');
      if (!$list.length) return;
      try {
        $list.sortable({
          items: '.spar-reward-item',
          handle: '.spar-drag-handle',
          tolerance: 'pointer',
          axis: 'y',
          containment: 'parent',
          placeholder: 'spar-reward-sortable-placeholder',
          forcePlaceholderSize: true,
          update: function(){
            reindexRewards();
            if (typeof jQuery !== 'undefined') {
              jQuery('#spar-settings-form').trigger('spar-save-settings');
            }
          }
        });
      } catch(e) { /* no-op */ }
    }

    // Init sortable after DOM ready
    initSortable();

    var settingsForm = document.querySelector('form[method="post"]');
    if (settingsForm) settingsForm.addEventListener('submit', function(){
      // Ensure consistent naming order before submit
      reindexRewards();
      var disabledFields = document.querySelectorAll('#spar-rewards-list select[disabled], #spar-rewards-list input[disabled]');
      disabledFields.forEach(function(field){ field.removeAttribute('disabled'); field.setAttribute('data-was-disabled','true'); });
      setTimeout(function(){
        disabledFields.forEach(function(field){ if (field.hasAttribute('data-was-disabled')){ field.setAttribute('disabled','disabled'); field.removeAttribute('data-was-disabled'); } });
      }, 100);
    });
  });
})();
