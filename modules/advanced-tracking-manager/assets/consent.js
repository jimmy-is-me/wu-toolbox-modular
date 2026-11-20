(() => {
    'use strict';
    const config = window.wutmAtmPrivacy;
    const banner = document.getElementById('wutm-atm-privacy');
    const dialog = document.getElementById('wutm-atm-privacy-dialog');
    if (!config || !banner || !dialog) return;

    const meta = window.wutmAtmPixel;
    const analytics = dialog.querySelector('[name="analytics"]');
    const marketing = dialog.querySelector('[name="marketing"]');
    const buttons = document.querySelectorAll('[data-wutm-privacy-choice]');
    const reminders = [banner, dialog].map(el => el.querySelector('.wutm-atm-privacy-status'));
    const defaultNotice = banner.querySelector('[data-wutm-privacy-default]');
    const modalBanner = banner.tagName === 'DIALOG';
    const denied = {analytics:false, marketing:false, tags:{}};
    let state = null;
    let effective = denied;
    let busy = false;
    let resolving = false;
    let applying = 0;
    let pendingVisit = false;
    let visited = false;
    let cartPending = false;
    let fetchingCart = false;
    let expiryTimer;
    let opener;
    let bannerDismissed = false;
    const visit = Array.from(crypto.getRandomValues(new Uint8Array(16)), byte => byte.toString(16).padStart(2, '0')).join('');
    const channel = 'BroadcastChannel' in window ? new BroadcastChannel('wutm-atm-privacy:' + config.url) : null;
    const configured = new Set();
    const containers = new Set();
    let googleLoaded = false;
    let ga4Id = '';
    // Basic Consent Mode. Defaults are queued locally before any external tag can load.
    window.dataLayer = window.dataLayer || [];
    const gtag = window.gtag || function () { window.dataLayer.push(arguments); };
    window.gtag = gtag;
    const googleConsent = (analysis, ads, explicit = false) => ({
        analytics_storage:analysis ? 'granted' : 'denied',
        ad_storage:ads ? 'granted' : 'denied',
        ad_user_data:ads && explicit ? 'granted' : 'denied',
        ad_personalization:ads && explicit ? 'granted' : 'denied'
    });
    gtag('consent', 'default', googleConsent(false, false));

    function script(url) {
        const tag = document.createElement('script');
        tag.async = true;
        tag.src = url;
        document.head.append(tag);
    }

    function tagsFor(preference) {
        return preference && preference.tags || {};
    }

    function configureTags(next) {
        const tags = tagsFor(next);
        const ga = /^G-[A-Z0-9]+$/.test(tags.ga4 || '') ? tags.ga4 : '';
        const ads = /^AW-\d+(?:\/[A-Za-z0-9_-]+)?$/.test(tags.ads || '') ? tags.ads.split('/')[0] : '';
        const gtm = /^GTM-[A-Z0-9]+$/.test(tags.gtm || '') ? tags.gtm : '';
        if (ga4Id) window['ga-disable-' + ga4Id] = ga4Id !== ga;
        if (ga) {
            ga4Id = ga;
            window['ga-disable-' + ga] = false;
        }
        const consent = googleConsent(next.analytics, next.marketing, !!next.chosen);
        gtag('consent', 'update', consent);
        if ((ga || ads) && !googleLoaded) {
            googleLoaded = true;
            gtag('js', new Date());
            script('https://www.googletagmanager.com/gtag/js?id=' + encodeURIComponent(ga || ads));
        }
        if (ga && !configured.has(ga)) {
            configured.add(ga);
            gtag('config', ga, {allow_google_signals:false, allow_ad_personalization_signals:false});
        }
        if (ads && !configured.has(ads)) {
            configured.add(ads);
            gtag('config', ads);
        }
        window.wutmAtmConsent = Object.freeze({necessary:true, analytics:next.analytics, marketing:next.marketing});
        window.dataLayer.push({event:'wutm_atm_consent_changed', wutm_atm_consent:consent});
        if (gtm && !containers.has(gtm)) {
            containers.add(gtm);
            window.dataLayer.push({'gtm.start':Date.now(), event:'gtm.js'});
            script('https://www.googletagmanager.com/gtm.js?id=' + encodeURIComponent(gtm));
        }
        if (meta) meta.setPixel(tags.pixel || '');
        document.dispatchEvent(new CustomEvent('wutm-atm-consent', {detail:{
            ...window.wutmAtmConsent, granted:!!next.chosen && next.marketing
        }}));
    }

    async function call(op, data = {}, csrf = '') {
        const response = await fetch(config.url, {
            method:'POST', credentials:'same-origin', cache:'no-store',
            headers:{'Content-Type':'application/json', 'X-WUTM-Consent':'1'},
            body:JSON.stringify({op, csrf, ...data})
        });
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error('Privacy request failed');
        return result.data;
    }

    async function trackVisit() {
        const tags = tagsFor(effective);
        if (visited || pendingVisit || !effective.marketing) return;
        if (tags.legacy && tags.pixel && meta) {
            visited = true;
            meta.send({id:'legacy-' + visit, name:'PageView', data:{}});
        }
        if (!tags.visit) return;
        const version = applying;
        pendingVisit = true;
        try {
            const items = await call('visit', {page:config.page, visit}, state.csrf);
            if (version === applying && effective.marketing) {
                visited = true;
                if (meta) items.forEach(meta.send);
            }
        } catch (_) {
            // Event collection must not affect page and checkout behavior.
        } finally {
            pendingVisit = false;
            if (version !== applying && !visited && effective.marketing) trackVisit();
        }
    }

    async function trackCart() {
        if (!tagsFor(effective).cart || !effective.marketing) return;
        if (fetchingCart) { cartPending = true; return; }
        const version = applying;
        fetchingCart = true;
        try {
            const items = await call('cart', {}, state.csrf);
            if (meta && version === applying && effective.marketing) items.forEach(meta.send);
        } catch (_) {
            // An analytics error never blocks a cart operation.
        } finally {
            fetchingCart = false;
            if (cartPending) { cartPending = false; trackCart(); }
        }
    }

    function message(text) {
        reminders.forEach(el => { el.textContent = text; el.hidden = !text; });
    }

    function apply(next) {
        applying++;
        effective = next;
        configureTags(next);
    }

    function setBanner(visible) {
        visible = visible && !bannerDismissed;
        banner.hidden = !visible;
        if (!modalBanner) return;
        if (visible && !banner.open) {
            if (typeof banner.showModal === 'function') banner.showModal();
            else banner.setAttribute('open', '');
        } else if (!visible && banner.open) {
            if (typeof banner.close === 'function') banner.close();
            else banner.removeAttribute('open');
        }
    }

    function display(next) {
        state = next;
        if (defaultNotice) defaultNotice.hidden = !next.defaults;
        setBanner(!next.chosen && !(modalBanner && dialog.open));
        if (!dialog.open) {
            analytics.checked = next.analytics;
            marketing.checked = next.marketing;
        }
        message('');
        apply(next);
        trackVisit();
        clearTimeout(expiryTimer);
        if (next.expires > 0) {
            const ms = next.expires * 1000 - Date.now();
            expiryTimer = setTimeout(() => refresh(true), Math.max(1000, Math.min(ms + 100, 2147483647)));
        }
    }

    async function refresh(pause = false) {
        if (busy || resolving) return;
        if (pause) apply(denied);
        resolving = true;
        try {
            display(await call('state'));
        } catch (_) {
            apply(denied);
            if (defaultNotice) defaultNotice.hidden = true;
            setBanner(!(modalBanner && dialog.open));
            message('暫時無法確認 Cookie 偏好。非必要追蹤已暫停，請稍後再試。');
        } finally {
            resolving = false;
        }
    }

    async function save(choice) {
        if (busy || resolving) return;
        const next = {
            analytics:choice === 'accept' || (choice === 'custom' && analytics.checked),
            marketing:choice === 'accept' || (choice === 'custom' && marketing.checked)
        };
        busy = true;
        buttons.forEach(button => { button.disabled = true; });
        // Local tracking stops immediately. A new grant waits for the server to save it.
        if ((effective.analytics && !next.analytics) || (effective.marketing && !next.marketing)) apply(denied);
        try {
            if (!state) state = await call('state');
            display(await call('save', next, state.csrf));
            if (dialog.open) dialog.close();
            if (channel) channel.postMessage('changed');
        } catch (_) {
            state = null;
            message('偏好尚未儲存。請再試一次。');
        } finally {
            busy = false;
            buttons.forEach(button => { button.disabled = false; });
        }
    }

    document.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button) return;
        if (button.hasAttribute('data-wutm-privacy-open')) {
            opener = button;
            analytics.checked = !!(state && state.analytics);
            marketing.checked = !!(state && state.marketing);
            if (!dialog.open) {
                if (modalBanner) setBanner(false);
                if (typeof dialog.showModal === 'function') dialog.showModal();
                else dialog.setAttribute('open', '');
            }
        } else if (button.hasAttribute('data-wutm-privacy-close')) {
            if (typeof dialog.close === 'function') dialog.close();
            else dialog.removeAttribute('open');
        } else if (button.dataset.wutmPrivacyChoice) save(button.dataset.wutmPrivacyChoice);
    });
    banner.addEventListener('cancel', event => {
        event.preventDefault();
        bannerDismissed = true;
        setBanner(false);
        document.querySelector('.wutm-atm-privacy-footer button')?.focus();
    });
    dialog.addEventListener('close', () => {
        if (modalBanner && !state?.chosen && !bannerDismissed) setBanner(true);
        if (opener && opener.getClientRects().length) opener.focus();
        else document.querySelector('.wutm-atm-privacy-footer button')?.focus();
    });
    if (channel) channel.addEventListener('message', () => refresh(true));
    window.addEventListener('pageshow', event => { if (event.persisted) refresh(true); });
    document.addEventListener('visibilitychange', () => {
        if (!document.hidden && state?.expires * 1000 <= Date.now()) refresh(true);
    });
    if (window.jQuery) window.jQuery(document.body).on('added_to_cart', trackCart);
    document.body.addEventListener('wc-blocks_added_to_cart', trackCart);
    refresh();
})();
