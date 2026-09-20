<?php
/**
 * Module: layout-inspector
 * WUMETAX Layout Inspector adapted for WU Toolbox Modular.
 */
defined('ABSPATH') || exit;

const WUTM_LAYOUT_INSPECTOR_OPTION = 'wutm_layout_inspector_options';

function wutm_layout_inspector_defaults(): array {
    return [
        'section_width'   => 1521,
        'container_width' => 1361,
        'tolerance'       => 12,
    ];
}

function wutm_layout_inspector_options(): array {
    $saved = get_option(WUTM_LAYOUT_INSPECTOR_OPTION, []);
    return wp_parse_args(is_array($saved) ? $saved : [], wutm_layout_inspector_defaults());
}

function wutm_layout_inspector_sanitize($input): array {
    $input = is_array($input) ? $input : [];
    $defaults = wutm_layout_inspector_defaults();
    return [
        'section_width'   => max(320, min(3840, absint($input['section_width'] ?? $defaults['section_width']))),
        'container_width' => max(280, min(3840, absint($input['container_width'] ?? $defaults['container_width']))),
        'tolerance'       => max(0, min(200, absint($input['tolerance'] ?? $defaults['tolerance']))),
    ];
}

add_action('admin_init', function (): void {
    register_setting('wutm_layout_inspector_group', WUTM_LAYOUT_INSPECTOR_OPTION, [
        'type'              => 'array',
        'sanitize_callback' => 'wutm_layout_inspector_sanitize',
        'default'           => wutm_layout_inspector_defaults(),
    ]);
});

add_action('admin_menu', function (): void {
    add_submenu_page(
        'wu-toolbox-modular',
        '版面檢查器',
        '版面檢查器',
        'manage_options',
        'wu-layout-inspector',
        'wutm_layout_inspector_settings_page'
    );
}, 999);

function wutm_layout_inspector_settings_page(): void {
    if (!current_user_can('manage_options')) return;
    $options = wutm_layout_inspector_options();
    ?>
    <div class="wrap wutm-module-wrap">
        <h1>版面檢查器</h1>
        <p class="wutm-module-subtitle">登入管理員後，從網站前台管理列點擊「版面檢查」即可掃描頁面區塊；關閉面板時會清除所有檢查框線。</p>
        <form method="post" action="options.php">
            <?php settings_fields('wutm_layout_inspector_group'); ?>
            <div class="card" style="max-width:900px;padding:24px;">
                <h2 style="margin-top:0;">版面基準數值</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="wutm-li-section">Section 基準寬度</label></th>
                        <td><input id="wutm-li-section" type="number" min="320" max="3840" step="1" name="<?php echo esc_attr(WUTM_LAYOUT_INSPECTOR_OPTION); ?>[section_width]" value="<?php echo esc_attr($options['section_width']); ?>"> px<p class="description">頁面最外層區塊的正式設計寬度。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wutm-li-container">Container 基準寬度</label></th>
                        <td><input id="wutm-li-container" type="number" min="280" max="3840" step="1" name="<?php echo esc_attr(WUTM_LAYOUT_INSPECTOR_OPTION); ?>[container_width]" value="<?php echo esc_attr($options['container_width']); ?>"> px<p class="description">區塊內主要內容容器的正式設計寬度。</p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="wutm-li-tolerance">容許誤差</label></th>
                        <td><input id="wutm-li-tolerance" type="number" min="0" max="200" step="1" name="<?php echo esc_attr(WUTM_LAYOUT_INSPECTOR_OPTION); ?>[tolerance]" value="<?php echo esc_attr($options['tolerance']); ?>"> px<p class="description">因捲軸、子像素或佈景主題計算造成的合理誤差範圍。</p></td>
                    </tr>
                </table>
                <?php submit_button('儲存基準數值'); ?>
            </div>
        </form>
    </div>
    <?php
}

add_action('admin_bar_menu', function ($admin_bar): void {
    if (is_admin() || !current_user_can('manage_options')) return;
    $admin_bar->add_node([
        'id'    => 'wumetax-layout-inspector',
        'title' => '版面檢查',
        'href'  => '#wumetax-layout-inspector',
        'meta'  => ['title' => '開啟／關閉 Wumetax 版面檢查器'],
    ]);
}, 100);

add_action('wp_footer', function (): void {
    if (!current_user_can('manage_options')) return;
    $options = wutm_layout_inspector_options();
    $config = [
        'sectionWidth'   => (int) $options['section_width'],
        'containerWidth' => (int) $options['container_width'],
        'tolerance'      => (int) $options['tolerance'],
    ];
    ?>
    <style id="wutm-layout-inspector-css">
    #wutm-layout-inspector{position:fixed;z-index:2147483645;right:18px;bottom:18px;width:480px;max-width:calc(100vw - 36px);max-height:78vh;overflow:auto;border:1px solid #d9dedb;border-radius:14px;background:#fff;box-shadow:0 20px 70px rgba(0,0,0,.2);color:#171b19;font:12px/1.55 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;text-align:left}
    #wutm-layout-inspector.is-hidden{display:none!important}.wuli-head{position:sticky;top:0;z-index:10;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid #2c332f;background:#171b19;color:#fff}.wuli-head strong,.wuli-head small{display:block}.wuli-head small{margin-top:2px;color:#aeb8b2;font-size:10px}.wuli-close{display:flex;align-items:center;justify-content:center;width:30px;height:30px;padding:0;border:0;border-radius:6px;background:rgba(255,255,255,.08);color:#fff;font-size:20px;cursor:pointer}.wuli-close:hover{background:rgba(255,255,255,.16)}.wuli-body{padding:15px}.wuli-standard{margin-bottom:14px;padding:12px 13px;border:1px solid #cfe3d5;border-radius:9px;background:#f2f8f4}.wuli-standard-title,.wuli-section-title{color:#347c4b;font-size:10px;font-weight:800;letter-spacing:.06em}.wuli-standard-row{display:flex;justify-content:space-between;gap:12px;padding:3px 0;color:#69736e;font-size:10px}.wuli-standard-row strong{color:#171b19;font-size:11px}.wuli-summary{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin-bottom:14px}.wuli-stat{padding:10px;border:1px solid #e3e8e5;border-radius:9px;background:#f7f8f7}.wuli-stat span{display:block;color:#7a857f;font-size:10px}.wuli-stat strong{display:block;margin-top:3px;font-size:15px}.wuli-actions{display:flex;flex-wrap:wrap;gap:7px;margin-bottom:15px}.wuli-button{padding:7px 10px;border:1px solid #d8dfda;border-radius:7px;background:#fff;color:#171b19;font-size:11px;font-weight:600;cursor:pointer}.wuli-button:hover{background:#f2f7f4}.wuli-section-title{margin:17px 0 8px}.wuli-row{margin-bottom:8px;padding:11px;border:1px solid #e3e8e5;border-radius:9px;background:#fff;cursor:pointer}.wuli-row:hover{border-color:#4fa567}.wuli-row.is-good{border-color:#b9dec3;background:#f5fbf7}.wuli-row.is-warning{border-color:#e7b24c;background:#fffaf0}.wuli-row-title{font-weight:700;word-break:break-all}.wuli-row-meta{margin-top:5px;color:#69736e;font-size:10px}.wuli-row-status{margin-top:5px;font-size:9px;font-weight:700}.is-good .wuli-row-status{color:#347c4b}.is-warning .wuli-row-status{color:#9d6516}.wuli-details{margin-top:12px;padding:13px;border-radius:9px;background:#111613;color:#cbd5ce;font:10px/1.7 ui-monospace,SFMono-Regular,Menlo,monospace;white-space:pre-wrap;word-break:break-word}.wu-layout-debug-section{outline:2px dashed rgba(79,165,103,.82)!important;outline-offset:-2px}.wu-layout-debug-inner{outline:2px solid rgba(49,118,214,.9)!important;outline-offset:-2px}.wu-layout-debug-warning{outline:3px solid rgba(230,153,45,.95)!important;outline-offset:-3px}.wu-layout-debug-selected{outline:4px solid #e74848!important;outline-offset:-4px!important}
    @media(max-width:700px){#wutm-layout-inspector{left:10px;right:10px;bottom:10px;width:auto;max-width:none;max-height:60vh}}
    </style>
    <div id="wutm-layout-inspector" class="is-hidden" aria-live="polite">
        <div class="wuli-head"><div><strong>Wumetax Layout Inspector</strong><small>Section <?php echo esc_html($config['sectionWidth']); ?>px · Container <?php echo esc_html($config['containerWidth']); ?>px</small></div><button type="button" class="wuli-close" id="wuli-close" title="關閉版面檢查">×</button></div>
        <div class="wuli-body">
            <div class="wuli-standard"><div class="wuli-standard-title">WUMETAX 版面基準</div><div class="wuli-standard-row"><span>Section</span><strong><?php echo esc_html($config['sectionWidth']); ?>px</strong></div><div class="wuli-standard-row"><span>Container</span><strong><?php echo esc_html($config['containerWidth']); ?>px</strong></div><div class="wuli-standard-row"><span>容許誤差</span><strong>±<?php echo esc_html($config['tolerance']); ?>px</strong></div></div>
            <div class="wuli-summary" id="wuli-summary"></div>
            <div class="wuli-actions"><button type="button" class="wuli-button" id="wuli-rescan">重新掃描</button><button type="button" class="wuli-button" id="wuli-toggle-lines">框線</button><button type="button" class="wuli-button" id="wuli-copy">複製完整報告</button></div>
            <div class="wuli-section-title">偵測到的頁面區塊</div><div id="wuli-sections"></div>
            <div class="wuli-section-title">目前選取</div><div class="wuli-details" id="wuli-details">點擊任一區塊查看詳細資訊。</div>
        </div>
    </div>
    <script id="wutm-layout-inspector-js">
    (function(){'use strict';
        var config=<?php echo wp_json_encode($config); ?>,panel=document.getElementById('wutm-layout-inspector'),report=[],outlines=true,scanned=false;
        if(!panel)return;
        function round(value){return Math.round(parseFloat(value)||0)}
        function visible(el){if(!el)return false;var r=el.getBoundingClientRect(),c=getComputedStyle(el);return r.width>0&&r.height>0&&c.display!=='none'&&c.visibility!=='hidden'}
        function name(el){if(!el)return '';var value=el.tagName.toLowerCase();if(el.id)value+='#'+el.id;var classes=Array.from(el.classList).filter(function(c){return c.indexOf('wu-layout-debug')!==0}).slice(0,6);if(classes.length)value+='.'+classes.join('.');return value}
        function info(el){var r=el.getBoundingClientRect(),c=getComputedStyle(el);return{element:el,name:name(el),width:round(r.width),height:round(r.height),left:round(r.left),right:round(innerWidth-r.right),cssWidth:c.width,maxWidth:c.maxWidth,marginLeft:c.marginLeft,marginRight:c.marginRight,paddingLeft:c.paddingLeft,paddingRight:c.paddingRight,display:c.display,position:c.position,boxSizing:c.boxSizing}}
        function main(){return document.querySelector('main#main')||document.querySelector('main.site-main')||document.querySelector('#main')||document.body}
        function sections(){var root=main(),items=Array.from(root.querySelectorAll('section,.alignfull,.alignwide')).filter(function(el){return visible(el)&&!(el.parentElement&&el.parentElement.closest('section'))});if(!items.length){var entry=root.querySelector('.entry-content');if(entry)items=Array.from(entry.children).filter(visible)}return items.filter(function(el){var r=el.getBoundingClientRect();return r.width>100&&r.height>20})}
        function inner(section){var sr=section.getBoundingClientRect(),best=null,score=-Infinity;Array.from(section.querySelectorAll(':scope > *,:scope > * > *,:scope > * > * > *')).forEach(function(el){if(!visible(el))return;var r=el.getBoundingClientRect();if(r.width<300||r.height<10||r.width<sr.width*.45)return;var c=getComputedStyle(el),value=Math.max(0,2500-Math.abs(r.width-config.containerWidth)*5)+Math.max(0,400-Math.abs(r.left-(innerWidth-r.right)));if(el.tagName==='DIV')value+=120;if(r.width<sr.width-30)value+=120;if(c.maxWidth&&c.maxWidth!=='none')value+=80;if(value>score){score=value;best=el}});return best||section.firstElementChild||section}
        function chain(el){var root=main(),list=[];while(el&&el!==root&&el!==document.body){var current=info(el);list.push(current.name+' ['+current.width+'px]');el=el.parentElement}return list.reverse()}
        function clear(){['wu-layout-debug-section','wu-layout-debug-inner','wu-layout-debug-warning','wu-layout-debug-selected'].forEach(function(c){document.querySelectorAll('.'+c).forEach(function(el){el.classList.remove(c)})})}
        function details(item){document.getElementById('wuli-details').textContent='Section: '+item.section.name+'\nSection width: '+item.section.width+'px / '+config.sectionWidth+'px\nContainer: '+item.inner.name+'\nContainer width: '+item.inner.width+'px / '+config.containerWidth+'px\nLeft / Right: '+item.inner.left+' / '+item.inner.right+'px\nCSS width: '+item.inner.cssWidth+'\nmax-width: '+item.inner.maxWidth+'\nmargin L/R: '+item.inner.marginLeft+' / '+item.inner.marginRight+'\npadding L/R: '+item.inner.paddingLeft+' / '+item.inner.paddingRight+'\nDOM chain:\n→ '+item.chain.join('\n→ ')}
        function scan(){clear();report=[];var list=document.getElementById('wuli-sections'),items=sections(),good=0,warnings=0;list.innerHTML='';items.forEach(function(section,index){var content=inner(section),sectionInfo=info(section),innerInfo=info(content),warning=Math.abs(sectionInfo.width-config.sectionWidth)>config.tolerance||Math.abs(innerInfo.width-config.containerWidth)>config.tolerance,item={index:index+1,section:sectionInfo,inner:innerInfo,warning:warning,chain:chain(content)};report.push(item);warning?warnings++:good++;if(outlines){section.classList.add('wu-layout-debug-section');content.classList.add('wu-layout-debug-inner');if(warning)content.classList.add('wu-layout-debug-warning')}var row=document.createElement('div');row.className='wuli-row '+(warning?'is-warning':'is-good');row.innerHTML='<div class="wuli-row-title">區塊 '+(index+1)+' · '+sectionInfo.name+'</div><div class="wuli-row-meta">Section '+sectionInfo.width+'px ｜ Container '+innerInfo.width+'px</div><div class="wuli-row-status">'+(warning?'⚠ 與基準不同':'✓ 正確')+'</div>';row.addEventListener('click',function(){document.querySelectorAll('.wu-layout-debug-selected').forEach(function(el){el.classList.remove('wu-layout-debug-selected')});content.classList.add('wu-layout-debug-selected');content.scrollIntoView({behavior:'smooth',block:'center'});details(item)});list.appendChild(row)});document.getElementById('wuli-summary').innerHTML='<div class="wuli-stat"><span>區塊</span><strong>'+items.length+'</strong></div><div class="wuli-stat"><span>正確</span><strong>'+good+'</strong></div><div class="wuli-stat"><span>異常</span><strong>'+warnings+'</strong></div><div class="wuli-stat"><span>基準</span><strong>'+config.sectionWidth+' / '+config.containerWidth+'</strong></div>';scanned=true}
        function buildReport(){var text='WUMETAX LAYOUT REPORT\n================================\nURL: '+location.href+'\nTitle: '+document.title+'\nViewport: '+innerWidth+'px\nExpected Section: '+config.sectionWidth+'px\nExpected Container: '+config.containerWidth+'px\nTolerance: ±'+config.tolerance+'px\nBody classes: '+document.body.className+'\n\n';if(!report.length)return text+'NO SCAN DATA\n';report.forEach(function(item){text+='SECTION '+item.index+'\nSection: '+item.section.name+'\nSection width: '+item.section.width+'px\nContainer: '+item.inner.name+'\nContainer width: '+item.inner.width+'px\nContainer left/right: '+item.inner.left+' / '+item.inner.right+'px\nCSS width: '+item.inner.cssWidth+'\nmax-width: '+item.inner.maxWidth+'\nDOM chain:\n→ '+item.chain.join('\n→ ')+'\nStatus: '+(item.warning?'WARNING':'OK')+'\n\n'});return text}
        function open(){panel.classList.remove('is-hidden');setTimeout(scan,60)}function close(){clear();panel.classList.add('is-hidden')}
        document.getElementById('wuli-rescan').addEventListener('click',scan);document.getElementById('wuli-toggle-lines').addEventListener('click',function(){outlines=!outlines;outlines?scan():clear()});document.getElementById('wuli-copy').addEventListener('click',function(event){if(!scanned)scan();var button=event.currentTarget;navigator.clipboard.writeText(buildReport()).then(function(){var original=button.textContent;button.textContent='已複製';setTimeout(function(){button.textContent=original},1200)})});document.getElementById('wuli-close').addEventListener('click',close);
        var adminButton=document.querySelector('#wp-admin-bar-wumetax-layout-inspector > a');if(adminButton)adminButton.addEventListener('click',function(event){event.preventDefault();event.stopPropagation();panel.classList.contains('is-hidden')?open():close()});
    })();
    </script>
    <?php
}, 9999);
