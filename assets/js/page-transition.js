(function () {
    'use strict';
    var root = document.documentElement, key = 'wumetax_page_transition_v220';
    var pending = 0, recovery = 0;
    function clear(removeMarker) {
        clearTimeout(pending); clearTimeout(recovery); pending = recovery = 0;
        root.classList.remove('wupt-show');
        if (removeMarker) try { sessionStorage.removeItem(key); } catch (error) {}
    }
    function eligible(event, link) {
        if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return false;
        if (link.closest('#wpadminbar') || link.hasAttribute('download') || (link.target && link.target !== '_self') ||
            link.hasAttribute('data-no-transition') || link.matches('.ajax_add_to_cart,.add_to_cart_button,.remove') ||
            window.matchMedia('(prefers-reduced-motion:reduce)').matches) return false;
        try {
            var url = new URL(link.href, location.href);
            return /^https?:$/.test(url.protocol) && url.origin === location.origin && !/\/wp-admin(?:\/|$)|\/wp-login\.php/.test(url.pathname) &&
                !(url.pathname === location.pathname && url.search === location.search);
        } catch (error) { return false; }
    }
    document.addEventListener('click', function (event) {
        var link = event.target.closest && event.target.closest('a[href]');
        if (!eligible(event, link)) return;
        clear(true);
        var destination = link.href;
        // Keep native navigation immediate. Fast/cached loads leave before this timer fires.
        pending = window.setTimeout(function () {
            if (event.defaultPrevented || document.hidden) return;
            root.classList.add('wupt-show');
            try { sessionStorage.setItem(key, JSON.stringify({ url: destination, time: Date.now() })); } catch (error) {}
            recovery = window.setTimeout(function () { clear(true); }, 8000);
        }, 220);
    });
    window.addEventListener('pagehide', function () { clear(false); });
    window.addEventListener('pageshow', function () { clear(true); });
    document.addEventListener('visibilitychange', function () { if (document.hidden) clear(true); });
})();

