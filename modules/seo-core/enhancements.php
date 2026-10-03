<?php
/** SEO-only extensions. No redirects, crawlers, remote calls, or scheduled scans. */
defined( 'ABSPATH' ) || exit;

trait WUTM_SEO_Enhancements {
    private static function enhancement_defaults() {
        return [
            'organization_type' => 'Organization',
            'organization_legal_name' => '',
            'organization_description' => '',
            'organization_phone' => '',
            'organization_street' => '',
            'organization_city' => '',
            'organization_postcode' => '',
            'about_page' => 0,
            'contact_page' => 0,
            'title_template_post' => '{title}｜{site}',
            'title_template_page' => '{title}｜{site}',
            'title_template_product' => '{title}｜{site}',
            'title_template_term' => '{title}｜{site}',
            'large_image_preview' => 1,
            'avoid_seo_conflicts' => 1,
        ];
    }

    private static function enhancement_init() {
        add_action( 'init', [ __CLASS__, 'register_taxonomy_seo' ], 30 );
        add_filter( 'wp_sitemaps_posts_query_args', [ __CLASS__, 'sitemap_posts_query' ], 10, 2 );
        add_filter( 'wp_sitemaps_posts_entry', [ __CLASS__, 'sitemap_post_entry' ], 10, 3 );
        add_filter( 'wp_sitemaps_taxonomies_query_args', [ __CLASS__, 'sitemap_terms_query' ], 10, 2 );
        add_filter( 'wp_sitemaps_add_provider', [ __CLASS__, 'sitemap_provider' ], 10, 2 );
        add_action( 'save_post', [ __CLASS__, 'invalidate_audit_post' ] );
        add_action( 'updated_post_meta', [ __CLASS__, 'invalidate_audit_meta' ], 10, 3 );
        add_action( 'added_post_meta', [ __CLASS__, 'invalidate_audit_meta' ], 10, 3 );
        add_action( 'deleted_post_meta', [ __CLASS__, 'invalidate_audit_meta' ], 10, 3 );
        add_action( 'before_delete_post', [ __CLASS__, 'invalidate_audit_post' ] );
        add_action( 'update_option_' . self::OPTION_KEY, [ __CLASS__, 'invalidate_audit' ] );
    }

    public static function invalidate_audit() {
        delete_transient( 'wutm_seo_content_audit_v350' );
    }

    public static function invalidate_audit_post( $post_id ) {
        if ( in_array( get_post_type( $post_id ), self::supported_post_types(), true ) ) {
            self::invalidate_audit();
        }
    }

    public static function invalidate_audit_meta( $meta_id, $post_id, $key ) {
        // Do not churn the audit cache for orders, sessions or unrelated metadata.
        if ( 0 === strpos( $key, '_wu_seo_' ) || '_thumbnail_id' === $key ) {
            self::invalidate_audit_post( $post_id );
        }
    }

    private static function sanitize_enhancements( $input ) {
        $out = self::enhancement_defaults();
        foreach ( [ 'organization_legal_name', 'organization_description', 'organization_phone', 'organization_street', 'organization_city', 'organization_postcode' ] as $key ) {
            $out[ $key ] = sanitize_text_field( $input[ $key ] ?? '' );
        }
        $type = $input['organization_type'] ?? 'Organization';
        $out['organization_type'] = in_array( $type, [ 'Organization', 'OnlineStore' ], true ) ? $type : 'Organization';
        foreach ( [ 'about_page', 'contact_page' ] as $key ) {
            $out[ $key ] = absint( $input[ $key ] ?? 0 );
        }
        foreach ( [ 'post', 'page', 'product', 'term' ] as $type ) {
            $key = 'title_template_' . $type;
            $value = sanitize_text_field( $input[ $key ] ?? $out[ $key ] );
            $out[ $key ] = '' !== $value ? $value : '{title}｜{site}';
        }
        foreach ( [ 'large_image_preview', 'avoid_seo_conflicts' ] as $key ) {
            $out[ $key ] = ! empty( $input[ $key ] ) ? 1 : 0;
        }
        return $out;
    }

    /** Detect known SEO owners without loading their admin APIs on the front end. */
    private static function output_is_owned_elsewhere() {
        if ( empty( self::settings()['avoid_seo_conflicts'] ) ) {
            return false;
        }
        $active = array_merge(
            (array) get_option( 'active_plugins', [] ),
            array_keys( (array) get_site_option( 'active_sitewide_plugins', [] ) )
        );
        return (bool) array_intersect( $active, [
            'wordpress-seo/wp-seo.php', 'seo-by-rank-math/rank-math.php',
            'all-in-one-seo-pack/all_in_one_seo_pack.php',
            'autodescription/autodescription.php', 'wp-seopress/seopress.php',
        ] );
    }

    private static function automatic_title( $title, $type = 'post', $page = 1 ) {
        $s = self::settings();
        $template = $s[ 'title_template_' . $type ] ?? '{title}｜{site}';
        $result = strtr( $template, [
            '{title}' => wp_strip_all_tags( $title ),
            '{site}' => self::site_name(),
            '{tagline}' => wp_strip_all_tags( get_bloginfo( 'description' ) ),
        ] );
        return $result . ( $page > 1 ? '｜第 ' . $page . ' 頁' : '' );
    }

    private static function current_term_value( $key ) {
        if ( ! ( is_category() || is_tag() || is_tax() ) ) {
            return '';
        }
        return (string) get_term_meta( get_queried_object_id(), '_wu_seo_' . $key, true );
    }

    public static function register_taxonomy_seo() {
        foreach ( get_taxonomies( [ 'public' => true ], 'objects' ) as $taxonomy ) {
            if ( empty( $taxonomy->show_ui ) || 'post_format' === $taxonomy->name ) {
                continue;
            }
            add_action( $taxonomy->name . '_add_form_fields', [ __CLASS__, 'term_add_fields' ] );
            add_action( $taxonomy->name . '_edit_form_fields', [ __CLASS__, 'term_edit_fields' ] );
            add_action( 'created_' . $taxonomy->name, [ __CLASS__, 'save_term_fields' ] );
            add_action( 'edited_' . $taxonomy->name, [ __CLASS__, 'save_term_fields' ] );
        }
    }

    private static function term_field_controls( $id = 0 ) {
        wp_nonce_field( 'wutm_seo_term_save', 'wutm_seo_term_nonce' );
        echo '<p>控制文章分類、商品分類與標籤的搜尋資訊。留空沿用分類名稱及內容說明；不改變網址。</p>';
        foreach ( [ 'title' => 'SEO 標題', 'description' => 'Meta Description', 'canonical' => 'Canonical（通常留空）' ] as $key => $label ) {
            $value = $id ? get_term_meta( $id, '_wu_seo_' . $key, true ) : '';
            echo '<p><label>' . esc_html( $label ) . '<br><input class="large-text" type="' . ( 'canonical' === $key ? 'url' : 'text' ) . '" name="wu_seo_term_' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '"></label></p>';
        }
        echo '<p><label><input type="checkbox" name="wu_seo_term_noindex" value="1" ' . checked( 1, $id ? get_term_meta( $id, '_wu_seo_noindex', true ) : 0, false ) . '> 不納入搜尋結果（noindex，同時排除 Sitemap）</label></p>';
    }

    public static function term_add_fields() {
        echo '<div class="form-field"><h3>SEO 核心</h3>';
        self::term_field_controls();
        echo '</div>';
    }

    public static function term_edit_fields( $term ) {
        echo '<tr class="form-field"><th scope="row">SEO 核心</th><td>';
        self::term_field_controls( $term->term_id );
        echo '</td></tr>';
    }

    public static function save_term_fields( $term_id ) {
        if ( ! isset( $_POST['wutm_seo_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['wutm_seo_term_nonce'] ) ), 'wutm_seo_term_save' ) ) {
            return;
        }
        $term = get_term( $term_id );
        $taxonomy = $term && ! is_wp_error( $term ) ? get_taxonomy( $term->taxonomy ) : null;
        if ( ! $taxonomy || ! current_user_can( $taxonomy->cap->manage_terms ) ) {
            return;
        }
        foreach ( [ 'title', 'description', 'canonical' ] as $key ) {
            $raw = wp_unslash( $_POST[ 'wu_seo_term_' . $key ] ?? '' );
            $raw = is_string( $raw ) ? $raw : '';
            $value = 'canonical' === $key ? esc_url_raw( $raw, [ 'http', 'https' ] ) : sanitize_text_field( $raw );
            if ( '' === $value ) {
                delete_term_meta( $term_id, '_wu_seo_' . $key );
            } else {
                update_term_meta( $term_id, '_wu_seo_' . $key, $value );
            }
        }
        update_term_meta( $term_id, '_wu_seo_noindex', ! empty( $_POST['wu_seo_term_noindex'] ) ? 1 : 0 );
    }

    private static function indexable_meta_query( $existing = [] ) {
        $indexable = [ 'relation' => 'OR',
            [ 'key' => '_wu_seo_noindex', 'compare' => 'NOT EXISTS' ],
            [ 'key' => '_wu_seo_noindex', 'value' => '1', 'compare' => '!=' ],
        ];
        return $existing ? [ 'relation' => 'AND', $existing, $indexable ] : $indexable;
    }

    public static function sitemap_posts_query( $args, $post_type ) {
        if ( self::output_is_owned_elsewhere() ) {
            return $args;
        }
        $args['meta_query'] = self::indexable_meta_query( $args['meta_query'] ?? [] );
        $args['has_password'] = false;
        return $args;
    }

    public static function sitemap_terms_query( $args, $taxonomy ) {
        if ( ! self::output_is_owned_elsewhere() ) {
            $args['meta_query'] = self::indexable_meta_query( $args['meta_query'] ?? [] );
        }
        return $args;
    }

    public static function sitemap_post_entry( $entry, $post, $post_type ) {
        if ( ! self::output_is_owned_elsewhere() && ! empty( $post->post_modified_gmt ) && '0000-00-00 00:00:00' !== $post->post_modified_gmt ) {
            $entry['lastmod'] = gmdate( DATE_W3C, strtotime( $post->post_modified_gmt . ' UTC' ) );
        }
        return $entry;
    }

    public static function sitemap_provider( $provider, $name ) {
        return ! self::output_is_owned_elsewhere() && 'users' === $name && ! empty( self::settings()['noindex_author_archives'] ) ? false : $provider;
    }

    private static function published_page_url( $id ) {
        $page = $id ? get_post( $id ) : null;
        return $page && 'page' === $page->post_type && 'publish' === $page->post_status && empty( $page->post_password ) ? get_permalink( $page ) : '';
    }

    private static function enrich_schema( $graph, $canonical ) {
        $s = self::settings();
        $about = self::published_page_url( $s['about_page'] );
        $contact = self::published_page_url( $s['contact_page'] );
        $page_id = $canonical . '#webpage';
        $extra = [];
        foreach ( $graph as &$node ) {
            if ( 'Organization' === $node['@type'] ) {
                $node['@type'] = $s['organization_type'];
                foreach ( [ 'alternateName' => 'organization_alt_name', 'legalName' => 'organization_legal_name', 'description' => 'organization_description', 'email' => 'organization_email', 'telephone' => 'organization_phone' ] as $property => $setting ) {
                    if ( '' !== $s[ $setting ] ) {
                        $node[ $property ] = $s[ $setting ];
                    }
                }
                if ( $s['organization_street'] && $s['organization_city'] ) {
                    $node['address'] = [ '@type' => 'PostalAddress', 'streetAddress' => $s['organization_street'], 'addressLocality' => $s['organization_city'], 'addressCountry' => 'TW' ];
                    if ( $s['organization_postcode'] ) {
                        $node['address']['postalCode'] = $s['organization_postcode'];
                    }
                }
            } elseif ( 'WebSite' === $node['@type'] ) {
                if ( $s['organization_alt_name'] ) {
                    $node['alternateName'] = $s['organization_alt_name'];
                }
            } elseif ( in_array( $node['@type'], [ 'Article', 'TechArticle' ], true ) ) {
                $author_id = (int) get_post_field( 'post_author', get_queried_object_id() );
                $author = get_userdata( $author_id );
                if ( $author && $author->display_name ) {
                    $author_ref = home_url( '/#author-' . $author_id );
                    $person = [ '@type' => 'Person', '@id' => $author_ref, 'name' => $author->display_name ];
                    $author_url = esc_url_raw( $author->user_url, [ 'http', 'https' ] );
                    if ( $author_url ) {
                        $person['url'] = $author_url;
                    } elseif ( empty( $s['noindex_author_archives'] ) ) {
                        $person['url'] = get_author_posts_url( $author_id );
                    }
                    $bio = get_user_meta( $author_id, 'description', true );
                    if ( $bio ) {
                        $person['description'] = self::clean_text( $bio, 500 );
                    }
                    $node['author'] = [ '@id' => $author_ref ];
                    $extra[] = $person;
                }
                $node['mainEntityOfPage'] = [ '@id' => $page_id ];
                $extra[] = [ '@type' => 'WebPage', '@id' => $page_id, 'url' => $canonical, 'name' => get_the_title( get_queried_object_id() ), 'isPartOf' => [ '@id' => home_url( '/#website' ) ], 'inLanguage' => 'zh-TW' ];
            } elseif ( 'WebPage' === $node['@type'] ) {
                if ( is_singular( 'page' ) && get_queried_object_id() === (int) $s['about_page'] && $about ) {
                    $node['@type'] = 'AboutPage';
                    $node['about'] = [ '@id' => home_url( '/#organization' ) ];
                } elseif ( is_singular( 'page' ) && get_queried_object_id() === (int) $s['contact_page'] && $contact ) {
                    $node['@type'] = 'ContactPage';
                    $node['about'] = [ '@id' => home_url( '/#organization' ) ];
                }
            }
        }
        unset( $node );
        $graph = array_merge( $graph, $extra );
        if ( is_category() || is_tag() || is_tax() ) {
            $graph[] = [ '@type' => 'CollectionPage', '@id' => $page_id, 'url' => $canonical, 'name' => single_term_title( '', false ), 'description' => self::seo_description(), 'isPartOf' => [ '@id' => home_url( '/#website' ) ], 'inLanguage' => 'zh-TW' ];
            $breadcrumb = self::hierarchical_breadcrumbs();
            if ( $breadcrumb ) { $graph[] = $breadcrumb; }
        }
        $breadcrumb_id = '';
        foreach ( $graph as $node ) {
            if ( 'BreadcrumbList' === $node['@type'] ) { $breadcrumb_id = $node['@id']; }
        }
        if ( $breadcrumb_id ) {
            foreach ( $graph as &$node ) {
                if ( in_array( $node['@type'], [ 'WebPage', 'CollectionPage', 'AboutPage', 'ContactPage' ], true ) ) {
                    $node['breadcrumb'] = [ '@id' => $breadcrumb_id ];
                }
            }
            unset( $node );
        }
        return $graph;
    }

    private static function hierarchical_breadcrumbs() {
        if ( is_front_page() || ! ( is_singular() || is_category() || is_tag() || is_tax() ) ) { return []; }
        $items = [ [ '@type' => 'ListItem', 'position' => 1, 'name' => '首頁', 'item' => home_url( '/' ) ] ];
        if ( is_singular( 'page' ) ) {
            foreach ( array_reverse( get_post_ancestors( get_queried_object_id() ) ) as $ancestor ) {
                $post = get_post( $ancestor );
                if ( ! $post || 'publish' !== $post->post_status || ! empty( $post->post_password ) ) { continue; }
                $items[] = [ '@type' => 'ListItem', 'position' => count( $items ) + 1, 'name' => get_the_title( $ancestor ), 'item' => get_permalink( $ancestor ) ];
            }
        } elseif ( is_category() || is_tag() || is_tax() ) {
            $term = get_queried_object();
            foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor ) {
                $parent = get_term( $ancestor, $term->taxonomy );
                if ( ! $parent || is_wp_error( $parent ) ) { continue; }
                $link = get_term_link( $parent );
                if ( is_wp_error( $link ) ) { continue; }
                $items[] = [ '@type' => 'ListItem', 'position' => count( $items ) + 1, 'name' => $parent->name, 'item' => $link ];
            }
        }
        $items[] = [ '@type' => 'ListItem', 'position' => count( $items ) + 1, 'name' => is_singular() ? get_the_title( get_queried_object_id() ) : single_term_title( '', false ), 'item' => self::canonical_url() ];
        return [ '@type' => 'BreadcrumbList', '@id' => self::canonical_url() . '#breadcrumb', 'itemListElement' => $items ];
    }

    private static function enhancement_settings_fields( $s ) {
        ?>
        <div class="wu-seo-card">
            <h2>品牌與內容可信度</h2>
            <p>提供真實、公開且與網站內容一致的品牌資料，讓搜尋引擎理解組織與作者。這些設定不會產生虛構評價、認證或保證排名。</p>
            <div class="wu-seo-grid"><label class="wu-seo-label" for="wu-org-type"><strong>組織類型</strong></label><div><select id="wu-org-type" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[organization_type]"><?php foreach ( [ 'Organization' => '一般組織／品牌', 'OnlineStore' => '線上商店' ] as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $s['organization_type'], $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></div></div>
            <?php foreach ( [ 'organization_legal_name' => '法定組織名稱（選填）', 'organization_description' => '品牌／組織說明', 'organization_phone' => '公開聯絡電話（含國碼）', 'organization_street' => '公開街道地址（選填）', 'organization_city' => '所在縣市', 'organization_postcode' => '郵遞區號' ] as $key => $label ) : ?>
                <div class="wu-seo-grid"><label class="wu-seo-label" for="wu-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><div><input id="wu-<?php echo esc_attr( $key ); ?>" class="regular-text wu-seo-input" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>"></div></div>
            <?php endforeach; ?>
            <?php foreach ( [ 'about_page' => '關於我們頁面', 'contact_page' => '聯絡我們頁面' ] as $key => $label ) : ?>
                <div class="wu-seo-grid"><label class="wu-seo-label"><strong><?php echo esc_html( $label ); ?></strong></label><div><?php wp_dropdown_pages( [ 'name' => self::OPTION_KEY . '[' . $key . ']', 'selected' => $s[ $key ], 'show_option_none' => '不指定', 'option_none_value' => '0', 'post_status' => 'publish' ] ); ?></div></div>
            <?php endforeach; ?>
            <p>文章 Schema 會引用實際作者、發佈與更新時間；作者名稱、個人網站與介紹可在 WordPress「使用者 → 個人資料」補充。請讓文章前台同樣顯示作者與相關介紹。</p>
        </div>
        <div class="wu-seo-card">
            <h2>搜尋標題範本與索引一致性</h2>
            <p>範本僅套用未自訂 SEO 標題的內容，支援 <code>{title}</code>、<code>{site}</code>、<code>{tagline}</code>。首頁維持既有標題；分頁自動標示頁碼，不新增轉址。</p>
            <?php foreach ( [ 'post' => '文章／其他公開內容', 'page' => '頁面', 'product' => '商品', 'term' => '分類／標籤／商品分類' ] as $type => $label ) : $key = 'title_template_' . $type; ?>
                <div class="wu-seo-grid"><label class="wu-seo-label" for="wu-<?php echo esc_attr( $key ); ?>"><strong><?php echo esc_html( $label ); ?></strong></label><div><input class="large-text" id="wu-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>"></div></div>
            <?php endforeach; ?>
            <p>分類編輯頁新增 SEO 標題、描述、Canonical 與 noindex。Sitemap 自動排除 noindex 及密碼保護內容，並提供實際更新時間；作者／標籤索引開關也同步套用 Sitemap。</p>
            <?php foreach ( [ 'large_image_preview' => '允許搜尋引擎使用大型圖片預覽（max-image-preview:large）', 'avoid_seo_conflicts' => '偵測其他主流 SEO 外掛時，暫停本核心前台輸出，避免重複 Meta／Schema（建議保持開啟）' ] as $key => $label ) : ?>
                <p><label><input type="checkbox" name="<?php echo esc_attr( self::OPTION_KEY . '[' . $key . ']' ); ?>" value="1" <?php checked( $s[ $key ], 1 ); ?>> <?php echo esc_html( $label ); ?></label></p>
            <?php endforeach; ?>
            <p><a href="https://search.google.com/test/rich-results" target="_blank" rel="noopener noreferrer">Google 複合式搜尋結果測試</a> · <a href="https://search.google.com/search-console" target="_blank" rel="noopener noreferrer">Search Console</a>。儲存後請測試代表性文章、分類及品牌頁；搜尋摘要及排名由搜尋引擎決定。</p>
        </div>
        <?php
    }
}
