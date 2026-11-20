(() => {
    'use strict';
    const config = window.wutmAtmConfig;
    let pixel = '';
    const initialized = new Set();
    const seen = new Set();

    function loadPixel() {
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
        if (!initialized.has(pixel)) {
            window.fbq('init', pixel);
            initialized.add(pixel);
        }
    }

    function setPixel(id, explicit = true) {
        const next = /^\d+$/.test(id || '') ? String(id) : '';
        if (next === pixel) return;
        const previous = pixel;
        pixel = next;
        if (previous && !pixel && initialized.has(previous) && window.fbq) {
            if (!window.fbq.callMethod && Array.isArray(window.fbq.queue)) {
                window.fbq.queue = window.fbq.queue.filter(args => !['trackSingle','trackSingleCustom'].includes(args[0]) || args[1] !== previous);
            }
            window.fbq('consent', 'revoke');
        }
        if (pixel && explicit) {
            loadPixel();
            window.fbq('consent', 'grant');
        }
    }

    function send(event) {
        if (!pixel || !event || typeof event.id !== 'string' || seen.has(event.id)) return;
        // The paid-order ID is stable across reloads. Browser storage is best effort.
        if (event.name === 'Purchase') {
            try {
                const key = 'wutm-atm-purchase-' + pixel;
                const now = Date.now();
                const ledger = JSON.parse(localStorage.getItem(key) || '{}');
                if (ledger[event.id] > now - 172800000) return;
                const current = Object.fromEntries(Object.entries(ledger).filter(([, time]) => time > now - 172800000).slice(-99));
                current[event.id] = now;
                localStorage.setItem(key, JSON.stringify(current));
            } catch (_) {}
        }
        loadPixel();
        const standard = ['PageView','ViewContent','Search','AddToCart','InitiateCheckout','Purchase','Lead','CompleteRegistration','Contact'];
        window.fbq(standard.includes(event.name) ? 'trackSingle' : 'trackSingleCustom', pixel, event.name, event.data, { eventID: event.id });
        seen.add(event.id);
    }

    window.wutmAtmPixel = {setPixel, send};
    if (!config) return;
    setPixel(config.pixel, false);
    document.addEventListener('wutm-atm-consent', event => {
        if (!event.detail || event.detail.granted !== true) {
            setPixel('');
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
        if (!pixel) return;
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
