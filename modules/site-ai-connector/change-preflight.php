<?php
defined( 'ABSPATH' ) || exit;

function sac_change_error_path( $error, $group, $custom = [] ) {
    $data = (array) $error->get_error_data();
    $field = $data['field'] ?? '';
    if ( $field !== $group && ! str_starts_with( $field, $group . '.' ) ) {
        $prefix = $group === 'content' && is_array( $custom ) && array_key_exists( $field, $custom ) ? 'content.custom_fields' : $group;
        $data['field'] = $field === '' ? $prefix : $prefix . '.' . $field;
    }
    return new WP_Error( $error->get_error_code(), $error->get_error_message(), $data );
}

function sac_change_preflight_scopes( $request, $values ) {
    $grant = sac_authenticate( $request );
    if ( is_wp_error( $grant ) ) return $grant;
    $required = [ 'content:read', 'content:update' ];
    if ( isset( $values['seo'] ) ) $required = array_merge( $required, [ 'seo:read', 'seo:write' ] );
    if ( array_key_exists( 'faqs', $values ) ) $required = array_merge( $required, [ 'faq:read', 'faq:write' ] );
    if ( isset( $values['content']['terms'] ) ) $required = array_merge( $required, [ 'taxonomy:read', 'taxonomy:write' ] );
    if ( isset( $values['media'] ) || isset( $values['content']['featured_image_id'] ) ) $required = array_merge( $required, [ 'media:read', 'media:update' ] );
    if ( isset( $values['seo']['og_image_id'] ) ) $required[] = 'media:read';
    $missing = false;
    foreach ( array_unique( $required ) as $scope ) {
        $result = sac_oauth_require_scope( $grant, $scope );
        if ( ! is_wp_error( $result ) ) continue;
        if ( $result->get_error_code() !== 'insufficient_scope' ) return $result;
        $missing = true;
    }
    return $missing ? new WP_Error( 'insufficient_scope', '此變更需要額外 OAuth 權限。', [
        'status' => 403, 'scope' => implode( ' ', array_unique( $required ) ),
    ] ) : true;
}

function sac_change_preflight( $request, $target, $data, $restore = null ) {
    $dry = rest_sanitize_boolean( $data['dry_run'] ?? false );
    $post = $target['post'];
    $errors = [];
    $warnings = [];
    $values = array_intersect_key( $data, array_flip( [ 'content', 'seo', 'faqs', 'media' ] ) );
    $definition = array_column( sac_changes_definitions(), 'params', 'name' )['prepare_content_changes'];
    foreach ( array_diff_key( $data, $definition ) as $key => $unused ) $errors[] = sac_content_error( (string) $key, '此參數未開放。' );
    foreach ( [ 'content', 'seo', 'media' ] as $key ) {
        if ( array_key_exists( $key, $values ) && ( ! is_array( $values[ $key ] ) || ( $values[ $key ] && array_is_list( $values[ $key ] ) ) ) ) {
            $errors[] = sac_content_error( $key, '請提供物件。' );
            unset( $values[ $key ] );
        }
    }
    if ( array_key_exists( 'expected_status', $data ) && $data['expected_status'] !== $post->post_status ) {
        $errors[] = new WP_Error( 'sac_status_conflict', '文章狀態與預期值不同。請重新查詢。', [ 'field' => 'expected_status', 'status' => 409, 'actual_status' => $post->post_status ] );
    }
    if ( array_key_exists( 'status', $values['content'] ?? [] ) ) {
        if ( $values['content']['status'] !== $post->post_status ) {
            $errors[] = sac_content_error( 'content.status', '只接受目前文章狀態。發佈請使用 publish_content 與既有審核。' );
        } else {
            $warnings[] = [ 'code' => 'sac_status_unchanged', 'field' => 'content.status', 'message' => '狀態相同，將保留原狀態。' ];
        }
        unset( $values['content']['status'] );
    }
    if ( empty( $values['content'] ) ) unset( $values['content'] );
    $blocks = array_key_exists( 'block_edits', $data ) || ! empty( $restore['blocks'] );
    if ( array_key_exists( 'block_edits', $data ) ) {
        if ( array_key_exists( 'content', $values['content'] ?? [] ) ) {
            $errors[] = sac_content_error( 'block_edits', '整篇正文與區塊操作請擇一。' );
        } else {
            $edited = sac_apply_block_edits( $post->post_content, $data['block_version'] ?? null, $data['block_edits'] );
            if ( is_wp_error( $edited ) ) $errors[] = $edited;
            else $values['content']['content'] = $edited;
        }
    }
    if ( $errors && ! $dry ) return $errors[0];
    if ( ! $errors && ! $values && $warnings ) {
        return [ 'success' => true, 'no_changes' => true, 'dry_run' => $dry, 'confirmation_required' => false,
            'post_id' => $post->ID, 'post_type' => $post->post_type, 'actual_status' => $post->post_status, 'warnings' => $warnings ];
    }
    $scopes = sac_change_preflight_scopes( $request, $values );
    if ( is_wp_error( $scopes ) ) {
        if ( ! $errors ) return $scopes;
        $errors[] = $scopes;
        $values = [];
    }
    $expected = sac_change_validate( $request, $target, $values, $blocks );
    if ( is_wp_error( $expected ) ) {
        if ( ! $dry ) return $expected;
        $errors[] = $expected;
        // Validate independent fields with the same write validators. Limit per-request work.
        $checks = [];
        foreach ( $values['content'] ?? [] as $field => $value ) {
            if ( in_array( $field, [ 'custom_fields', 'terms' ], true ) && is_array( $value ) ) {
                foreach ( $value as $name => $item ) $checks[] = [ 'content' => [ $field => [ $name => $item ] ] ];
            } else {
                $checks[] = [ 'content' => [ $field => $value ] ];
            }
        }
        foreach ( $values['seo'] ?? [] as $field => $value ) $checks[] = [ 'seo' => [ $field => $value ] ];
        foreach ( [ 'faqs', 'media' ] as $group ) if ( array_key_exists( $group, $values ) ) $checks[] = [ $group => $values[ $group ] ];
        foreach ( array_slice( $checks, 0, 64 ) as $check ) {
            $result = sac_change_validate( $request, $target, $check, $blocks );
            if ( is_wp_error( $result ) ) $errors[] = $result;
        }
        if ( count( $checks ) > 64 ) $warnings[] = [ 'code' => 'sac_preflight_limit', 'message' => '個別驗證以 64 項為限，請分批檢查剩餘項目。' ];
    }
    if ( $dry ) {

        if ( ! $errors ) {
            $before = sac_change_snapshot( $target, $expected );
            $estimate = is_wp_error( $before ) ? false : sac_review_json( [ $values, $expected, $before ] );
            if ( is_wp_error( $before ) ) $errors[] = $before;
            elseif ( $estimate === false || strlen( $estimate ) > 2 * sac_review_max_bytes() - 4096 ) {
                $errors[] = sac_content_error( 'content', '審核快照超出上限。請減少正文或拆分變更。', 'sac_review_too_large' );
            }
        }
        $details = [];
        foreach ( $errors as $error ) {
            $field = $error->get_error_data()['field'] ?? 'changes';
            $parts = explode( '.', $field );
            $rule = $definition[ $parts[0] ] ?? [];
            if ( isset( $parts[1] ) ) $rule = $rule['properties'][ $parts[1] ] ?? $rule;
            if ( ( $parts[1] ?? '' ) === 'custom_fields' && isset( $parts[2] ) ) {
                $custom = sac_content_fields( $post->post_type, $post );
                $rule = $custom[ $parts[2] ] ?? [];
            }
            $details[ $field . ':' . $error->get_error_code() ] = [ 'field' => $field, 'code' => $error->get_error_code(),
                'message' => $error->get_error_message(), 'expected_type' => $rule['type'] ?? null, 'recommended_action' => 'review_field_and_rerun_preflight' ];
        }
        return [ 'success' => ! $errors, 'error_code' => $errors ? 'sac_preflight_failed' : null, 'dry_run' => true,
            'post_id' => $post->ID, 'post_type' => $post->post_type, 'actual_status' => $post->post_status,
            'errors' => array_values( $details ), 'warnings' => $warnings, 'max_request_bytes' => sac_review_max_bytes() ];
    }
    if ( $restore && ( sac_change_value( $expected ) !== sac_change_value( $restore['before'] ) ) ) {
        return sac_oauth_error( 'sac_rollback_unsupported', '部分原值無法透過現有適配器還原。', 409 );
    }
    return compact( 'values', 'expected', 'blocks', 'warnings' );
}
