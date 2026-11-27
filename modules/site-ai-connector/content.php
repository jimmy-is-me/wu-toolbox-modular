<?php
defined( 'ABSPATH' ) || exit;

define( 'SAC_OPTION_CONTENT_TYPES', 'sac_content_types' );

function sac_content_types() {
    $excluded = apply_filters( 'sac_excluded_content_types', [] );
    $dedicated = [ 'attachment', 'revision', 'product', 'product_variation', 'shop_order', 'shop_order_refund', 'shop_coupon', 'shop_subscription', 'wu_entry', 'wutm_message', 'acf-field-group', 'acf-field', 'acf-post-type', 'acf-taxonomy', 'acf-ui-options-page', '_pods_pod', '_pods_field', '_pods_group', SAC_FORM_CPT ];
    return array_filter( get_post_types( [ 'show_ui' => true ], 'objects' ), static function ( $type ) use ( $excluded, $dedicated ) {
        return ! in_array( $type->name, array_merge( $dedicated, (array) $excluded ), true )
            && ( ! $type->_builtin || in_array( $type->name, [ 'post', 'page' ], true ) );
    } );
}

function sac_site_content_policy() {
    return (array) get_option( SAC_OPTION_CONTENT_TYPES, [ 'post' => [ 'read', 'create', 'update' ] ] );
}

function sac_content_operations( $type, $grant ) {
    $owner = get_userdata( (int) ( $grant['user_id'] ?? 0 ) );
    if ( ! $owner || ! is_user_member_of_blog( $owner->ID ) || ! user_can( $owner, 'read' ) ) return [];
    $site = sac_site_content_policy();
    $scope = $grant['content_scope'] ?? [];
    $operations = array_intersect( $site[ $type->name ] ?? [], $scope[ $type->name ] ?? [] );
    $capabilities = [ 'read' => $type->cap->edit_posts, 'create' => $type->cap->create_posts, 'update' => $type->cap->edit_posts, 'publish' => $type->cap->publish_posts ];
    return array_values( array_filter( $operations, static function ( $operation ) use ( $grant, $owner, $capabilities ) {
        return isset( $capabilities[ $operation ] )
            && in_array( 'content:' . $operation, $grant['scopes'], true )
            && user_can( $owner, $capabilities[ $operation ] );
    } ) );
}

function sac_content_target( WP_REST_Request $request, $slug, $operation, $id = null ) {
    $grant = sac_authenticate( $request );
    if ( is_wp_error( $grant ) ) return $grant;
    $types = sac_content_types();
    if ( ! isset( $types[ $slug ] ) || ! in_array( $operation, sac_content_operations( $types[ $slug ], $grant ), true ) ) {
        return new WP_Error( 'sac_content_scope', '此授權無法操作指定內容類型。請確認站點、授權及 WordPress 使用者權限。', [ 'status' => 403 ] );
    }
    $user = get_userdata( $grant['user_id'] );
    $post = null;
    if ( $id !== null ) {
        $post = get_post( $id );
        if ( ! $post ) return new WP_Error( 'sac_not_found', '找不到指定的內容。請先查詢本站內容。', [ 'status' => 404 ] );
        if ( $post->post_type !== $slug ) return new WP_Error( 'sac_content_type_mismatch', '目標 ID 與指定的內容類型不符。請核對類型及 ID。', [ 'status' => 409 ] );
        if ( ! sac_content_can_access_post( $user, $types[ $slug ], $post, $operation ) ) {
            return new WP_Error( 'sac_content_capability', '授權的使用者無權操作這筆內容。', [ 'status' => 403 ] );
        }
    }
    return [ 'type' => $types[ $slug ], 'user' => $user, 'post' => $post ];
}

function sac_content_can_access_post( $user, $type, $post, $operation ) {
    if ( in_array( $post->post_status, [ 'auto-draft', 'inherit', 'trash' ], true ) ) return false;
    $cap = $operation === 'read' ? $type->cap->read_post : $type->cap->edit_post;
    if ( ! user_can( $user, $cap, $post->ID ) ) return false;
    // WordPress core's read_post can fall back to the generic read capability for drafts.
    return $operation !== 'read' || ( is_post_publicly_viewable( $post ) && $post->post_password === '' )
        || user_can( $user, $type->cap->edit_post, $post->ID );
}

function sac_content_legacy_target( WP_REST_Request $request ) {
    $route = substr( $request->get_route(), strlen( '/' . SAC_NAMESPACE ) );
    $url = $request->get_url_params();
    if ( $route !== '/seo/missing' && ! preg_match( '~^/posts(?:/\d+(?:/(?:faq|seo|featured-image))?)?$~D', $route ) ) return null;
    if ( ( $request->has_param( 'post_type' ) && $request['post_type'] !== 'post' )
        || ( isset( $url['id'] ) && (string) $request['id'] !== (string) $url['id'] ) ) {
        return new WP_Error( 'sac_content_type_mismatch', '請用網址中的文章 ID，並透過共用內容工具操作其他類型。', [ 'status' => 409 ] );
    }
    $operation = in_array( $request->get_method(), [ 'GET', 'HEAD' ], true ) ? 'read' : ( isset( $url['id'] ) ? 'update' : 'create' );
    return sac_content_target( $request, 'post', $operation, isset( $url['id'] ) ? (int) $url['id'] : null );
}

function sac_content_fields( $slug, $post = null ) {
    $fields = apply_filters( 'sac_content_field_definitions', [], $slug, $post );
    $fields = is_array( $fields ) ? $fields : [];
    $opted_in = (array) apply_filters( 'sac_oauth_registered_meta', [], $slug );
    foreach ( array_intersect_key( get_registered_meta_keys( 'post', $slug ), array_fill_keys( $opted_in, true ) ) as $key => $definition ) {
        if ( isset( $fields[ $key ] ) || empty( $definition['single'] ) || empty( $definition['show_in_rest'] )
            || ! in_array( $definition['type'], [ 'string', 'integer', 'number', 'boolean' ], true ) ) continue;
        $fields[ $key ] = [
            'label' => ( $definition['label'] ?? '' ) ?: $key, 'type' => $definition['type'],
            'authorize' => static function ( $operation, $user, $post, $type ) use ( $key ) {
                if ( ! $post ) return user_can( $user, $type->cap->create_posts );
                return ( $operation !== 'read' || user_can( $user, 'read_post', $post->ID ) )
                    && user_can( $user, 'edit_post_meta', $post->ID, $key );
            },
            'validate' => static function ( $value ) use ( $definition, $key, $slug ) {
                $clean = rest_sanitize_value_from_schema( $value, [ 'type' => $definition['type'] ], $key );
                return is_wp_error( $clean ) ? $clean : sanitize_meta( $key, $clean, 'post', $slug );
            },
            'read' => static function ( $post ) use ( $key, $definition ) {
                return rest_sanitize_value_from_schema( get_post_meta( $post->ID, $key, true ), [ 'type' => $definition['type'] ], $key );
            },
            'write' => static function ( $id, $value ) use ( $key ) {
                if ( ! current_user_can( 'edit_post_meta', $id, $key ) ) return false;
                update_post_meta( $id, $key, wp_slash( $value ) );
                return metadata_exists( 'post', $id, $key ) && (string) get_post_meta( $id, $key, true ) === (string) $value;
            },
        ];
    }
    if ( function_exists( 'acf_get_field_groups' ) && function_exists( 'acf_get_fields' ) ) {
        $context = [ 'post_type' => $slug ];
        if ( $post ) $context['post_id'] = $post->ID;
        foreach ( acf_get_field_groups( $context ) as $group ) {
            foreach ( (array) acf_get_fields( $group ) as $field ) {
                $name = $field['name'] ?? '';
                if ( $name === '' || isset( $fields[ $name ] ) ) continue;
                $fields[ $name ] = [
                    'label' => $field['label'] ?? $name,
                    'type' => $field['type'] ?? '',
                    'required' => ! empty( $field['required'] ),
                    'unsupported' => '請於 ACF 欄位群組確認欄位，請維護者登錄連接器欄位或從 WordPress 後台編輯。',
                    'is_present' => static function ( $post ) use ( $field ) {
                        return function_exists( 'get_field' ) && metadata_exists( 'post', $post->ID, $field['name'] )
                            && sac_content_value_present( get_field( $field['key'], $post->ID, false ) );
                    },
                ];
            }
        }
    }
    if ( function_exists( 'pods_api' ) && function_exists( 'pods' ) && pods_api()->load_pod( $slug ) ) {
        foreach ( (array) pods( $slug )->fields() as $name => $field ) {
            if ( isset( $fields[ $name ] ) ) continue;
            $fields[ $name ] = [
                'label' => $field['label'] ?? $name,
                'type' => $field['type'] ?? '',
                'required' => ! empty( $field['required'] ),
                'unsupported' => '請於 Pods 欄位設定確認欄位，請維護者登錄連接器欄位或從 WordPress 後台編輯。',
                'is_present' => static function ( $post ) use ( $slug, $name ) {
                    $value = pods( $slug, $post->ID )->field( $name );
                    return $value !== false && sac_content_value_present( $value );
                },
            ];
        }
    }
    return $fields;
}

function sac_content_field_access( $field, $operation, $target ) {
    if ( ! empty( $field['unsupported'] ) || ! is_callable( $field[ $operation ] ?? null )
        || ! is_callable( $field['authorize'] ?? null ) || ( $operation === 'write' && ! is_callable( $field['validate'] ?? null ) ) ) return false;
    try {
        return (bool) $field['authorize']( $operation, $target['user'], $target['post'], $target['type'] );
    } catch ( Throwable $error ) {
        return false;
    }
}

function sac_content_native_fields( $slug ) {
    $supports = [ 'title' => 'title', 'content' => 'editor', 'excerpt' => 'excerpt', 'featured_image_id' => 'thumbnail' ];
    $type = get_post_type_object( $slug );
    $fields = [ 'slug' => true, 'status' => true, 'terms' => (bool) get_object_taxonomies( $slug ),
        'parent_id' => $type->hierarchical, 'author_id' => post_type_supports( $slug, 'author' ) ];
    foreach ( $supports as $field => $feature ) $fields[ $field ] = post_type_supports( $slug, $feature );
    return $fields;
}

function sac_content_extensions( $slug ) {
    return [
        'faq' => post_type_supports( $slug, 'editor' ),
        'seo' => is_post_type_viewable( $slug ) && post_type_supports( $slug, 'title' )
            && (bool) sac_seo_adapter(),
        'featured_image' => post_type_supports( $slug, 'thumbnail' ),
    ];
}

function sac_content_description( $target ) {
    $type = $target['type'];
    $fields = [];
    foreach ( sac_content_fields( $type->name, $target['post'] ) as $name => $field ) {
        $fields[ $name ] = [
            'label' => $field['label'] ?? $name,
            'type' => $field['type'] ?? 'string',
            'required' => ! empty( $field['required'] ),
            'read' => sac_content_field_access( $field, 'read', $target ),
            'write' => sac_content_field_access( $field, 'write', $target ),
            'help' => $field['unsupported'] ?? '',
        ];
    }
    $taxonomies = [];
    foreach ( get_object_taxonomies( $type->name, 'objects' ) as $tax ) {
        if ( ! $tax->show_ui ) continue;
        $taxonomies[ $tax->name ] = [ 'label' => $tax->label, 'hierarchical' => $tax->hierarchical, 'assign' => user_can( $target['user'], $tax->cap->assign_terms ) ];
    }
    return [
        'post_type' => $type->name, 'label' => $type->label, 'show_in_rest' => $type->show_in_rest,
        'fields' => sac_content_native_fields( $type->name ), 'custom_fields' => (object) $fields,
        'required_on_create' => post_type_supports( $type->name, 'title' ) ? [ 'title' ] : [],
        'taxonomies' => (object) $taxonomies, 'features' => sac_content_extensions( $type->name ),
        'seo_source' => sac_has_wumetax_seo_core() ? 'wumetax_seo_core'
            : ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ? 'seo_plugin' : ( $type->name === 'post' ? 'post_excerpt_fallback' : null ) ),
    ];
}

function sac_content_result( $post, $user, $detail = false ) {
    $result = [ 'id' => $post->ID, 'post_type' => $post->post_type, 'title' => $post->post_title, 'status' => $post->post_status, 'modified' => $post->post_modified ];
    $type = get_post_type_object( $post->post_type );
    $edit = $type->_edit_link;
    if ( $edit && user_can( $user, $type->cap->edit_post, $post->ID ) ) {
        $result['edit_url'] = admin_url( sprintf( $edit, $post->ID ) . '&action=edit' );
    }
    if ( ! $detail ) return $result;
    $native = sac_content_native_fields( $post->post_type );
    foreach ( [ 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name' ] as $name => $column ) {
        if ( $native[ $name ] ) $result[ $name ] = $post->$column;
    }
    if ( $native['parent_id'] ) $result['parent_id'] = (int) $post->post_parent;
    if ( $native['author_id'] ) $result['author_id'] = (int) $post->post_author;
    if ( $native['featured_image_id'] ) $result['featured_image_id'] = (int) get_post_thumbnail_id( $post );
    $result['terms'] = [];
    foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
        if ( ! $taxonomy->show_ui ) continue;
        $terms = wp_get_object_terms( $post->ID, $taxonomy->name );
        if ( ! is_wp_error( $terms ) ) {
            $result['terms'][ $taxonomy->name ] = array_map( static function ( $term ) {
                return [ 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug ];
            }, $terms );
        }
    }
    $result['custom_fields'] = [];
    $field_guidance = [];
    $target = [ 'type' => get_post_type_object( $post->post_type ), 'post' => $post, 'user' => $user ];
    foreach ( sac_content_fields( $post->post_type, $post ) as $name => $field ) {
        if ( sac_content_field_access( $field, 'read', $target ) ) {
            try {
                $value = $field['read']( $post, $user );
                if ( ! is_wp_error( $value ) ) {
                    $result['custom_fields'][ $name ] = $value;
                    continue;
                }
            } catch ( Throwable $error ) {
                // Report availability without exposing a third-party exception.
            }
            $field_guidance[ $name ] = '欄位讀取失敗，請檢查綁定或從 WordPress 後台查看。';
        } elseif ( ! empty( $field['unsupported'] ) ) {
            $field_guidance[ $name ] = $field['unsupported'];
        }
    }
    if ( $field_guidance ) $result['field_guidance'] = $field_guidance;
    return $result;
}

function sac_content_error( $field, $reason, $code = 'sac_invalid_field' ) {
    return new WP_Error( $code, $field . '：' . $reason, [ 'status' => 400, 'field' => $field ] );
}

function sac_content_thumbnail( $value, $target ) {
    if ( ! post_type_supports( $target['type']->name, 'thumbnail' ) ) return sac_content_error( 'featured_image_id', '此內容類型未啟用精選圖片。' );
    if ( ! is_int( $value ) || $value < 0 ) return sac_content_error( 'featured_image_id', '請提供媒體庫圖片 ID，或以 0 移除。' );
    if ( $value && ( get_post_type( $value ) !== 'attachment' || ! wp_attachment_is_image( $value ) || ! user_can( $target['user'], 'read_post', $value ) ) ) {
        return sac_content_error( 'featured_image_id', '找不到可存取的媒體庫圖片。請先使用媒體工具確認 ID。' );
    }
    return $value;
}

function sac_content_value_present( $value ) {
    return $value !== null && $value !== '' && $value !== [] && ! is_wp_error( $value );
}

function sac_content_validate_custom( $values, $target ) {
    if ( ! is_array( $values ) ) return sac_content_error( 'custom_fields', '請以欄位名稱指定值。' );
    $fields = sac_content_fields( $target['type']->name, $target['post'] );
    $prepared = [];
    foreach ( $values as $name => $value ) {
        $field = $fields[ $name ] ?? [];
        if ( ! sac_content_field_access( $field, 'write', $target ) ) {
            return sac_content_error( $name, $field['unsupported'] ?? '欄位未登錄或無寫入權限。請至「內容類型與權限」檢查綁定。' );
        }
        $valid = rest_validate_value_from_schema( $value, [ 'type' => $field['type'] ?? 'string' ] + ( $field['schema'] ?? [] ), $name );
        if ( is_wp_error( $valid ) ) return sac_content_error( $name, $valid->get_error_message() );
        try {
            $clean = $field['validate']( $value, $target['post'], $target['user'] );
        } catch ( Throwable $error ) {
            return sac_content_error( $name, '欄位驗證失敗，請檢查欄位綁定。' );
        }
        if ( is_wp_error( $clean ) ) return sac_content_error( $name, $clean->get_error_message() );
        $prepared[ $name ] = [ $field, $clean ];
    }
    foreach ( $fields as $name => $field ) {
        if ( empty( $field['required'] ) ) continue;
        if ( array_key_exists( $name, $prepared ) ) {
            $value = $prepared[ $name ][1];
        } elseif ( $target['post'] && is_callable( $field['is_present'] ?? null ) && $field['is_present']( $target['post'] ) ) {
            continue;
        } else {
            $value = $target['post'] && is_callable( $field['read'] ?? null ) ? $field['read']( $target['post'], $target['user'] ) : null;
        }
        if ( ! sac_content_value_present( $value ) ) {
            return sac_content_error( $name, $field['unsupported'] ?? '請提供必填欄位的值，或從 WordPress 後台補齊。', 'sac_required_field' );
        }
    }
    return $prepared;
}

function sac_content_save( $request, $target, $values = null, $mode = 'review', $preserve_blocks = false ) {
    $post = $target['post'];
    $slug = $target['type']->name;
    $native = sac_content_native_fields( $slug );
    $values = $values ?? ( $request->get_json_params() ?: $request->get_body_params() );
    if ( ! is_array( $values ) ) return sac_content_error( 'body', '請提供欄位與值組成的物件。' );
    if ( ! $post && array_key_exists( 'id', $values ) ) return sac_content_error( 'id', '建立草稿由 WordPress 分配 ID。' );
    $unknown = array_diff( array_keys( $values ), array_merge( array_keys( $native ), [ 'custom_fields', 'id', 'post_type' ] ) );
    if ( $unknown ) return sac_content_error( reset( $unknown ), '欄位未登錄。請先查看內容類型的欄位說明。' );
    $grant = sac_authenticate( $request );
    if ( is_wp_error( $grant ) ) return $grant;
    foreach ( [ 'terms' => 'taxonomy:write', 'featured_image_id' => 'media:update' ] as $field => $scope ) {
        if ( ! array_key_exists( $field, $values ) ) continue;
        $permission = sac_oauth_require_scope( $grant, $scope );
        if ( is_wp_error( $permission ) ) return $permission;
    }
    $data = $post ? [ 'ID' => $post->ID ] : [ 'post_type' => $slug, 'post_author' => $target['user']->ID, 'post_status' => 'draft' ];
    $native_columns = [ 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name' ];
    foreach ( $native_columns as $field => $column ) {
        if ( ! array_key_exists( $field, $values ) ) continue;
        if ( ! $native[ $field ] || ! is_string( $values[ $field ] ) ) return sac_content_error( $field, '此類型未啟用此欄位，或欄位格式錯誤。' );
        $cleaners = [ 'title' => 'sanitize_text_field', 'content' => 'wp_kses_post', 'excerpt' => 'sanitize_textarea_field', 'slug' => 'sanitize_title' ];
        $data[ $column ] = $field === 'content' && $preserve_blocks && user_can( $target['user'], 'unfiltered_html' )
            ? $values[ $field ] : $cleaners[ $field ]( $values[ $field ] );
    }
    if ( ! $post && $native['title'] && empty( $data['post_title'] ) && ( $data['post_title'] ?? '' ) !== '0' ) {
        return sac_content_error( 'title', '請提供必填標題。', 'sac_required_field' );
    }
    if ( array_key_exists( 'parent_id', $values ) ) {
        $parent_id = $values['parent_id'];
        if ( ! $native['parent_id'] || ! is_int( $parent_id ) || $parent_id < 0 ) return sac_content_error( 'parent_id', '請提供有效上層 ID。' );
        if ( $parent_id ) {
            $parent = get_post( $parent_id );
            if ( ! $parent || $parent->post_type !== $slug || ! sac_content_can_access_post( $target['user'], $target['type'], $parent, 'read' )
                || ( $post && ( $parent_id === $post->ID || in_array( $post->ID, get_post_ancestors( $parent ), true ) ) ) ) {
                return sac_content_error( 'parent_id', '上層內容不存在、無法讀取或形成循環。' );
            }
        }
        $data['post_parent'] = $parent_id;
    }
    if ( array_key_exists( 'author_id', $values ) ) {
        $author = $values['author_id'];
        if ( ! $native['author_id'] || ! is_int( $author ) || ! sac_oauth_account( $author )
            || ( $author !== $target['user']->ID && ! user_can( $target['user'], $target['type']->cap->edit_others_posts ) ) ) {
            return sac_content_error( 'author_id', '無法指定此作者。' );
        }
        $data['post_author'] = $author;
    }
    if ( array_key_exists( 'status', $values ) ) {
        $data['post_status'] = str_ends_with( $request->get_route(), '/publish' ) ? 'publish' : sac_checked_publication_status( $values['status'] );
        if ( is_wp_error( $data['post_status'] ) ) return $data['post_status'];
    }
    $custom = sac_content_validate_custom( $values['custom_fields'] ?? [], $target );
    if ( is_wp_error( $custom ) ) return $custom;
    $thumbnail = null;
    if ( array_key_exists( 'featured_image_id', $values ) ) {
        $thumbnail = sac_content_thumbnail( $values['featured_image_id'], $target );
        if ( is_wp_error( $thumbnail ) ) return $thumbnail;
    }
    $terms = $values['terms'] ?? [];
    if ( ! is_array( $terms ) ) return sac_content_error( 'terms', '請以分類法 slug 指定分類項目 ID。' );
    foreach ( $terms as $taxonomy => $ids ) {
        $tax = get_taxonomy( $taxonomy );
        if ( ! $tax || ! $tax->show_ui || ! is_object_in_taxonomy( $slug, $taxonomy ) || ! user_can( $target['user'], $tax->cap->assign_terms ) || ! is_array( $ids ) ) {
            return sac_content_error( 'terms.' . $taxonomy, '此分類法未綁定內容類型，或無法指派。' );
        }
        foreach ( $ids as $term_id ) {
            if ( ! is_int( $term_id ) || $term_id < 1 || ! term_exists( $term_id, $taxonomy ) ) return sac_content_error( 'terms.' . $taxonomy, '找不到指定的分類項目 ID。' );
        }
    }
    // Keep a new publication out of publish hooks until the secondary writes succeed.
    $publication = $data['post_status'] ?? null;
    if ( in_array( $publication, [ 'publish', 'private' ], true ) && $publication !== ( $post->post_status ?? null ) ) {
        unset( $data['post_status'] );
        if ( ! $post ) $data['post_status'] = 'draft';
    } else {
        $publication = null;
    }

    if ( $mode === 'validate' ) {
        $expected = [];
        foreach ( $native_columns as $field => $column ) {
            if ( array_key_exists( $column, $data ) ) $expected[ $field ] = $data[ $column ];
        }
        foreach ( [ 'parent_id' => 'post_parent', 'author_id' => 'post_author' ] as $field => $column ) {
            if ( array_key_exists( $column, $data ) ) $expected[ $field ] = $data[ $column ];
        }
        if ( $terms ) $expected['terms'] = $terms;
        if ( $thumbnail !== null ) $expected['featured_image_id'] = $thumbnail;
        if ( $custom ) $expected['custom_fields'] = array_map( static fn( $entry ) => $entry[1], $custom );
        return $expected;
    }
    if ( $mode === 'review' ) {
        $review = sac_content_review( $request, $target );
        if ( is_wp_error( $review ) ) return $review;
    }
    $user_id = get_current_user_id();
    wp_set_current_user( $target['user']->ID );
    try {
        $id = $post
            ? ( count( $data ) === 1 ? $post->ID : wp_update_post( wp_slash( $data ), true ) )
            : wp_insert_post( wp_slash( $data ), true );
        if ( is_wp_error( $id ) ) return $id;
        if ( ! $id ) return new WP_Error( 'sac_save_failed', 'WordPress 無法儲存內容。', [ 'status' => 500 ] );
        foreach ( $terms as $taxonomy => $ids ) {
            $result = wp_set_object_terms( $id, $ids, $taxonomy );
            $saved_terms = wp_get_object_terms( $id, $taxonomy, [ 'fields' => 'ids' ] );
            if ( is_wp_error( $result ) || is_wp_error( $saved_terms ) || array_diff( $ids, $saved_terms ) || array_diff( $saved_terms, $ids ) ) {
                return sac_content_save_error( $id, 'terms.' . $taxonomy, $post );
            }
        }
        if ( $thumbnail !== null && (int) get_post_thumbnail_id( $id ) !== $thumbnail ) {
            $thumbnail ? set_post_thumbnail( $id, $thumbnail ) : delete_post_thumbnail( $id );
            if ( (int) get_post_thumbnail_id( $id ) !== $thumbnail ) return sac_content_save_error( $id, 'featured_image_id', $post );
        }
        foreach ( $custom as $name => [ $field, $value ] ) {
            try {
                $result = $field['write']( $id, $value, $target['user'] );
            } catch ( Throwable $error ) {
                $result = false;
            }
            if ( $result !== true ) return sac_content_save_error( $id, $name, $post );
        }
        if ( $publication !== null ) {
            $result = wp_update_post( [ 'ID' => $id, 'post_status' => $publication ], true );
            if ( is_wp_error( $result ) || ! $result || get_post_status( $id ) !== $publication ) {
                return sac_content_save_error( $id, 'status', $post );
            }
        }
        return [ 'success' => true ] + sac_content_result( get_post( $id ), $target['user'] );
    } finally {
        wp_set_current_user( $user_id );
    }
}

function sac_content_save_error( $id, $field, $original ) {
    if ( ! $original && wp_delete_post( $id, true ) ) {
        return new WP_Error( 'sac_field_save_failed', $field . ' 儲存失敗。WordPress 已移除本次建立的內容，請檢查欄位綁定。', [ 'status' => 500, 'field' => $field ] );
    }
    return new WP_Error( 'sac_partial_save', $field . ' 儲存失敗。部分變更可能已儲存，請至後台核對內容。', [ 'status' => 500, 'id' => $id, 'field' => $field ] );
}

function sac_content_tool_definitions() {
    $type = [ 'type' => 'string', 'in' => 'path', 'required' => true, 'description' => '本站內容類型 slug。先呼叫 list_content_types 取得。' ];
    $id = [ 'type' => 'integer', 'in' => 'path', 'required' => true, 'description' => '此類型的 WordPress 內容 ID。' ];
    $taxonomy = [ 'type' => 'string', 'in' => 'path', 'required' => true, 'description' => '此類型所綁定的分類法 slug。' ];
    $native = [
        'title' => [ 'type' => 'string', 'in' => 'body' ],
        'content' => [ 'type' => 'string', 'in' => 'body', 'description' => 'WordPress 正文 HTML。' ],
        'excerpt' => [ 'type' => 'string', 'in' => 'body' ],
        'slug' => [ 'type' => 'string', 'in' => 'body' ],
        'status' => [ 'type' => 'string', 'in' => 'body', 'enum' => [ 'draft', 'pending' ] ],
        'featured_image_id' => [ 'type' => 'integer', 'in' => 'body', 'description' => '媒體庫圖片 ID。填 0 移除。' ],
        'parent_id' => [ 'type' => 'integer', 'in' => 'body' ],
        'author_id' => [ 'type' => 'integer', 'in' => 'body' ],
        'terms' => [ 'type' => 'object', 'in' => 'body', 'description' => '分類法 slug 對應分類項目 ID 陣列。指定的分類法整組更新。', 'additionalProperties' => [ 'type' => 'array', 'items' => [ 'type' => 'integer' ] ] ],
        'custom_fields' => [ 'type' => 'object', 'in' => 'body', 'description' => 'describe_content_type 顯示有寫入權限的欄位和值。', 'additionalProperties' => true ],
    ];
    $list = [
        'search' => [ 'type' => 'string', 'in' => 'query', 'description' => '關鍵字。' ],
        'status' => [ 'type' => 'string', 'in' => 'query', 'description' => '例如 draft、publish 或 any。' ],
        'page' => [ 'type' => 'integer', 'in' => 'query', 'description' => '頁碼，從 1 開始。' ],
        'per_page' => [ 'type' => 'integer', 'in' => 'query', 'description' => '每頁 1 至 50 筆。' ],
    ];
    $faq = [ 'faqs' => [ 'type' => 'array', 'in' => 'body', 'required' => true, 'items' => [ 'type' => 'object', 'properties' => [ 'question' => [ 'type' => 'string' ], 'answer' => [ 'type' => 'string' ] ], 'required' => [ 'question', 'answer' ] ] ] ];
    $seo = [];
    foreach ( [ 'title', 'description', 'focus_keyword', 'canonical', 'og_title', 'og_description' ] as $field ) {
        $seo[ $field ] = [ 'type' => 'string', 'in' => 'body' ];
    }
    $seo['og_image_id'] = [ 'type' => 'integer', 'in' => 'body' ];
    $seo['noindex'] = $seo['nofollow'] = [ 'type' => 'boolean', 'in' => 'body' ];
    $rows = [
        [ 'list_content_types', 'GET', '/content-types', '查詢本站可用的內容類型', '列出此授權可使用的內容類型、操作與支援狀態。', [], '文章與 FAQ' ],
        [ 'describe_content_type', 'GET', '/content-types/{post_type}', '查看欄位與分類法', '查看類型的原生及自訂欄位、必填規則、分類法與 SEO、FAQ、精選圖片支援。可提供現有內容 ID 取得該筆欄位定義。', [ 'post_type' => $type, 'id' => [ 'type' => 'integer', 'in' => 'query' ] ], '文章與 FAQ' ],
        [ 'list_content_terms', 'GET', '/content-types/{post_type}/terms/{taxonomy}', '查詢分類項目',
            '按分類名稱查詢現有項目與 ID。建立或更新前請先用此工具核對分類。', [ 'post_type' => $type, 'taxonomy' => $taxonomy ] + array_diff_key( $list, [ 'status' => true ] ), '文章與 FAQ' ],
        [ 'list_content', 'GET', '/content/{post_type}', '查詢本站內容', '依授權內容類型、關鍵字與狀態分頁查詢。', [ 'post_type' => $type ] + $list, '文章與 FAQ' ],
        [ 'get_content', 'GET', '/content/{post_type}/{id}', '查看單筆內容', '透過內容類型與 ID 核對正文、分類及可讀的欄位。', [ 'post_type' => $type, 'id' => $id ], '文章與 FAQ' ],
        [ 'create_content', 'POST', '/content/{post_type}', '建立內容草稿', '先查看類型欄位與必填規則，確認草稿內容後再建立。預設草稿，成功回傳類型、標題、ID、狀態及編輯連結。', [ 'post_type' => $type ] + $native, '文章與 FAQ' ],
        [ 'update_content', 'POST', '/content/{post_type}/{id}', '更新指定欄位', '先查詢目標並逐項確認變更，再只送出需要更新的欄位。', [ 'post_type' => $type, 'id' => $id ] + $native, '文章與 FAQ' ],
        [ 'publish_content', 'POST', '/content/{post_type}/{id}/publish', '確認並發佈', '發佈已完成的草稿。需在 WordPress 以原授權帳號確認。', [ 'post_type' => $type, 'id' => $id ], '文章與 FAQ' ],
        [ 'set_content_featured_image', 'POST', '/content/{post_type}/{id}/featured-image', '設定精選圖片', '設定已上傳的媒體庫圖片，填 0 移除。', [ 'post_type' => $type, 'id' => $id, 'featured_image_id' => $native['featured_image_id'] + [ 'required' => true ] ], '圖片上傳' ],
        [ 'get_content_faq', 'GET', '/content/{post_type}/{id}/faq', '查看內容 FAQ', '查看已授權內容的現有問答。', [ 'post_type' => $type, 'id' => $id ], '文章與 FAQ' ],
        [ 'update_content_faq', 'POST', '/content/{post_type}/{id}/faq', '儲存內容 FAQ', '先核對現有問答和使用者確認的完整清單再儲存。', [ 'post_type' => $type, 'id' => $id ] + $faq, '文章與 FAQ' ],
        [ 'get_content_seo', 'GET', '/content/{post_type}/{id}/seo', '查看內容 SEO', '查看此類型支援的 SEO 欄位。', [ 'post_type' => $type, 'id' => $id ], 'SEO' ],
        [ 'update_content_seo', 'POST', '/content/{post_type}/{id}/seo', '更新內容 SEO', '依 SEO 模組與類型支援狀態修改指定欄位。', [ 'post_type' => $type, 'id' => $id ] + $seo, 'SEO' ],
    ];
    return array_map( static function ( $row ) {
        [ $name, $method, $path, $summary, $description, $params, $group ] = $row;
        return [
            'name' => $name, 'method' => $method, 'path' => $path, 'group' => $group, 'summary' => $summary,
            'description' => $description, 'scenario' => $summary, 'prompt' => $description,
            'available' => $name !== 'publish_content' || (bool) get_option( SAC_OPTION_ALLOW_PUBLISH, false ), 'need_write' => $method === 'POST', 'params' => $params,
        ];
    }, $rows );
}

function sac_query_content( $request, $target, $legacy = false ) {
    $status = $request->get_param( 'status' ) ?: 'any';
    $search = $request->get_param( 'search' ) ?? '';
    $statuses = array_diff( get_post_stati(), [ 'auto-draft', 'inherit', 'trash' ] );
    if ( ! is_string( $status ) || ( $status !== 'any' && ! in_array( $status, $statuses, true ) ) || ! is_string( $search ) ) {
        return sac_content_error( 'status/search', '請提供有效的內容狀態及搜尋文字。' );
    }
    $limit = max( 1, min( 50, (int) ( $request->get_param( $legacy ? 'limit' : 'per_page' ) ?: 10 ) ) );
    $raw_page = $request->get_param( 'page' ) ?? 1;
    $page = $legacy ? 1 : filter_var( $raw_page, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1, 'max_range' => 10000 ] ] );
    if ( $page === false ) return sac_content_error( 'page', '請提供 1 至 10000 的頁碼，或使用搜尋縮小範圍。' );
    $args = [
        'post_type' => $target['type']->name, 'post_status' => $status === 'any' ? array_values( $statuses ) : $status,
        's' => $search, 'posts_per_page' => 100, 'orderby' => [ 'modified' => 'DESC', 'ID' => 'DESC' ],
        'no_found_rows' => true, 'update_post_meta_cache' => false, 'update_post_term_cache' => false,
    ];
    $items = [];
    $skip = ( $page - 1 ) * $limit;
    for ( $batch = 1; count( $items ) <= $limit; $batch++ ) {
        $query_args = $args + [ 'paged' => $batch, 'fields' => 'ids' ];
        $cache_key = 'content:' . wp_json_encode( $query_args );
        $ids = sac_cache_get( $request, $cache_key );
        if ( $ids === false ) {
            $query = new WP_Query( $query_args );
            $ids = $query->posts;
            sac_cache_set( $request, $cache_key, $ids );
        }
        if ( $ids ) _prime_post_caches( $ids, false, false );
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post || $post->post_type !== $target['type']->name || ! sac_content_can_access_post( $target['user'], $target['type'], $post, 'read' ) ) continue;
            if ( $skip ) { $skip--; continue; }
            $items[] = $legacy
                ? [ 'id' => $post->ID, 'title' => get_the_title( $post ), 'status' => $post->post_status, 'modified' => $post->post_modified ]
                : sac_content_result( $post, $target['user'] );
            if ( count( $items ) > $limit ) break;
        }
        if ( count( $ids ) < $args['posts_per_page'] ) break;
    }
    $more = count( $items ) > $limit;
    if ( $more ) array_pop( $items );
    return $legacy ? $items : [ 'items' => $items, 'page' => $page, 'per_page' => $limit, 'has_more' => $more ];
}

function sac_list_content_terms( $request, $target, $taxonomy ) {
    $tax = get_taxonomy( $taxonomy );
    if ( ! $tax || ! $tax->show_ui || ! is_object_in_taxonomy( $target['type']->name, $taxonomy ) ) {
        return sac_content_error( 'taxonomy', '分類法未綁定此內容類型。請先查看類型說明。' );
    }
    $page = filter_var( $request->get_param( 'page' ) ?? 1, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1, 'max_range' => 10000 ] ] );
    $limit = filter_var( $request->get_param( 'per_page' ) ?? 20, FILTER_VALIDATE_INT, [ 'options' => [ 'min_range' => 1, 'max_range' => 50 ] ] );
    $search = $request->get_param( 'search' ) ?? '';
    if ( ! $page || ! $limit || ! is_string( $search ) ) return sac_content_error( 'search/page/per_page', '請提供有效的搜尋文字、頁碼及筆數。' );
    $terms = get_terms( [
        'taxonomy' => $taxonomy, 'search' => $search, 'order' => 'ASC', 'hide_empty' => false,
        'number' => $limit + 1, 'offset' => ( $page - 1 ) * $limit,
    ] );
    if ( is_wp_error( $terms ) ) return $terms;
    $more = count( $terms ) > $limit;
    if ( $more ) array_pop( $terms );
    $items = array_map( static function ( $term ) {
        return [ 'id' => $term->term_id, 'name' => $term->name, 'slug' => $term->slug, 'parent' => $term->parent ];
    }, $terms );
    return [ 'items' => $items, 'page' => $page, 'per_page' => $limit, 'has_more' => $more ];
}

function sac_content_faq( $request, $post_id, $write ) {
    if ( ! $write ) {
        return [ 'post_id' => $post_id, 'title' => get_the_title( $post_id ), 'status' => get_post_status( $post_id ), 'faqs' => get_post_meta( $post_id, '_sac_faq_list', true ) ?: [] ];
    }
    $clean = sac_content_prepare_faq( $request->get_param( 'faqs' ), $post_id );
    if ( is_wp_error( $clean ) ) return $clean;
    return sac_content_store_faq( $post_id, $clean );
}

function sac_content_prepare_faq( $faqs, $post_id, $content = null ) {
    if ( ! is_array( $faqs ) || count( $faqs ) > 200 ) return sac_content_error( 'faqs', '請提供完整問答陣列。' );
    $clean = [];
    foreach ( $faqs as $index => $item ) {
        if ( ! is_array( $item ) || ! is_string( $item['question'] ?? null ) || ! is_string( $item['answer'] ?? null ) || trim( $item['question'] ) === '' ) {
            return sac_content_error( 'faqs.' . $index, '每題需要 question 和 answer 欄位。' );
        }
        $clean[] = [ 'question' => sanitize_text_field( $item['question'] ), 'answer' => wp_kses_post( $item['answer'] ) ];
    }
    if ( $clean && ( $content === null ? sac_faq_block_exists( $post_id ) : ( has_block( 'yoast/faq-block', $content ) || has_block( 'rank-math/faq-block', $content ) ) ) ) return sac_oauth_error( 'sac_faq_block_exists', '此內容已有 SEO 外掛的 FAQ 區塊，請在原區塊編輯。', 409 );
    return $clean;
}

function sac_content_store_faq( $post_id, $clean ) {
    update_post_meta( $post_id, '_sac_faq_list', wp_slash( $clean ) );
    if ( get_post_meta( $post_id, '_sac_faq_list', true ) !== $clean || ! sac_sync_faq_schema( $post_id, $clean ) ) {
        return new WP_Error( 'sac_partial_save', 'FAQ 或結構化資料可能已部分更新，請到後台核對。', [ 'status' => 500, 'id' => $post_id ] );
    }
    return [ 'success' => true, 'post_id' => $post_id, 'faqs' => $clean ];
}

function sac_handle_content_tool( $name, WP_REST_Request $request ) {
    if ( $name === 'list_content_types' ) {
        $key = sac_authenticate( $request );
        $results = [];
        foreach ( sac_content_types() as $type ) {
            $ops = sac_content_operations( $type, $key );
            if ( $ops ) $results[] = [ 'post_type' => $type->name, 'label' => $type->label, 'operations' => $ops, 'features' => sac_content_extensions( $type->name ) ];
        }
        return [ 'types' => $results ];
    }
    $url = $request->get_url_params();
    foreach ( $url as $name_in_path => $value ) {
        if ( ! is_scalar( $request[ $name_in_path ] ) || (string) $request[ $name_in_path ] !== (string) $value ) {
            return new WP_Error( 'sac_content_type_mismatch', '網址與請求內容指定的類型或 ID 不同。', [ 'status' => 409 ] );
        }
    }
    $slug = $url['post_type'];
    $id = isset( $url['id'] ) ? (int) $url['id'] : null;
    if ( $name === 'describe_content_type' && $request->has_param( 'id' ) ) {
        if ( ! is_scalar( $request['id'] ) || ! preg_match( '/^[1-9][0-9]*$/D', (string) $request['id'] ) ) return sac_content_error( 'id', '請提供有效內容 ID。' );
        $id = (int) $request['id'];
    }
    if ( $id !== null && $id < 1 ) return sac_content_error( 'id', '請提供有效內容 ID。' );
    $write = $request->get_method() === 'POST';
    $operation = $name === 'publish_content' ? 'publish' : ( $write ? ( $id === null ? 'create' : 'update' ) : 'read' );
    $target = sac_content_target( $request, $slug, $operation, $id );
    if ( is_wp_error( $target ) && in_array( $name, [ 'describe_content_type', 'list_content_terms' ], true ) && $id === null ) {
        foreach ( [ 'create', 'update' ] as $alternative ) {
            $candidate = sac_content_target( $request, $slug, $alternative );
            if ( ! is_wp_error( $candidate ) ) { $target = $candidate; break; }
        }
    }
    if ( is_wp_error( $target ) ) return $target;
    switch ( $name ) {
        case 'describe_content_type':
            return sac_content_description( $target );
        case 'list_content_terms':
            return sac_list_content_terms( $request, $target, $url['taxonomy'] );
        case 'list_content':
            return sac_query_content( $request, $target );
        case 'get_content':
            $result = sac_content_result( $target['post'], $target['user'], true );
            $grant = sac_authenticate( $request );
            if ( ! in_array( 'taxonomy:read', $grant['scopes'], true ) ) unset( $result['terms'] );
            if ( ! in_array( 'media:read', $grant['scopes'], true ) ) unset( $result['featured_image_id'] );
            return $result;
        case 'create_content':
        case 'update_content':
            return sac_content_save( $request, $target );
        case 'publish_content':
            if ( ! in_array( $target['post']->post_status, [ 'draft', 'pending' ], true ) ) return sac_oauth_error( 'sac_publish_status', '此工具只發佈草稿或待審內容。', 409 );
            return sac_content_save( $request, $target, [ 'status' => 'publish' ] );
        case 'set_content_featured_image':
            return sac_content_save( $request, $target, [ 'featured_image_id' => $request->get_param( 'featured_image_id' ) ] );
        case 'get_content_faq':
        case 'update_content_faq':
            if ( ! sac_content_extensions( $slug )['faq'] ) return sac_content_error( 'faq', '此類型未支援 FAQ。請從 WordPress 後台確認類型設定。' );
            $review = $write ? sac_content_review( $request, $target ) : true;
            return is_wp_error( $review ) ? $review : sac_content_faq( $request, $id, $write );
        case 'get_content_seo':
        case 'update_content_seo':
            if ( ! sac_content_extensions( $slug )['seo'] ) return sac_content_error( 'seo', '此類型缺少可用的 SEO 模組或欄位。' );
            if ( ! $write ) return sac_get_seo_fields( $id );
            $review = sac_content_review( $request, $target );
            if ( is_wp_error( $review ) ) return $review;
            $fields = $request->get_json_params() ?: $request->get_body_params();
            $saved = sac_update_seo_fields( $id, $fields );
            return is_wp_error( $saved ) ? $saved : [ 'success' => true, 'post_id' => $id, 'seo' => sac_get_seo_fields( $id ) ];
    }
}

add_action( 'rest_api_init', static function () {
    foreach ( sac_content_tool_definitions() as $tool ) {
        $route = strtr( $tool['path'], [ '{post_type}' => '(?P<post_type>[a-z0-9_-]+)', '{id}' => '(?P<id>\d+)', '{taxonomy}' => '(?P<taxonomy>[a-z0-9_-]+)' ] );
        register_rest_route( SAC_NAMESPACE, $route, [
            'methods' => $tool['method'],
            'permission_callback' => $tool['need_write'] ? 'sac_permission_write' : 'sac_permission_read',
            'callback' => static function ( WP_REST_Request $request ) use ( $tool ) {
                return sac_handle_content_tool( $tool['name'], $request );
            },
        ] );
    }
} );

function sac_handle_legacy_content( WP_REST_Request $request ) {
    $target = sac_content_legacy_target( $request );
    if ( is_wp_error( $target ) ) return $target;
    $post = $target['post'];
    $write = $request->get_method() === 'POST';
    $route = $request->get_route();
    if ( $write && ( str_ends_with( $route, '/faq' ) || str_ends_with( $route, '/seo' ) ) ) {
        $review = sac_content_review( $request, $target );
        if ( is_wp_error( $review ) ) return $review;
    }
    if ( str_ends_with( $route, '/faq' ) ) return sac_content_faq( $request, $post->ID, $write );
    if ( str_ends_with( $route, '/seo' ) ) {
        if ( ! $write ) return sac_get_seo_fields( $post->ID );
        $saved = sac_update_seo_fields( $post->ID, $request->get_json_params() ?: $request->get_body_params() );
        if ( is_wp_error( $saved ) ) return $saved;
        return [ 'success' => true, 'post_id' => $post->ID, 'seo' => sac_get_seo_fields( $post->ID ) ];
    }
    if ( ! $write ) {
        if ( ! $post ) return sac_query_content( $request, $target, true );
        return [ 'id' => $post->ID, 'title' => $post->post_title, 'content' => $post->post_content, 'status' => $post->post_status, 'modified' => $post->post_modified ];
    }
    $values = array_intersect_key( $request->get_params(), array_flip( [ 'title', 'content', 'excerpt', 'slug', 'status', 'custom_fields' ] ) );
    if ( ! $post && ! isset( $values['excerpt'] ) ) {
        $values['excerpt'] = is_string( $values['content'] ?? null ) ? sac_seo_summary( $values['content'] ) : '';
    }
    $result = sac_content_save( $request, $target, $values );
    if ( is_wp_error( $result ) ) return $result;
    $post_id = $result['id'];
    sac_apply_seo_request( $post_id, $request );
    $grant = sac_authenticate( $request );
    return [ 'success' => true, 'post_id' => $post_id ] + ( in_array( 'seo:read', $grant['scopes'], true ) ? [ 'seo' => sac_get_seo_fields( $post_id ) ] : [] );
}
