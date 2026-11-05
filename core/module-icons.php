<?php
defined('ABSPATH') || exit;
/** Local, code-owned SVG artwork. Never render registry text or uploaded markup as SVG. */
function wutm_module_icon(string $key,array $module=[]): string {
    $paths=[
        'layout'=>'M3 4h18v16H3z M3 9h18 M9 9v11',
        'box'=>'M3 4h18v16H3z M3 9h18 M7 6h1 M11 6h1 M7 13h10 M7 16h7',
        'search'=>'M16 16l5 5 M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0',
        'clock'=>'M12 7v5l4 2 M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0',
        'card'=>'M3 5h18v14H3z M3 9h18 M6 15h5',
        'truck'=>'M2 5h12v12H2z M14 9h4l4 4v4h-8 M8 18a2 2 0 1 1-4 0 2 2 0 0 1 4 0 M20 18a2 2 0 1 1-4 0 2 2 0 0 1 4 0',
        'file'=>'M5 2h9l5 5v15H5z M14 2v6h5 M8 12h8 M8 16h6',
        'list'=>'M8 5h13 M8 12h13 M8 19h13 M3 5h1 M3 12h1 M3 19h1',
        'mail'=>'M2 5h20v14H2z M2 5l10 8L22 5',
        'form'=>'M5 2h14v20H5z M8 7h8 M8 11h8 M8 15h3 M13 15h3 M8 19h8',
        'chart'=>'M3 3v18h18 M7 17v-5 M12 17V7 M17 17V4',
        'shield'=>'M12 2l9 4v6c0 5-9 10-9 10S3 17 3 12V6z M8 12l3 3 5-6',
        'archive'=>'M3 3h18v5H3z M5 8v13h14V8 M9 12h6',
        'users'=>'M15 6a3 3 0 1 1-6 0 3 3 0 0 1 6 0 M5 21v-4a7 7 0 0 1 14 0v4 M20 5a3 3 0 0 1 0 6 M22 21v-4a5 5 0 0 0-2-4',
        'link'=>'M9 15l6-6 M9 6l2-2a5 5 0 0 1 7 7l-2 2 M15 18l-2 2a5 5 0 0 1-7-7l2-2',
        'code'=>'M8 5l-6 7 6 7 M16 5l6 7-6 7 M14 2l-4 20',
        'globe'=>'M22 12a10 10 0 1 1-20 0 10 10 0 0 1 20 0 M2 12h20 M12 2c6 6 6 14 0 20-6-6-6-14 0-20',
        'database'=>'M21 5c0 4-18 4-18 0s18-4 18 0 M3 5v14c0 4 18 4 18 0V5 M3 12c0 4 18 4 18 0',
        'spark'=>'M12 2l3 7 7 3-7 3-3 7-3-7-7-3 7-3z',
        'image'=>'M3 3h18v18H3z M3 17l6-6 4 4 3-3 5 5 M17 7h.01',
        'cart'=>'M2 3h3l3 13h12l2-9H6 M10 21h.01 M19 21h.01',
        'key'=>'M14 8a5 5 0 1 1-10 0 5 5 0 0 1 10 0 M13 12l9 9 M17 16l3-3 M20 19l3-3',
        'play'=>'M8 4l12 8-12 8z',
        'pointer'=>'M4 2l16 11-8 1-4 8z',
        'chat'=>'M3 3h18v14H9l-6 5z M7 8h10 M7 12h6',
        'package'=>'M12 2l10 5v10l-10 5-10-5V7z M2 7l10 5 10-5 M12 12v10 M7 4l10 5',
        'calendar'=>'M3 5h18v16H3z M7 2v6 M17 2v6 M3 10h18 M7 14h3 M14 14h3 M7 18h3',
        'tag'=>'M2 2h9l11 11-9 9L2 11z M7 7h.01',
        'folder'=>'M2 5h8l2 3h10v13H2z',
        'menu'=>'M3 5h18 M3 12h18 M3 19h18',
        'settings'=>'M12 2v4 M12 18v4 M2 12h4 M18 12h4 M5 5l3 3 M16 16l3 3 M5 19l3-3 M16 8l3-3 M16 12a4 4 0 1 1-8 0 4 4 0 0 1 8 0',
    ];
    $rules=['postbox'=>'box','contact-form'=>'form','dashboard|insights|analytics|audit'=>'chart','subscription|schedule|unpublish'=>'calendar','shipping|logistics'=>'truck','invoice|report|pdf'=>'file','payuni|payment|ecpay|newebpay|bacs'=>'card','coupon|badge'=>'tag','seo|search'=>'search','contact-widget|faq|notification'=>'chat','email|smtp'=>'mail','user|member|login'=>'users','backup|restore'=>'archive','security|firewall|protect|restrict'=>'shield','cache|transient|disk'=>'database','media-library'=>'folder','media|image|photo'=>'image','youtube|video'=>'play','cursor'=>'pointer','redirect|link'=>'link','code|snippet|shortcode'=>'code','translate|maps'=>'globe','product|cart|checkout'=>'cart','menu'=>'menu','admin-bar|classic'=>'layout'];
    $kind='settings';foreach($rules as $pattern=>$candidate)if(preg_match('~'.$pattern.'~i',$key)){$kind=$candidate;break;}
    if($kind==='settings')$kind=['AI功能'=>'spark','SEO'=>'search','媒體工具'=>'image','效能優化'=>'database','安全性'=>'shield','監控追蹤'=>'chart','內容管理'=>'file','內容總覽'=>'list','前台介面'=>'layout','備份還原'=>'archive','測試工具'=>'settings'][$module['group']??'']??'settings';
    return '<svg data-wutm-icon="'.$kind.'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="'.$paths[$kind].'"/></svg>';
}
function wutm_toolbox_menu_icon(): string {
    return 'data:image/svg+xml;base64,'.base64_encode('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path fill="#a7aaad" d="M12 1 23 7l-5 3-6-3-6 3-5-3zM1 10l5 3v5l6 3v3L1 18zm22 0v8l-11 6v-3l6-3v-5zM12 10l5 3v4l-5 3-5-3v-4z"/></svg>');
}
