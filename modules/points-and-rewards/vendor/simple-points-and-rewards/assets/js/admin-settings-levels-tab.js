(function(){
  'use strict';

  function onReady(run){ if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',run);} else {run();} }

  onReady(function(){
  var cfg = window.sparLevelsTab || {};
    var badgeIcons = cfg.badgeIcons || {};
    var i18n = cfg.i18n || {};
    var defaults = cfg.defaults || {};

    var editorIndexCounter = 0;

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

    function getBadgeOptionLabel(icon, label){
      return getFontAwesomeIconClass(icon) ? label : (icon + ' ' + label);
    }

    function getSafeHexColor(value){
      var color = String(value || '').trim();
      return /^#(?:[a-f0-9]{3}){1,2}$/i.test(color) ? color : '';
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

    function refreshEditorIndex(){
      var editors = document.querySelectorAll('textarea[id^="level_up_email_body_"]');
      editors.forEach(function(editor){
        var id = editor.id || '';
        var match = id.match(/level_up_email_body_(\d+)/);
        if (!match) {
          return;
        }
        var num = parseInt(match[1], 10);
        if (!isNaN(num) && num > editorIndexCounter) {
          editorIndexCounter = num;
        }
      });
    }

    function getNextEditorId(){
      editorIndexCounter += 1;
      return 'level_up_email_body_' + editorIndexCounter;
    }

    // Toggle visibility of entire levels configuration container.
    // Uses the 'spar-hidden' class instead of inline styles for consistency.
    function toggleLevelsConfiguration(){
      // Prefer the canonical Levels tab toggle if present, else fall back to first match
      var checkbox = document.querySelector('input[data-canonical-toggle="true"][name="levels_enabled"]')
                   || document.querySelector('input[name="levels_enabled"]')
                   || document.querySelector('input[data-name="levels_enabled"]');
      var bodyDiv   = document.getElementById('spar-levels-body');
      var configDiv = document.getElementById('spar-levels-configuration');
      if (!bodyDiv) return; // Nothing to toggle
      if (!checkbox) return; // Checkbox not found yet
      var show = !!checkbox.checked;
      // Prefer class toggle, but also force inline display fallback for reliability
      bodyDiv.classList.toggle('spar-hidden', !show);
      if (!show) {
        bodyDiv.style.display = 'none';
        bodyDiv.setAttribute('aria-hidden', 'true');
      } else {
        bodyDiv.style.display = '';
        bodyDiv.removeAttribute('aria-hidden');
      }
      // Also toggle the inner configuration container so all settings follow the toggle state
      if (configDiv) {
        configDiv.classList.toggle('spar-hidden', !show);
        if (!show) {
          configDiv.style.display = 'none';
          configDiv.setAttribute('aria-hidden', 'true');
        } else {
          configDiv.style.display = '';
          configDiv.removeAttribute('aria-hidden');
        }
      }
    }
    // Expose globally so duplicate toggle sync in admin-settings.js can invoke it when a mirror changes.
    window.sparToggleLevelsUI = toggleLevelsConfiguration;

    function updateAddLevelButton(){
      var addButton = document.getElementById('spar-add-level');
      if (!addButton) return;
      var maxLevels = parseInt(cfg.maxLevels, 10);
      if (isNaN(maxLevels)) { maxLevels = 1; }
      var list = document.getElementById('spar-levels-list');
      var count = list ? list.querySelectorAll('.spar-level-item').length : 0;
      var atMax = count >= maxLevels;
      addButton.disabled = atMax;
      addButton.title = atMax ? (i18n.maxLevelsReached || '') : '';
      addButton.classList.toggle('spar-disabled-button', atMax);
      var baseLabel = addButton.textContent.replace(/\s*\(PRO\)\s*$/, '').trim();
      addButton.textContent = atMax ? baseLabel + ' (PRO)' : baseLabel;
    }

    function getSelectedBadgeType(value){
      if (value === 'custom_url') return 'custom_url';
      if (value === 'custom_text') return 'custom_text';
      return 'preset';
    }

    function updateBadgeControls(container){
      if (!container) return;
      var select = container.querySelector('.spar-badge-select');
      var typeField = container.querySelector('.spar-badge-type-field');
      var selectedValue = select ? select.value : '';
      var badgeType = getSelectedBadgeType(selectedValue);
      var effectiveIcon = (badgeType === 'preset') ? selectedValue : '';

      if (typeField) {
        typeField.value = badgeType;
      }

      // Icon colour controls (only for Font Awesome icons or custom text/emoji).
      var colorWrap = container.querySelector('.spar-level-badge-color-col');
      var colorInput = container.querySelector('.spar-level-badge-color-input');
      var modeSelect = container.querySelector('.spar-level-badge-color-mode-select');
      var showColor = badgeType === 'custom_text' || (badgeType === 'preset' && !!getFontAwesomeIconClass(effectiveIcon));
      var colorMode = (modeSelect && showColor) ? modeSelect.value : 'default';
      var useGradient = (colorMode !== 'custom');
      var iconColor = (showColor && !useGradient && colorInput) ? getSafeHexColor(colorInput.value) : '';

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

  var urlWrap = container.querySelector('.spar-badge-url-row');
      var textWrap = container.querySelector('.spar-badge-text-row');
      if (urlWrap) {
        urlWrap.classList.toggle('spar-hidden', badgeType !== 'custom_url');
        urlWrap.style.display = (badgeType === 'custom_url') ? '' : 'none';
      }
      if (textWrap) {
        textWrap.classList.toggle('spar-hidden', badgeType !== 'custom_text');
        textWrap.style.display = (badgeType === 'custom_text') ? '' : 'none';
      }

      var preview;
      if (container.classList.contains('spar-level-content-starter')) {
        preview = document.querySelector('.spar-level-starter .spar-level-badge');
      } else {
        var levelItem = container.closest('.spar-level-item');
        preview = levelItem ? levelItem.querySelector('.spar-level-badge') : null;
      }
      if (!preview) return;

      while (preview.firstChild) {
        preview.removeChild(preview.firstChild);
      }

      if (badgeType === 'custom_url') {
        var urlInput = container.querySelector('.spar-badge-url-input');
        var url = urlInput ? urlInput.value.trim() : '';
        if (url) {
          var img = document.createElement('img');
          img.src = url;
          img.alt = '';
          img.className = 'spar-level-badge-img';
          preview.appendChild(img);
        } else {
          preview.textContent = '🖼️';
        }
      } else if (badgeType === 'custom_text') {
        var textInput = container.querySelector('.spar-badge-text-input');
        var textValue = (textInput && textInput.value) ? textInput.value : '✏️';
        var textSpan = document.createElement('span');
        textSpan.className = 'spar-level-badge-icon' + (useGradient ? ' spar-badge-icon--gradient' : '');
        if (!useGradient && iconColor) {
          textSpan.style.color = iconColor;
        }
        textSpan.textContent = textValue;
        preview.appendChild(textSpan);
      } else if (select) {
        var iconValue = select.value;
        if (iconValue === 'custom_url' || iconValue === 'custom_text') {
          iconValue = '';
        }
        setBadgePreview(preview, iconValue || '', 'spar-level-badge-icon', iconColor, useGradient);
      }
    }

    function initBadgeControls(root){
      var scope = root || document;
      var containers = scope.querySelectorAll('.spar-level-content, .spar-level-content-starter');
      if (!containers.length) return;
      containers.forEach(function(container){
        var select = container.querySelector('.spar-badge-select');
        if (select && !select.dataset.sparBadgeBound) {
          select.dataset.sparBadgeBound = '1';
          select.addEventListener('change', function(){ updateBadgeControls(container); });
        }
        var urlInput = container.querySelector('.spar-badge-url-input');
        if (urlInput && !urlInput.dataset.sparBadgeBound) {
          urlInput.dataset.sparBadgeBound = '1';
          urlInput.addEventListener('input', function(){ updateBadgeControls(container); });
        }
        var textInput = container.querySelector('.spar-badge-text-input');
        if (textInput && !textInput.dataset.sparBadgeBound) {
          textInput.dataset.sparBadgeBound = '1';
          textInput.addEventListener('input', function(){ updateBadgeControls(container); });
        }
        var colorInput = container.querySelector('.spar-level-badge-color-input');
        if (colorInput && !colorInput.dataset.sparBadgeBound) {
          colorInput.dataset.sparBadgeBound = '1';
          colorInput.addEventListener('input', function(){ updateBadgeControls(container); });
          colorInput.addEventListener('change', function(){ updateBadgeControls(container); });
        }
        var modeSelect = container.querySelector('.spar-level-badge-color-mode-select');
        if (modeSelect && !modeSelect.dataset.sparBadgeBound) {
          modeSelect.dataset.sparBadgeBound = '1';
          modeSelect.addEventListener('change', function(){ updateBadgeControls(container); });
        }
        updateBadgeControls(container);
      });
    }

    function getEmailButtonTexts(button){
      var openText = (button && button.dataset.openText) ? button.dataset.openText : (i18n.customiseEmail || 'Customise Email');
      var closeText = (button && button.dataset.closeText) ? button.dataset.closeText : (i18n.close || 'Close');
      return { open: openText, close: closeText };
    }

    function setEmailFieldsVisibility(fields, button, show){
      if (!fields) return;
      fields.classList.toggle('spar-hidden', !show);
      fields.style.display = show ? '' : 'none';
      if (button) {
        var texts = getEmailButtonTexts(button);
        button.textContent = show ? texts.close : texts.open;
      }
    }

    function setEmailActionsVisibility(actions, enabled){
      if (!actions) return;
      actions.classList.toggle('spar-hidden', !enabled);
      actions.style.display = enabled ? '' : 'none';
    }

    function initLevelEmailControls(root){
      var scope = root || document;
      var containers = scope.querySelectorAll('.spar-level-content, .spar-level-content-starter');
      if (!containers.length) return;
      containers.forEach(function(container){
        var fields = container.querySelector('.spar-level-email-fields');
        if (!fields) return;
        var button = container.querySelector('.spar-level-email-customize');
        var actions = container.querySelector('.spar-level-email-actions');
        var toggle = container.querySelector('.spar-level-email-toggle');

        var initialVisible = !fields.classList.contains('spar-hidden');
        setEmailFieldsVisibility(fields, button, initialVisible);

        var enabled = toggle ? !!toggle.checked : initialVisible;
        setEmailActionsVisibility(actions, enabled);
        if (!enabled) {
          setEmailFieldsVisibility(fields, button, false);
        }

        if (button && !button.dataset.sparEmailBound) {
          button.dataset.sparEmailBound = '1';
          button.addEventListener('click', function(){
            var isVisible = !fields.classList.contains('spar-hidden');
            setEmailFieldsVisibility(fields, button, !isVisible);
          });
        }

        if (toggle && !toggle.dataset.sparEmailBound) {
          toggle.dataset.sparEmailBound = '1';
          toggle.addEventListener('change', function(){
            var isEnabled = !!toggle.checked;
            setEmailActionsVisibility(actions, isEnabled);
            // Keep customise fields closed by default when enabling via checkbox.
            // Users can click the customise button to open.
            setEmailFieldsVisibility(fields, button, false);
          });
        }
      });
    }

    /**
     * Toggle visibility of per-earn-type multiplier fields when the checkbox changes.
     */
    function initCustomMultiplierControls(root) {
      var scope = root || document;
      var toggles = scope.querySelectorAll('.spar-custom-multipliers-toggle');
      if (!toggles.length) return;
      toggles.forEach(function(toggle) {
        var container = toggle.closest('.spar-level-content') || toggle.closest('.spar-level-content-starter');
        if (!container) return;
        var panel = container.querySelector('.spar-earn-type-multipliers');
        if (!panel) return;

        // Set initial visibility.
        if (toggle.checked) {
          panel.classList.remove('spar-hidden');
          panel.style.display = '';
        } else {
          panel.classList.add('spar-hidden');
          panel.style.display = 'none';
        }

        if (!toggle.dataset.sparCmBound) {
          toggle.dataset.sparCmBound = '1';
          toggle.addEventListener('change', function() {
            if (toggle.checked) {
              panel.classList.remove('spar-hidden');
              panel.style.display = '';
            } else {
              panel.classList.add('spar-hidden');
              panel.style.display = 'none';
            }
          });
        }
      });
    }

  /**
   * Sync the global multiplier input's value as the placeholder on all
   * per-earn-type multiplier inputs within the same level container.
   */
  function syncGlobalMultiplierPlaceholder(container) {
    if (!container) return;
    var globalInput = container.querySelector('.spar-global-multiplier-input');
    if (!globalInput) return;
    var val = globalInput.value.trim();
    var placeholder = (val !== '' && !isNaN(parseFloat(val))) ? val : '1.0';
    var earnInputs = container.querySelectorAll('.spar-earn-type-multipliers input[type="number"]');
    earnInputs.forEach(function(input) {
      input.placeholder = placeholder;
    });
  }

  function initGlobalMultiplierSync(root) {
    var scope = root || document;
    var containers = scope.querySelectorAll('.spar-level-content, .spar-level-content-starter');
    containers.forEach(function(container) {
      // Set initial placeholders.
      syncGlobalMultiplierPlaceholder(container);
      var globalInput = container.querySelector('.spar-global-multiplier-input');
      if (globalInput && !globalInput.dataset.sparGmBound) {
        globalInput.dataset.sparGmBound = '1';
        globalInput.addEventListener('input', function() {
          syncGlobalMultiplierPlaceholder(container);
        });
      }
    });
  }

  refreshEditorIndex();

  toggleLevelsConfiguration(); // initial state
    updateAddLevelButton();
    initBadgeControls();
    initLevelEmailControls();
    initCustomMultiplierControls();
    initGlobalMultiplierSync();
    var levelsEnabledCheckbox = document.querySelector('input[data-canonical-toggle="true"][name="levels_enabled"]')
                 || document.querySelector('input[name="levels_enabled"]');
    if (levelsEnabledCheckbox) levelsEnabledCheckbox.addEventListener('change', toggleLevelsConfiguration);
    // Also listen for changes on any mirrored clone that may have data-name instead of name
    document.addEventListener('change', function(ev){
      var t = ev.target;
      if (!t) return;
      var isLevelsToggle = false;
      if (t.matches && (t.matches('input[name="levels_enabled"]') || t.matches('input[data-name="levels_enabled"]'))){
        isLevelsToggle = true;
      } else if (t.closest) {
        var c = t.closest('input[name="levels_enabled"], input[data-name="levels_enabled"]');
        if (c) isLevelsToggle = true;
      }
      if (isLevelsToggle) { toggleLevelsConfiguration(); }
    }, true);

    var addLevelBtn = document.getElementById('spar-add-level');
    if (addLevelBtn) addLevelBtn.addEventListener('click', function(){
      var list = document.getElementById('spar-levels-list');
      if (!list) return;
      var levelIndex = list.querySelectorAll('.spar-level-item').length;
      var editorId = getNextEditorId();
      // Stable ID for the new level; the level capability is derived from it and
      // mirrors spar_get_level_capability() (sanitize_key + "spar_" prefix).
      var levelId = 'level_' + Math.random().toString(36).substr(2, 8);
      var levelCapability = ('spar_' + levelId).toLowerCase();
      // Build icon options
      var defaultIcon = cfg.defaultIcon || 'fa-solid fa-trophy';
      var defaultName  = i18n.defaultLevelName || 'Bronze Member';
      var iconOptions = '';
      Object.keys(badgeIcons).forEach(function(icon){
        var label = badgeIcons[icon];
        if (typeof label !== 'string') { label = ''; }
        var sel = (icon === defaultIcon) ? ' selected="selected"' : '';
        iconOptions += '<option value="' + escapeHtml(icon) + '"' + sel + '>' + escapeHtml(getBadgeOptionLabel(icon, label)) + '</option>';
      });
      var html = ''+
      '<div class="spar-level-item" data-index="'+levelIndex+'">'+
        '<div class="spar-level-header clickable-header">'+
          '<span class="spar-drag-handle dashicons dashicons-move" aria-label="Drag to reorder" title="Drag to reorder"></span>'+
          '<span class="spar-level-badge">'+getBadgePreviewHtml(defaultIcon, 'spar-level-badge-icon', '', true)+'</span>'+
          '<h4>'+defaultName+'</h4>'+
          '<div class="spar-level-actions">'+
            '<button type="button" class="button spar-toggle-level">'+(i18n.edit||'Edit')+'</button>'+
            '<button type="button" class="button spar-duplicate-level">'+(i18n.duplicate||'Duplicate')+'</button>'+
            '<button type="button" class="button spar-delete-level">'+(i18n.delete||'Delete')+'</button>'+
          '</div>'+
        '</div>'+
        '<div class="spar-level-content" style="display:block;">'+
          '<input type="hidden" name="levels['+levelIndex+'][id]" value="'+levelId+'" />'+
          '<div class="spar-level-row">'+
            '<div class="spar-level-col">'+
              '<label>'+(i18n.levelName||'Level Name:')+'</label>'+
              '<input type="text" name="levels['+levelIndex+'][name]" value="'+defaultName+'" placeholder="'+(i18n.levelNamePlaceholder||'Bronze Member')+'" />'+
            '</div>'+
            '<div class="spar-level-col">'+
              '<label>'+(i18n.requiredPoints||'Required Points:')+'</label>'+
              '<input type="number" name="levels['+levelIndex+'][required_points]" value="" min="0" placeholder="100" />'+
            '</div>'+
          '</div>'+
          '<div class="spar-level-row spar-level-icon-row">'+
            '<div class="spar-level-col spar-level-icon-select-col">'+
              '<label>'+(i18n.badgeIcon||'Badge Icon:')+'</label>'+
              '<select name="levels['+levelIndex+'][badge_icon]" class="spar-badge-select" data-badge-context="level">'+
                '<option value="">'+(i18n.selectIcon||'Select an icon')+'</option>'+
                '<option value="custom_url">'+(i18n.customOptionUrl||'Custom (Image URL)')+'</option>'+
                '<option value="custom_text">'+(i18n.customOptionText||'Custom (Text/Emoji)')+'</option>'+
                iconOptions+
              '</select>'+
              '<input type="hidden" name="levels['+levelIndex+'][badge_type]" class="spar-badge-type-field" value="preset" />'+
            '</div>'+
            '<div class="spar-level-col spar-level-badge-color-col">'+
              '<label>'+(i18n.iconColor||'Icon Color:')+'</label>'+
              '<select name="levels['+levelIndex+'][badge_color_mode]" class="spar-level-badge-color-mode-select">'+
                '<option value="default">'+(i18n.colorDefault||'Default (Theme Color)')+'</option>'+
                '<option value="custom">'+(i18n.colorCustom||'Custom')+'</option>'+
              '</select>'+
              '<input type="color" name="levels['+levelIndex+'][badge_color]" class="spar-level-badge-color-input" value="#667eea" style="display:none;" />'+
            '</div>'+
          '</div>'+
          '<div class="spar-level-row spar-badge-url-row spar-hidden" data-badge-field="url">'+
            '<div class="spar-level-col">'+
              '<label>'+(i18n.customBadgeUrl||'Custom Badge (Image URL):')+'</label>'+
              '<input type="url" name="levels['+levelIndex+'][badge_url]" class="spar-badge-url-input" value="" placeholder="https://example.com/badge.png" />'+
              '<small>'+(i18n.customBadgeHelp||i18n.customBadgeUrlHelp||'Provide an image URL that will be used when Custom (Image URL) is selected.')+'</small>'+
            '</div>'+ 
          '</div>'+ 
          '<div class="spar-level-row spar-badge-text-row spar-hidden" data-badge-field="text">'+
            '<div class="spar-level-col">'+
              '<label>'+(i18n.customBadgeText||'Custom Badge (Text/Emoji):')+'</label>'+
              '<input type="text" name="levels['+levelIndex+'][badge_text]" class="spar-badge-text-input" value="" placeholder="'+(i18n.customBadgeTextPlaceholder||'e.g. VIP or 😎')+'" />'+
              '<small>'+(i18n.customBadgeTextHelp||'Shown instead of the preset icon when Custom (Text/Emoji) is selected.')+'</small>'+
            '</div>'+
          '</div>'+
          '<h5>'+(i18n.levelBenefits||'Level Benefits')+'</h5>'+
          '<div class="spar-level-section-box">'+
            '<div class="spar-level-section-box-title">'+(i18n.pointsMultiplierTitle||'Points Multiplier')+'</div>'+
            '<div class="spar-level-row">'+
              '<div class="spar-level-col">'+
                '<label>'+(i18n.pointsMultiplier||'Points Multiplier:')+'</label>'+
                '<input type="number" step="0.01" name="levels['+levelIndex+'][points_multiplier]" value="1.0" min="0" placeholder="1.0" class="spar-global-multiplier-input" />'+
                '<small>'+(i18n.pointsMultiplierHelp||'Multiply all earned points by this amount (e.g., 1.5 = 50% bonus on all activities)')+'</small>'+
              '</div>'+
            '</div>'+
            (cfg.isPro ? (
            '<div class="spar-level-row">'+
              '<div class="spar-level-col">'+
                '<label>'+
                  '<input type="hidden" name="levels['+levelIndex+'][custom_multipliers_enabled]" value="0" />'+
                  '<input type="checkbox" name="levels['+levelIndex+'][custom_multipliers_enabled]" value="1" class="spar-custom-multipliers-toggle" />'+
                  ' '+(i18n.customMultipliers||'Use different multipliers per earn type')+
                '</label>'+
                '<small>'+(i18n.customMultipliersHelp||'Override the global multiplier above with a unique multiplier for each way to earn points.')+'</small>'+
              '</div>'+
            '</div>'+
            '<div class="spar-earn-type-multipliers spar-hidden">'+
              (function(){
              var etLabels = cfg.earnTypeLabels || {};
              var keys = Object.keys(etLabels);
              var h = '';
              for (var k = 0; k < keys.length; k++) {
                h += '<div class="spar-level-row spar-earn-multiplier-row"><div class="spar-level-col">';
                h += '<label>'+etLabels[keys[k]]+':</label>';
                h += '<input type="number" step="0.01" min="0" placeholder="1.0" name="levels['+levelIndex+'][earn_type_multipliers]['+keys[k]+']" value="" />';
                h += '</div></div>';
              }
              h += '<small class="spar-text-muted">'+(i18n.earnMultipliersBlankHelp||'Leave blank to use the global multiplier for that earn type.')+'</small>';
              return h;
            })()+
            '</div>'
            ) :
            '<div class="spar-level-row">'+
              '<div class="spar-level-col">'+
                '<label style="opacity: 0.6; cursor: default;">'+
                  '<input type="checkbox" disabled />'+
                  ' '+(i18n.customMultipliers||'Use different multipliers per earn type')+
                  ' <span class="spar-premium-settings-badge">(PRO)</span>'+
                '</label>'+
                '<small>'+(i18n.customMultipliersHelp||'Override the global multiplier above with a unique multiplier for each way to earn points.')+'</small>'+
              '</div>'+
            '</div>'
            )+
          '</div>'+
          '<div class="spar-level-section-box">'+
            '<div class="spar-level-section-box-title">'+(i18n.customBenefitsTitle||'Custom Benefits')+'</div>'+
            '<div class="spar-level-row">'+
              '<div class="spar-level-col">'+
                '<label>'+(i18n.customBenefits||'Custom Benefits:')+'</label>'+
                '<textarea name="levels['+levelIndex+'][custom_benefits]" placeholder="'+(i18n.customBenefitsPlaceholder||'Enter one benefit per line...')+'" rows="4" style="width:100%;"></textarea>'+
                '<small>'+(i18n.customBenefitsHelp||'Enter one benefit per line. These will be displayed to customers alongside the automatic benefits.')+'</small>'+
              '</div>'+
            '</div>'+
          '</div>'+
          '<div class="spar-level-section-box">'+
            '<div class="spar-level-section-box-title">'+(i18n.userCapabilityTitle||'User Capability')+'</div>'+
            '<div class="spar-level-row">'+
              '<div class="spar-level-col">'+
                '<label>'+(i18n.capabilityName||'Capability name:')+'</label>'+
                '<input type="text" class="spar-level-capability-field spar-full-width" value="'+escapeHtml(levelCapability)+'" readonly onclick="this.select();" />'+
                '<small>'+(i18n.capabilityHelp||'Customers at this level are automatically granted this WordPress capability. Use it with a membership/restriction plugin or current_user_can() in your own code to give this level access to specific content.')+'</small>'+
              '</div>'+
            '</div>'+
          '</div>'+
          '<div class="spar-level-section-box">'+
            '<div class="spar-level-section-box-title">'+(i18n.levelUpEmailTitle||'Level Up Email')+'</div>'+
            '<div class="spar-level-row spar-level-email-row">'+
              '<div class="spar-level-col">'+
                '<label>'+
                  '<input type="hidden" name="levels['+levelIndex+'][level_up_email_enabled]" value="0" />'+
                  '<input type="checkbox" name="levels['+levelIndex+'][level_up_email_enabled]" value="1" class="spar-level-email-toggle" />'+
                  '<span>'+(i18n.sendLevelEmail||'Send email to customer on level up')+'</span>'+
                '</label>'+
                '<small>'+(i18n.levelEmailHelp||'Email is sent as soon as the customer reaches this level.')+'</small>'+
                '<div class="spar-level-email-actions spar-hidden">'+
                  '<button type="button" class="button button-secondary spar-level-email-customize" data-open-text="'+(i18n.customiseEmail||'Customise Email')+'" data-close-text="'+(i18n.close||'Close')+'">'+(i18n.customiseEmail||'Customise Email')+'</button>'+
                '</div>'+
              '</div>'+
            '</div>'+
            '<div class="spar-level-row spar-level-email-fields spar-hidden" data-email-fields="1">'+
              '<div class="spar-level-col">'+
                '<label>'+(i18n.emailSubject||'Subject:')+'</label>'+
                '<input type="text" name="levels['+levelIndex+'][level_up_email_subject]" value="" placeholder="'+(i18n.levelEmailSubjectPlaceholder||'Congratulations! You reached {level_name}')+'" class="spar-full-width" />'+
                '<label>'+(i18n.emailBody||'Email Body:')+'</label>'+
                '<textarea id="'+editorId+'" name="levels['+levelIndex+'][level_up_email_body]" rows="6" class="spar-full-width spar-level-email-body wp-editor-area"></textarea>'+
                '<small>'+(i18n.levelEmailPlaceholders||'Available placeholders: {user_name}, {user_email}, {level_name}, {previous_level_name}, {points_label}, {total_points}, {site_name}, {site_url}, {rewards_url}')+'</small>'+
              '</div>'+
            '</div>'+
          '</div>'+
            '</div>'+
          '</div>'+
        '</div>'+
      '</div>';
      list.insertAdjacentHTML('beforeend', html);
      var newItem = list.lastElementChild;
      if (newItem) {
        var subjectInput = newItem.querySelector('input[name="levels['+levelIndex+'][level_up_email_subject]"]');
        if (subjectInput && !subjectInput.value) {
          subjectInput.value = defaults.subject || subjectInput.value || '';
        }
        var textarea = newItem.querySelector('#' + editorId);
        if (textarea && !textarea.value) {
          textarea.value = defaults.body || textarea.value || '';
        }
        if (textarea && typeof window.wp !== 'undefined' && window.wp.editor && typeof window.wp.editor.initialize === 'function') {
          window.wp.editor.initialize(editorId, {
            tinymce: {
              wpautop: true,
              toolbar1: 'bold italic | bullist numlist | link unlink',
              toolbar2: '',
              plugins: 'lists,link,paste',
              menubar: false
            },
            quicktags: true,
            mediaButtons: false
          });
        }
        initBadgeControls(newItem);
        initLevelEmailControls(newItem);
        if (cfg.isPro) {
          initCustomMultiplierControls(newItem);
          initGlobalMultiplierSync(newItem);
        }
      }
      updateAddLevelButton();
      if (typeof jQuery !== 'undefined' && jQuery.fn.sortable) {
        jQuery('#spar-levels-list').sortable('refresh');
      }
      if (typeof jQuery !== 'undefined') {
        jQuery('#spar-settings-form').trigger('spar-save-settings');
      }
    });

    function toggleLevelContent(content, toggleBtn){
      if (!content) return;
      var isHidden = content.classList.contains('spar-hidden') || content.style.display === 'none';
      content.classList.toggle('spar-hidden', !isHidden);
      content.style.display = isHidden ? 'block' : 'none';
      if (toggleBtn) {
        toggleBtn.textContent = isHidden ? (i18n.close || 'Close') : (i18n.edit || 'Edit');
      }
    }

    var levelsListEl = document.getElementById('spar-levels-list');
    if (levelsListEl) levelsListEl.addEventListener('click', function(e){
      // Click full header to toggle (except on actions or drag handle)
      if (e.target.closest('.spar-level-header') && !e.target.closest('.spar-level-actions') && !e.target.closest('.spar-drag-handle')){
        var header = e.target.closest('.spar-level-header');
        var content = header.nextElementSibling;
        var toggleBtn = header.querySelector('.spar-toggle-level');
        if (content && content.classList.contains('spar-level-content')){
          toggleLevelContent(content, toggleBtn);
        }
        return;
      }
      if (e.target.classList.contains('spar-toggle-level')){
        var content = e.target.closest('.spar-level-item').querySelector('.spar-level-content');
        toggleLevelContent(content, e.target);
      }
      if (e.target.classList.contains('spar-duplicate-level')){
        var srcLevel = e.target.closest('.spar-level-item');
        if (srcLevel) { duplicateLevel(srcLevel); }
      }
      if (e.target.classList.contains('spar-delete-level')){
        if (confirm(i18n.confirmDelete || 'Are you sure you want to delete this level?')){
          var item = e.target.closest('.spar-level-item');
          if (item && typeof window.wp !== 'undefined' && window.wp.editor && typeof window.wp.editor.remove === 'function') {
            var editorAreas = item.querySelectorAll('textarea[id^="level_up_email_body_"]');
            editorAreas.forEach(function(area){
              if (area.id) {
                window.wp.editor.remove(area.id);
              }
            });
          }
          if (item) {
            item.remove();
          }
          updateAddLevelButton();
          if (typeof reindexLevels === 'function') { reindexLevels(); }
          if (typeof jQuery !== 'undefined') {
            jQuery('#spar-settings-form').trigger('spar-save-settings');
          }
        }
      }
    });

    if (levelsListEl) levelsListEl.addEventListener('input', function(e){
      if (e.target.name && e.target.name.indexOf('[name]') !== -1){
        var header = e.target.closest('.spar-level-item').querySelector('.spar-level-header h4');
        if (header) header.textContent = e.target.value || (i18n.untitledLevel || 'Untitled Level');
      }
      if (e.target.matches('.spar-badge-select, .spar-badge-url-input, .spar-badge-text-input')){
        var container = e.target.closest('.spar-level-content');
        if (container) updateBadgeControls(container);
      }
      if (e.target.classList.contains('spar-global-multiplier-input')){
        var container = e.target.closest('.spar-level-content, .spar-level-content-starter');
        if (container) syncGlobalMultiplierPlaceholder(container);
      }
    });

    // Starter edit dropdown toggle
    var starterEditBtn = document.querySelector('.spar-toggle-level-starter');
    var starterContent = document.querySelector('.spar-level-content-starter');
    var starterHeader = document.querySelector('.spar-level-starter .spar-level-header');
    // Make the entire Starter header clickable, matching custom levels.
    if (starterHeader && starterContent) starterHeader.addEventListener('click', function(e){
      // Ignore clicks on the action buttons (the Edit button handles its own toggle).
      if (e.target.closest('.spar-level-actions')) return;
      toggleLevelContent(starterContent, starterEditBtn);
    });
    if (starterEditBtn && starterContent) starterEditBtn.addEventListener('click', function(){
      toggleLevelContent(starterContent, starterEditBtn);
    });

    // Live preview of the Starter level "Benefits shown to customers" list.
    // Mirrors spar_get_starter_level_benefits(): custom benefits (one per line)
    // override the auto-generated list derived from the earn settings.
    (function initStarterBenefitsPreview(){
      var sb = cfg.starterBenefits || {};
      var list = document.querySelector('[data-spar-starter-benefits]');
      if (!list) return;

      function fieldValue(name){
        var el = document.querySelector('[name="' + name + '"]');
        return el ? el.value : '';
      }

      function numberFormat(value, decimals){
        var sep = sb.decimalSep || '.';
        var thou = sb.thousandSep || ',';
        var n = isFinite(value) ? value : 0;
        var parts = n.toFixed(decimals).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, thou);
        return decimals > 0 ? parts[0] + sep + parts[1] : parts[0];
      }

      function formatPoints(value){
        return numberFormat(value, value % 1 === 0 ? 0 : 1);
      }

      function buildBenefits(){
        var ppPoints = parseFloat(fieldValue('order_points_per_points'));
        if (!isFinite(ppPoints)) ppPoints = 5;
        var ppAmount = parseFloat(fieldValue('order_points_per_amount'));
        if (!(ppAmount > 0)) ppAmount = 1;
        var referral = String(fieldValue('referral_fixed_points')).trim();
        if (referral === '' || isNaN(parseFloat(referral))) referral = '100';

        var decimals = sb.priceDecimals != null ? sb.priceDecimals : 2;
        var perSpend = (sb.perSpend || '%1$s points per %2$s%3$s spent')
          .replace('%1$s', function(){ return formatPoints(ppPoints); })
          .replace('%2$s', function(){ return sb.currencySymbol || '$'; })
          .replace('%3$s', function(){ return numberFormat(ppAmount, decimals); });
        var perReferral = (sb.perReferral || '%s points for each referral')
          .replace('%s', function(){ return referral; });

        var benefits = [perSpend, perReferral];

        // Custom benefits are appended to the auto-generated list.
        var custom = fieldValue('levels_starter_custom_benefits');
        if (custom && custom.trim() !== '') {
          custom.split('\n').forEach(function(line){
            line = line.trim();
            if (line !== '') benefits.push(line);
          });
        }

        return benefits;
      }

      function render(){
        var benefits = buildBenefits();
        list.innerHTML = '';
        benefits.forEach(function(text){
          var li = document.createElement('li');
          li.textContent = text;
          list.appendChild(li);
        });
      }

      ['levels_starter_custom_benefits', 'order_points_per_points', 'order_points_per_amount', 'referral_fixed_points']
        .forEach(function(name){
          var el = document.querySelector('[name="' + name + '"]');
          if (el) el.addEventListener('input', render);
        });
    })();

    // Copy live field values from a source level item to a cloned one. cloneNode
    // does not reliably copy user-modified input/select/textarea values, so mirror
    // them explicitly. Source and clone share identical structure at this point.
    function copyLevelFieldValues(src, dst){
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

    // Apply a new index to every field name of a level item.
    function setLevelItemIndex(item, newIndex){
      item.setAttribute('data-index', String(newIndex));
      var fields = item.querySelectorAll('[name^="levels["]');
      Array.prototype.forEach.call(fields, function(field){
        var name = field.getAttribute('name');
        if (!name) return;
        field.setAttribute('name', name.replace(/levels\[(?:\d+)\]/, 'levels['+ newIndex +']'));
      });
    }

    // Duplicate an existing level item, copying all of its settings into a new item.
    function duplicateLevel(sourceItem){
      var list = document.getElementById('spar-levels-list');
      if (!list || !sourceItem) return;
      var maxLevels = parseInt(cfg.maxLevels, 10);
      if (isNaN(maxLevels)) { maxLevels = 1; }
      var count = list.querySelectorAll('.spar-level-item').length;
      if (count >= maxLevels){
        if (i18n.maxLevelsReached) { window.alert(i18n.maxLevelsReached); }
        return;
      }
      var newIndex = count;
      var newId = 'level_' + Math.random().toString(36).substr(2, 8);
      var newCapability = ('spar_' + newId).toLowerCase();

      // Capture the source email body content BEFORE cloning (TinyMCE may hold
      // unsaved content that is not yet reflected in the textarea).
      var srcBody = sourceItem.querySelector('.spar-level-email-body, textarea[name*="[level_up_email_body]"]');
      var srcBodyName = srcBody ? srcBody.getAttribute('name') : '';
      var srcBodyContent = '';
      if (srcBody){
        var srcId = srcBody.id;
        if (srcId && typeof window.tinymce !== 'undefined' && window.tinymce.get && window.tinymce.get(srcId)){
          srcBodyContent = window.tinymce.get(srcId).getContent();
        } else {
          srcBodyContent = srcBody.value;
        }
      }

      var clone = sourceItem.cloneNode(true);
      // Reset the "bound" markers so controls re-attach their listeners on the clone.
      ['data-spar-badge-bound', 'data-spar-email-bound', 'data-spar-cm-bound', 'data-spar-gm-bound'].forEach(function(attr){
        var els = clone.querySelectorAll('[' + attr + ']');
        Array.prototype.forEach.call(els, function(el){ el.removeAttribute(attr); });
      });
      copyLevelFieldValues(sourceItem, clone);

      // Rebuild the email body editor as a fresh plain textarea. The cloned TinyMCE
      // markup is non-functional and would duplicate editor IDs.
      var newEditorId = getNextEditorId();
      var cloneBody = clone.querySelector('.spar-level-email-body, textarea[name*="[level_up_email_body]"]');
      if (cloneBody){
        var editorHost = cloneBody.closest('.wp-editor-wrap') || cloneBody;
        if (editorHost && editorHost.parentNode){
          var newTextarea = document.createElement('textarea');
          newTextarea.id = newEditorId;
          if (srcBodyName){ newTextarea.setAttribute('name', srcBodyName); }
          newTextarea.setAttribute('rows', '6');
          newTextarea.className = 'spar-full-width spar-level-email-body wp-editor-area';
          newTextarea.value = srcBodyContent;
          editorHost.parentNode.replaceChild(newTextarea, editorHost);
        }
      }

      setLevelItemIndex(clone, newIndex);

      var idField = clone.querySelector('input[name="levels['+newIndex+'][id]"]');
      if (idField){ idField.value = newId; }
      var capField = clone.querySelector('.spar-level-capability-field');
      if (capField){ capField.value = newCapability; }
      var nameField = clone.querySelector('input[name="levels['+newIndex+'][name]"]');
      if (nameField){
        var baseName = nameField.value || (i18n.untitledLevel || 'Untitled Level');
        nameField.value = baseName + (i18n.copySuffix || ' (Copy)');
      }
      // Open the duplicate so it is immediately visible/editable.
      var content = clone.querySelector('.spar-level-content');
      if (content){ content.classList.remove('spar-hidden'); content.style.display = 'block'; }
      var toggleBtn = clone.querySelector('.spar-toggle-level');
      if (toggleBtn){ toggleBtn.textContent = (i18n.close || 'Close'); }

      list.appendChild(clone);

      var header = clone.querySelector('.spar-level-header h4');
      if (header && nameField){ header.textContent = nameField.value || (i18n.untitledLevel || 'Untitled Level'); }

      // Initialise the fresh email editor (mirrors the "Add Level" behaviour).
      var textarea = clone.querySelector('#' + newEditorId);
      if (textarea && typeof window.wp !== 'undefined' && window.wp.editor && typeof window.wp.editor.initialize === 'function'){
        window.wp.editor.initialize(newEditorId, {
          tinymce: {
            wpautop: true,
            toolbar1: 'bold italic | bullist numlist | link unlink',
            toolbar2: '',
            plugins: 'lists,link,paste',
            menubar: false
          },
          quicktags: true,
          mediaButtons: false
        });
      }

      initBadgeControls(clone);
      initLevelEmailControls(clone);
      if (cfg.isPro){
        initCustomMultiplierControls(clone);
        initGlobalMultiplierSync(clone);
      }
      updateAddLevelButton();
      if (typeof jQuery !== 'undefined' && jQuery.fn.sortable){ jQuery('#spar-levels-list').sortable('refresh'); }
      if (typeof jQuery !== 'undefined'){ jQuery('#spar-settings-form').trigger('spar-save-settings'); }
      if (clone.scrollIntoView){ clone.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); }
    }

    // Reindex level fields after reorder or delete
    function reindexLevels(){
      var list = document.getElementById('spar-levels-list');
      if (!list) return;
      var items = list.querySelectorAll('.spar-level-item');
      items.forEach(function(item, newIndex){
        item.setAttribute('data-index', String(newIndex));
        var fields = item.querySelectorAll('input[name^="levels["], select[name^="levels["], textarea[name^="levels["]');
        fields.forEach(function(field){
          var name = field.getAttribute('name');
          if (!name) return;
          var newName = name.replace(/levels\[(?:\d+)\]/, 'levels['+ newIndex +']');
          field.setAttribute('name', newName);
        });
      });
    }

    // Enable sortable drag-and-drop ordering (like Rewards)
    function initSortable(){
      if (typeof jQuery === 'undefined') return;
      var $ = jQuery;
      if (!$.fn.sortable) return;
      var $list = $('#spar-levels-list');
      if (!$list.length) return;
      try {
        $list.sortable({
          items: '.spar-level-item',
          handle: '.spar-drag-handle',
          tolerance: 'pointer',
          axis: 'y',
          containment: 'parent',
          placeholder: 'spar-level-sortable-placeholder',
          forcePlaceholderSize: true,
          update: function(){
            reindexLevels();
            if (typeof jQuery !== 'undefined') {
              jQuery('#spar-settings-form').trigger('spar-save-settings');
            }
          }
        });
      } catch(e) { /* no-op */ }
    }

    initSortable();

    // Before form submit ensure consistent ordering
    var settingsForm = document.querySelector('form[method="post"]');
    if (settingsForm) settingsForm.addEventListener('submit', function(){
      reindexLevels();
    });
  });
})();
