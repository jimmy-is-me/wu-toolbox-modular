(function () {
    'use strict';
    var media = window.matchMedia('(hover:hover) and (pointer:fine)');
    var root = document.documentElement, cursor, frame = 0, point;
    var clickable = 'a[href],button,[role="button"],summary,label[for],input[type="button"],input[type="submit"],input[type="reset"],input[type="checkbox"],input[type="radio"],.wp-block-button__link,.wp-element-button,.ct-button';
    var native = 'input:not([type="button"]):not([type="submit"]):not([type="reset"]):not([type="checkbox"]):not([type="radio"]),textarea,select,[contenteditable]:not([contenteditable="false"]),iframe,embed,object';
    function syncScale() {
        if (!cursor) return;
        var rootZoom = parseFloat(getComputedStyle(root).zoom) || 1;
        var bodyZoom = parseFloat(getComputedStyle(document.body).zoom) || 1;
        cursor.style.zoom = String(1 / (rootZoom * bodyZoom));
    }
    function hide() {
        if (frame) cancelAnimationFrame(frame);
        frame = 0; point = null;
        root.classList.remove('wutm-cursor-enabled','wutm-cursor-visible','wutm-cursor-hover','wutm-cursor-down','wutm-cursor-text');
    }
    function mount() {
        if (cursor) return true;
        cursor = document.createElement('span');
        cursor.id = 'wutm-minimal-cursor'; cursor.setAttribute('aria-hidden','true');
        cursor.innerHTML = '<i class="wutm-cursor-ring"></i><i class="wutm-cursor-dot"></i>';
        // The top layer keeps viewport coordinates even when a theme transforms html/body.
        if (typeof cursor.showPopover !== 'function') { cursor = null; return false; }
        cursor.setAttribute('popover','manual');
        document.body.appendChild(cursor);
        try { cursor.showPopover(); } catch (error) { cursor.remove(); cursor = null; return false; }
        syncScale();
        var inlineZoom = root.style.zoom + '|' + document.body.style.zoom;
        var observer = new MutationObserver(function () {
            var next = root.style.zoom + '|' + document.body.style.zoom;
            if (next !== inlineZoom) { inlineZoom = next; hide(); syncScale(); }
        });
        observer.observe(root, { attributes:true, attributeFilter:['style'] });
        observer.observe(document.body, { attributes:true, attributeFilter:['style'] });
        return true;
    }
    function updateTarget(target) {
        if (!target || !target.closest || target.closest(native)) { hide(); return false; }
        root.classList.toggle('wutm-cursor-hover', !!target.closest(clickable));
        return true;
    }
    document.addEventListener('pointermove', function (event) {
        if (!media.matches || event.pointerType !== 'mouse' || document.hidden || document.fullscreenElement || !updateTarget(event.target)) { hide(); return; }
        if (!mount()) return; // Unsupported browsers keep their native cursor.
        var samples = event.getCoalescedEvents ? event.getCoalescedEvents() : [];
        var last = samples.length ? samples[samples.length - 1] : event;
        point = { x: last.clientX, y: last.clientY };
        if (!frame) frame = requestAnimationFrame(function () {
            frame = 0;
            if (!point) return;
            cursor.style.transform = 'translate3d(' + point.x + 'px,' + point.y + 'px,0) translate(-50%,-50%)';
            root.classList.add('wutm-cursor-enabled','wutm-cursor-visible');
        });
    }, { passive:true, capture:true });
    document.addEventListener('pointerover', function (event) {
        if (event.pointerType === 'mouse') updateTarget(event.target); else hide();
    }, { passive:true });
    document.addEventListener('pointerout', function (event) { if (!event.relatedTarget || (event.relatedTarget.closest && event.relatedTarget.closest(native))) hide(); }, { passive:true });
    document.addEventListener('pointerdown', function (event) { if (event.pointerType === 'mouse' && root.classList.contains('wutm-cursor-visible')) root.classList.add('wutm-cursor-down'); else hide(); }, { passive:true });
    document.addEventListener('pointerup', function () { root.classList.remove('wutm-cursor-down'); }, { passive:true });
    document.addEventListener('mouseleave', hide, { passive:true });
    document.addEventListener('dragstart', hide, { passive:true });
    document.addEventListener('visibilitychange', hide);
    document.addEventListener('fullscreenchange', hide);
    window.addEventListener('blur', hide);
    window.addEventListener('resize', function () { hide(); syncScale(); }, { passive:true });
    window.addEventListener('pageshow', hide);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', hide, { passive:true });
        window.visualViewport.addEventListener('scroll', hide, { passive:true });
    }
    if (media.addEventListener) media.addEventListener('change', hide); else if (media.addListener) media.addListener(hide);
})();

