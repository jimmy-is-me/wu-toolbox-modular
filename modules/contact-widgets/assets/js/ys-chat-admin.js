/**
 * 浮動聯絡按鈕 — 後台 JS
 *
 * App 清單管理（新增/排序/移除）+ AJAX 儲存 + 浮動通知
 *
 * @since 1.0.0
 */
( function ( $ ) {
    'use strict';

    var apps = ( ysChatAdmin.settings && ysChatAdmin.settings.apps ) || [];

    /**
     * 顯示浮動通知
     */
    function showNotice( message, type ) {
        var $notice = $( '<div>' )
            .addClass( 'ysch-notice ysch-notice--' + type )
            .text( message )
            .appendTo( 'body' );

        setTimeout( function () { $notice.addClass( 'is-visible' ); }, 10 );
        setTimeout( function () {
            $notice.removeClass( 'is-visible' );
            setTimeout( function () { $notice.remove(); }, 300 );
        }, 3000 );
    }

    /**
     * 渲染 App 清單
     */
    function renderApps() {
        var $list  = $( '#ysch-app-list' ).empty();
        var $empty = $( '#ysch-app-empty' );

        if ( ! apps.length ) {
            $empty.text( ysChatAdmin.i18n.emptyApps ).show();
            return;
        }
        $empty.hide();

        apps.forEach( function ( app, idx ) {
            var meta = ysChatAdmin.apps[ app.key ];
            if ( ! meta ) {
                return;
            }

            var $row = $( '<li class="ysch-app-row">' ).attr( 'data-idx', idx );

            // 圖示預覽：有自訂圖示就顯示上傳圖，否則內建 SVG。
            var $rowIcon = $( '<span class="ysch-app-row-icon">' )
                .css( { background: meta.color, color: meta.icon_fg } )
                .appendTo( $row );
            if ( app.icon ) {
                $( '<img>' ).attr( 'src', app.icon ).css( { width: '62%', height: '62%', objectFit: 'contain' } ).appendTo( $rowIcon );
            } else {
                $rowIcon.html( meta.icon );
            }

            var $main = $( '<div class="ysch-app-row-main">' ).appendTo( $row );
            $( '<span class="ysch-app-row-name">' ).text( meta.title ).appendTo( $main );

            // 聯絡內容。
            $( '<input type="text" class="ysch-app-value">' )
                .attr( 'placeholder', meta.placeholder )
                .val( app.value || '' )
                .appendTo( $main );

            // 顯示名稱（所有 app 都可改；留空用預設）。
            $( '<input type="text" class="ysch-app-title">' )
                .attr( 'placeholder', ysChatAdmin.i18n.customTitle + '：' + meta.title )
                .val( app.title || '' )
                .appendTo( $main );

            // 自訂圖示上傳 / 移除。
            var $iconRow = $( '<div class="ysch-app-icon-row">' ).appendTo( $main );
            $( '<button type="button" class="ysch-btn ysch-btn-secondary ysch-app-icon-pick">' )
                .html( '<span class="dashicons dashicons-format-image"></span>' + ysChatAdmin.i18n.uploadIcon )
                .appendTo( $iconRow );
            if ( app.icon ) {
                $( '<button type="button" class="ysch-btn ysch-btn-secondary ysch-app-icon-remove">' )
                    .text( ysChatAdmin.i18n.removeIcon )
                    .appendTo( $iconRow );
            }

            $( '<p class="description">' ).text( meta.desc ).appendTo( $main );

            var $actions = $( '<div class="ysch-app-row-actions">' ).appendTo( $row );
            $( '<button type="button" class="ysch-icon-btn ysch-move-up" title="' + ysChatAdmin.i18n.moveUp + '"><span class="dashicons dashicons-arrow-up-alt2"></span></button>' ).appendTo( $actions );
            $( '<button type="button" class="ysch-icon-btn ysch-move-down" title="' + ysChatAdmin.i18n.moveDown + '"><span class="dashicons dashicons-arrow-down-alt2"></span></button>' ).appendTo( $actions );
            $( '<button type="button" class="ysch-icon-btn ysch-remove" title="' + ysChatAdmin.i18n.remove + '"><span class="dashicons dashicons-no-alt"></span></button>' ).appendTo( $actions );

            $list.append( $row );
        } );
    }

    /**
     * 從 DOM 同步輸入值回 apps 陣列
     */
    function syncInputs() {
        $( '#ysch-app-list .ysch-app-row' ).each( function () {
            var idx = parseInt( $( this ).data( 'idx' ), 10 );
            if ( ! apps[ idx ] ) {
                return;
            }
            apps[ idx ].value = $( this ).find( '.ysch-app-value' ).val() || '';
            var $title = $( this ).find( '.ysch-app-title' );
            apps[ idx ].title = $title.length ? ( $title.val() || '' ) : ( apps[ idx ].title || '' );
        } );
    }

    /* ── App 事件 ─────────────────────────── */

    $( document ).on( 'click', '.ysch-app-add', function () {
        syncInputs();
        apps.push( { key: $( this ).data( 'app' ), value: '', title: '', icon: '' } );
        renderApps();
        // 聚焦新列輸入框。
        $( '#ysch-app-list .ysch-app-row' ).last().find( '.ysch-app-value' ).trigger( 'focus' );
    } );

    $( document ).on( 'click', '#ysch-app-list .ysch-remove', function () {
        syncInputs();
        apps.splice( parseInt( $( this ).closest( '.ysch-app-row' ).data( 'idx' ), 10 ), 1 );
        renderApps();
    } );

    $( document ).on( 'click', '#ysch-app-list .ysch-move-up', function () {
        syncInputs();
        var idx = parseInt( $( this ).closest( '.ysch-app-row' ).data( 'idx' ), 10 );
        if ( idx > 0 ) {
            apps.splice( idx - 1, 0, apps.splice( idx, 1 )[ 0 ] );
            renderApps();
        }
    } );

    $( document ).on( 'click', '#ysch-app-list .ysch-move-down', function () {
        syncInputs();
        var idx = parseInt( $( this ).closest( '.ysch-app-row' ).data( 'idx' ), 10 );
        if ( idx < apps.length - 1 ) {
            apps.splice( idx + 1, 0, apps.splice( idx, 1 )[ 0 ] );
            renderApps();
        }
    } );

    // 每個 App 的自訂圖示上傳。
    $( document ).on( 'click', '#ysch-app-list .ysch-app-icon-pick', function ( e ) {
        e.preventDefault();
        if ( ! window.wp || ! wp.media ) {
            return;
        }
        syncInputs();
        var idx = parseInt( $( this ).closest( '.ysch-app-row' ).data( 'idx' ), 10 );
        var frame = wp.media( { title: ysChatAdmin.i18n.chooseImage, multiple: false, library: { type: 'image' } } );
        frame.on( 'select', function () {
            var att = frame.state().get( 'selection' ).first().toJSON();
            if ( apps[ idx ] ) { apps[ idx ].icon = att.url; }
            renderApps();
        } );
        frame.open();
    } );

    // 移除某 App 的自訂圖示（還原內建圖示）。
    $( document ).on( 'click', '#ysch-app-list .ysch-app-icon-remove', function () {
        syncInputs();
        var idx = parseInt( $( this ).closest( '.ysch-app-row' ).data( 'idx' ), 10 );
        if ( apps[ idx ] ) { apps[ idx ].icon = ''; }
        renderApps();
    } );

    /* ── 顯示條件切換 ─────────────────────── */

    function toggleDisplayFields() {
        var mode = $( '#ysch-display' ).val();
        $( '#ysch-include-wrap' ).toggle( 'include' === mode );
        $( '#ysch-exclude-wrap' ).toggle( 'exclude' === mode );
    }

    $( document ).on( 'change', '#ysch-display', toggleDisplayFields );

    /* ── Media picker ─────────────────────── */

    $( document ).on( 'click', '#ysch-button-icon-pick', function ( e ) {
        e.preventDefault();
        if ( ! window.wp || ! wp.media ) {
            return;
        }
        var frame = wp.media( {
            title: ysChatAdmin.i18n.chooseImage,
            multiple: false,
            library: { type: 'image' },
        } );
        frame.on( 'select', function () {
            var att = frame.state().get( 'selection' ).first().toJSON();
            $( '#ysch-button-icon' ).val( att.url );
        } );
        frame.open();
    } );

    /* ── 儲存 ─────────────────────────────── */

    $( document ).on( 'click', '#ysch-save-btn', function ( e ) {
        e.preventDefault();
        syncInputs();

        var $btn         = $( this );
        var originalHtml = $btn.html();

        var settings = {
            enabled:       $( '#ysch-enabled' ).is( ':checked' ) ? 1 : 0,
            position:      $( 'input[name="ysch-position"]:checked' ).val() || 'right',
            bottom:        parseInt( $( '#ysch-bottom' ).val(), 10 ) || 0,
            side:          parseInt( $( '#ysch-side' ).val(), 10 ) || 0,
            size_outer:    parseInt( $( '#ysch-size-outer' ).val(), 10 ) || 56,
            size_inner:    parseInt( $( '#ysch-size-inner' ).val(), 10 ) || 46,
            button_color:  $( '#ysch-button-color' ).val() || '',
            button_icon:   $( '#ysch-button-icon' ).val() || '',
            icon_style:    $( '#ysch-icon-style' ).val() || 'contain',
            show_desktop:  $( '#ysch-show-desktop' ).is( ':checked' ) ? 1 : 0,
            show_mobile:   $( '#ysch-show-mobile' ).is( ':checked' ) ? 1 : 0,
            tooltip:       $( '#ysch-tooltip' ).val() || 'appname',
            mode:          $( 'input[name="ysch-mode"]:checked' ).val() || 'redirect',
            style_mode:    $( '#ysch-style-mode' ).val() || 'auto',
            display:       $( '#ysch-display' ).val() || 'all',
            include_pages: ( $( '#ysch-include-pages' ).val() || [] ).map( Number ),
            exclude_pages: ( $( '#ysch-exclude-pages' ).val() || [] ).map( Number ),
            apps:          apps,
        };

        $btn.prop( 'disabled', true ).text( ysChatAdmin.i18n.saving );

        $.ajax( {
            url: ysChatAdmin.ajax_url,
            type: 'POST',
            data: {
                action: 'wutm_contact_widgets_save',
                nonce: ysChatAdmin.nonce,
                settings: JSON.stringify( settings ),
            },
            success: function ( response ) {
                if ( response.success ) {
                    showNotice( ysChatAdmin.i18n.saved, 'success' );
                    // 以伺服器清洗後的結果同步（例如 LINE 連結正規化）。
                    if ( response.data && response.data.settings ) {
                        apps = response.data.settings.apps || [];
                        renderApps();
                    }
                } else {
                    showNotice( ( response.data && response.data.message ) || ysChatAdmin.i18n.error, 'error' );
                }
            },
            error: function () {
                showNotice( ysChatAdmin.i18n.error, 'error' );
            },
            complete: function () {
                $btn.prop( 'disabled', false ).html( originalHtml );
            },
        } );
    } );

    /* ── 初始化 ───────────────────────────── */

    $( function () {
        renderApps();
        toggleDisplayFields();
    } );

} )( jQuery );
