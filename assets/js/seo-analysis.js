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
        var explanations = [
            '用一句話說明本頁解決的問題。標題可以自然提及主題，不必重複同一詞。',
            '摘要應交代對象、可獲得的答案與特色；搜尋引擎可能改寫摘要。',
            '小標題幫助閱讀層級；短頁面不必為了通過檢查硬加標題。',
            '連到能補充本頁問題的相關內容，連結文字要讓讀者知道會看到什麼。',
            '有意義的圖片描述畫面重點；純裝飾圖保留 alt=""，不要塞入關鍵字。',
            '數據與主張應能被查證，連到原始資料比只增加連結數量更重要。',
            '可使用自然同義詞；這項僅比對字面，不代表搜尋意圖符合或排名能力。',
            '開頭先讓讀者知道文章回答什麼；不必強迫精確關鍵字出現。'
        ];
        checks.forEach(function (check, index) { check.help = explanations[index]; });
        var sequence = Array.from(doc.querySelectorAll('h1,h2,h3,h4,h5,h6'));
        var previous = 1, skipped = 0;
        sequence.forEach(function (heading) { var level = Number(heading.tagName.slice(1)); if (level > previous + 1) skipped++; previous = level; });
        var paragraphs = Array.from(doc.querySelectorAll('p')).map(function (p) { return p.textContent.trim(); }).filter(Boolean);
        var longParagraphs = paragraphs.filter(function (p) { return Array.from(p).length > 300; }).length;
        var questions = sequence.filter(function (h) { return /[?？]|如何|什麼|為何|是否|怎麼/.test(h.textContent); });
        var answered = questions.filter(function (h) {
            var next = h.nextElementSibling;
            return next && /^(P|UL|OL|TABLE|DIV)$/.test(next.tagName) && next.textContent.trim().length > 0;
        }).length;
        var guidance = [
            { ok: skipped === 0, text: '標題層級：' + skipped + ' 處跳級', help: '依 H2 → H3 組織子題。只分析編輯內容，不含主題自動輸出的頁面 H1。' },
            { ok: longParagraphs === 0, text: '閱讀段落：' + longParagraphs + ' 段超過 300 字', help: '這是閱讀提醒，不是排名門檻；依語意拆段，步驟或比較可用清單、表格。' },
            { ok: paragraphs.length > 0, text: '開頭答案與搜尋意圖（請人工確認）', help: '先交代「這是什麼、適用誰、結論是什麼」，再補限制與例外。系統無法判斷答案是否正確。' },
            { ok: questions.length ? answered === questions.length : true, text: '問答段落：' + questions.length + ' 個問題標題，' + answered + ' 個緊接文字答案', help: '有常見疑問時再補 FAQ，讓每段可獨立理解；不強制加入 FAQ，也不自動產生不可見的結構化內容。' },
            { ok: true, text: '原創經驗、作者與資料時效（人工複查）', help: '補實際案例、方法、作者背景與資料日期；這些無法靠格式或關鍵字推斷。AI 引用與收錄不保證，無須特殊 AI 標記。' }
        ];
        return { checks: checks, guidance: guidance, characters: Array.from(text).length };
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
            result.checks.concat(result.guidance || []).forEach(function (check) {
                var item = document.createElement('li');
                var details = document.createElement('details');
                var summary = document.createElement('summary');
                summary.textContent = (check.ok ? '參考：' : '建議確認：') + check.text;
                var explanation = document.createElement('p');
                explanation.textContent = check.help || '';
                details.append(summary, explanation); item.appendChild(details);
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
