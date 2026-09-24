(function ($) {
    'use strict';

    $(function () {
        const root = document.querySelector('.wutm-hp-admin');
        if (!root) return;

        const order = root.querySelector('.wutm-hp-product-order');
        const input = root.querySelector('.wutm-hp-product-order-ids');
        const results = root.querySelector('.wutm-hp-product-results');
        const searchInput = root.querySelector('.wutm-hp-product-search-input');
        const more = root.querySelector('.wutm-hp-load-more');
        const count = root.querySelector('.wutm-hp-order-count');
        const empty = root.querySelector('.wutm-hp-order-empty');
        let orderedIds = input.value.split(',').map(Number).filter(Boolean);
        let loadedThrough = Number(order.dataset.loaded) || 0;

        root.querySelectorAll('.wutm-hp-color').forEach(function (field) {
            $(field).wpColorPicker();
        });

        function updateFooter() {
            input.value = orderedIds.join(',');
            count.textContent = '目前顯示 ' + order.children.length + ' / ' + orderedIds.length + ' 項';
            empty.hidden = orderedIds.length > 0;
            more.hidden = loadedThrough >= orderedIds.length;
        }

        function makeRow(product) {
            const item = document.createElement('li');
            item.dataset.productId = product.id;
            const handle = document.createElement('span');
            handle.className = 'wutm-hp-drag';
            handle.textContent = '⠿';
            handle.setAttribute('aria-hidden', 'true');
            const title = document.createElement('span');
            title.className = 'wutm-hp-product-title';
            title.textContent = product.title;
            const number = document.createElement('small');
            number.textContent = '#' + product.id;
            const pin = document.createElement('button');
            pin.type = 'button';
            pin.className = 'button-link wutm-hp-product-pin';
            pin.textContent = '置頂';
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'button-link-delete wutm-hp-product-remove';
            remove.textContent = '移除';
            item.append(handle, title, number, pin, remove);
            return item;
        }

        function syncVisibleOrder() {
            const visible = Array.from(order.children, function (item) { return Number(item.dataset.productId); });
            const visibleSet = new Set(visible);
            orderedIds = visible.concat(orderedIds.filter(function (id) { return !visibleSet.has(id); }));
            updateFooter();
        }

        $(order).sortable({
            handle: '.wutm-hp-drag',
            axis: 'y',
            scroll: true,
            scrollSensitivity: 40,
            scrollSpeed: 20,
            placeholder: 'wutm-hp-sort-placeholder',
            update: syncVisibleOrder
        });

        function moveToFront(product) {
            const id = Number(product.id);
            const previous = order.querySelector('[data-product-id="' + id + '"]');
            const visibleCount = order.children.length;
            orderedIds = orderedIds.filter(function (value) { return value !== id; });
            orderedIds.unshift(id);
            if (previous) previous.remove();
            order.prepend(previous || makeRow(product));
            if (!previous && visibleCount > 0 && order.children.length > visibleCount) {
                order.lastElementChild.remove();
            }
            if (!visibleCount) loadedThrough = 1;
            syncVisibleOrder();
            order.scrollTop = 0;
        }

        function post(action, fields) {
            const data = new URLSearchParams({ action: action, nonce: wutmHpAdmin.nonce });
            Object.keys(fields).forEach(function (key) {
                const value = fields[key];
                if (Array.isArray(value)) value.forEach(function (item) { data.append(key + '[]', item); });
                else data.append(key, value);
            });
            return fetch(wutmHpAdmin.ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: data.toString()
            }).then(function (response) { return response.json(); });
        }

        function searchProducts() {
            const term = searchInput.value.trim();
            if (!term) return;
            results.textContent = '搜尋中…';
            post('wutm_hp_search_products', { term: term }).then(function (response) {
                results.textContent = '';
                if (!response.success || !response.data.length) {
                    results.textContent = '找不到符合的已上架商品。';
                    return;
                }
                response.data.forEach(function (product) {
                    const row = document.createElement('div');
                    row.className = 'wutm-hp-search-result';
                    const label = document.createElement('span');
                    label.textContent = product.title + ' (#' + product.id + ')';
                    const button = document.createElement('button');
                    button.type = 'button';
                    button.className = 'button wutm-hp-add-product';
                    button.dataset.productId = product.id;
                    button.dataset.productTitle = product.title;
                    button.textContent = orderedIds.includes(Number(product.id)) ? '移到最前' : '加入最前';
                    row.append(label, button);
                    results.appendChild(row);
                });
            }).catch(function () { results.textContent = '搜尋失敗，請稍後再試。'; });
        }

        function loadMore() {
            const nextIds = orderedIds.slice(loadedThrough, loadedThrough + 40);
            if (!nextIds.length) return;
            more.disabled = true;
            post('wutm_hp_order_products', { ids: nextIds }).then(function (response) {
                if (!response.success) throw new Error('Unable to load products');
                response.data.forEach(function (product) { order.appendChild(makeRow(product)); });
                loadedThrough += nextIds.length;
                updateFooter();
            }).catch(function () {
                window.alert('無法載入更多商品，請稍後再試。');
            }).finally(function () { more.disabled = false; });
        }

        root.addEventListener('click', function (event) {
            const target = event.target.closest ? event.target : event.target.parentElement;
            const removeProduct = target.closest('.wutm-hp-product-remove');
            if (removeProduct) {
                const item = removeProduct.closest('[data-product-id]');
                orderedIds = orderedIds.filter(function (id) { return id !== Number(item.dataset.productId); });
                item.remove();
                loadedThrough = Math.max(0, loadedThrough - 1);
                updateFooter();
                return;
            }
            const pinProduct = target.closest('.wutm-hp-product-pin');
            if (pinProduct) {
                const item = pinProduct.closest('[data-product-id]');
                moveToFront({ id: item.dataset.productId, title: item.querySelector('.wutm-hp-product-title').textContent });
                return;
            }
            const addProduct = target.closest('.wutm-hp-add-product');
            if (addProduct) {
                moveToFront({ id: addProduct.dataset.productId, title: addProduct.dataset.productTitle });
                addProduct.textContent = '已移到最前';
                return;
            }
            if (target.closest('.wutm-hp-product-search-button')) { searchProducts(); return; }
            if (target.closest('.wutm-hp-load-more')) { loadMore(); return; }
            const removeSlide = target.closest('.wutm-hp-remove');
            if (removeSlide) { removeSlide.closest('.wutm-hp-slide').remove(); return; }
            const addSlide = target.closest('.wutm-hp-add-slide');
            if (addSlide) {
                if (root.querySelectorAll('.wutm-hp-slide').length >= 10) {
                    window.alert('最多 10 張圖片');
                    return;
                }
                const node = root.querySelector('.wutm-hp-slide-template').content.firstElementChild.cloneNode(true);
                const index = Date.now();
                node.querySelectorAll('[name]').forEach(function (field) { field.name = field.name.replace('999999', index); });
                root.querySelector('.wutm-hp-slides').appendChild(node);
                return;
            }
            const upload = target.closest('.wutm-hp-upload');
            if (upload) {
                const input = upload.parentElement.querySelector('input');
                const frame = wp.media({ title: '選擇圖片', button: { text: '使用這張圖片' }, multiple: false });
                frame.on('select', function () { input.value = frame.state().get('selection').first().toJSON().url; });
                frame.open();
            }
        });

        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') { event.preventDefault(); searchProducts(); }
        });
        updateFooter();
    });
})(jQuery);
