(() => {
    document.querySelectorAll('.wutm-pcm').forEach(root => {
        if (root.dataset.ready) return;
        root.dataset.ready = '1';
        const mobile = root.querySelector('.wutm-pcm-mobile-toggle');
        if (mobile) mobile.addEventListener('click', () => {
            const open = root.classList.toggle('is-mobile-open');
            mobile.setAttribute('aria-expanded', String(open));
            mobile.lastElementChild.textContent = open ? '−' : '＋';
        });

        root.querySelectorAll('.wutm-pcm-toggle').forEach(button => {
            const children = button.closest('.wutm-pcm-item').querySelector(':scope > .wutm-pcm-children');
            button.addEventListener('click', () => {
                const open = children.hidden;
                children.hidden = !open;
                button.setAttribute('aria-expanded', String(open));
            });
        });
    });
})();
