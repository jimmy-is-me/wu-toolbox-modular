(() => {
    'use strict';
    const config = window.wutmAtmConfig;
    if (!config) return;
    let allowed = true;
    let initialized = false;
    const seen = new Set();

    function send(event) {
        if (!allowed || !config.pixel || seen.has(event.id)) return;
        // The paid-order ID is stable across reloads. Browser storage is best effort.
        if (event.name === 'Purchase') {
            try {
                const key = 'wutm-atm-purchase-' + config.pixel;
                const now = Date.now();
                const ledger = JSON.parse(localStorage.getItem(key) || '{}');
                if (ledger[event.id] > now - 172800000) return;
                const current = Object.fromEntries(Object.entries(ledger).filter(([, time]) => time > now - 172800000).slice(-99));
                current[event.id] = now;
                localStorage.setItem(key, JSON.stringify(current));
            } catch (_) {}
        }
        if (!window.fbq) {
            const fbq = function () {
                if (fbq.callMethod) fbq.callMethod.apply(fbq, arguments);
                else fbq.queue.push(arguments);
            };
            fbq.queue = [];
            fbq.push = fbq;
            fbq.loaded = true;
            fbq.version = '2.0';
            window.fbq = window._fbq = fbq;
            const script = document.createElement('script');
            script.async = true;
            script.src = 'https://connect.facebook.net/en_US/fbevents.js';
            document.head.append(script);
        }
        if (!initialized) {
            window.fbq('init', config.pixel);
            initialized = true;
        }
        const standard = ['PageView','ViewContent','Search','AddToCart','InitiateCheckout','Purchase','Lead','CompleteRegistration','Contact'];
        window.fbq(standard.includes(event.name) ? 'trackSingle' : 'trackSingleCustom', config.pixel, event.name, event.data, { eventID: event.id });
        seen.add(event.id);
    }

    document.addEventListener('wutm-atm-consent', event => {
        if (!event.detail || event.detail.granted !== true) {
            allowed = false;
            if (initialized && window.fbq) window.fbq('consent', 'revoke');
            if (config.ga4) window['ga-disable-' + config.ga4] = true;
            if (config.google) {
                const gtag = window.gtag || function () { (window.dataLayer ||= []).push(arguments); };
                gtag('consent', 'update', {
                    ad_storage: 'denied', analytics_storage: 'denied',
                    ad_user_data: 'denied', ad_personalization: 'denied'
                });
            }
        }
    });
    config.events.forEach(send);
    if (!config.cart) return;
    let fetching = false;
    let pending = false;
    async function flush() {
        if (!allowed) return;
        if (fetching) { pending = true; return; }
        fetching = true;
        try {
            const body = new URLSearchParams({action:'wutm_atm_cart', nonce:config.nonce});
            const response = await fetch(config.cart, {method:'POST', credentials:'same-origin', body, cache:'no-store'});
            const result = await response.json();
            if (result.success) result.data.forEach(send);
        } catch (_) {
            // Analytics never interrupts a cart operation.
        } finally {
            fetching = false;
            if (pending) { pending = false; flush(); }
        }
    }
    if (window.jQuery) window.jQuery(document.body).on('added_to_cart', flush);
    document.body.addEventListener('wc-blocks_added_to_cart', flush);
})();
