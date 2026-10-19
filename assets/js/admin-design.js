/* Presentation only: preserve element identity, form boundaries and event listeners. */
(function () {
    'use strict';
    const excluded = '[data-wutm-ui="preserve"],.media-modal,[role="dialog"],.wp-editor-wrap,.block-editor';
    function adapt(content) {
        if (!document.body.classList.contains('wutm-admin-screen') || !content) return;
        const roots = content.matches?.('.wutm-ui-root,.wrap') ? [content] : Array.from(content.children).filter(el => el.matches('.wrap,.wutm-module-wrap,.wutm-loyalty-layout,.ysch-hero,.ysch-admin-wrap'));
        roots.forEach(root => {
            if (root.matches(excluded)) return;
            root.classList.add('wutm-ui-root');
            root.classList.toggle('wutm-ui-dashboard', !!root.querySelector('.wutm-card[data-module],.wutm-module-group'));
            const header = root.matches('.ysch-hero') ? root : (root.querySelector('.wutm-ui-header,.wutm-header,.wutm-module-header,.wme-heading,.wco-header,.ysch-hero,.wutm-clo-hero') || Array.from(root.children).find(node => node.tagName === 'HEADER' && node.querySelector('h1')));
            if (header && !header.closest(excluded)) header.classList.add('wutm-ui-header');
            const title = root.querySelector(':scope > h1') || (!header && root.querySelector('h1'));
            if (title && !title.closest(excluded)) title.classList.add('wutm-ui-title');
            root.querySelectorAll('[class], [role="tablist"],button,a,input,select,textarea').forEach(node => {
                if (node.closest(excluded)) return;
                const names = Array.from(node.classList);
                if (node.matches('textarea,select,input:not([type]),input[type=text],input[type=search],input[type=email],input[type=url],input[type=password],input[type=number],input[type=date],input[type=datetime-local]')) node.classList.add('wutm-ui-field');
                if (names.some(name => /(?:^|[-_])(panel|card)$/.test(name)) && node !== root && node !== header && !node.matches('.wutm-card')) node.classList.add('wutm-ui-panel');
                if (node.matches('.nav-tab-wrapper,[role="tablist"],.wme-tabs')) node.classList.add('wutm-ui-tabs');
                if (node.matches('.button,.wme-button,.jmi-btn') || names.some(name => /-(btn|button)(-|$)/.test(name))) {
                    node.classList.add('wutm-ui-button');
                    node.classList.toggle('wutm-ui-primary', node.matches('.button-primary,.wme-primary,.jmi-btn-primary') || names.some(name => /-primary$/.test(name)));
                    node.classList.toggle('wutm-ui-danger', names.some(name => /-(danger|delete)$/.test(name)));
                }
            });
        });
    }
    window.WUTMAdminUI = { adapt: adapt };
    function init() {
        if (!document.body.classList.contains('wutm-admin-screen')) return;
        const content = document.getElementById('wpbody-content');
        if (!content) return;
        adapt(content);
        document.addEventListener('wutm-admin-ui-updated', () => adapt(content));
        const panels = '.card,.wu-mail-panel,.wutm-panel,.wu-info-panel,.wu-stat-box,.wu-moving-form,.wu-moving-preview,.wu-status-table,.atm-card,.wu-seo-card,.monitor-card,.wme-panel,.wutm-policy-panel,.wutm-policy-card,.sac-tools-card,.sac-prompt-card,.wuqsa-card,.wuqs-card,.wutm-hp-panel,.wutm-media-overview-card,.wutm-nmd-panel,.ysch-card,.wutm-coupon-card,.wutm-bacs-panel,.wutm-bacs-card,.wutm-so-panel,.wutm-smart-301-card,.wutm-sp-admin-panel,.wutm-sp-admin-card,.wutm-co-panel,.wutm-sne-panel,.wutm-clo-card,.wutm-smb-panel,.wco-panel,.wutm-pcm-panel,.wutm-sc-panel,.wutm-cache-panel,.wutm-vt-card,.wutm-vt-dashboard-card,.wutm-ordering-panel,.wutm-popup-design,.wutm-license-panel,.wutm-license-block,.wutm-csv-tools,.jmi-panel,.jmi-card,.jmi-pulse-panel';
        // Only top-level module roots: nested legacy screens retain their own layout.
        Array.from(content.children).filter(el => el.matches('.wrap,.wutm-module-wrap,.wutm-loyalty-layout,.ysch-hero,.ysch-admin-wrap')).forEach(root => {
            if (root.matches(excluded)) return;
            root.classList.add('wutm-ui-root');
            root.querySelectorAll(panels).forEach(panel => { if (!panel.closest(excluded)) panel.classList.add('wutm-ui-panel'); });
            // Scrolling is confined to data tables; never constrain color pickers/editors.
            root.querySelectorAll('table.widefat,table.wp-list-table,table.wu-status-table').forEach(table => {
                if (table.closest(excluded)) return;
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
        const preview = content.querySelector('.wutm-cursor-preview');
        if (preview) {
            const pointer = preview.querySelector('.wutm-preview-pointer');
            const colors = () => ['ring','dot','hover'].forEach(key => {
                const field = document.getElementById('wutm-cursor-' + key);
                if (field) preview.style.setProperty('--cursor-' + key, field.value);
            });
            colors();
            ['ring','dot','hover'].forEach(key => document.getElementById('wutm-cursor-' + key)?.addEventListener('input', colors));
            preview.addEventListener('pointermove', event => {
                if (event.pointerType === 'touch') return;
                const rect = preview.getBoundingClientRect();
                pointer.style.left = (event.clientX - rect.left) + 'px';
                pointer.style.top = (event.clientY - rect.top) + 'px';
                preview.classList.toggle('is-hover', !!event.target.closest('button,a'));
            });
            preview.addEventListener('pointerleave', () => {
                pointer.style.left = '70%'; pointer.style.top = '55%'; preview.classList.remove('is-hover','is-down');
            });
            preview.addEventListener('pointerdown', () => preview.classList.add('is-down'));
            ['pointerup','pointercancel','blur'].forEach(name => preview.addEventListener(name, () => preview.classList.remove('is-down')));
        }
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
    else init();
}());
