<?php
/**
 * Plugin Name: Wumetax Media Folders
 * Description: Virtual folders for the WordPress Media Library. Nested folders, drag & drop, colors, stars, rename, delete, undo, Media Library + media modal support. Files never move on disk.
 * Version: 1.1.0
 * Author: Wumetax
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/*
 * You can also paste this whole file into a custom plugin.
 * If you paste into functions.php / Code Snippets, remove only the plugin header above if you want.
 */

// Keep the existing Toolbox taxonomy so previously categorized media remains in place.
const WUMF_TAXONOMY = 'wutm_media_folder';
const WUMF_META_COLOR = '_wumf_color';
const WUMF_META_STAR  = '_wumf_star';
const WUMF_META_ORDER = '_wumf_order';
const WUMF_UNDO_KEY   = '_wumf_last_undo';

/* ---------------------------------------------------------
 * 1) Virtual-folder taxonomy
 * --------------------------------------------------------- */
add_action( 'init', 'wumf_register_taxonomy', 5 );
function wumf_register_taxonomy() {
    register_taxonomy(
        WUMF_TAXONOMY,
        array( 'attachment' ),
        array(
            'labels' => array(
                'name'          => '媒體資料夾',
                'singular_name' => '媒體資料夾',
            ),
            'hierarchical'          => true,
            'public'                => false,
            'publicly_queryable'    => false,
            'show_ui'               => false,
            'show_in_menu'          => false,
            'show_in_nav_menus'     => false,
            'show_tagcloud'         => false,
            'show_in_quick_edit'    => false,
            'show_admin_column'     => false,
            'show_in_rest'          => false,
            'query_var'             => false,
            'rewrite'               => false,
            'update_count_callback' => '_update_generic_term_count',
            'capabilities'          => array(
                'manage_terms' => 'upload_files',
                'edit_terms'   => 'upload_files',
                'delete_terms' => 'upload_files',
                'assign_terms' => 'upload_files',
            ),
        )
    );
}

/* ---------------------------------------------------------
 * 2) Helpers
 * --------------------------------------------------------- */
function wumf_can_manage() {
    return current_user_can( 'upload_files' );
}

function wumf_ajax_guard() {
    check_ajax_referer( 'wumf_nonce', 'nonce' );
    if ( ! wumf_can_manage() ) {
        wp_send_json_error( array( 'message' => '權限不足。' ), 403 );
    }
}

function wumf_get_term_or_error( $term_id ) {
    $term = get_term( absint( $term_id ), WUMF_TAXONOMY );
    return ( $term && ! is_wp_error( $term ) ) ? $term : false;
}

function wumf_get_order( $term_id ) {
    return (int) get_term_meta( $term_id, WUMF_META_ORDER, true );
}

function wumf_next_order( $parent ) {
    $terms = get_terms(
        array(
            'taxonomy'   => WUMF_TAXONOMY,
            'hide_empty' => false,
            'parent'     => absint( $parent ),
            'fields'     => 'ids',
        )
    );
    if ( is_wp_error( $terms ) || empty( $terms ) ) {
        return 10;
    }
    $max = 0;
    foreach ( $terms as $id ) {
        $max = max( $max, wumf_get_order( $id ) );
    }
    return $max + 10;
}

function wumf_normalize_sibling_order( $parent, $ordered_ids = array() ) {
    $parent = absint( $parent );

    if ( empty( $ordered_ids ) ) {
        $terms = get_terms(
            array(
                'taxonomy'   => WUMF_TAXONOMY,
                'hide_empty' => false,
                'parent'     => $parent,
            )
        );
        if ( is_wp_error( $terms ) ) {
            return;
        }
        usort(
            $terms,
            function( $a, $b ) {
                $oa = wumf_get_order( $a->term_id );
                $ob = wumf_get_order( $b->term_id );
                if ( $oa === $ob ) {
                    return strnatcasecmp( $a->name, $b->name );
                }
                return $oa <=> $ob;
            }
        );
        $ordered_ids = wp_list_pluck( $terms, 'term_id' );
    }

    $n = 10;
    foreach ( array_map( 'absint', $ordered_ids ) as $id ) {
        update_term_meta( $id, WUMF_META_ORDER, $n );
        $n += 10;
    }
}

function wumf_is_descendant_of( $maybe_child, $ancestor ) {
    $maybe_child = absint( $maybe_child );
    $ancestor    = absint( $ancestor );
    if ( ! $maybe_child || ! $ancestor ) {
        return false;
    }
    $ancestors = get_ancestors( $maybe_child, WUMF_TAXONOMY, 'taxonomy' );
    return in_array( $ancestor, array_map( 'absint', $ancestors ), true );
}

function wumf_get_attachment_folder_id( $attachment_id ) {
    $ids = wp_get_object_terms(
        absint( $attachment_id ),
        WUMF_TAXONOMY,
        array( 'fields' => 'ids' )
    );
    if ( is_wp_error( $ids ) || empty( $ids ) ) {
        return 0;
    }
    return absint( reset( $ids ) );
}

function wumf_set_attachment_folder( $attachment_id, $folder_id ) {
    $attachment_id = absint( $attachment_id );
    $folder_id     = (int) $folder_id;

    if ( $folder_id > 0 ) {
        return wp_set_object_terms( $attachment_id, array( $folder_id ), WUMF_TAXONOMY, false );
    }

    return wp_set_object_terms( $attachment_id, array(), WUMF_TAXONOMY, false );
}

function wumf_get_uncategorized_count() {
    global $wpdb;

    $sql = $wpdb->prepare(
        "SELECT COUNT(1)
         FROM {$wpdb->posts} p
         WHERE p.post_type = 'attachment'
           AND p.post_status = 'inherit'
           AND NOT EXISTS (
               SELECT 1
               FROM {$wpdb->term_relationships} tr
               INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
               WHERE tr.object_id = p.ID
                 AND tt.taxonomy = %s
           )",
        WUMF_TAXONOMY
    );

    return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
}

function wumf_folder_payload() {
    $terms = get_terms(
        array(
            'taxonomy'   => WUMF_TAXONOMY,
            'hide_empty' => false,
        )
    );

    if ( is_wp_error( $terms ) ) {
        $terms = array();
    }

    $items = array();
    foreach ( $terms as $term ) {
        $items[] = array(
            'id'     => (int) $term->term_id,
            'name'   => $term->name,
            'parent' => (int) $term->parent,
            'count'  => (int) $term->count,
            'color'  => (string) get_term_meta( $term->term_id, WUMF_META_COLOR, true ),
            'star'   => (bool) get_term_meta( $term->term_id, WUMF_META_STAR, true ),
            'order'  => (int) get_term_meta( $term->term_id, WUMF_META_ORDER, true ),
        );
    }

    $counts = wp_count_posts( 'attachment' );

    return array(
        'folders'            => $items,
        'all_count'          => isset( $counts->inherit ) ? (int) $counts->inherit : 0,
        'uncategorized_count'=> wumf_get_uncategorized_count(),
    );
}

function wumf_save_undo( $data ) {
    update_user_meta(
        get_current_user_id(),
        WUMF_UNDO_KEY,
        array(
            'time' => time(),
            'data' => $data,
        )
    );
}

function wumf_get_undo() {
    $undo = get_user_meta( get_current_user_id(), WUMF_UNDO_KEY, true );
    if ( ! is_array( $undo ) || empty( $undo['time'] ) || empty( $undo['data'] ) ) {
        return false;
    }
    if ( time() - (int) $undo['time'] > 20 * MINUTE_IN_SECONDS ) {
        delete_user_meta( get_current_user_id(), WUMF_UNDO_KEY );
        return false;
    }
    return $undo['data'];
}

/* ---------------------------------------------------------
 * 3) AJAX API
 * --------------------------------------------------------- */
add_action( 'wp_ajax_wumf_tree', 'wumf_ajax_tree' );
function wumf_ajax_tree() {
    wumf_ajax_guard();
    wp_send_json_success( wumf_folder_payload() );
}

add_action( 'wp_ajax_wumf_create_folder', 'wumf_ajax_create_folder' );
function wumf_ajax_create_folder() {
    wumf_ajax_guard();

    $name   = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
    $parent = isset( $_POST['parent'] ) ? absint( $_POST['parent'] ) : 0;

    if ( '' === $name ) {
        wp_send_json_error( array( 'message' => '請輸入資料夾名稱。' ) );
    }
    if ( $parent && ! wumf_get_term_or_error( $parent ) ) {
        wp_send_json_error( array( 'message' => '父層資料夾不存在。' ) );
    }

    $result = wp_insert_term(
        $name,
        WUMF_TAXONOMY,
        array( 'parent' => $parent )
    );

    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    $term_id = absint( $result['term_id'] );
    update_term_meta( $term_id, WUMF_META_ORDER, wumf_next_order( $parent ) );
    update_term_meta( $term_id, WUMF_META_COLOR, '#8b5cf6' );

    wp_send_json_success( array( 'id' => $term_id ) );
}

add_action( 'wp_ajax_wumf_rename_folder', 'wumf_ajax_rename_folder' );
function wumf_ajax_rename_folder() {
    wumf_ajax_guard();

    $id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $name = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';

    if ( ! wumf_get_term_or_error( $id ) || '' === $name ) {
        wp_send_json_error( array( 'message' => '資料夾或名稱無效。' ) );
    }

    $result = wp_update_term( $id, WUMF_TAXONOMY, array( 'name' => $name ) );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    wp_send_json_success();
}

add_action( 'wp_ajax_wumf_folder_meta', 'wumf_ajax_folder_meta' );
function wumf_ajax_folder_meta() {
    wumf_ajax_guard();

    $id   = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $type = isset( $_POST['type'] ) ? sanitize_key( $_POST['type'] ) : '';

    if ( ! wumf_get_term_or_error( $id ) ) {
        wp_send_json_error( array( 'message' => '資料夾不存在。' ) );
    }

    if ( 'star' === $type ) {
        $value = ! empty( $_POST['value'] ) ? 1 : 0;
        update_term_meta( $id, WUMF_META_STAR, $value );
    } elseif ( 'color' === $type ) {
        $value = isset( $_POST['value'] ) ? sanitize_hex_color( wp_unslash( $_POST['value'] ) ) : '';
        if ( ! $value ) {
            $value = '#8b5cf6';
        }
        update_term_meta( $id, WUMF_META_COLOR, $value );
    } else {
        wp_send_json_error( array( 'message' => '不支援的操作。' ) );
    }

    wp_send_json_success();
}

add_action( 'wp_ajax_wumf_assign', 'wumf_ajax_assign' );
function wumf_ajax_assign() {
    wumf_ajax_guard();

    $folder = isset( $_POST['folder'] ) ? (int) $_POST['folder'] : 0;
    $ids    = isset( $_POST['ids'] ) ? (array) $_POST['ids'] : array();
    $ids    = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );

    if ( $folder > 0 && ! wumf_get_term_or_error( $folder ) ) {
        wp_send_json_error( array( 'message' => '目標資料夾不存在。' ) );
    }
    if ( empty( $ids ) ) {
        wp_send_json_error( array( 'message' => '沒有可移動的媒體。' ) );
    }

    $previous = array();
    $moved    = array();

    foreach ( $ids as $id ) {
        if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) {
            continue;
        }
        $previous[ $id ] = wumf_get_attachment_folder_id( $id );
        $result = wumf_set_attachment_folder( $id, $folder );
        if ( ! is_wp_error( $result ) ) {
            $moved[] = $id;
        }
    }

    if ( empty( $moved ) ) {
        wp_send_json_error( array( 'message' => '媒體移動失敗。' ) );
    }

    wumf_save_undo(
        array(
            'type'     => 'assign',
            'previous' => $previous,
        )
    );

    wp_send_json_success( array( 'moved' => count( $moved ) ) );
}

add_action( 'wp_ajax_wumf_move_folder', 'wumf_ajax_move_folder' );
function wumf_ajax_move_folder() {
    wumf_ajax_guard();

    $id       = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $target   = isset( $_POST['target'] ) ? absint( $_POST['target'] ) : 0;
    $position = isset( $_POST['position'] ) ? sanitize_key( $_POST['position'] ) : 'inside';

    $folder = wumf_get_term_or_error( $id );
    $to     = wumf_get_term_or_error( $target );

    if ( ! $folder || ! $to || $id === $target ) {
        wp_send_json_error( array( 'message' => '拖曳目標無效。' ) );
    }
    if ( wumf_is_descendant_of( $target, $id ) ) {
        wp_send_json_error( array( 'message' => '不能把資料夾移到自己的子資料夾。' ) );
    }

    $old_parent = (int) $folder->parent;
    $old_order  = wumf_get_order( $id );

    if ( 'before' === $position || 'after' === $position ) {
        $new_parent = (int) $to->parent;
    } else {
        $position   = 'inside';
        $new_parent = $target;
    }

    if ( $new_parent && wumf_is_descendant_of( $new_parent, $id ) ) {
        wp_send_json_error( array( 'message' => '不能形成循環資料夾。' ) );
    }

    $result = wp_update_term( $id, WUMF_TAXONOMY, array( 'parent' => $new_parent ) );
    if ( is_wp_error( $result ) ) {
        wp_send_json_error( array( 'message' => $result->get_error_message() ) );
    }

    $siblings = get_terms(
        array(
            'taxonomy'   => WUMF_TAXONOMY,
            'hide_empty' => false,
            'parent'     => $new_parent,
            'fields'     => 'ids',
        )
    );
    if ( is_wp_error( $siblings ) ) {
        $siblings = array();
    }
    $siblings = array_values( array_diff( array_map( 'absint', $siblings ), array( $id ) ) );

    usort(
        $siblings,
        function( $a, $b ) {
            $oa = wumf_get_order( $a );
            $ob = wumf_get_order( $b );
            if ( $oa === $ob ) {
                return $a <=> $b;
            }
            return $oa <=> $ob;
        }
    );

    if ( 'inside' === $position ) {
        $siblings[] = $id;
    } else {
        $index = array_search( $target, $siblings, true );
        if ( false === $index ) {
            $siblings[] = $id;
        } elseif ( 'before' === $position ) {
            array_splice( $siblings, $index, 0, array( $id ) );
        } else {
            array_splice( $siblings, $index + 1, 0, array( $id ) );
        }
    }

    wumf_normalize_sibling_order( $new_parent, $siblings );
    if ( $old_parent !== $new_parent ) {
        wumf_normalize_sibling_order( $old_parent );
    }

    wumf_save_undo(
        array(
            'type'       => 'move_folder',
            'id'         => $id,
            'old_parent' => $old_parent,
            'old_order'  => $old_order,
        )
    );

    wp_send_json_success();
}

add_action( 'wp_ajax_wumf_delete_folder', 'wumf_ajax_delete_folder' );
function wumf_ajax_delete_folder() {
    wumf_ajax_guard();

    $id          = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
    $delete_media= ! empty( $_POST['delete_media'] );
    $typed       = isset( $_POST['typed'] ) ? sanitize_text_field( wp_unslash( $_POST['typed'] ) ) : '';

    $term = wumf_get_term_or_error( $id );
    if ( ! $term ) {
        wp_send_json_error( array( 'message' => '資料夾不存在。' ) );
    }

    if ( $delete_media ) {
        if ( $typed !== $term->name ) {
            wp_send_json_error( array( 'message' => '確認文字不正確。' ) );
        }

        $descendants = get_term_children( $id, WUMF_TAXONOMY );
        if ( is_wp_error( $descendants ) ) {
            $descendants = array();
        }
        $term_ids = array_merge( array( $id ), array_map( 'absint', $descendants ) );

        $attachment_ids = get_posts(
            array(
                'post_type'      => 'attachment',
                'post_status'    => 'inherit',
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'no_found_rows'  => true,
                'tax_query'      => array(
                    array(
                        'taxonomy'         => WUMF_TAXONOMY,
                        'field'            => 'term_id',
                        'terms'            => $term_ids,
                        'include_children' => false,
                    ),
                ),
            )
        );

        foreach ( $attachment_ids as $attachment_id ) {
            if ( current_user_can( 'delete_post', $attachment_id ) ) {
                wp_delete_attachment( $attachment_id, true );
            }
        }

        // Delete deepest folders first.
        usort(
            $term_ids,
            function( $a, $b ) {
                return count( get_ancestors( $b, WUMF_TAXONOMY, 'taxonomy' ) ) <=> count( get_ancestors( $a, WUMF_TAXONOMY, 'taxonomy' ) );
            }
        );
        foreach ( $term_ids as $term_id ) {
            if ( wumf_get_term_or_error( $term_id ) ) {
                wp_delete_term( $term_id, WUMF_TAXONOMY );
            }
        }

        delete_user_meta( get_current_user_id(), WUMF_UNDO_KEY );
        wp_send_json_success( array( 'deleted_media' => count( $attachment_ids ) ) );
    }

    $child_ids = get_terms(
        array(
            'taxonomy'   => WUMF_TAXONOMY,
            'hide_empty' => false,
            'parent'     => $id,
            'fields'     => 'ids',
        )
    );
    if ( is_wp_error( $child_ids ) ) {
        $child_ids = array();
    }

    $attachment_ids = get_objects_in_term( $id, WUMF_TAXONOMY );
    if ( is_wp_error( $attachment_ids ) ) {
        $attachment_ids = array();
    }

    $snapshot = array(
        'type'        => 'delete_folder',
        'name'        => $term->name,
        'parent'      => (int) $term->parent,
        'color'       => (string) get_term_meta( $id, WUMF_META_COLOR, true ),
        'star'        => (int) get_term_meta( $id, WUMF_META_STAR, true ),
        'order'       => (int) get_term_meta( $id, WUMF_META_ORDER, true ),
        'children'    => array_map( 'absint', $child_ids ),
        'attachments' => array_map( 'absint', $attachment_ids ),
    );

    foreach ( $attachment_ids as $attachment_id ) {
        if ( current_user_can( 'edit_post', $attachment_id ) ) {
            wumf_set_attachment_folder( $attachment_id, 0 );
        }
    }

    foreach ( $child_ids as $child_id ) {
        wp_update_term( $child_id, WUMF_TAXONOMY, array( 'parent' => (int) $term->parent ) );
    }

    $deleted = wp_delete_term( $id, WUMF_TAXONOMY );
    if ( is_wp_error( $deleted ) || ! $deleted ) {
        wp_send_json_error( array( 'message' => '資料夾刪除失敗。' ) );
    }

    wumf_save_undo( $snapshot );
    wumf_normalize_sibling_order( (int) $term->parent );

    wp_send_json_success();
}

add_action( 'wp_ajax_wumf_undo', 'wumf_ajax_undo' );
function wumf_ajax_undo() {
    wumf_ajax_guard();

    $undo = wumf_get_undo();
    if ( ! $undo ) {
        wp_send_json_error( array( 'message' => '沒有可復原的操作，或復原期限已過。' ) );
    }

    $type = isset( $undo['type'] ) ? $undo['type'] : '';

    if ( 'assign' === $type && ! empty( $undo['previous'] ) && is_array( $undo['previous'] ) ) {
        foreach ( $undo['previous'] as $attachment_id => $folder_id ) {
            $attachment_id = absint( $attachment_id );
            if ( $attachment_id && current_user_can( 'edit_post', $attachment_id ) ) {
                wumf_set_attachment_folder( $attachment_id, (int) $folder_id );
            }
        }
    } elseif ( 'move_folder' === $type ) {
        $id = absint( $undo['id'] ?? 0 );
        if ( ! wumf_get_term_or_error( $id ) ) {
            wp_send_json_error( array( 'message' => '原資料夾已不存在。' ) );
        }
        $old_parent = absint( $undo['old_parent'] ?? 0 );
        wp_update_term( $id, WUMF_TAXONOMY, array( 'parent' => $old_parent ) );
        update_term_meta( $id, WUMF_META_ORDER, (int) ( $undo['old_order'] ?? 10 ) );
        wumf_normalize_sibling_order( $old_parent );
    } elseif ( 'delete_folder' === $type ) {
        $result = wp_insert_term(
            sanitize_text_field( $undo['name'] ?? '已復原資料夾' ),
            WUMF_TAXONOMY,
            array( 'parent' => absint( $undo['parent'] ?? 0 ) )
        );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( array( 'message' => $result->get_error_message() ) );
        }
        $new_id = absint( $result['term_id'] );
        update_term_meta( $new_id, WUMF_META_COLOR, sanitize_hex_color( $undo['color'] ?? '' ) ?: '#8b5cf6' );
        update_term_meta( $new_id, WUMF_META_STAR, ! empty( $undo['star'] ) ? 1 : 0 );
        update_term_meta( $new_id, WUMF_META_ORDER, (int) ( $undo['order'] ?? 10 ) );

        foreach ( (array) ( $undo['children'] ?? array() ) as $child_id ) {
            if ( wumf_get_term_or_error( $child_id ) ) {
                wp_update_term( $child_id, WUMF_TAXONOMY, array( 'parent' => $new_id ) );
            }
        }
        foreach ( (array) ( $undo['attachments'] ?? array() ) as $attachment_id ) {
            $attachment_id = absint( $attachment_id );
            if ( $attachment_id && current_user_can( 'edit_post', $attachment_id ) ) {
                wumf_set_attachment_folder( $attachment_id, $new_id );
            }
        }
    } else {
        wp_send_json_error( array( 'message' => '這個操作無法復原。' ) );
    }

    delete_user_meta( get_current_user_id(), WUMF_UNDO_KEY );
    wp_send_json_success();
}

/* ---------------------------------------------------------
 * 4) Filter Media Library queries
 * --------------------------------------------------------- */
add_filter( 'ajax_query_attachments_args', 'wumf_ajax_query_attachments_args' );
function wumf_ajax_query_attachments_args( $query ) {
    $raw_folder = null;
    $raw_scope  = 'folder';

    if ( isset( $_REQUEST['query']['wumf_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw_folder = (int) $_REQUEST['query']['wumf_folder']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }
    if ( isset( $_REQUEST['query']['wumf_scope'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $raw_scope = sanitize_key( $_REQUEST['query']['wumf_scope'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    if ( null === $raw_folder || 0 === $raw_folder ) {
        return $query;
    }

    $tax_query = isset( $query['tax_query'] ) && is_array( $query['tax_query'] ) ? $query['tax_query'] : array();

    if ( -1 === $raw_folder ) {
        $tax_query[] = array(
            'taxonomy' => WUMF_TAXONOMY,
            'operator' => 'NOT EXISTS',
        );
    } elseif ( $raw_folder > 0 ) {
        $tax_query[] = array(
            'taxonomy'         => WUMF_TAXONOMY,
            'field'            => 'term_id',
            'terms'            => array( $raw_folder ),
            'include_children' => ( 'subtree' === $raw_scope ),
        );
    }

    if ( $tax_query ) {
        $query['tax_query'] = $tax_query;
    }

    return $query;
}

/* Media Library list-mode fallback. */
add_action( 'restrict_manage_posts', 'wumf_list_filter_dropdown' );
function wumf_list_filter_dropdown() {
    global $pagenow;
    if ( 'upload.php' !== $pagenow || ! wumf_can_manage() ) {
        return;
    }

    $selected = isset( $_GET['wumf_folder'] ) ? (int) $_GET['wumf_folder'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    $terms = get_terms(
        array(
            'taxonomy'   => WUMF_TAXONOMY,
            'hide_empty' => false,
        )
    );

    echo '<select name="wumf_folder">';
    echo '<option value="0">所有資料夾</option>';
    echo '<option value="-1"' . selected( $selected, -1, false ) . '>未分類</option>';

    if ( ! is_wp_error( $terms ) ) {
        $by_parent = array();
        foreach ( $terms as $term ) {
            $by_parent[ (int) $term->parent ][] = $term;
        }
        wumf_render_list_options( 0, $by_parent, $selected, 0 );
    }

    echo '</select>';
}

function wumf_render_list_options( $parent, $by_parent, $selected, $depth ) {
    if ( empty( $by_parent[ $parent ] ) ) {
        return;
    }
    usort(
        $by_parent[ $parent ],
        function( $a, $b ) {
            $oa = wumf_get_order( $a->term_id );
            $ob = wumf_get_order( $b->term_id );
            return $oa === $ob ? strnatcasecmp( $a->name, $b->name ) : ( $oa <=> $ob );
        }
    );
    foreach ( $by_parent[ $parent ] as $term ) {
        printf(
            '<option value="%d"%s>%s%s</option>',
            (int) $term->term_id,
            selected( $selected, (int) $term->term_id, false ),
            esc_html( str_repeat( '— ', $depth ) ),
            esc_html( $term->name )
        );
        wumf_render_list_options( (int) $term->term_id, $by_parent, $selected, $depth + 1 );
    }
}

add_action( 'pre_get_posts', 'wumf_list_filter_query' );
function wumf_list_filter_query( $query ) {
    global $pagenow;
    if ( ! is_admin() || 'upload.php' !== $pagenow || ! $query->is_main_query() ) {
        return;
    }
    if ( ! isset( $_GET['wumf_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }

    $folder = (int) $_GET['wumf_folder']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( 0 === $folder ) {
        return;
    }

    $tax_query = (array) $query->get( 'tax_query' );
    if ( -1 === $folder ) {
        $tax_query[] = array(
            'taxonomy' => WUMF_TAXONOMY,
            'operator' => 'NOT EXISTS',
        );
    } elseif ( $folder > 0 ) {
        $tax_query[] = array(
            'taxonomy'         => WUMF_TAXONOMY,
            'field'            => 'term_id',
            'terms'            => array( $folder ),
            'include_children' => false,
        );
    }
    $query->set( 'tax_query', $tax_query );
}

/* ---------------------------------------------------------
 * 5) Put newly-uploaded media into the active folder
 * --------------------------------------------------------- */
add_action( 'add_attachment', 'wumf_assign_new_upload' );
function wumf_assign_new_upload( $attachment_id ) {
    if ( ! isset( $_REQUEST['wumf_upload_folder'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        return;
    }
    $folder = absint( $_REQUEST['wumf_upload_folder'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    if ( $folder && wumf_get_term_or_error( $folder ) && current_user_can( 'edit_post', $attachment_id ) ) {
        wumf_set_attachment_folder( $attachment_id, $folder );
    }
}

/* ---------------------------------------------------------
 * 6) Admin UI assets / styles
 * --------------------------------------------------------- */
add_action( 'admin_enqueue_scripts', 'wumf_admin_assets' );
function wumf_admin_assets() {
    if ( ! wumf_can_manage() ) {
        return;
    }

    // WordPress core ships these. They are only used in wp-admin and make
    // dragging existing attachments much more reliable than native HTML5 DnD alone.
    wp_enqueue_script( 'jquery-ui-draggable' );
    wp_enqueue_script( 'jquery-ui-droppable' );
}

add_action( 'admin_head', 'wumf_admin_css' );
function wumf_admin_css() {
    if ( ! wumf_can_manage() ) {
        return;
    }
    ?>
    <style id="wumf-admin-css">
        :root{--wumf-panel-width:258px}
        .attachments-browser.wumf-has-folders{
            position:relative!important;
            box-sizing:border-box!important;
            --wumf-panel-width:258px;
            --wumf-panel-top:72px;
        }
        /* IMPORTANT: only push the media grid, never cover WordPress' own toolbar. */
        .attachments-browser.wumf-has-folders > .attachments,
        .attachments-browser.wumf-has-folders > .uploader-inline{
            left:var(--wumf-panel-width)!important;
            inset-inline-start:var(--wumf-panel-width)!important;
        }
        /* WP 6.8+ wraps the grid in attachments-wrapper. Shift that wrapper too,
           otherwise the folder panel sits on top of the first media columns. */
        .attachments-browser.wumf-has-folders > .attachments-wrapper{
            box-sizing:border-box!important;
            margin-left:var(--wumf-panel-width)!important;
            width:calc(100% - var(--wumf-panel-width))!important;
        }
        .attachments-browser.wumf-has-folders > .attachments-wrapper > .attachments{
            left:0!important;
            inset-inline-start:0!important;
        }
        .wumf-panel{
            position:absolute;
            left:0;
            inset-inline-start:0;
            top:var(--wumf-panel-top);
            bottom:0;
            width:var(--wumf-panel-width);
            background:#fff;
            border-right:1px solid #dcdcde;
            z-index:25;
            display:flex;
            flex-direction:column;
            box-sizing:border-box;
            font-size:13px;
            color:#1d2327;
            overflow:hidden;
        }
        .wumf-head{padding:10px 10px 9px;border-bottom:1px solid #e5e7eb;background:#fff;flex:0 0 auto;box-sizing:border-box}
        .wumf-titleline{display:flex;align-items:center;justify-content:space-between;gap:7px;margin-bottom:8px}
        .wumf-title{font-weight:700;font-size:14px;line-height:28px}
        .wumf-tools{display:flex;gap:5px;align-items:center}
        .wumf-btn{border:1px solid #2271b1;background:#fff;color:#2271b1;border-radius:3px;padding:4px 7px;cursor:pointer;font-size:12px;line-height:1.45;white-space:nowrap}
        .wumf-btn:hover{background:#f0f6fc}.wumf-btn.primary{background:#2271b1;color:#fff}.wumf-btn:disabled{opacity:.55;cursor:not-allowed}
        .wumf-search{width:100%!important;max-width:none!important;margin:7px 0 0!important;padding:5px 8px!important;border:1px solid #8c8f94!important;border-radius:3px!important;box-sizing:border-box!important;min-height:32px!important}
        .wumf-scope{width:100%!important;max-width:none!important;margin:0!important;min-height:32px!important}
        .wumf-hint{font-size:11px;line-height:1.45;color:#646970;margin-top:7px}
        .wumf-tree{overflow:auto;padding:7px 6px 80px;flex:1;min-height:0}
        .wumf-row{display:flex;align-items:center;min-height:32px;border-radius:4px;cursor:pointer;position:relative;user-select:none;border:1px solid transparent;box-sizing:border-box;padding-right:2px}
        .wumf-row:hover{background:#f6f7f7}.wumf-row.active{background:#eef4ff;border-color:#72aee6}
        .wumf-row.wumf-media-drop{outline:2px dashed #2271b1;outline-offset:-2px;background:#e8f2fb}
        .wumf-row.dragover{outline:2px dashed #2271b1;outline-offset:-2px;background:#e8f2fb}
        .wumf-row.drag-before:before,.wumf-row.drag-after:after{content:"";position:absolute;left:7px;right:7px;height:2px;background:#2271b1;z-index:9}
        .wumf-row.drag-before:before{top:-2px}.wumf-row.drag-after:after{bottom:-2px}
        .wumf-indent{flex:0 0 auto}.wumf-caret{width:18px;flex:0 0 18px;text-align:center;color:#646970}.wumf-foldericon{width:18px;flex:0 0 18px;text-align:center;font-size:15px}.wumf-dot{width:9px;height:9px;border-radius:50%;display:inline-block}
        .wumf-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0}.wumf-count{color:#646970;font-size:11px;margin-left:5px;flex:0 0 auto}.wumf-star{width:20px;flex:0 0 20px;text-align:center;color:#dba617;font-size:14px}.wumf-more{width:22px;flex:0 0 22px;text-align:center;color:#646970;font-weight:700}
        .wumf-row[data-special="all"] .wumf-foldericon,.wumf-row[data-special="uncat"] .wumf-foldericon{color:#646970}
        .wumf-toast{position:fixed;right:24px;bottom:28px;z-index:100000;background:#1d2327;color:#fff;padding:10px 12px;border-radius:5px;box-shadow:0 4px 18px rgba(0,0,0,.22);display:flex;gap:10px;align-items:center}.wumf-toast button{background:none;border:0;color:#72aee6;text-decoration:underline;cursor:pointer;padding:0}
        .wumf-menu{position:fixed;z-index:100001;min-width:185px;background:#fff;border:1px solid #c3c4c7;border-radius:5px;box-shadow:0 8px 28px rgba(0,0,0,.18);padding:5px;display:none}.wumf-menu button{display:block;width:100%;text-align:left;border:0;background:none;padding:7px 9px;border-radius:3px;cursor:pointer;color:#1d2327}.wumf-menu button:hover{background:#f0f0f1}.wumf-menu .danger{color:#b32d2e}.wumf-colors{display:flex;gap:5px;padding:7px 8px;flex-wrap:wrap}.wumf-color{width:19px;height:19px;border-radius:50%;border:2px solid #fff;box-shadow:0 0 0 1px #c3c4c7;cursor:pointer}
        .wumf-dialog-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100010;display:flex;align-items:center;justify-content:center}.wumf-dialog{width:min(480px,calc(100vw - 32px));background:#fff;border-radius:7px;box-shadow:0 14px 50px rgba(0,0,0,.25);padding:18px;box-sizing:border-box}.wumf-dialog h3{margin:0 0 10px}.wumf-dialog p{margin:8px 0;color:#50575e}.wumf-dialog input[type=text],.wumf-dialog select{width:100%;max-width:none;box-sizing:border-box}.wumf-dialog-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
        .wumf-drag-helper{z-index:100020!important;background:#1d2327;color:#fff;border-radius:4px;padding:7px 10px;box-shadow:0 5px 18px rgba(0,0,0,.25);font-size:12px;pointer-events:none;white-space:nowrap}
        .attachments-browser .attachment.wumf-draggable{cursor:grab}.attachments-browser .attachment.wumf-draggable:active{cursor:grabbing}
        .wumf-panel *{box-sizing:border-box}
        @media(max-width:782px){
            .attachments-browser.wumf-has-folders{--wumf-panel-width:220px}
            .wumf-titleline{align-items:flex-start}.wumf-tools{flex-direction:column;align-items:stretch}.wumf-btn{padding-left:5px;padding-right:5px}
        }
    </style>
    <?php
}

/* ---------------------------------------------------------
 * 7) Admin UI JavaScript
 * --------------------------------------------------------- */
add_action( 'admin_footer', 'wumf_admin_js', 99 );
function wumf_admin_js() {
    if ( ! wumf_can_manage() ) {
        return;
    }

    $config = array(
        'ajax'  => admin_url( 'admin-ajax.php' ),
        'nonce' => wp_create_nonce( 'wumf_nonce' ),
    );
    ?>
    <script id="wumf-admin-js">
    (function($){
        'use strict';

        const CFG = <?php echo wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ); ?>;
        const COLORS = ['#ef4444','#f59e0b','#eab308','#22c55e','#14b8a6','#3b82f6','#6366f1','#8b5cf6','#ec4899'];
        const STATE = {
            data:null,
            activeFolder:0,
            activeScope:'folder',
            collapsed:{},
            dragFolder:0,
            dragMedia:[],
            dragBrowser:null,
            refreshing:false
        };

        function api(action, data){
            return $.ajax({
                url: CFG.ajax,
                method: 'POST',
                dataType: 'json',
                data: Object.assign({action:action, nonce:CFG.nonce}, data||{})
            }).then(function(r){
                if(!r || !r.success){
                    throw new Error((r && r.data && r.data.message) || '操作失敗');
                }
                return r.data || {};
            });
        }

        function esc(s){ return $('<div>').text(s == null ? '' : String(s)).html(); }

        function toast(msg, undo){
            $('.wumf-toast').remove();
            const $t = $('<div class="wumf-toast"><span></span></div>').find('span').text(msg).end();
            if(undo){
                $('<button type="button">復原</button>').appendTo($t).on('click', function(){
                    api('wumf_undo').then(function(){
                        toast('已復原');
                        reloadTree(true);
                        refreshAll();
                    }).catch(function(err){ alert(err.message); });
                });
            }
            $('body').append($t);
            setTimeout(function(){ $t.fadeOut(180,function(){$t.remove();}); }, 6500);
        }

        function reloadTree(force){
            if(STATE.data && !force){
                return $.Deferred().resolve(STATE.data).promise();
            }
            return api('wumf_tree').then(function(data){
                STATE.data=data;
                renderAll();
                return data;
            });
        }

        function byParent(){
            const m={};
            (STATE.data?.folders||[]).forEach(function(f){
                (m[f.parent]||(m[f.parent]=[])).push(f);
            });
            Object.keys(m).forEach(function(k){
                m[k].sort(function(a,b){
                    if(!!a.star !== !!b.star) return a.star ? -1 : 1;
                    if((a.order||0)!==(b.order||0)) return (a.order||0)-(b.order||0);
                    return a.name.localeCompare(b.name, 'zh-Hant');
                });
            });
            return m;
        }

        function descendants(id, map, out){
            (map[id]||[]).forEach(function(f){
                out.push(f.id);
                descendants(f.id,map,out);
            });
            return out;
        }

        function filteredSet(q){
            q=(q||'').trim().toLowerCase();
            if(!q) return null;
            const folders=STATE.data?.folders||[];
            const map=byParent(), keep=new Set();
            folders.forEach(function(f){
                if(f.name.toLowerCase().includes(q)){
                    keep.add(f.id);
                    let p=f.parent;
                    while(p){
                        keep.add(p);
                        const pf=folders.find(function(x){ return x.id===p; });
                        p=pf?pf.parent:0;
                    }
                    descendants(f.id,map,[]).forEach(function(x){ keep.add(x); });
                }
            });
            return keep;
        }

        function renderTree($panel){
            if(!$panel.length || !STATE.data) return;
            const $tree=$panel.find('.wumf-tree').empty();
            const map=byParent();
            const query=$panel.find('.wumf-search').val()||'';
            const keep=filteredSet(query);
            const active=Number($panel.data('folder')||0);

            function rowSpecial(id,name,count,special,icon){
                const $r=$('<div class="wumf-row" tabindex="0"></div>').attr({'data-folder':id,'data-special':special});
                if(active===id) $r.addClass('active');
                $r.append('<span class="wumf-caret"></span><span class="wumf-foldericon">'+icon+'</span><span class="wumf-name">'+esc(name)+'</span><span class="wumf-count">'+count+'</span>');
                $tree.append($r);
            }

            rowSpecial(0,'所有檔案',STATE.data.all_count||0,'all','◫');
            rowSpecial(-1,'未分類',STATE.data.uncategorized_count||0,'uncat','▱');

            function draw(parent,depth){
                (map[parent]||[]).forEach(function(f){
                    if(keep && !keep.has(f.id)) return;
                    const hasKids=!!(map[f.id]&&map[f.id].length);
                    const collapsed=!!STATE.collapsed[f.id] && !query;
                    const $r=$('<div class="wumf-row" tabindex="0" draggable="true"></div>').attr({'data-folder':f.id,'data-parent':f.parent});
                    if(active===f.id) $r.addClass('active');
                    $r.append('<span class="wumf-indent" style="width:'+(depth*16)+'px"></span>');
                    $r.append('<span class="wumf-caret">'+(hasKids?(collapsed?'▸':'▾'):'')+'</span>');
                    $r.append('<span class="wumf-foldericon"><span class="wumf-dot" style="background:'+(f.color||'#8b5cf6')+'"></span></span>');
                    $r.append('<span class="wumf-name">'+esc(f.name)+'</span><span class="wumf-count">'+f.count+'</span>');
                    $r.append('<span class="wumf-star">'+(f.star?'★':'')+'</span><span class="wumf-more">⋯</span>');
                    $tree.append($r);
                    if(!collapsed) draw(f.id,depth+1);
                });
            }
            draw(0,0);

            initFolderDropTargets($panel.closest('.attachments-browser'), $panel);
        }

        function renderAll(){
            $('.wumf-panel').each(function(){ renderTree($(this)); });
        }

        function panelHtml(){
            return '<div class="wumf-panel">'+
                '<div class="wumf-head">'+
                    '<div class="wumf-titleline"><span class="wumf-title">資料夾</span><div class="wumf-tools">'+
                        '<button type="button" class="wumf-btn wumf-move-selected">移動所選</button>'+
                        '<button type="button" class="wumf-btn wumf-new">＋ 新增</button>'+
                    '</div></div>'+
                    '<select class="wumf-scope"><option value="folder">只顯示此資料夾</option><option value="subtree">包含子資料夾</option></select>'+
                    '<input class="wumf-search" type="search" placeholder="搜尋資料夾">'+
                    '<div class="wumf-hint">可直接把右側既有圖片拖到資料夾；若不好拖，先選取圖片後按「移動所選」。</div>'+
                '</div><div class="wumf-tree"></div></div>';
        }

        function getCollection($browser){
            const view=$browser.data('wumfView');
            if(view && view.collection && view.collection.props) return view.collection;
            try{
                if(window.wp && wp.media){
                    const frame=(wp.media.frames && wp.media.frames.browse) || wp.media.frame || null;
                    if(frame && frame.content){
                        const content=frame.content.get();
                        if(content && content.collection && content.collection.props) return content.collection;
                    }
                    if(frame && frame.state){
                        const state=frame.state();
                        const lib=state && state.get ? state.get('library') : null;
                        if(lib && lib.props) return lib;
                    }
                }
            }catch(e){}
            return null;
        }

        function applyFilter($browser, folder, scope){
            folder=Number(folder||0);
            scope=scope||'folder';
            const $panel=$browser.children('.wumf-panel');
            $panel.data('folder',folder);
            $panel.find('.wumf-scope').val(scope);
            STATE.activeFolder=folder;
            STATE.activeScope=scope;
            renderTree($panel);

            const collection=getCollection($browser);
            if(collection && collection.props){
                collection.props.set({wumf_folder:folder,wumf_scope:scope,wumf_tick:Date.now()});
            } else if($('body').hasClass('upload-php') && !$('.media-modal:visible').length){
                const u=new URL(window.location.href);
                u.searchParams.set('mode','grid');
                if(folder) u.searchParams.set('wumf_folder',folder); else u.searchParams.delete('wumf_folder');
                window.location.href=u.toString();
            }

            setUploadFolder(folder>0?folder:0);
        }

        function refreshBrowser($browser){
            const c=getCollection($browser);
            if(c && c.props){
                const $panel=$browser.children('.wumf-panel');
                const f=Number($panel.data('folder')||0);
                const s=$panel.find('.wumf-scope').val()||'folder';
                c.props.set({wumf_folder:f,wumf_scope:s,wumf_tick:Date.now()});
            } else if($('body').hasClass('upload-php') && !$('.media-modal:visible').length){
                window.location.reload();
            }
        }

        function refreshAll(){
            $('.attachments-browser.wumf-has-folders').each(function(){ refreshBrowser($(this)); });
        }

        function setUploadFolder(folder){
            try{
                if(window.wp && wp.Uploader && wp.Uploader.defaults){
                    wp.Uploader.defaults.multipart_params=wp.Uploader.defaults.multipart_params||{};
                    wp.Uploader.defaults.multipart_params.wumf_upload_folder=folder||0;
                }
                if(window.wp && wp.media){
                    const frame=(wp.media.frames && wp.media.frames.browse) || wp.media.frame || null;
                    const uploader=frame && frame.uploader && frame.uploader.uploader && frame.uploader.uploader.uploader;
                    if(uploader && uploader.settings){
                        uploader.settings.multipart_params=uploader.settings.multipart_params||{};
                        uploader.settings.multipart_params.wumf_upload_folder=folder||0;
                    }
                }
            }catch(e){}
        }

        function syncBrowserLayout($browser){
            if(!$browser || !$browser.length) return;
            const $toolbar=$browser.children('.media-toolbar:visible').first();
            let top=0;

            if($toolbar.length){
                const pos=$toolbar.position();
                top=Math.max(0,Math.ceil((pos ? pos.top : 0)+$toolbar.outerHeight(true)));
            } else {
                // Some WP builds briefly render before the toolbar exists.
                const $attachments=$browser.children('.attachments').first();
                if($attachments.length){
                    const pos=$attachments.position();
                    top=Math.max(0,Math.ceil(pos ? pos.top : 0));
                }
            }

            if(top<40) top=72;
            $browser[0].style.setProperty('--wumf-panel-top', top+'px');

            // Never let our panel be wider than ~40% of a small media modal.
            const width=$browser.width();
            let panelWidth=258;
            if(width && width<760) panelWidth=220;
            if(width && panelWidth>width*0.42) panelWidth=Math.max(180,Math.floor(width*0.36));
            $browser[0].style.setProperty('--wumf-panel-width', panelWidth+'px');
        }

        function mountBrowser(viewOrEl){
            let $browser, view=null;
            if(viewOrEl && viewOrEl.$el){
                view=viewOrEl;
                $browser=view.$el;
            } else {
                $browser=$(viewOrEl);
            }
            if(!$browser || !$browser.length) return;

            if(!$browser.hasClass('wumf-has-folders')){
                $browser.addClass('wumf-has-folders').prepend(panelHtml());
                if(view) $browser.data('wumfView',view);
                const $panel=$browser.children('.wumf-panel').data('folder',STATE.activeFolder);
                $panel.find('.wumf-scope').val(STATE.activeScope);
                bindPanel($browser,$panel);
                reloadTree(false).then(function(){ renderTree($panel); });
            } else if(view) {
                $browser.data('wumfView',view);
            }

            syncBrowserLayout($browser);
            initAttachmentDraggables($browser);
        }

        function bindPanel($browser,$panel){
            $panel.on('click','.wumf-row',function(e){
                if($(e.target).is('.wumf-more')) return;
                if($(e.target).is('.wumf-caret')){
                    const id=Number($(this).data('folder'));
                    if(id>0){
                        STATE.collapsed[id]=!STATE.collapsed[id];
                        renderTree($panel);
                    }
                    return;
                }
                const id=Number($(this).data('folder'));
                applyFilter($browser,id,$panel.find('.wumf-scope').val());
            });

            $panel.on('dblclick','.wumf-name',function(e){
                const id=Number($(this).closest('.wumf-row').data('folder'));
                if(id>0) renameFolder(id);
                e.stopPropagation();
            });

            $panel.on('click','.wumf-more',function(e){
                e.preventDefault();
                e.stopPropagation();
                const id=Number($(this).closest('.wumf-row').data('folder'));
                showMenu(id,e.clientX,e.clientY);
            });

            $panel.on('contextmenu','.wumf-row[data-folder]:not([data-special])',function(e){
                e.preventDefault();
                showMenu(Number($(this).data('folder')),e.clientX,e.clientY);
            });

            $panel.on('click','.wumf-new',function(){
                createFolder(Number($panel.data('folder'))>0?Number($panel.data('folder')):0);
            });

            $panel.on('click','.wumf-move-selected',function(){
                const ids=getSelectedAttachmentIdsFromBrowser($browser);
                if(!ids.length){
                    alert('請先在右側媒體庫選取至少一個檔案。\n\n單一檔案可直接點選；多個檔案可使用 WordPress 的「批次選取」。');
                    return;
                }
                moveSelectedDialog($browser,ids);
            });

            $panel.on('input','.wumf-search',function(){ renderTree($panel); });
            $panel.on('change','.wumf-scope',function(){ applyFilter($browser,Number($panel.data('folder')),$(this).val()); });

            // Native HTML5 drag for folders themselves.
            $panel.on('dragstart','.wumf-row[draggable="true"]',function(e){
                STATE.dragFolder=Number($(this).data('folder'));
                STATE.dragMedia=[];
                e.originalEvent.dataTransfer.effectAllowed='move';
                e.originalEvent.dataTransfer.setData('text/wumf-folder',String(STATE.dragFolder));
            });
            $panel.on('dragend','.wumf-row[draggable="true"]',function(){
                STATE.dragFolder=0;
                clearDragClasses();
            });

            $panel.on('dragover','.wumf-row',function(e){
                if(!STATE.dragFolder && !STATE.dragMedia.length) return;
                const target=Number($(this).data('folder'));
                if(STATE.dragFolder && target<1) return;
                e.preventDefault();
                const $r=$(this);
                clearDragClasses();
                if(STATE.dragFolder){
                    const rect=this.getBoundingClientRect();
                    const y=e.originalEvent.clientY-rect.top;
                    const frac=y/rect.height;
                    if(frac<0.26) $r.addClass('drag-before');
                    else if(frac>0.74) $r.addClass('drag-after');
                    else $r.addClass('dragover');
                } else {
                    $r.addClass('dragover');
                }
            });

            $panel.on('dragleave','.wumf-row',function(e){
                if(!this.contains(e.relatedTarget)) $(this).removeClass('dragover drag-before drag-after');
            });

            $panel.on('drop','.wumf-row',function(e){
                e.preventDefault();
                const $r=$(this);
                const target=Number($r.data('folder'));
                const special=$r.data('special');

                if(STATE.dragFolder){
                    if(target<1) return clearDragClasses();
                    let pos='inside';
                    if($r.hasClass('drag-before')) pos='before';
                    else if($r.hasClass('drag-after')) pos='after';
                    api('wumf_move_folder',{id:STATE.dragFolder,target:target,position:pos}).then(function(){
                        toast('資料夾已移動',true);
                        reloadTree(true);
                    }).catch(function(err){ alert(err.message); }).always(clearDragClasses);
                    STATE.dragFolder=0;
                    return;
                }

                const ids=STATE.dragMedia.length?STATE.dragMedia:dragIdsFromTransfer(e.originalEvent.dataTransfer);
                if(!ids.length) return clearDragClasses();
                if(target===0 && special==='all') return clearDragClasses();
                const dest=target>0?target:0;
                assignMedia($browser,ids,dest);
            });
        }

        function clearDragClasses(){
            $('.wumf-row').removeClass('dragover drag-before drag-after wumf-media-drop');
        }

        function dragIdsFromTransfer(dt){
            try{
                const raw=dt.getData('text/wumf-media');
                return raw?JSON.parse(raw):[];
            }catch(e){
                return [];
            }
        }

        function getSelectedAttachmentIdsFromBrowser($browser, fallbackId){
            let ids=[];
            if(!$browser || !$browser.length) return fallbackId?[Number(fallbackId)]:[];

            // WP uses .selected for batch selection and .details for the current single selection.
            const $selected=$browser.find('.attachment.selected[data-id], .attachment.details[data-id]');
            $selected.each(function(){
                const id=Number($(this).attr('data-id'));
                if(id) ids.push(id);
            });

            if(!ids.length && fallbackId){
                ids=[Number(fallbackId)];
            }
            return Array.from(new Set(ids.filter(Boolean)));
        }

        function assignMedia($browser,ids,dest){
            ids=Array.from(new Set((ids||[]).map(Number).filter(Boolean)));
            if(!ids.length) return;
            api('wumf_assign',{folder:dest,ids:ids}).then(function(d){
                toast('已移動 '+d.moved+' 個檔案',true);
                STATE.dragMedia=[];
                clearDragClasses();
                reloadTree(true);
                refreshBrowser($browser);
            }).catch(function(err){
                STATE.dragMedia=[];
                clearDragClasses();
                alert(err.message);
            });
        }

        function initAttachmentDraggables($browser){
            if(!$browser || !$browser.length) return;
            const $items=$browser.find('.attachment[data-id]');
            $items.addClass('wumf-draggable').attr('draggable','true');

            // Native DnD - delegated by data flag so rerendered attachments also work.
            $items.each(function(){
                const el=this;
                if(el.dataset.wumfNativeDrag==='1') return;
                el.dataset.wumfNativeDrag='1';

                el.addEventListener('dragstart',function(e){
                    const id=Number(el.getAttribute('data-id'));
                    STATE.dragFolder=0;
                    STATE.dragBrowser=$browser;
                    STATE.dragMedia=getSelectedAttachmentIdsFromBrowser($browser,id);
                    try{
                        e.dataTransfer.effectAllowed='move';
                        e.dataTransfer.setData('text/wumf-media',JSON.stringify(STATE.dragMedia));
                        e.dataTransfer.setData('text/plain','wumf-media');
                    }catch(err){}
                },false);

                el.addEventListener('dragend',function(){
                    STATE.dragMedia=[];
                    STATE.dragBrowser=null;
                    clearDragClasses();
                },false);
            });

            // jQuery UI fallback: this fixes themes/plugins/browser builds where native
            // dragging of the Backbone attachment <li> is intercepted.
            if($.fn.draggable){
                $items.each(function(){
                    const $item=$(this);
                    if($item.data('wumfJquiDrag')) return;
                    $item.data('wumfJquiDrag',1);
                    try{
                        $item.draggable({
                            distance:7,
                            delay:40,
                            appendTo:'body',
                            zIndex:100019,
                            cursorAt:{left:12,top:12},
                            helper:function(){
                                const id=Number($item.attr('data-id'));
                                STATE.dragFolder=0;
                                STATE.dragBrowser=$browser;
                                STATE.dragMedia=getSelectedAttachmentIdsFromBrowser($browser,id);
                                return $('<div class="wumf-drag-helper">移動 '+STATE.dragMedia.length+' 個媒體檔案</div>');
                            },
                            start:function(){
                                const id=Number($item.attr('data-id'));
                                STATE.dragMedia=getSelectedAttachmentIdsFromBrowser($browser,id);
                                STATE.dragBrowser=$browser;
                                initFolderDropTargets($browser,$browser.children('.wumf-panel'));
                            },
                            stop:function(){
                                setTimeout(function(){
                                    STATE.dragMedia=[];
                                    STATE.dragBrowser=null;
                                    clearDragClasses();
                                },50);
                            }
                        });
                    }catch(e){}
                });
            }
        }

        function initFolderDropTargets($browser,$panel){
            if(!$panel || !$panel.length || !$.fn.droppable) return;
            $panel.find('.wumf-row').each(function(){
                const $row=$(this);
                const target=Number($row.data('folder'));
                const special=$row.data('special');
                if(target===0 && special==='all') return;
                if($row.data('wumfJquiDrop')) return;
                $row.data('wumfJquiDrop',1);
                try{
                    $row.droppable({
                        tolerance:'pointer',
                        accept:'.attachment.wumf-draggable',
                        over:function(){
                            if(STATE.dragMedia.length) $row.addClass('wumf-media-drop');
                        },
                        out:function(){ $row.removeClass('wumf-media-drop'); },
                        drop:function(e,ui){
                            $row.removeClass('wumf-media-drop');
                            const sourceId=Number(ui.draggable && ui.draggable.attr ? ui.draggable.attr('data-id') : 0);
                            const ids=STATE.dragMedia.length?STATE.dragMedia:getSelectedAttachmentIdsFromBrowser($browser,sourceId);
                            if(!ids.length) return;
                            const dest=target>0?target:0;
                            assignMedia($browser,ids,dest);
                        }
                    });
                }catch(e){}
            });
        }

        function folderById(id){
            return (STATE.data?.folders||[]).find(function(f){ return f.id===Number(id); });
        }

        function createFolder(parent){
            const name=window.prompt('資料夾名稱：','新資料夾');
            if(name===null || !name.trim()) return;
            api('wumf_create_folder',{name:name.trim(),parent:parent||0}).then(function(){
                toast('資料夾已新增');
                reloadTree(true);
            }).catch(function(err){ alert(err.message); });
        }

        function renameFolder(id){
            const f=folderById(id);
            if(!f) return;
            const name=window.prompt('重新命名資料夾：',f.name);
            if(name===null || !name.trim() || name.trim()===f.name) return;
            api('wumf_rename_folder',{id:id,name:name.trim()}).then(function(){
                toast('已重新命名');
                reloadTree(true);
            }).catch(function(err){ alert(err.message); });
        }

        function starFolder(id){
            const f=folderById(id);
            if(!f) return;
            api('wumf_folder_meta',{id:id,type:'star',value:f.star?0:1}).then(function(){
                reloadTree(true);
            }).catch(function(err){ alert(err.message); });
        }

        function colorFolder(id,color){
            api('wumf_folder_meta',{id:id,type:'color',value:color}).then(function(){
                reloadTree(true);
            }).catch(function(err){ alert(err.message); });
        }

        function showMenu(id,x,y){
            $('.wumf-menu').remove();
            const f=folderById(id);
            if(!f) return;
            const $m=$('<div class="wumf-menu"></div>');
            $('<button type="button">＋ 新增子資料夾</button>').appendTo($m).on('click',function(){ closeMenu(); createFolder(id); });
            $('<button type="button">✎ 重新命名</button>').appendTo($m).on('click',function(){ closeMenu(); renameFolder(id); });
            $('<button type="button">'+(f.star?'☆ 取消收藏':'★ 收藏')+'</button>').appendTo($m).on('click',function(){ closeMenu(); starFolder(id); });
            const $colors=$('<div class="wumf-colors"></div>').appendTo($m);
            COLORS.forEach(function(c){
                $('<span class="wumf-color"></span>').css('background',c).attr('title',c).appendTo($colors).on('click',function(){ closeMenu(); colorFolder(id,c); });
            });
            $('<button type="button" class="danger">🗑 刪除資料夾</button>').appendTo($m).on('click',function(){ closeMenu(); deleteFolderDialog(id); });
            $('body').append($m);
            const w=$m.outerWidth(), h=$m.outerHeight(), vw=window.innerWidth, vh=window.innerHeight;
            $m.css({left:Math.max(5,Math.min(x,vw-w-10)),top:Math.max(5,Math.min(y,vh-h-10))}).show();
            setTimeout(function(){
                $(document).one('mousedown.wumfmenu',function(e){
                    if(!$(e.target).closest('.wumf-menu').length) closeMenu();
                });
            },0);
        }

        function closeMenu(){
            $('.wumf-menu').remove();
            $(document).off('mousedown.wumfmenu');
        }

        function moveSelectedDialog($browser,ids){
            const folders=(STATE.data?.folders||[]).slice();
            const map={};
            folders.forEach(function(f){ (map[f.parent]||(map[f.parent]=[])).push(f); });
            Object.keys(map).forEach(function(k){
                map[k].sort(function(a,b){
                    if((a.order||0)!==(b.order||0)) return (a.order||0)-(b.order||0);
                    return a.name.localeCompare(b.name,'zh-Hant');
                });
            });

            let options='<option value="0">未分類</option>';
            function walk(parent,depth){
                (map[parent]||[]).forEach(function(f){
                    options+='<option value="'+f.id+'">'+esc('— '.repeat(depth)+f.name)+'</option>';
                    walk(f.id,depth+1);
                });
            }
            walk(0,0);

            const html='<div class="wumf-dialog-backdrop"><div class="wumf-dialog">'+
                '<h3>移動所選媒體</h3>'+
                '<p>已選取 <strong>'+ids.length+'</strong> 個檔案。請選擇要移動到的資料夾：</p>'+
                '<select class="wumf-move-destination">'+options+'</select>'+
                '<div class="wumf-dialog-actions"><button type="button" class="button wumf-cancel">取消</button><button type="button" class="button button-primary wumf-confirm-move">移動</button></div>'+
                '</div></div>';
            const $d=$(html).appendTo('body');
            $d.on('click','.wumf-cancel',function(){ $d.remove(); });
            $d.on('click',function(e){ if(e.target===this) $d.remove(); });
            $d.on('click','.wumf-confirm-move',function(){
                const dest=Number($d.find('.wumf-move-destination').val()||0);
                $(this).prop('disabled',true).text('移動中…');
                api('wumf_assign',{folder:dest,ids:ids}).then(function(r){
                    $d.remove();
                    toast('已移動 '+r.moved+' 個檔案',true);
                    reloadTree(true);
                    refreshBrowser($browser);
                }).catch(function(err){
                    alert(err.message);
                    $d.find('.wumf-confirm-move').prop('disabled',false).text('移動');
                });
            });
        }

        function deleteFolderDialog(id){
            const f=folderById(id);
            if(!f) return;
            const html='<div class="wumf-dialog-backdrop"><div class="wumf-dialog">'+
                '<h3>刪除「'+esc(f.name)+'」</h3>'+
                '<p><label><input type="radio" name="wumf-del-mode" value="folder" checked> 只刪除資料夾（媒體保留，子資料夾往上一層）</label></p>'+
                '<p><label><input type="radio" name="wumf-del-mode" value="media"> 同時永久刪除這個資料夾與其子資料夾內的媒體</label></p>'+
                '<div class="wumf-danger-confirm" style="display:none"><p><strong>此操作不可復原。</strong> 請輸入資料夾名稱確認：</p><input type="text" class="wumf-typed" autocomplete="off" placeholder="'+esc(f.name)+'"></div>'+
                '<div class="wumf-dialog-actions"><button type="button" class="button wumf-cancel">取消</button><button type="button" class="button button-primary wumf-confirm-delete">刪除</button></div>'+
                '</div></div>';
            const $d=$(html).appendTo('body');
            $d.on('change','input[name="wumf-del-mode"]',function(){
                $d.find('.wumf-danger-confirm').toggle($(this).val()==='media');
            });
            $d.on('click','.wumf-cancel',function(){ $d.remove(); });
            $d.on('click',function(e){ if(e.target===this) $d.remove(); });
            $d.on('click','.wumf-confirm-delete',function(){
                const delMedia=$d.find('input[name="wumf-del-mode"]:checked').val()==='media';
                const typed=$d.find('.wumf-typed').val()||'';
                if(delMedia && typed!==f.name){
                    alert('請完整輸入資料夾名稱「'+f.name+'」');
                    return;
                }
                $(this).prop('disabled',true).text('處理中…');
                api('wumf_delete_folder',{id:id,delete_media:delMedia?1:0,typed:typed}).then(function(){
                    $d.remove();
                    toast(delMedia?'資料夾與媒體已永久刪除':'資料夾已刪除',!delMedia);
                    reloadTree(true);
                    refreshAll();
                }).catch(function(err){
                    alert(err.message);
                    $d.find('.wumf-confirm-delete').prop('disabled',false).text('刪除');
                });
            });
        }

        function patchMediaViews(){
            if(!(window.wp && wp.media && wp.media.view && wp.media.view.AttachmentsBrowser)) return false;
            const proto=wp.media.view.AttachmentsBrowser.prototype;
            if(proto.__wumfPatched) return true;
            proto.__wumfPatched=true;
            const originalRender=proto.render;
            proto.render=function(){
                const out=originalRender.apply(this,arguments);
                const self=this;
                setTimeout(function(){
                    mountBrowser(self);
                    syncBrowserLayout(self.$el);
                    initAttachmentDraggables(self.$el);
                },0);
                setTimeout(function(){ syncBrowserLayout(self.$el); },120);
                return out;
            };
            return true;
        }

        function observe(){
            const mo=new MutationObserver(function(muts){
                let needsLayout=false;
                muts.forEach(function(m){
                    m.addedNodes.forEach(function(n){
                        if(n.nodeType!==1) return;
                        if(n.matches && n.matches('.attachments-browser')){
                            mountBrowser(n);
                            needsLayout=true;
                        }
                        if(n.querySelectorAll){
                            $(n).find('.attachments-browser').each(function(){ mountBrowser(this); });
                            if($(n).find('.attachment[data-id]').length || (n.matches && n.matches('.attachment[data-id]'))){
                                const $b=$(n).closest('.attachments-browser').length?$(n).closest('.attachments-browser'):$(n).find('.attachments-browser').first();
                                if($b.length) initAttachmentDraggables($b);
                                $('.attachments-browser.wumf-has-folders').each(function(){ initAttachmentDraggables($(this)); });
                            }
                        }
                    });
                });
                if(needsLayout){
                    requestAnimationFrame(function(){ $('.attachments-browser.wumf-has-folders').each(function(){ syncBrowserLayout($(this)); }); });
                }
            });
            mo.observe(document.documentElement,{childList:true,subtree:true});
            $('.attachments-browser').each(function(){ mountBrowser(this); });
        }

        // Preserve folder/scope in query-attachments requests even when a WP build
        // drops unknown Backbone query props.
        $.ajaxPrefilter(function(options){
            if(!options || typeof options.data!=='string') return;
            if(options.data.indexOf('action=query-attachments')!==-1 && options.data.indexOf('query%5Bwumf_folder%5D')===-1 && options.data.indexOf('query[wumf_folder]')===-1){
                options.data += '&query%5Bwumf_folder%5D='+encodeURIComponent(STATE.activeFolder||0)+'&query%5Bwumf_scope%5D='+encodeURIComponent(STATE.activeScope||'folder');
            }
        });

        // Append the active folder to standard WordPress media uploads.
        $(document).ajaxSend(function(e,xhr,settings){
            if(!STATE.activeFolder || !settings) return;
            if(typeof settings.data==='string' && settings.data.indexOf('action=upload-attachment')!==-1 && settings.data.indexOf('wumf_upload_folder=')===-1){
                settings.data += '&wumf_upload_folder='+encodeURIComponent(STATE.activeFolder);
            }
        });

        $(window).on('resize.wumf',function(){
            $('.attachments-browser.wumf-has-folders').each(function(){ syncBrowserLayout($(this)); });
        });

        $(function(){
            let tries=0;
            const timer=setInterval(function(){
                tries++;
                if(patchMediaViews() || tries>40) clearInterval(timer);
            },250);
            patchMediaViews();
            observe();
            reloadTree(false);

            // Initial and delayed layout passes handle media modals opening after DOM paint.
            setTimeout(function(){ $('.attachments-browser.wumf-has-folders').each(function(){ syncBrowserLayout($(this)); initAttachmentDraggables($(this)); }); },100);
            setTimeout(function(){ $('.attachments-browser.wumf-has-folders').each(function(){ syncBrowserLayout($(this)); initAttachmentDraggables($(this)); }); },500);
        });
    })(jQuery);
    </script>
    <?php
}

/* ---------------------------------------------------------
 * Toolbox settings page: management stays in Media Library;
 * this page is intentionally a short operational overview.
 * --------------------------------------------------------- */
add_action( 'admin_menu', 'wutm_media_library_manager_menu', 30 );
function wutm_media_library_manager_menu() {
    add_submenu_page(
        'wu-toolbox-modular',
        '媒體庫管理',
        '媒體庫管理',
        'upload_files',
        'wu-media-library-manager',
        'wutm_media_library_manager_settings_page'
    );
}

function wutm_media_library_manager_settings_page() {
    if ( ! current_user_can( 'upload_files' ) ) {
        return;
    }

    $folders = get_terms( array( 'taxonomy' => WUMF_TAXONOMY, 'hide_empty' => false ) );
    $count   = is_wp_error( $folders ) ? 0 : count( $folders );
    ?>
    <div class="wrap wutm-module-wrap wutm-media-folders-overview">
        <header class="wutm-header"><div><h1>媒體庫管理</h1><p>以虛擬資料夾整理媒體檔案；不會搬移實體檔案，也不會改變既有圖片網址。</p></div><span>v<?php echo esc_html( WUTM_VERSION ); ?></span></header>
        <div class="wutm-media-overview-grid">
            <section class="wutm-media-overview-card"><h2>快速開始</h2><p>資料夾操作已整合到 WordPress 媒體庫與所有媒體選取視窗。</p><p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'upload.php?mode=grid' ) ); ?>">開啟媒體庫</a></p><ol><li>在左側資料夾面板按「＋ 新增」。</li><li>把圖片拖曳到資料夾列，或先多選圖片後點「移動所選」。</li><li>點選資料夾可立即篩選圖片；右鍵／更多選單可重新命名、變更顏色或刪除。</li></ol></section>
            <section class="wutm-media-overview-card"><h2>目前狀態</h2><p class="wutm-media-overview-number"><?php echo esc_html( (string) $count ); ?></p><p>個虛擬資料夾</p><p class="description">資料夾只儲存在 WordPress 媒體資料中，刪除資料夾不會刪除圖片；刪除圖片時才需要另外確認。</p></section>
            <section class="wutm-media-overview-card"><h2>簡單設定</h2><p><strong>拖放分類：</strong>已啟用。拖曳圖片時，資料夾目標會顯示藍色虛線提示。</p><p><strong>可用範圍：</strong>媒體庫、文章／頁面編輯器、WooCommerce 與其他使用 WordPress 媒體選取器的地方。</p><p><strong>子資料夾：</strong>已啟用，可在資料夾更多選單建立與重新排序。</p></section>
        </div>
    </div>
    <style>.wutm-media-folders-overview{max-width:1180px}.wutm-media-overview-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:18px;margin-top:22px}.wutm-media-overview-card{padding:22px;background:#fff;border:1px solid #dcdcde;border-radius:8px}.wutm-media-overview-card h2{margin-top:0}.wutm-media-overview-card li{margin:10px 0;line-height:1.6}.wutm-media-overview-number{margin:0;color:#2271b1;font-size:52px;font-weight:700;line-height:1}@media(max-width:782px){.wutm-media-overview-grid{grid-template-columns:1fr}}</style>
    <?php
}
