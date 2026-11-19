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

        const search = root.querySelector('.wutm-pcm-search input');
        const branches = new Map();
        root.querySelectorAll('.wutm-pcm-toggle').forEach(button => {
            const children = button.closest('.wutm-pcm-item').querySelector(':scope > .wutm-pcm-children');
            const branch = { children, open: button.getAttribute('aria-expanded') === 'true' };
            branches.set(button, branch);
            button.addEventListener('click', () => {
                const open = children.hidden;
                if (!search || !search.value.trim()) branch.open = open;
                children.hidden = !open;
                button.setAttribute('aria-expanded', String(open));
            });
        });
        if (!search) return;
        const filter = (list, query, inherited = false) => {
            let any = false;
            for (const item of list.children) {
                const row = item.firstElementChild;
                const name = row.textContent.trim().toLocaleLowerCase();
                const direct = inherited || name.includes(query);
                const button = row.querySelector('.wutm-pcm-toggle');
                const branch = branches.get(button);
                const descendant = branch ? filter(branch.children.querySelector(':scope > .wutm-pcm-list'), query, direct) : false;
                const visible = direct || descendant;
                item.hidden = !visible;
                if (branch) {
                    const open = query ? visible : branch.open;
                    branch.children.hidden = !open;
                    button.setAttribute('aria-expanded', String(open));
                }
                any = any || visible;
            }
            return any;
        };
        search.addEventListener('input', () => {
            const any = filter(root.querySelector(':scope > .wutm-pcm-list'), search.value.trim().toLocaleLowerCase());
            root.querySelector('.wutm-pcm-empty').hidden = any;
        });
    });
})();
