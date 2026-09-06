(function(){
  'use strict';

  // Selectors for every "Badge Icon" / "Reward Icon" / "Widget Icon" dropdown.
  var SELECT_SELECTOR = '.spar-badge-select, .spar-reward-badge-select, .spar-widget-badge-select';

  // Tracks which <select> elements have already been enhanced. Using a WeakSet
  // means cloned selects (e.g. from "Duplicate") are treated as new and rebuilt.
  var enhanced = new WeakSet();

  function onReady(run){
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', run);
    } else {
      run();
    }
  }

  function escapeHtml(value){
    return String(value == null ? '' : value).replace(/[&<>"']/g, function(char){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
    });
  }

  // Return sanitized Font Awesome classes for a value, or '' when not an icon class.
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

  // Dashicon glyphs used to represent the non-icon "special" options.
  var SPECIAL_GLYPHS = {
    '': 'dashicons-no-alt',
    'custom_url': 'dashicons-format-image',
    'custom_text': 'dashicons-editor-textcolor',
    'product_image': 'dashicons-cart'
  };

  function buildGlyphHtml(value){
    var faClass = getFontAwesomeIconClass(value);
    if (faClass) {
      return '<i class="' + escapeHtml(faClass) + '" aria-hidden="true"></i>';
    }
    if (Object.prototype.hasOwnProperty.call(SPECIAL_GLYPHS, value)) {
      return '<span class="dashicons ' + SPECIAL_GLYPHS[value] + '" aria-hidden="true"></span>';
    }
    // Emoji / plain text value.
    return '<span class="spar-icon-picker__emoji" aria-hidden="true">' + escapeHtml(value) + '</span>';
  }

  // Produce a short, tidy caption from the option text.
  function buildCaption(value, text){
    text = String(text || '').trim();
    if (value && value.indexOf('fa-') !== 0 && text.indexOf(value) === 0) {
      text = text.slice(value.length).trim();
    }
    text = text.replace(/^Font Awesome\s*-\s*/i, '').replace(/^Font Awesome\s+/i, '');
    return text;
  }

  function isHiddenOption(option){
    return !!(option.hidden || option.disabled);
  }

  // Build the visual picker once for a given select and keep it in sync.
  function buildPicker(select){
    var picker = document.createElement('div');
    picker.className = 'spar-icon-picker';
    picker.setAttribute('role', 'listbox');

    var boxes = [];
    Array.prototype.forEach.call(select.options, function(option){
      var isSpecial = Object.prototype.hasOwnProperty.call(SPECIAL_GLYPHS, option.value);
      var box = document.createElement('button');
      box.type = 'button';
      box.className = 'spar-icon-picker__option' + (isSpecial ? ' spar-icon-picker__option--special' : '');
      box.setAttribute('role', 'option');
      box.tabIndex = -1;
      box.title = String(option.textContent || '').trim();
      box.innerHTML =
        '<span class="spar-icon-picker__glyph">' + buildGlyphHtml(option.value) + '</span>' +
        '<span class="spar-icon-picker__label">' + escapeHtml(buildCaption(option.value, option.textContent)) + '</span>';
      box._sparOption = option;
      picker.appendChild(box);
      boxes.push(box);
    });

    picker.addEventListener('click', function(e){
      var box = e.target.closest ? e.target.closest('.spar-icon-picker__option') : null;
      if (!box || !picker.contains(box)) return;
      var option = box._sparOption;
      if (!option || isHiddenOption(option)) return;
      if (select.value === option.value) return;
      select.value = option.value;
      select.dispatchEvent(new Event('change', { bubbles: true }));
      syncPicker(select);
    });

    select._sparPicker = picker;
    select._sparPickerBoxes = boxes;

    // Remove any stale picker left behind by a clone before inserting the fresh one.
    var next = select.nextElementSibling;
    if (next && next.classList && next.classList.contains('spar-icon-picker')) {
      next.parentNode.removeChild(next);
    }
    select.parentNode.insertBefore(picker, select.nextSibling);
    select.classList.add('spar-icon-picker-native');
    syncPicker(select);
  }

  // Refresh selected highlight + option visibility without rebuilding the DOM.
  function syncPicker(select){
    var boxes = select._sparPickerBoxes;
    if (!boxes) return;
    var currentValue = select.value;
    boxes.forEach(function(box){
      var option = box._sparOption;
      var hidden = isHiddenOption(option);
      box.style.display = hidden ? 'none' : '';
      box.disabled = hidden;
      var selected = !hidden && option.value === currentValue;
      box.classList.toggle('is-selected', selected);
      box.setAttribute('aria-selected', selected ? 'true' : 'false');
      box.tabIndex = selected ? 0 : -1;
    });
  }

  function enhance(select){
    if (!select || enhanced.has(select)) return;
    enhanced.add(select);
    buildPicker(select);
  }

  function scanAndEnhance(root){
    var scope = root && root.querySelectorAll ? root : document;
    var selects = scope.querySelectorAll(SELECT_SELECTOR);
    Array.prototype.forEach.call(selects, enhance);
    // Also catch the root itself if it is a matching select.
    if (root && root.matches && root.matches(SELECT_SELECTOR)) {
      enhance(root);
    }
  }

  onReady(function(){
    scanAndEnhance(document);

    // Keep pickers in sync with programmatic select changes and re-render the
    // Reward Icon picker when the reward type toggles the "Product Image" option.
    document.addEventListener('change', function(e){
      var target = e.target;
      if (!target || !target.classList) return;
      if (target.classList.contains('spar-badge-select') || target.classList.contains('spar-reward-badge-select') || target.classList.contains('spar-widget-badge-select')) {
        if (target._sparPicker) {
          syncPicker(target);
        }
        return;
      }
      if (target.classList.contains('reward-type-select')) {
        var item = target.closest('.spar-reward-item');
        if (!item) return;
        var badgeSelect = item.querySelector('.spar-reward-badge-select');
        if (badgeSelect && badgeSelect._sparPicker) {
          // Let the existing handler toggle option visibility/value first.
          setTimeout(function(){ syncPicker(badgeSelect); }, 0);
        }
      }
    });

    // Enhance selects added dynamically (Add Level, Add Reward, Duplicate).
    if (typeof MutationObserver !== 'undefined') {
      var scheduled = false;
      var observer = new MutationObserver(function(){
        if (scheduled) return;
        scheduled = true;
        setTimeout(function(){
          scheduled = false;
          scanAndEnhance(document);
        }, 0);
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }
  });
})();
