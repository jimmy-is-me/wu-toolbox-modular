(() => {
    'use strict';
    const root = document.getElementById('wu-seo-dashboard');
    const get = (q, node = root) => node?.querySelector(q);
    const form = get('[data-filters]');
    if (!form) return;

    const labels = {impressions:'曝光', clicks:'點擊', ctr:'CTR', position:'平均排名'};
    const fields = Object.keys(labels);
    const indexLabels = {indexed:'已收錄', excluded:'未收錄', unknown:'未能判定', pending:'待查', failed:'查詢失敗'};
    const colors = {indexed:'#147c68', excluded:'#b25336', unknown:'#7788ab'};
    const number = new Intl.NumberFormat('zh-TW', {maximumFractionDigits:2});
    const panels = [...root.querySelectorAll('[data-panel]')];
    let connected = root.dataset.analytics === '1';
    let report = {};
    let period = {};
    let snapshot = {posts:[], keywords:[]};
    let tab = root.dataset.tab;
    let selection = root.dataset.page ? {type:'page',value:root.dataset.page} : {};
    let keyword;
    let task = 0;
    let search = '';

    function el(tag, content, className) {
        const node = document.createElement(tag);
        if (content !== undefined) node.textContent = content;
        if (className) node.className = className;
        return node;
    }
    function button(text, action) {
        const node = el('button', text, 'button-link');
        node.type = 'button';
        node.addEventListener('click', action);
        return node;
    }
    function link(text, url) {
        const node = el('a', text);
        try {
            const target = new URL(url);
            if (['http:','https:'].includes(target.protocol)) {
                node.href = target.href; node.target = '_blank'; node.rel = 'noopener noreferrer';
            }
        } catch (_) {}
        return node;
    }
    function cell(row, title, value) {
        const td = el(title ? 'th' : 'td');
        if (title) td.scope = 'row';
        if (value instanceof Node) td.append(value);
        else td.textContent = value ?? '—';
        row.append(td);
    }
    function table(title, headings, entries) {
        const group = el('section', undefined, 'wus-data');
        if (title) group.append(el('h3', title));
        if (!entries.length) { group.append(el('p','無資料', 'wus-muted')); return group; }
        const wrap = el('div', undefined, 'wus-scroll');
        wrap.tabIndex = 0;
        wrap.setAttribute('role','region');
        wrap.setAttribute('aria-label',title || 'SEO 資料表');
        const list = el('table', undefined, 'widefat striped');
        const header = list.createTHead().insertRow();
        for (const title of headings) {
            const th = el('th', title); th.scope = 'col'; header.append(th);
        }
        const body = list.createTBody();
        for (const entry of entries) {
            const tr = body.insertRow();
            entry.forEach((value,i) => cell(tr,i === 0,value));
        }
        wrap.append(list); group.append(wrap);
        return group;
    }
    function fmt(key, value) {
        return typeof value !== 'number' ? '—' : number.format(value * (key === 'ctr' ? 100 : 1)) + (key === 'ctr' ? '%' : '');
    }
    const values = row => fields.map(key => fmt(key,row?.[key]));
    const baseline = (row,key) => key === 'ctr' && !(row?.impressions > 0) ? null : row?.[key];
    function time(value) {
        if (!value) return '—';
        const d = new Date(typeof value === 'number' ? value * 1000 : value);
        return isNaN(d) ? '—' : d.toLocaleString('zh-TW');
    }
    function delta(key, now, earlier) {
        if (typeof now !== 'number' || typeof earlier !== 'number') return ['—',''];
        const diff = key === 'position' ? earlier-now : now-earlier;
        if (key !== 'ctr' && earlier <= 0) return ['—',''];
        const change = key === 'ctr' ? diff*100 : key === 'position' ? diff : diff / earlier * 100;
        const value = number.format(Math.abs(change));
        const suffix = key === 'ctr' ? ' 個百分點' : key === 'position' ? ' 名' : '%';
        return [change > 0 ? '↑ +' + value + suffix : change < 0 ? '↓ −' + value + suffix : '持平', change > 0 ? 'wus-up' : change < 0 ? 'wus-down' : ''];
    }
    const msg = text => {get('[data-state]').textContent = text;};
    async function call(op, data = {}) {
        const body = new URLSearchParams({action:'wu_seo_dashboard', nonce:root.dataset.nonce, payload:JSON.stringify({...data,op})});
        const response = await fetch(root.dataset.ajax,{method:'POST', credentials:'same-origin', cache:'no-store', body});
        const json = await response.json();
        if (!json.success) throw Object.assign(new Error(json.data?.message || '更新失敗，請重試。'), {code:json.data?.code});
        return json.data;
    }
    async function poll(id, current = () => true) {
        for (let tick=0; tick<40 && current(); tick++) {
            const job = await call('job',{id});
            if (job.state !== 'pending') return job;
            await new Promise(resolve => setTimeout(resolve, tick < 5 ? 1800 : 4500));
        }
        if (current()) msg('背景同步仍在等待。稍後可按更新取回結果。');
        return null;
    }
    function filterValues() {
        return {...Object.fromEntries(new FormData(form)), compare:form.elements.compare.checked};
    }
    function periodDays(start, end) {
        const dates = [];
        const date = new Date(start + 'T00:00:00Z');
        for (; !isNaN(date) && date.toISOString().slice(0,10)<=end && dates.length<90; date.setUTCDate(date.getUTCDate()+1)) dates.push(date.toISOString().slice(0,10));
        return dates;
    }
    function dayRows(rows, dates) {
        const data = new Map((rows || []).map(r => [r.key,r]));
        return dates.map(key => data.get(key) || {key});
    }
    function chart(host, groups, metric, small = false) {
        const all = groups.flat().filter(r => typeof r[metric] === 'number');
        host.replaceChildren();
        if (!all.length) {
            if (!small) {host.append(el('p','尚無趨勢資料','wus-empty')); get('[data-point]').textContent = '—';}
            return;
        }
        const ns = 'http://www.w3.org/2000/svg';
        const svg = document.createElementNS(ns,'svg');
        const w = small ? 120 : 640, h = small ? 37 : 225, pad = small ? 3 : 36;
        svg.setAttribute('viewBox','0 0 '+w+' '+h);
        if (small) svg.setAttribute('aria-hidden','true');
        else svg.setAttribute('aria-label', labels[metric] + '每日趨勢，方向鍵移動資料點');
        const min = Math.min(...all.map(r=>r[metric])), max = Math.max(...all.map(r=>r[metric]));
        const y = v => {
            const ratio = max === min ? 0.5 : (v-min)/(max-min);
            return pad + (metric === 'position' ? ratio : 1-ratio)*(h-2*pad);
        };
        const shape = (name, attrs) => {
            const node = document.createElementNS(ns,name);
            for (const [k,v] of Object.entries(attrs)) node.setAttribute(k,v);
            svg.append(node);
            return node;
        };
        if (!small) for (let i=0; i<4; i++) {
            const py = pad+i*(h-2*pad)/3;
            shape('line',{x1:pad,x2:w-pad,y1:py,y2:py,class:'wus-gridline'});
            const v = metric === 'position' ? min+(max-min)*i/3 : max-(max-min)*i/3;
            shape('text',{x:pad-5,y:py+4,class:'wus-axis','text-anchor':'end'}).textContent = fmt(metric,v);
        }
        const points = [];
        const count = Math.max(...groups.map(g=>g.length));
        groups.forEach((group,i) => {
            if (i && small) return;
            let segment = '', inLine = false;
            const seriesPoints = [];
            group.forEach((item,j) => {
                if (typeof item[metric] !== 'number') {inLine=false; return;}
                const x = count < 2 ? w/2 : pad + j*(w-2*pad)/(count-1);
                const py = y(item[metric]);
                segment += (inLine ? ' L ' : ' M ') + x + ' ' + py;
                inLine = true;
                seriesPoints.push([item,x,py]);
            });
            shape('path',{d:segment,fill:'none',class:i?'wus-series wus-prior':'wus-series','vector-effect':'non-scaling-stroke'});
            for (const [item,x,py] of seriesPoints) {
                if (small && seriesPoints.length > 1) continue;
                const circle = shape('circle',{cx:x,cy:py,r:small?2:4,class:i?'wus-point wus-prior':'wus-point'});
                if (small) continue;
                const description = (i?'前期 ':'本期 ') + item.key + '　' + labels[metric] + ' ' + fmt(metric,item[metric]);
                const show = () => {get('[data-point]').textContent=description;};
                circle.setAttribute('tabindex',points.length ? '-1' : '0');
                circle.setAttribute('aria-label',description);
                circle.addEventListener('focus',show);
                circle.addEventListener('pointerenter',show);
                circle.addEventListener('pointerdown',show);
                circle.addEventListener('keydown',event => {
                    if (!['ArrowLeft','ArrowRight','Home','End'].includes(event.key)) return;
                    event.preventDefault();
                    const index = points.indexOf(circle);
                    const move = {ArrowLeft:index-1,ArrowRight:index+1,Home:0,End:points.length-1}[event.key];
                    const next = points[Math.max(0,Math.min(points.length-1,move))];
                    circle.setAttribute('tabindex','-1'); next.setAttribute('tabindex','0'); next.focus();
                });
                points.push(circle);
            }
        });
        host.append(svg);
        if (!small) get('[data-point]').textContent = '滑鼠或方向鍵查看資料點';
    }

    function dates() {
        const current = periodDays(period.start,period.end);
        if (!period.compare || !current.length) return [current,[]];
        const first = new Date(period.start+'T00:00:00Z');
        const end = new Date(first.getTime()-86400000).toISOString().slice(0,10);
        const start = new Date(first.getTime()-current.length*86400000).toISOString().slice(0,10);
        return [current,periodDays(start,end)];
    }
    function drawMain() {
        const [current,prior] = dates();
        const data = [dayRows(report.daily,current),dayRows(report.prior_daily,prior)];
        const cards = get('[data-kpis]');
        cards.replaceChildren();
        for (const key of fields) {
            const card = el('article',undefined,'wutm-ui-panel wus-card wus-kpi');
            const [text, tone] = delta(key, report.total?.[0]?.[key], baseline(report.prior_total?.[0],key));
            const spark = el('div',undefined,'wus-spark');
            card.append(el('h2',labels[key]),el('strong',fmt(key,report.total?.[0]?.[key])),el('small',text,tone),spark);
            chart(spark,data,key,true); cards.append(card);
        }
        chart(get('[data-chart]'),data,get('[data-metric]').value);
        get('[data-compare]').hidden = !period.compare;
        const tableHost = get('[data-tables]');
        tableHost.replaceChildren();
        if (selection.type) {
            const node = el('p','目前範圍 · ' + selection.value); tableHost.append(node);
        }
        const total = [ ['本期',...values(report.total?.[0])] ];
        if (period.compare) total.push(['前期',...values(report.prior_total?.[0])]);
        tableHost.append(table('區間總計',['期間',...Object.values(labels)],total));
        for (const [type,key,title] of [['query','queries','查詢（API 前 25 筆）'],['page','pages','頁面（API 前 25 筆）']]) {
            if (selection.type === type) continue;
            tableHost.append(table(title,[type==='query'?'關鍵字':'頁面',...Object.values(labels)],(report[key] || []).map(row => [button(row.key,()=>drill(type,row.key)),...values(row)])));
        }
        const details = el('details'); details.append(el('summary','每日資料與前期區間'));
        details.append(table('本期每日',['日期',...Object.values(labels)],data[0].map(row=>[row.key,...values(row)])));
        if (period.compare) details.append(table('前期每日',['日期',...Object.values(labels)],data[1].map(row=>[row.key,...values(row)])));
        tableHost.append(details);
        get('[data-reset]').hidden = !selection.type;
        drawSummary();
    }

    function drill(type,value) {
        selection = {type,value}; keyword = null; chooseTab('performance',true); performance();
    }
    async function performance(force = false) {
        const serial = ++task;
        period = filterValues();
        report = {}; drawMain();
        if (!connected) {msg('尚未連結 Search Console'); return;}
        get('[data-sync]').textContent = '資料截止日 —　上次同步 —';
        msg('正在同步 Search Console…');
        try {
            const id = await call('performance', {...period,...selection,force});
            const job = await poll(id,()=>task===serial);
            if (!job || task!==serial) return;
            report = job.data || {}; drawMain();
            const dates = (report.daily || []).map(row=>row.key).filter(Boolean).sort();
            get('[data-sync]').textContent = '資料截止日 ' + (dates.at(-1) || '—') + '（太平洋時間）　上次同步 ' + time(job.state==='failed' ? 0 : job.time);
            const errors = Object.entries(job.errors || {});
            const detail = get('[data-errors]');
            detail.hidden = !errors.length;
            get('pre',detail).textContent = JSON.stringify(job.errors || {},null,2);
            if (errors.length) msg((job.state==='partial'?'部分資料更新失敗 · ':'更新失敗 · ')+errors[0][1].message);
            else msg(report.total?.length ? '' : '此期間或篩選沒有搜尋資料');
            if (keyword) drawKeyword(report,keyword);
        } catch (error) {if (task===serial) msg(error.message);}
    }

    function drawSummary() {
        const old = new Map((report.prior_queries || []).map(r=>[r.key,r.position]));
        const list = (report.queries || []).filter(r=>typeof r.position==='number' && typeof old.get(r.key)==='number')
            .map(r=>[r,old.get(r.key)-r.position]).filter(([,diff])=>diff!==0);
        const host = get('[data-movers]'); host.replaceChildren();
        for (const [sign, heading] of [[1,'進步'],[-1,'下滑']]) {
            const row = list.filter(([,d])=>Math.sign(d)===sign).sort((a,b)=>Math.abs(b[1])-Math.abs(a[1])).slice(0,3);
            host.append(el('small',heading));
            for (const [item,d] of row) {
                const line = el('p'); line.append(button(item.key,()=>drill('query',item.key)), el('span', (d>0?' ↑ ':' ↓ ')+number.format(Math.abs(d)), d>0?'wus-up':'wus-down')); host.append(line);
            }
            if (!row.length) host.append(el('p','—'));
        }
        host.append(el('small','兩期共同回傳的查詢'));
        const watch = get('[data-watch]'); watch.replaceChildren();
        const pages = (report.pages || []).filter(r=>typeof r.impressions==='number').sort((a,b)=>b.impressions-a.impressions).slice(0,3);
        for (const row of pages) {
            const line = el('p'); line.append(button(row.key,()=>drill('page',row.key)),el('small','　'+fmt('impressions',row.impressions)+' 曝光')); watch.append(line);
        }
        if (!pages.length) watch.append(el('p','尚無頁面資料'));
    }

    function details(title,data) {
        const node = el('details'); node.append(el('summary',title),el('pre',JSON.stringify(data,null,2))); return node;
    }
    function drawIndex() {
        const counts = {indexed:0, excluded:0, unknown:0, pending:0, failed:0};
        for (const post of snapshot.posts) counts[post.google?.state || 'pending']++;
        const ring = get('[data-donut]');
        ring.replaceChildren();
        const checked = counts.indexed+counts.excluded+counts.unknown;
        let angle = 0;
        const stops = Object.keys(colors).map(k=>{
            const end = angle + (checked ? counts[k]/checked*100 : 0);
            const stop = colors[k]+' '+angle+'% '+end+'%'; angle=end; return stop;
        });
        const disc = el('div',undefined,'wus-disc');
        if (checked) disc.style.background = 'conic-gradient('+stops.join(',')+')';
        const center = el('div'); center.append(el('strong',checked),el('small','已查詢')); disc.append(center);
        disc.setAttribute('aria-hidden','true');
        const legend = el('div',undefined,'wus-index-legend');
        for (const [key,label] of Object.entries(indexLabels)) {
            const row = button(label+'　'+counts[key],()=>{get('[data-index-filter]').value=key; chooseTab('index',true); drawIndex();});
            row.className='wus-chip wus-'+key; legend.append(row);
        }
        ring.append(disc,legend);
        const selected = get('[data-index-filter]').value;
        const rows = snapshot.posts.filter(p=>!selected || (p.google?.state || 'pending')===selected);
        get('[data-index-table]').replaceChildren(table('目前內容清單',['網址','本機設定','公開 HTTP','Google 索引','查詢時間','操作'],rows.map(post=>{
            const first=el('label',undefined,'wus-check'); const box=el('input'); box.type='checkbox'; box.value=post.id; box.dataset.indexId=post.id;
            first.append(box,el('span',post.title+' · '+post.url));
            const local=el('div',post.local.noindex?'本機 noindex':'本機未設 noindex');
            if (post.local.canonical) local.append(el('p',post.local.canonical));
            const google=el('div',indexLabels[post.google?.state || 'pending'],'wus-chip wus-'+(post.google?.state || 'pending'));
            if (post.google?.detail) {
                if (typeof post.google.detail.coverageState==='string') google.append(el('p',post.google.detail.coverageState));
                const raw=post.google.detail;
                if (raw.lastCrawlTime) google.append(el('p','最後檢索 '+time(raw.lastCrawlTime)));
                const information=el('details');
                information.append(el('summary','Google 檢索與 Canonical'));
                for(const [name,key] of [['檢索允許','robotsTxtState'],['網頁擷取','pageFetchState'],['索引允許','indexingState'],
                    ['使用者宣告 Canonical','userCanonical'],['Google 選擇 Canonical','googleCanonical'],['Google 參照 Sitemap','sitemap']]){
                    const value=Array.isArray(raw[key])?raw[key].join('、'):raw[key];
                    information.append(el('p',name+'　'+(value || '—')));
                }
                information.append(details('原始索引欄位',raw));
                google.append(information);
            }
            const actions=el('div',undefined,'wus-inline');
            actions.append(button('查詢',()=>inspect([post.id])),button('成效',()=>drill('page',post.url)));
            if (post.google?.link) actions.append(link('Search Console',post.google.link));
            const http=post.http ? (post.http.challenge?'Cloudflare 驗證挑戰':post.http.status ? 'HTTP '+post.http.status : '檢查失敗')+'　'+time(post.http.checked_at) : '尚未檢查';
            return [first,local,http,google,time(post.google?.time),actions];
        })));
    }
    async function inspect(ids) {
        if (!ids.length) {msg('請先勾選要查詢的網址'); return;}
        if (ids.length>20) {msg('每批最多 20 個網址'); return;}
        await run('inspect',{ids});
    }
    async function run(op,data={}) {
        msg('已要求背景檢查…');
        try {
            const job = await poll(await call(op,data));
            await loadSnapshot();
            if (!job) return;
            const errors = Object.values(job.errors || {});
            msg(errors.length ? '檢查有未完成項目 · '+errors[0].message : '背景檢查完成');
        } catch (error) {msg(error.message);}
    }

    function drawSitemap() {
        const local = snapshot.sitemap;
        const http = local?.http;
        const job = snapshot.sitemap_job;
        const records = [...(job?.data?.submitted || []), ...(job?.data?.children || [])];
        const urls = new Map(records.map(item=>[item.path,item]));
        if (snapshot.sitemap_url && !urls.has(snapshot.sitemap_url)) urls.set(snapshot.sitemap_url,{});
        for (const child of http?.children || []) if (!urls.has(child)) urls.set(child,{});
        const googleRoot = records.find(r=>r.path===snapshot.sitemap_url);
        const summary = get('[data-sitemap-summary]');
        summary.replaceChildren(el('p',local?.enabled?'● WordPress 原生 Sitemap 已啟用':'○ WordPress 原生 Sitemap 尚未啟用'),
            el('p',http ? (http.valid && http.http === 200 && !http.challenge && !http.error ? '✓ 公開 HTTP／XML 通過' : '！公開 HTTP／XML 需檢查') : '○ 公開 HTTP 尚未檢查'),
            el('p',googleRoot?.lastDownloaded?'✓ Google 最近讀取 '+time(googleRoot.lastDownloaded):'○ Google 尚無讀取證據'));
        const host = get('[data-sitemap-table]');
        host.replaceChildren(el('p','公開 HTTP 檢查時間 '+time(http?.time)+'　Google 同步時間 '+time(job?.time)));
        if (http && (http.error || http.issues?.length || http.challenge || http.http!==200)) host.append(details('公開 HTTP／XML 原因', http));
        if (job && Object.keys(job.errors || {}).length) host.append(details('Google API 查詢原因',job.errors));
        host.append(table('Google Sitemaps API 與原生清單',['Sitemap','Google 狀態','上次讀取','錯誤','警告'],[...urls].map(([url,item])=>[
            link(url,url),item.isPending===true?'等待 Google 處理':item.lastDownloaded?'Google 已讀取':'尚無讀取紀錄',
            time(item.lastDownloaded),item.errors ?? '—',item.warnings ?? '—'
        ])));
        get('[data-publication]').replaceChildren(table('WordPress 首次發布 · 公開 Sitemap',['頁面','檢查結果','檢查時間'],snapshot.posts.filter(p=>p.publication).map(p=>[
            link(p.title,p.url),p.publication.state==='pending'?'等待背景檢查':p.publication.state,time(p.publication.time)
        ])));
    }

    function drawKeywords() {
        const menu=get('[data-keyword-form] select');
        const current=menu.value;
        menu.replaceChildren(new Option('不指定',''));
        for (const post of snapshot.posts) menu.append(new Option(post.title,post.id));
        menu.value=current;
        get('[data-keywords]').replaceChildren(table('已追蹤',['關鍵字','目標頁','操作'],snapshot.keywords.map(item=>{
            const actions=el('div',undefined,'wus-inline');
            actions.append(button('看趨勢',()=>{keyword=item; selection={type:'query',value:item.term}; performance();}),
                button('移除',async()=>{try{snapshot.keywords=await call('keyword_remove',{term:item.term});drawKeywords();}catch(e){msg(e.message);}}));
            return [item.term,item.target_url?link(item.target_url,item.target_url):'—',actions];
        })));
    }
    function drawKeyword(data,item) {
        const host=get('[data-keyword-data]');
        const now=data.total?.[0]; const prior=data.prior_total?.[0];
        const metricRow=['前期差異', ...fields.map(k=>delta(k,now?.[k],baseline(prior,k))[0])];
        const pages=(data.pages || []).filter(row=>row.impressions>0);
        const part=table(item.term,['期間',...Object.values(labels)],[['本期',...values(now)],['前期',...values(prior)],metricRow]);
        host.replaceChildren(part,table('實際取得曝光的頁面',['網址',...Object.values(labels)],pages.map(row=>[
            row.key===item.target_url ? el('strong',row.key+'（目標頁）') : link(row.key,row.key),...values(row)
        ])));
    }

    function chooseTab(key,scroll=false) {
        tab=key;
        for (const panel of panels) panel.hidden = panel.dataset.panel!==key;
        for (const anchor of root.querySelectorAll('.wus-tabs [data-tab]')) {
            if(anchor.dataset.tab===key) anchor.setAttribute('aria-current','page');
            else anchor.removeAttribute('aria-current');
        }
        if (scroll) get('[data-panel="'+key+'"]')?.scrollIntoView({block:'start'});
    }
    async function loadSnapshot() {
        const data=await call('snapshot',{search,post:root.dataset.post});
        snapshot=data; connected=data.connected;
        drawIndex();drawSitemap();drawKeywords();
    }
    root.addEventListener('click', event=>{
        const anchor=event.target.closest('a[data-tab]');
        if (!anchor || ['technical','indexnow'].includes(anchor.dataset.tab) || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); chooseTab(anchor.dataset.tab,!anchor.closest('.wus-tabs'));
        const url=new URL(anchor.href); history.replaceState(null,'',url);
    });
    form.addEventListener('submit',event=>{event.preventDefault();performance(true);});
    get('[data-metric]').addEventListener('change',drawMain);
    get('[data-reset]').addEventListener('click',()=>{selection={};keyword=null;performance();});
    get('[data-index-filter]').addEventListener('change',drawIndex);
    get('[data-inspect]').addEventListener('click',()=>inspect([...root.querySelectorAll('[data-index-id]:checked')].map(box=>box.value)));
    get('[data-sitemap-update]').addEventListener('click',()=>run('sitemaps'));
    get('[data-find]').addEventListener('submit',async event=>{
        event.preventDefault();search=event.target.elements.search.value;
        try {await loadSnapshot();} catch(e) {msg(e.message);}
    });
    get('[data-keyword-form]').addEventListener('submit',async event=>{
        event.preventDefault();
        try {snapshot.keywords=await call('keyword_add',Object.fromEntries(new FormData(event.target))); event.target.reset();drawKeywords();}
        catch(e){msg(e.message);}
    });
    chooseTab(tab);
    drawMain();
    loadSnapshot().then(()=>performance()).catch(error=>msg(error.message));
})();
