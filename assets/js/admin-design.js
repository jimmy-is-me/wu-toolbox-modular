/* Presentation only: preserve element identity, form boundaries and event listeners. */
(function () {
    'use strict';
    function init() {
        if (!document.body.classList.contains('wutm-admin-screen')) return;
        const content = document.getElementById('wpbody-content');
        if (!content) return;
        const panels = '.card,.wu-mail-panel,.wutm-panel,.wu-info-panel,.wu-stat-box,.wu-moving-form,.wu-moving-preview,.wu-status-table,.atm-card,.wu-seo-card,.monitor-card,.wme-panel,.wutm-policy-panel,.wutm-policy-card,.sac-tools-card,.sac-prompt-card,.wuqsa-card,.wuqs-card,.wutm-hp-panel,.wutm-media-overview-card,.wutm-nmd-panel,.ysch-card,.wutm-coupon-card,.wutm-bacs-panel,.wutm-bacs-card,.wutm-so-panel,.wutm-smart-301-card,.wutm-sp-admin-panel,.wutm-sp-admin-card,.wutm-co-panel,.wutm-sne-panel,.wutm-clo-card,.wutm-smb-panel,.wco-panel,.wutm-pcm-panel,.wutm-sc-panel,.wutm-cache-panel,.wutm-vt-card,.wutm-vt-dashboard-card,.wutm-ordering-panel,.wutm-popup-design,.wutm-license-panel,.wutm-license-block,.wutm-csv-tools,.jmi-panel,.jmi-card,.jmi-pulse-panel';
        // Only top-level module roots: nested legacy screens retain their own layout.
        Array.from(content.children).filter(el => el.matches('.wrap,.wutm-module-wrap,.wutm-loyalty-layout,.ysch-hero,.ysch-admin-wrap')).forEach(root => {
            root.classList.add('wutm-ui-root');
            const header = root.matches('.ysch-hero') ? root : root.querySelector('.wutm-header,.wutm-module-header,.wme-heading,.wco-header,.ysch-hero,.wutm-clo-hero');
            if (header) header.classList.add('wutm-ui-header');
            const title = root.querySelector(':scope > h1') || (!header && root.querySelector('h1'));
            if (title) title.classList.add('wutm-ui-title');
            root.querySelectorAll(panels).forEach(panel => panel.classList.add('wutm-ui-panel'));
            // Scrolling is confined to data tables; never constrain color pickers/editors.
            root.querySelectorAll('table.widefat,table.wp-list-table,table.wu-status-table').forEach(table => {
                if (table.closest('[role="dialog"],.media-modal')) return;
                const parent = table.parentElement;
                if (parent && parent.children.length === 1 && parent !== root && parent.tagName !== 'FORM') parent.classList.add('wutm-ui-table-scroll');
            });
        });
        content.querySelectorAll('.wutm-switch input[data-module]').forEach(input => {
            const card = input.closest('.wutm-card');
            if (card && !input.hasAttribute('aria-label')) input.setAttribute('aria-label', card.dataset.moduleName || '模組');
        });
        const updateMetrics = () => {
            const cards = Array.from(content.querySelectorAll('.wutm-card')).filter(card => card.dataset.navigation !== '1');
            const enabled = cards.filter(card => card.classList.contains('on')).length;
            const count = content.querySelector('.wutm-enabled-count');
            const track = content.querySelector('.wutm-metric-track > span');
            if (count) count.textContent = enabled;
            if (track) track.style.width = (cards.length ? enabled / cards.length * 100 : 0) + '%';
        };
        document.addEventListener('wutm-module-state-changed', updateMetrics);
        updateMetrics();
        // Keep native color inputs and submitted values. Update only adjacent display text.
        content.querySelectorAll('input[type="color"]').forEach(input => {
            const code = input.nextElementSibling;
            if (code && code.tagName === 'CODE') input.addEventListener('input', () => { code.textContent = input.value; });
        });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
}());
