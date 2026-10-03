(function (root) {
    'use strict';
    // Local inert parsing only: no network and no insertion of content HTML.
    function analyze(html, title, description, topic, url) {
        var template = document.createElement('template');
        template.innerHTML = String(html || '');
        var doc = template.content;
        doc.querySelectorAll('script, style, template').forEach(function (node) { node.remove(); });
        var text = Array.from(doc.childNodes).map(function (node) { return node.textContent || ''; }).join(' ').replace(/\s+/g, ' ').trim();
        var headings = doc.querySelectorAll('h2, h3');
        var images = Array.from(doc.querySelectorAll('img'));
        var missingAlt = images.filter(function (image) { return !image.hasAttribute('alt'); }).length;
        var internal = 0, external = 0;
        doc.querySelectorAll('a[href]').forEach(function (link) {
            try {
                var href = new URL(link.getAttribute('href'), url);
                if (!/^https?:$/.test(href.protocol)) return;
                var base = new URL(url);
                if (href.origin === base.origin) {
                    if (href.pathname !== base.pathname || href.search !== base.search) internal++;
                } else external++;
            } catch (_) { /* Invalid URLs are not counted. */ }
        });
        var checks = [
            { ok: !!String(title || '').trim(), text: '清楚的搜尋標題' },
            { ok: !!String(description || '').trim(), text: '具體的搜尋摘要' },
            { ok: headings.length > 0, text: '使用 H2／H3 組織內容（短頁面可不需要）' },
            { ok: internal > 0, text: '相關站內連結：' + internal + ' 個' },
            { ok: missingAlt === 0, text: '圖片替代文字：' + missingAlt + ' 張缺少 alt 屬性；裝飾圖可使用空 alt' },
            { ok: true, text: '外部參考連結：' + external + ' 個，請人工確認來源可信且支持內容' }
        ];
        topic = String(topic || '').trim().toLocaleLowerCase();
        if (topic) {
            checks.push({ ok: String(title || '').toLocaleLowerCase().includes(topic), text: '標題自然提及主要主題' });
            checks.push({ ok: text.slice(0, 300).toLocaleLowerCase().includes(topic), text: '開頭清楚說明主要主題' });
        }
        return { checks: checks, characters: Array.from(text).length };
    }
    root.WUTMSEOAnalysis = { analyze: analyze };
    if (typeof document === 'undefined') return;
    document.addEventListener('DOMContentLoaded', function () {
        var output = document.getElementById('wu-seo-content-checks');
        if (!output) return;
        var timer;
        function update() {
            var editor = root.tinymce && root.tinymce.get('content');
            var html = editor && !editor.isHidden() ? editor.getContent() : (document.getElementById('content') || {}).value;
            var title = document.getElementById('wu-seo-title');
            var desc = document.getElementById('wu-seo-description');
            var audit = document.getElementById('wu-seo-local-audit');
            var topic = document.getElementById('wu-seo-topic');
            var result = analyze(html, title.value || audit.dataset.fallbackTitle, desc.value || audit.dataset.fallbackDesc, topic.value, output.dataset.url);
            output.replaceChildren();
            result.checks.forEach(function (check) {
                var item = document.createElement('li');
                item.textContent = (check.ok ? '✓ ' : '建議確認：') + check.text;
                output.appendChild(item);
            });
        }
        function schedule() { clearTimeout(timer); timer = setTimeout(update, 350); }
        ['content', 'title', 'wu-seo-title', 'wu-seo-description', 'wu-seo-topic'].forEach(function (id) {
            var field = document.getElementById(id);
            if (field) field.addEventListener('input', schedule);
        });
        if (root.tinymce) {
            function bind(editor) { if (editor.id === 'content') editor.on('change input Undo Redo', schedule); }
            root.tinymce.editors.forEach(bind);
            root.tinymce.on('AddEditor', function (event) { bind(event.editor); });
        }
        update();
    });
})(typeof window !== 'undefined' ? window : globalThis);
