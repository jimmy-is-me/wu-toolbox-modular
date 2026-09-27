(function () {
    'use strict';

    var menu = document.querySelector('#toplevel_page_wu-toolbox-modular .wp-submenu');
    if (!menu) return;

    var root = menu.closest('#toplevel_page_wu-toolbox-modular');
    var keepOpen = root && root.classList.contains('wp-has-current-submenu');

    function isFlyout() {
        return window.innerWidth >= 783 && (
            document.body.classList.contains('folded') ||
            document.body.classList.contains('auto-fold')
        );
    }

    /**
     * Keep the long submenu entirely above or below the top-level row. When it
     * cannot fit on either side, make the larger side scrollable instead of
     * letting it cover the WU Toolbox row or the surrounding page content.
     */
    function positionFlyout() {
        if (!root || !isFlyout()) {
            menu.style.removeProperty('top');
            menu.style.removeProperty('max-height');
            return;
        }

        var row = root.getBoundingClientRect();
        var availableAbove = Math.max(0, row.top - 8);
        var availableBelow = Math.max(0, window.innerHeight - row.bottom - 8);
        var menuHeight = menu.scrollHeight;
        var openAbove = menuHeight > availableBelow && availableAbove > availableBelow;
        var available = openAbove ? availableAbove : availableBelow;
        var top = openAbove ? row.top - available : row.bottom;

        menu.style.setProperty('top', (top - row.top) + 'px', 'important');
        menu.style.setProperty('max-height', available + 'px', 'important');
    }

    function schedulePosition() {
        window.requestAnimationFrame(positionFlyout);
    }

    if (root) {
        function setOpen(open) {
            document.body.classList.toggle('wutm-menu-flyout-open', !!(keepOpen || open));
            schedulePosition();
        }

        setOpen(false);
        root.addEventListener('mouseenter', function () { setOpen(true); });
        root.addEventListener('mouseleave', function () { setOpen(false); });
        root.addEventListener('focusin', function () { setOpen(true); });
        root.addEventListener('focusout', function (event) {
            if (!root.contains(event.relatedTarget)) setOpen(false);
        });
        window.addEventListener('resize', schedulePosition);
        window.addEventListener('scroll', schedulePosition, true);
        schedulePosition();
    }

    menu.querySelectorAll('.wutm-submenu-group-label').forEach(function (label) {
        var link = label.closest('a');
        var heading = label.closest('li');
        var group = label.getAttribute('data-group');
        if (!link || !heading || !group) return;

        link.classList.add('wutm-submenu-group-link');
        link.setAttribute('role', 'button');
        link.setAttribute('aria-expanded', 'false');
        link.setAttribute('aria-label', label.textContent.trim() + '（展開分類）');
        heading.classList.add('wutm-menu-group-heading');

        var members = [];
        for (var item = heading.nextElementSibling; item && !item.querySelector('.wutm-submenu-group-label'); item = item.nextElementSibling) {
            item.classList.add('wutm-menu-group-hidden');
            item.hidden = true;
            item.setAttribute('aria-hidden', 'true');
            members.push(item);
        }

        function toggle() {
            var expanded = link.getAttribute('aria-expanded') !== 'true';
            link.setAttribute('aria-expanded', String(expanded));
            link.setAttribute('aria-label', label.textContent.trim() + (expanded ? '（收合分類）' : '（展開分類）'));
            members.forEach(function (member) {
                member.hidden = !expanded;
                member.classList.toggle('wutm-menu-group-hidden', !expanded);
                member.setAttribute('aria-hidden', String(!expanded));
            });
            schedulePosition();
        }

        link.addEventListener('click', function (event) {
            event.preventDefault();
            toggle();
        });
        link.addEventListener('keydown', function (event) {
            if (event.key === ' ' || event.key === 'Spacebar') {
                event.preventDefault();
                toggle();
            }
        });
    });
}());
