(function(){
  function switchTab(targetId){
    var navs = document.querySelectorAll('.spar-tab-nav');
    var panels = document.querySelectorAll('.spar-tab-panel');
    navs.forEach(function(n){ n.classList.remove('is-active'); });
    panels.forEach(function(p){ p.classList.remove('is-active'); });
    var targetBtn = document.querySelector('.spar-tab-nav[data-tab="' + targetId + '"]');
    var targetPanel = document.getElementById(targetId);
    if(targetBtn){ targetBtn.classList.add('is-active'); }
    if(targetPanel){ targetPanel.classList.add('is-active'); }
    // Sync mobile select value if present
    var select = document.getElementById('spar-tabs-select');
    if(select && select.value !== targetId){
      select.value = targetId;
    }
  }

  // Dark mode functionality
  var themeStorageKey = 'spar_dashboard_theme';

  function getStoredTheme() {
    try {
      return localStorage.getItem(themeStorageKey);
    } catch (e) {
      return null;
    }
  }

  function setStoredTheme(theme) {
    try {
      localStorage.setItem(themeStorageKey, theme);
    } catch (e) {
      return;
    }
  }

  function updateThemeToggle(toggle, theme) {
    if (!toggle) return;
    var isDark = theme === 'dark';
    var strings = typeof sparDashboard !== 'undefined' && sparDashboard.strings ? sparDashboard.strings : {};
    var label = isDark
      ? (strings.lightMode || 'Enable light mode')
      : (strings.darkMode || 'Enable dark mode');
    toggle.setAttribute('aria-pressed', isDark ? 'true' : 'false');
    toggle.setAttribute('title', label);
    toggle.setAttribute('aria-label', label);
    toggle.textContent = isDark ? '☀️' : '🌙';
  }

  function applyDashboardTheme(theme) {
    var dashboard = document.querySelector('.spar-rewards-dashboard');
    if (!dashboard) return;
    
    var darkModeHeader = typeof sparDashboard !== 'undefined' && sparDashboard.darkModeHeader;
    
    if (theme === 'dark') {
      dashboard.classList.add('spar-dashboard--dark');
      if (darkModeHeader) {
        dashboard.classList.add('spar-dashboard--dark-header');
      }
    } else {
      dashboard.classList.remove('spar-dashboard--dark');
      dashboard.classList.remove('spar-dashboard--dark-header');
    }
    var toggle = document.querySelector('.spar-dashboard-theme-toggle');
    updateThemeToggle(toggle, theme);
  }

  function initDashboardTheme() {
    if (typeof sparDashboard === 'undefined' || !sparDashboard.darkModeToggle) {
      return;
    }

    // If the toggle is hidden, the user cannot change the theme, so always
    // apply the admin-configured default and ignore any stored preference.
    if (sparDashboard.darkModeHideToggleWhenDefault && sparDashboard.darkModeDefault) {
      applyDashboardTheme('dark');
      return;
    }

    var stored = getStoredTheme();
    var theme;
    var usingDefault = false;
    
    // If user has explicitly stored a preference, use it
    if (stored === 'dark' || stored === 'light') {
      theme = stored;
    } else if (sparDashboard.darkModeDefault) {
      // No stored preference, but default is dark (server already applied classes)
      theme = 'dark';
      usingDefault = true;
    } else {
      // No stored preference, default is light
      theme = 'light';
    }
    
    applyDashboardTheme(theme);
  }

  document.addEventListener('click', function(e){
    // Handle "Buy spins" link in Earn tab — switch to Claim tab and scroll to buy-spins section.
    var earnBuySpinsLink = e.target.closest('.spar-earn-buy-spins-link');
    if(earnBuySpinsLink){
      e.preventDefault();
      var tabKey = earnBuySpinsLink.getAttribute('data-spar-tab');
      var anchorId = earnBuySpinsLink.getAttribute('data-spar-anchor');
      if(tabKey){
        switchTab('spar-tab-' + tabKey);
      }
      if(anchorId){
        // Give the DOM a moment to show the panel before scrolling.
        setTimeout(function(){
          var anchor = document.getElementById(anchorId);
          if(anchor){
            anchor.scrollIntoView({ behavior: 'smooth', block: 'start' });
          }
        }, 50);
      }
      return;
    }

    // Handle tab navigation
    var btn = e.target.closest('.spar-tab-nav');
    if(btn){
      var id = btn.getAttribute('data-tab');
      if(id){
        e.preventDefault();
        switchTab(id);
        return;
      }
    }
    
    // Handle dark mode toggle
    var themeToggle = e.target.closest('.spar-dashboard-theme-toggle');
    if(themeToggle){
      e.preventDefault();
      e.stopPropagation();
      var dashboard = document.querySelector('.spar-rewards-dashboard');
      if(!dashboard) return;
      var isDark = dashboard.classList.contains('spar-dashboard--dark');
      var newTheme = isDark ? 'light' : 'dark';
      applyDashboardTheme(newTheme);
      setStoredTheme(newTheme);
    }
  });

    // On load: ensure only the first tab/panel is active if multiple marked active accidentally
  document.addEventListener('DOMContentLoaded', function(){
    // Initialize dark mode
    initDashboardTheme();
    
    var navs = Array.prototype.slice.call(document.querySelectorAll('.spar-tab-nav'));
    var panels = Array.prototype.slice.call(document.querySelectorAll('.spar-tab-panel'));
    if(navs.length && panels.length){
      // If none active, activate first.
      var activeNav = navs.find(function(n){ return n.classList.contains('is-active'); });
      var activePanel = panels.find(function(p){ return p.classList.contains('is-active'); });
      if(!activeNav || !activePanel){
        var firstId = navs[0].getAttribute('data-tab');
        switchTab(firstId);
      }
    }
    // Initialize mobile select synchronization
    var select = document.getElementById('spar-tabs-select');
    if(select){
      // If an active nav exists, ensure select matches it
      var currentActive = document.querySelector('.spar-tab-nav.is-active');
      if(currentActive){
        var activeId = currentActive.getAttribute('data-tab');
        if(activeId){ select.value = activeId; }
      }
      select.addEventListener('change', function(){
        if(this.value){ switchTab(this.value); }
      });
    }

    // ---- Terms and Conditions modal ----
    initTermsModal();

    // ---- Review products modal ----
    initReviewProductsModal();
  });

  function initTermsModal() {
    var modal = document.getElementById('spar-terms-modal');
    if (!modal) return;

    var overlay = modal.querySelector('.spar-terms-modal-overlay');
    var closeBtn = modal.querySelector('.spar-terms-modal-close');
    var triggerLink = document.querySelector('.spar-terms-link');

    function openModal() {
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      if (closeBtn) { closeBtn.focus(); }
    }

    function closeModal() {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (triggerLink) { triggerLink.focus(); }
    }

    if (triggerLink) {
      triggerLink.addEventListener('click', function(e) {
        e.preventDefault();
        openModal();
      });
    }

    if (closeBtn) {
      closeBtn.addEventListener('click', closeModal);
    }

    if (overlay) {
      overlay.addEventListener('click', closeModal);
    }

    modal.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' || e.keyCode === 27) {
        closeModal();
      }
    });
  }

  function initReviewProductsModal() {
    var modal = document.getElementById('spar-review-products-modal');
    if (!modal) return;

    var overlay = modal.querySelector('.spar-review-products-modal-overlay');
    var closeBtn = modal.querySelector('.spar-review-products-modal-close');
    var triggerLink = document.querySelector('.spar-review-products-link');

    function openModal() {
      modal.classList.add('is-open');
      modal.setAttribute('aria-hidden', 'false');
      document.body.style.overflow = 'hidden';
      if (closeBtn) { closeBtn.focus(); }
    }

    function closeModal() {
      modal.classList.remove('is-open');
      modal.setAttribute('aria-hidden', 'true');
      document.body.style.overflow = '';
      if (triggerLink) { triggerLink.focus(); }
    }

    if (triggerLink) {
      triggerLink.addEventListener('click', function(e) {
        e.preventDefault();
        openModal();
      });
    }

    if (closeBtn) {
      closeBtn.addEventListener('click', closeModal);
    }

    if (overlay) {
      overlay.addEventListener('click', closeModal);
    }

    modal.addEventListener('keydown', function(e) {
      if (e.key === 'Escape' || e.keyCode === 27) {
        closeModal();
      }
    });
  }
})();
