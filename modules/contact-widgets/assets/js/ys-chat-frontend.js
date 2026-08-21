/**
 * 浮動聯絡按鈕 — 前端互動（vanilla JS）
 *
 * 兩種點擊模式：
 * - redirect：App 為純 <a> 錨點，點擊直接開啟（預設）。
 * - popup：桌機點擊在「widget 同側角落」彈出 QR Code 卡片（與傳統
 *   click-to-chat 外掛相同的角落彈窗，非全螢幕置中）— QR 由本地 JS
 *   生成，零 iframe、零外部請求；手機仍直接開啟連結。
 *
 * 卡片樣式全部 inline（不依賴 CSS 檔）— 避免 CDN 快取舊樣式造成跑版。
 * 本檔不做任何自動導向、不自動開任何視窗 — 一切都由使用者點擊觸發。
 *
 * @since 1.1.4
 */
( function () {
    'use strict';

    var MOBILE_QUERY = '(max-width: 767px), (hover: none) and (pointer: coarse)';
    var FONT_STACK   = '-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Noto Sans TC",sans-serif';

    function isMobileLike() {
        try {
            return window.matchMedia( MOBILE_QUERY ).matches;
        } catch ( e ) {
            return false;
        }
    }

    function i18n( key, fallback ) {
        try {
            return ( window.ysChatWidgets && window.ysChatWidgets.i18n && window.ysChatWidgets.i18n[ key ] ) || fallback;
        } catch ( e ) {
            return fallback;
        }
    }

    /** 批次設定 inline style。 */
    function css( el, styles ) {
        for ( var k in styles ) {
            el.style[ k ] = styles[ k ];
        }
    }

    /* ── QR 卡片（widget 同側角落彈出） ────── */

    var qrCard = null;

    function closeQrCard() {
        if ( qrCard && qrCard.parentNode ) {
            qrCard.parentNode.removeChild( qrCard );
        }
        qrCard = null;
        document.removeEventListener( 'keydown', onEscClose, true );
        document.removeEventListener( 'click', onOutsideClose, true );
    }

    function onEscClose( e ) {
        if ( 'Escape' === e.key ) {
            closeQrCard();
        }
    }

    function onOutsideClose( e ) {
        if ( qrCard && ! qrCard.contains( e.target ) ) {
            closeQrCard();
        }
    }

    /**
     * 開啟 QR 卡片（錨定在 widget 同側角落）
     *
     * @param {Object} data { url, label, value, color, fg, anchor }
     */
    function openQrCard( data ) {
        if ( 'function' !== typeof window.qrcode ) {
            window.open( data.url, '_blank', 'noopener' );
            return;
        }

        closeQrCard();

        var dataUrl = '';
        try {
            var qr = window.qrcode( 0, 'M' );
            qr.addData( data.url );
            qr.make();
            dataUrl = qr.createDataURL( 6, 12 );
        } catch ( e ) {
            window.open( data.url, '_blank', 'noopener' );
            return;
        }

        // 錨定位置：與 widget 同側（或置中）、疊在 toggle 上方。
        var a        = data.anchor;
        var isCenter = 'center' === a.side;
        var isLeft   = 'left' === a.side;
        var baseTransform = isCenter ? 'translateX(-50%) ' : '';

        qrCard = document.createElement( 'div' );
        qrCard.setAttribute( 'role', 'dialog' );
        qrCard.setAttribute( 'aria-label', data.label );
        css( qrCard, {
            position: 'fixed',
            bottom: ( a.bottom + a.toggleH + 14 ) + 'px',
            width: '272px',
            maxWidth: 'calc(100vw - 32px)',
            background: '#ffffff',
            borderRadius: '14px',
            overflow: 'hidden',
            boxShadow: '0 10px 34px rgba(0,0,0,0.28)',
            zIndex: '99999',
            fontFamily: FONT_STACK,
            opacity: '0',
            transform: baseTransform + 'translateY(8px) scale(0.97)',
            transition: 'opacity 0.16s ease, transform 0.16s ease',
        } );
        if ( isCenter ) {
            qrCard.style.left = a.center_x + 'px';
        } else {
            qrCard.style[ isLeft ? 'left' : 'right' ] = a.side_px + 'px';
        }
        qrCard.setAttribute( 'data-basetransform', baseTransform );

        var header = document.createElement( 'div' );
        css( header, {
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            padding: '11px 14px',
            background: data.color,
            color: data.fg,
        } );

        var title = document.createElement( 'span' );
        title.textContent = data.label;
        css( title, { fontSize: '14px', fontWeight: '700', lineHeight: '1' } );
        header.appendChild( title );

        var closeBtn = document.createElement( 'button' );
        closeBtn.type = 'button';
        closeBtn.setAttribute( 'aria-label', i18n( 'close', 'Close' ) );
        closeBtn.innerHTML = '&#10005;';
        css( closeBtn, {
            border: 'none',
            background: 'transparent',
            color: data.fg,
            fontSize: '15px',
            lineHeight: '1',
            cursor: 'pointer',
            padding: '2px 4px',
            opacity: '0.85',
        } );
        closeBtn.addEventListener( 'click', closeQrCard );
        header.appendChild( closeBtn );
        qrCard.appendChild( header );

        var body = document.createElement( 'div' );
        css( body, {
            padding: '16px 16px 18px',
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'center',
            gap: '8px',
        } );

        var img = document.createElement( 'img' );
        img.alt = 'QR Code — ' + data.label;
        img.src = dataUrl;
        css( img, {
            width: '184px',
            height: '184px',
            imageRendering: 'pixelated',
            border: '1px solid #edf1f4',
            borderRadius: '8px',
            display: 'block',
        } );
        body.appendChild( img );

        var hint = document.createElement( 'p' );
        hint.textContent = i18n( 'scanQr', 'Scan with your phone' );
        css( hint, { margin: '0', fontSize: '13px', color: '#2c3e50', fontWeight: '600' } );
        body.appendChild( hint );

        if ( data.value ) {
            var val = document.createElement( 'p' );
            val.textContent = data.value;
            css( val, {
                margin: '0',
                fontSize: '12px',
                color: '#7b8a96',
                wordBreak: 'break-all',
                textAlign: 'center',
                maxWidth: '100%',
            } );
            body.appendChild( val );
        }

        var open = document.createElement( 'a' );
        open.href = data.url;
        if ( /^https?:\/\//i.test( data.url ) ) {
            open.target = '_blank';
            open.rel = 'noopener nofollow';
        }
        open.textContent = i18n( 'openLink', 'Open directly' );
        css( open, {
            display: 'inline-block',
            marginTop: '2px',
            padding: '8px 20px',
            borderRadius: '18px',
            fontSize: '13px',
            fontWeight: '600',
            textDecoration: 'none',
            background: data.color,
            color: data.fg,
        } );
        body.appendChild( open );

        qrCard.appendChild( body );
        document.body.appendChild( qrCard );

        // 入場動畫（置中時保留 translateX(-50%)）。
        requestAnimationFrame( function () {
            if ( qrCard ) {
                qrCard.style.opacity = '1';
                qrCard.style.transform = baseTransform + 'translateY(0) scale(1)';
            }
        } );

        // 點卡片外關閉（capture 延後掛避免吃到當下這次點擊）。
        setTimeout( function () {
            document.addEventListener( 'keydown', onEscClose, true );
            document.addEventListener( 'click', onOutsideClose, true );
        }, 0 );

        closeBtn.focus();
    }

    /* ── 初始化 ───────────────────────────── */

    function init() {
        var wrap = document.getElementById( 'wu-contact-widgets' );
        if ( ! wrap ) {
            return;
        }
        var toggle = wrap.querySelector( '.ysch-toggle' );
        if ( ! toggle ) {
            return;
        }

        var iconOpen  = toggle.querySelector( '.ysch-toggle-open' );
        var iconClose = toggle.querySelector( '.ysch-toggle-close' );

        function setOpen( open ) {
            wrap.classList.toggle( 'ysch-open', open );
            toggle.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
            // 開/關 icon 交換由 JS 直接控制 inline opacity（inline 樣式已蓋過 CSS，
            // 故不能依賴 CSS 的 .ysch-open 規則做交換）。
            if ( iconOpen ) { iconOpen.style.opacity = open ? '0' : '1'; }
            if ( iconClose ) { iconClose.style.opacity = open ? '1' : '0'; }
        }

        toggle.addEventListener( 'click', function () {
            setOpen( ! wrap.classList.contains( 'ysch-open' ) );
        } );

        document.addEventListener( 'click', function ( e ) {
            if ( wrap.classList.contains( 'ysch-open' ) && ! wrap.contains( e.target ) && ! ( qrCard && qrCard.contains( e.target ) ) ) {
                setOpen( false );
            }
        } );

        document.addEventListener( 'keydown', function ( e ) {
            if ( 'Escape' === e.key && wrap.classList.contains( 'ysch-open' ) && ! qrCard ) {
                setOpen( false );
                toggle.focus();
            }
        } );

        // popup（QR）模式：桌機攔截 App 點擊 → 同側角落彈 QR 卡片；手機放行直接開。
        if ( 'popup' === wrap.getAttribute( 'data-mode' ) ) {
            wrap.addEventListener( 'click', function ( e ) {
                var item = e.target && e.target.closest ? e.target.closest( '.ysch-item' ) : null;
                if ( ! item || isMobileLike() ) {
                    return;
                }
                e.preventDefault();

                var cs = getComputedStyle( wrap );
                var rect = wrap.getBoundingClientRect();
                var isLeft = wrap.classList.contains( 'ysch-pos-left' );
                var isCenter = wrap.classList.contains( 'ysch-pos-center' );
                openQrCard( {
                    url:   item.getAttribute( 'href' ),
                    label: item.getAttribute( 'data-applabel' ) || '',
                    value: item.getAttribute( 'data-appvalue' ) || '',
                    color: item.getAttribute( 'data-appcolor' ) || '#8fa8b8',
                    fg:    item.getAttribute( 'data-appfg' ) || '#ffffff',
                    anchor: {
                        side:    isCenter ? 'center' : ( isLeft ? 'left' : 'right' ),
                        side_px: parseInt( isLeft ? cs.left : cs.right, 10 ) || 20,
                        center_x: rect.left + rect.width / 2,
                        bottom:  parseInt( cs.bottom, 10 ) || 30,
                        toggleH: toggle.offsetHeight || 56,
                    },
                } );
            } );
        }

        // ── 相容模式：auto = 偵測被主題破壞才強制（自動防呆） ──
        var styleMode = wrap.getAttribute( 'data-stylemode' ) || 'auto';
        if ( 'auto' === styleMode ) {
            setupAutoHeal( wrap, toggle );
        }
    }

    /** 以 !important 逐條套用（JS 端最高優先級，連 stylesheet !important 都壓得過）。 */
    function setImportant( el, map ) {
        if ( ! el ) { return; }
        for ( var k in map ) {
            try { el.style.setProperty( k, map[ k ], 'important' ); } catch ( e ) {}
        }
    }

    /** 判斷主按鈕是否被主題破壞（底色被清成透明，或尺寸明顯小於設定值）。 */
    function toggleLooksBroken( toggle, outer ) {
        var cs = getComputedStyle( toggle );
        var bg = cs.backgroundColor;
        var transparent = ( 'rgba(0, 0, 0, 0)' === bg || 'transparent' === bg );
        var w = parseFloat( cs.width ) || 0;
        return transparent || w < ( outer - 12 );
    }

    /** 強制套用關鍵樣式（用 widget 上的 data 色值與尺寸 — 尊重使用者設定）。 */
    function forceStyles( wrap, toggle ) {
        var color = wrap.getAttribute( 'data-color' ) || '#8fa8b8';
        var fg    = wrap.getAttribute( 'data-fg' ) || '#ffffff';
        var outer = ( parseInt( wrap.getAttribute( 'data-outer' ), 10 ) || 56 ) + 'px';
        var inner = ( parseInt( wrap.getAttribute( 'data-inner' ), 10 ) || 46 ) + 'px';
        setImportant( toggle, {
            'position': 'relative', 'box-sizing': 'border-box', 'display': 'flex',
            'align-items': 'center', 'justify-content': 'center',
            'width': outer, 'height': outer, 'min-width': outer,
            'padding': '0', 'margin': '0', 'border': 'none', 'border-radius': '50%',
            'background': color, 'color': fg, 'cursor': 'pointer', 'line-height': '0',
            'box-shadow': '0 4px 14px rgba(0,0,0,0.22)'
        } );
        var spans = toggle.querySelectorAll( '.ysch-toggle-open, .ysch-toggle-close' );
        for ( var i = 0; i < spans.length; i++ ) {
            setImportant( spans[ i ], { 'position': 'absolute', 'inset': '0', 'display': 'flex', 'align-items': 'center', 'justify-content': 'center', 'margin': '0' } );
        }
        var items = wrap.querySelectorAll( '.ysch-item' );
        for ( var j = 0; j < items.length; j++ ) {
            var icon = items[ j ].querySelector( '.ysch-icon' );
            setImportant( icon, {
                'box-sizing': 'border-box', 'display': 'flex', 'align-items': 'center', 'justify-content': 'center',
                'width': inner, 'height': inner, 'min-width': inner, 'border-radius': '50%',
                'background': items[ j ].getAttribute( 'data-appcolor' ) || color,
                'color': items[ j ].getAttribute( 'data-appfg' ) || '#ffffff', 'flex': '0 0 auto', 'line-height': '0'
            } );
        }
    }

    /** 自動防呆：載入後多個時點檢查，一旦偵測破壞即強制修正。 */
    function setupAutoHeal( wrap, toggle ) {
        var healed = false;
        var outer = parseInt( wrap.getAttribute( 'data-outer' ), 10 ) || 56;
        function heal() {
            if ( healed ) { return; }
            if ( toggleLooksBroken( toggle, outer ) ) {
                forceStyles( wrap, toggle );
                healed = true;
            }
        }
        heal();
        // 主題 CSS 可能較晚套用 → 多檢查幾次。
        window.addEventListener( 'load', heal );
        setTimeout( heal, 300 );
        setTimeout( heal, 1000 );
        setTimeout( heal, 2500 );
    }

    if ( 'loading' === document.readyState ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
} )();
