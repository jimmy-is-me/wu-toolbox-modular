<?php
defined( 'ABSPATH' ) || exit;

function sac_changes_definitions() {
    $target = [ 'post_type' => [ 'type' => 'string', 'in' => 'path', 'required' => true ], 'id' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ] ];
    $change = [ 'operation' => [ 'type' => 'integer', 'in' => 'path', 'required' => true ] ];
    $key = [ 'type' => 'string', 'in' => 'body', 'required' => true, 'description' => '同一次請求重試時使用相同的 16 至 80 字元識別碼。' ];
    $params = [
        'list_content_blocks' => [ 'GET', '/content/{post_type}/{id}/blocks', '列出 Gutenberg 區塊及版本', $target + [
            'offset' => [ 'type' => 'integer', 'in' => 'query' ], 'block_path' => [ 'type' => 'string', 'in' => 'query' ],
        ] ],
        'prepare_content_changes' => [ 'POST', '/content/{post_type}/{id}/changes', '準備文章變更並取得 WordPress 審核網址', $target + [
            'idempotency_key' => $key,
            'purpose' => [ 'type' => 'string', 'in' => 'body', 'maxLength' => 280 ],
            'content' => [ 'type' => 'object', 'in' => 'body', 'description' => '可編輯的原生欄位、terms、custom_fields 或 featured_image_id。' ],
            'seo' => [ 'type' => 'object', 'in' => 'body' ],
            'media' => [ 'type' => 'object', 'in' => 'body', 'properties' => [
                'id' => [ 'type' => 'integer' ], 'title' => [ 'type' => 'string' ], 'alt_text' => [ 'type' => 'string' ],
                'caption' => [ 'type' => 'string' ], 'description' => [ 'type' => 'string' ],
            ], 'required' => [ 'id' ], 'additionalProperties' => false ],
            'faqs' => [ 'type' => 'array', 'in' => 'body', 'items' => [ 'type' => 'object' ] ],
            'block_version' => [ 'type' => 'string', 'in' => 'body' ],
            'block_edits' => [ 'type' => 'array', 'in' => 'body', 'items' => [ 'type' => 'object', 'properties' => [
                'operation' => [ 'type' => 'string', 'enum' => [ 'insert_before', 'insert_after', 'prepend', 'append', 'replace', 'delete', 'move_before', 'move_after' ] ],
                'path' => [ 'type' => 'string' ], 'hash' => [ 'type' => 'string' ],
                'target_path' => [ 'type' => 'string' ], 'target_hash' => [ 'type' => 'string' ],
                'markup' => [ 'type' => 'string' ],
            ], 'required' => [ 'operation' ], 'additionalProperties' => false ] ],
        ] ],
        'list_content_operations' => [ 'GET', '/content/{post_type}/{id}/changes', '列出此授權的文章變更紀錄', $target + [ 'before' => [ 'type' => 'integer', 'in' => 'query' ] ] ],
        'get_content_change_status' => [ 'GET', '/content-changes/{operation}', '查詢變更核准及執行結果', $change ],
        'verify_content_changes' => [ 'GET', '/content-changes/{operation}/verify', '逐項重新讀取已核准的內容變更', $change + [
            'frontend' => [ 'type' => 'boolean', 'in' => 'query' ],
        ] ],
        'rollback_content_changes' => [ 'POST', '/content-changes/{operation}/rollback', '準備還原變更及取得新審核網址', $change + [ 'idempotency_key' => $key ] ],
        'retry_content_cache_purge' => [ 'POST', '/content-changes/{operation}/purge-cache', '重新清除已套用變更的相關快取', $change ],
        'cancel_content_changes' => [ 'POST', '/content-changes/{operation}/cancel', '取消待審核的變更', $change ],
    ];
    $tools = [];
    foreach ( $params as $name => [ $method, $path, $summary, $schema ] ) {
        $tools[] = [ 'name' => $name, 'method' => $method, 'path' => $path, 'group' => '文章與 FAQ', 'summary' => $summary,
            'description' => $summary, 'scenario' => $summary, 'prompt' => $summary, 'params' => $schema,
            'need_write' => $method === 'POST', 'available' => true ];
    }
    return $tools;
}

function sac_change_target( $request, $type, $id, $write ) {
    $read = sac_content_target( $request, $type, 'read', $id );
    return is_wp_error( $read ) || ! $write ? $read : sac_content_target( $request, $type, 'update', $id );
}

/** Read only the fields under review and normalize term sets before comparison. */
function sac_change_snapshot( $target, $values ) {
    $post = $target['post'];
    $snapshot = [];
    if ( ! empty( $values['content'] ) ) {
        $data = [];
        $columns = [ 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt', 'slug' => 'post_name',
            'parent_id' => 'post_parent', 'author_id' => 'post_author' ];
        foreach ( $values['content'] as $key => $value ) {
            if ( isset( $columns[ $key ] ) ) {
                $data[ $key ] = $post->{$columns[ $key ]};
                if ( in_array( $key, [ 'author_id', 'parent_id' ], true ) ) $data[ $key ] = (int) $data[ $key ];
            } elseif ( $key === 'featured_image_id' ) {
                $data[ $key ] = (int) get_post_thumbnail_id( $post );
            } elseif ( $key === 'terms' ) {
                foreach ( $value as $name => $ids ) {
                    $result = wp_get_object_terms( $post->ID, $name, [ 'fields' => 'ids' ] );
                    if ( is_wp_error( $result ) ) return $result;
                    sort( $result, SORT_NUMERIC );
                    $data[ $key ][ $name ] = $result;
                }
            } elseif ( $key === 'custom_fields' ) {
                $fields = sac_content_fields( $post->post_type, $post );
                foreach ( $value as $name => $unused ) {
                    $field = $fields[ $name ] ?? [];
                    if ( ! sac_content_field_access( $field, 'read', $target ) ) return sac_content_error( $name, '需要可讀取的欄位才能審核及核對。' );
                    try {
                        $result = $field['read']( $post, $target['user'] );
                    } catch ( Throwable $error ) {
                        return sac_content_error( $name, '無法讀取欄位。' );
                    }
                    if ( is_wp_error( $result ) ) return $result;
                    $data[ $key ][ $name ] = $result;
                }
            }
        }
        $snapshot['content'] = $data;
    }
    if ( ! empty( $values['seo'] ) ) {
        foreach ( $values['seo'] as $key => $unused ) $snapshot['seo'][ $key ] = get_post_meta( $post->ID, $key, true );
    }
    if ( isset( $values['media'] ) ) {
        $id = $values['media']['id'];
        if ( ! wp_attachment_is_image( $id ) || ! user_can( $target['user'], 'edit_post', $id ) || ! user_can( $target['user'], 'read_post', $id ) ) {
            return sac_oauth_error( 'media_not_found', '找不到可編輯的圖片。', 404 );
        }
        $media = sac_media_item( $id );
        $snapshot['media'] = [ 'id' => $id ] + array_intersect_key( $media, $values['media'] );
    }
    if ( array_key_exists( 'faqs', $values ) ) $snapshot['faqs'] = get_post_meta( $post->ID, '_sac_faq_list', true ) ?: [];
    return $snapshot;
}

function sac_change_validate( $request, $target, $values, $blocks = false ) {
    $grant = sac_authenticate( $request );
    if ( is_wp_error( $grant ) ) return $grant;
    $post = $target['post'];
    $expected = [];
    foreach ( [ 'seo' => 'seo', 'faqs' => 'faq' ] as $key => $extension ) {
        if ( ! array_key_exists( $key, $values ) ) continue;
        if ( ! sac_content_extensions( $post->post_type )[ $extension ] ) return sac_content_error( $key, '目前內容類型不支援此功能。' );
        foreach ( [ ':read', ':write' ] as $suffix ) {
            $allowed = sac_oauth_require_scope( $grant, $extension . $suffix );
            if ( is_wp_error( $allowed ) ) return $allowed;
        }
    }
    if ( ! empty( $values['content'] ) ) {
        if ( array_intersect_key( $values['content'], array_flip( [ 'status', 'id', 'post_type' ] ) ) ) return sac_content_error( 'content', '批次變更不修改文章發佈狀態或識別資料。' );
        foreach ( [ 'terms' => 'taxonomy:read', 'featured_image_id' => 'media:read' ] as $field => $scope ) {
            if ( array_key_exists( $field, $values['content'] ) ) {
                $allowed = sac_oauth_require_scope( $grant, $scope );
                if ( is_wp_error( $allowed ) ) return $allowed;
            }
        }
        $native = sac_content_save( $request, $target, $values['content'], 'validate', $blocks );
        if ( is_wp_error( $native ) ) return $native;
        if ( $blocks && array_key_exists( 'content', $values['content'] ) && ( $native['content'] ?? null ) !== ( $values['content']['content'] ?? null ) ) {
            return sac_content_error( 'block_edits', '以目前帳號的 HTML 權限儲存會連帶變更原有區塊，請使用 WordPress 編輯器。' );
        }
        if ( $native ) {
            foreach ( $native['terms'] ?? [] as $name => $ids ) {
                sort( $ids, SORT_NUMERIC );
                $native['terms'][ $name ] = array_values( array_unique( $ids ) );
            }
            $expected['content'] = $native;
        }
    }
    if ( isset( $values['seo'] ) ) {
        if ( array_key_exists( 'og_image_id', $values['seo'] ) ) {
            $allowed = sac_oauth_require_scope( $grant, 'media:read' );
            if ( is_wp_error( $allowed ) ) return $allowed;
        }
        $seo = sac_prepare_seo_fields( $post->ID, $values['seo'] );
        if ( is_wp_error( $seo ) ) return $seo;
        $expected['seo'] = array_map( static fn( $v ) => is_array( $v ) ? $v : (string) $v, $seo );
    }
    if ( array_key_exists( 'faqs', $values ) ) {
        $faqs = sac_content_prepare_faq( $values['faqs'], $post->ID, $expected['content']['content'] ?? null );
        if ( is_wp_error( $faqs ) ) return $faqs;
        $expected['faqs'] = $faqs;
    }
    if ( isset( $values['media'] ) ) {
        foreach ( [ 'media:read', 'media:update' ] as $scope ) {
            $allowed = sac_oauth_require_scope( $grant, $scope );
            if ( is_wp_error( $allowed ) ) return $allowed;
        }
        $value = $values['media'];
        $media = sac_prepare_media_fields( $value['id'] ?? 0, array_diff_key( $value, [ 'id' => true ] ) );
        if ( is_wp_error( $media ) ) return $media;
        if ( ! $media ) return sac_content_error( 'media', '至少提供一個圖片欄位。' );
        $expected['media'] = [ 'id' => $value['id'] ] + $media;
    }
    return $expected ?: sac_content_error( 'changes', '至少提供一項可執行變更。' );
}

function sac_change_receipt( $row ) {
    $payload = $row['payload'];
    $state = (int) $row['state'];
    $status = [ 'pending', 'applying', 'applied', 'failed', 'rejected', 'cancelled', 'conflicted', 'revision_requested' ][ $state ] ?? 'unavailable';
    if ( $state === 0 && $payload['approval_expires'] <= time() ) $status = 'expired';
    return [
        'operation_id' => (int) $row['id'], 'change_set_id' => (int) $row['id'], 'post_id' => $payload['post_id'], 'post_type' => $payload['post_type'],
        'approval_status' => $status, 'confirmation_required' => $status === 'pending',
        'confirmation_expires_at' => gmdate( DATE_ATOM, $payload['approval_expires'] ),
        'confirmed_at' => $payload['confirmed_at'] ?? null, 'completed_at' => $payload['completed_at'] ?? null,
        'fields' => array_keys( $payload['expected'] ?? [] ), 'verification' => $payload['verification'] ?? null,
        'cache_verification' => $payload['cache'] ?? [ 'state' => 'unavailable', 'cache_purge_status' => 'unavailable' ],
        'error_code' => $payload['error_code'] ?? null, 'recovery' => $payload['recovery'] ?? null,
    ];
}

function sac_change_prepare( $request, $target, $data, $restore = null ) {
    $grant = sac_authenticate( $request );
    $key = $data['idempotency_key'] ?? '';
    if ( ! is_string( $key ) || ! preg_match( '/^[a-zA-Z0-9_-]{16,80}$/D', $key ) ) return sac_content_error( 'idempotency_key', '請使用 16 至 80 字元的請求識別碼。' );
    $hash = sac_review_hash( [ $target['post']->ID, $data, $restore['id'] ?? null ] );
    $secret = hash_hmac( 'sha256', 'sac-change:' . $grant['id'] . ':' . $key, wp_salt( 'nonce' ) );
    $row = sac_oauth_row( 'changes', $secret );
    if ( $row && ! hash_equals( $row['payload']['request_hash'], $hash ) ) return sac_oauth_error( 'sac_idempotency_conflict', '同一識別碼已用於不同變更。', 409 );
    if ( ! $row ) {
        if ( ! sac_oauth_rate( 'prepare_changes', 8, HOUR_IN_SECONDS, (string) $grant['id'] ) ) return sac_oauth_error( 'sac_rate_limit', '變更準備過於頻繁，請稍後重試。', 429 );
        $values = array_intersect_key( $data, array_flip( [ 'content', 'seo', 'faqs', 'media' ] ) );
        if ( ( isset( $values['content'] ) && ! is_array( $values['content'] ) ) || ( isset( $values['seo'] ) && ! is_array( $values['seo'] ) ) ) {
            return sac_content_error( 'changes', '文章與 SEO 參數須為物件。' );
        }
        $post = $target['post'];
        $blocks = array_key_exists( 'block_edits', $data ) || ! empty( $restore['blocks'] );
        if ( array_key_exists( 'block_edits', $data ) ) {
            if ( isset( $values['content']['content'] ) ) return sac_content_error( 'block_edits', '整篇正文與區塊操作請擇一。' );
            $edited = sac_apply_block_edits( $post->post_content, $data['block_version'] ?? null, $data['block_edits'] );
            if ( is_wp_error( $edited ) ) return $edited;
            $values['content']['content'] = $edited;
        }
        $expected = sac_change_validate( $request, $target, $values, $blocks );
        if ( is_wp_error( $expected ) ) return $expected;
        if ( $restore && sac_change_value( $expected ) !== sac_change_value( $restore['before'] ) ) {
            return sac_oauth_error( 'sac_rollback_unsupported', '部分原始值無法透過現有欄位適配器安全還原，請在 WordPress 編輯器核對。', 409 );
        }
        $before = sac_change_snapshot( $target, $expected );
        if ( is_wp_error( $before ) ) return $before;
        if ( $restore && sac_change_value( $before ) !== sac_change_value( $restore['after'] ) ) {
            return sac_oauth_error( 'sac_confirmation_version_conflict', '內容已有後續變更，請勿覆蓋較新的值。', 409 );
        }
        $payload = [
            'request_hash' => $hash, 'prepared_at' => time(), 'purpose' => sanitize_text_field( mb_substr( $data['purpose'] ?? '核對並套用選取的文章變更', 0, 280 ) ), 'approval_expires' => time() + 30 * MINUTE_IN_SECONDS,
            'post_id' => $post->ID, 'post_type' => $post->post_type, 'post_status' => $post->post_status,
            'values' => $values, 'expected' => $expected, 'before' => $before, 'blocks' => $blocks,
            'provider' => sac_seo_adapter()['source'] ?? null, 'scopes' => $grant['scopes'],
            'restores_operation' => $restore['id'] ?? null,
            'seo_before' => isset( $values['seo'] ) ? array_intersect_key( sac_get_seo_fields( $post->ID ), $values['seo'] ) : [],
        ];
        $encoded = sac_review_json( $payload );
        if ( $encoded === false || strlen( $encoded ) > 2 * sac_review_max_bytes() ) return sac_oauth_error( 'sac_review_too_large', '變更及原值超過安全審核上限，請拆分變更。', 413 );
        sac_oauth_insert( 'changes', $secret, time() + 7 * DAY_IN_SECONDS, $payload, $grant['client'], $grant['id'], $grant['user_id'] );
        $row = sac_oauth_row( 'changes', $secret );
        if ( ! $row ) return sac_oauth_error( 'server_error', '無法儲存待審核的變更。', 500 );
        if ( ! hash_equals( $row['payload']['request_hash'], $hash ) ) return sac_oauth_error( 'sac_idempotency_conflict', '同一識別碼已用於不同變更。', 409 );
    }
    $receipt = sac_change_receipt( $row );
    if ( $receipt['confirmation_required'] ) {
        $receipt['confirmation_url'] = add_query_arg( [ 'action' => 'sac_review_changes', 'request' => $secret ], admin_url( 'admin-post.php' ) );
    }
    return $receipt;
}

function sac_change_grant( $row ) {
    $grant = sac_oauth_row( 'grant', $row['family'], true );
    $user = sac_oauth_valid_grant( $grant );
    if ( ! $user || (int) $grant['client'] !== (int) $row['client'] || (int) $user->ID !== (int) $row['owner']
        || ! sac_oauth_live( sac_oauth_row( 'client', $row['client'], true ) ) ) return null;
    return [ 'id' => (int) $grant['id'], 'client' => (int) $grant['client'], 'user_id' => $user->ID,
        'scopes' => array_values( array_intersect( $row['payload']['scopes'], $grant['payload']['scopes'], sac_oauth_scopes() ) ),
        'content_scope' => $grant['payload']['types'] ];
}

function sac_change_transition( $row, $from, $to, $payload = null ) {
    global $wpdb;
    $table = sac_oauth_table();
    $encoded = $payload === null ? null : sac_review_json( $payload );
    if ( $encoded === false ) return false;
    $sql = "UPDATE $table SET state = %d, touched = %d";
    $args = [ $to, time() ];
    if ( $encoded !== null ) {
        $sql .= ', payload = %s';
        $args[] = $encoded;
    }
    $sql .= ' WHERE id = %d AND state = %d AND expires > %d';
    return $wpdb->query( $wpdb->prepare( $sql, array_merge( $args, [ (int) $row['id'], $from, time() ] ) ) ) === 1;
}

function sac_change_value( $value ) {
    if ( ! is_array( $value ) ) return $value;
    if ( ! array_is_list( $value ) ) ksort( $value );
    return array_map( 'sac_change_value', $value );
}

function sac_change_verification( $actual, $expected ) {
    $result = [];
    foreach ( $expected as $group => $values ) {
        if ( $group === 'faqs' ) {
            $result['faqs'] = ( $actual['faqs'] ?? null ) === $values ? 'verified' : 'mismatch';
            continue;
        }
        foreach ( $values as $field => $value ) {
            $result[ $group . '.' . $field ] = sac_change_value( $actual[ $group ][ $field ] ?? null ) === sac_change_value( $value ) ? 'verified' : 'mismatch';
        }
    }
    return $result;
}

function sac_change_audit( $row, $payload, $success ) {
    sac_oauth_insert( 'audit', sac_oauth_random(), time() + 30 * DAY_IN_SECONDS, [
        'time' => current_time( 'mysql' ), 'tool' => 'apply_content_changes', 'target_type' => $payload['post_type'], 'target_id' => $payload['post_id'],
        'success' => $success, 'failure_reason' => $payload['error_code'] ?? '', 'grant_id' => (int) $row['family'],
        'user_id' => (int) $row['owner'], 'change_set_id' => (int) $row['id'],
    ], $row['client'], $row['family'], $row['owner'] );
}

function sac_change_apply( $row, $request, $selected ) {
    $payload = $row['payload'];
    $lock = sac_post_write_lock( $payload['post_id'] );
    if ( is_wp_error( $lock ) ) return $lock;
    $media_lock = null;
    try {
        if ( in_array( 'media', $selected, true ) && ! empty( $payload['expected']['media']['id'] ) ) {
            $media_lock = sac_post_write_lock( $payload['expected']['media']['id'] );
            if ( is_wp_error( $media_lock ) ) return $media_lock;
        }
        $row = sac_oauth_row( 'changes', (int) $row['id'], true );
        $grant = $row ? sac_change_grant( $row ) : null;
        if ( ! $grant || (int) $row['state'] !== 0 || $row['payload']['approval_expires'] <= time() ) return sac_oauth_error( 'sac_confirmation_expired', '無法核准此變更。請重新載入審核頁。', 409 );
        sac_authenticate( $request, $grant );
        clean_post_cache( $payload['post_id'] );
        $target = sac_change_target( $request, $payload['post_type'], $payload['post_id'], true );
        if ( is_wp_error( $target ) ) return $target;
        $fields = array_fill_keys( $selected, true );
        $values = array_intersect_key( $payload['values'], $fields );
        $reviewed_before = array_intersect_key( $payload['before'], $fields );
        $reviewed_expected = array_intersect_key( $payload['expected'], $fields );
        foreach ( $payload['expected']['content'] ?? [] as $field => $value ) {
            if ( ! isset( $fields[ 'content.' . $field ] ) ) continue;
            $values['content'][ $field ] = $payload['values']['content'][ $field ];
            $reviewed_before['content'][ $field ] = $payload['before']['content'][ $field ];
            $reviewed_expected['content'][ $field ] = $value;
        }
        if ( ! $values ) return sac_content_error( 'changes', '請選擇要核准的變更。' );
        $expected = sac_change_validate( $request, $target, $values, $payload['blocks'] );
        if ( is_wp_error( $expected ) ) return $expected;
        $before = sac_change_snapshot( $target, $expected );
        if ( is_wp_error( $before ) ) return $before;
        if ( $target['post']->post_status !== $payload['post_status'] || sac_change_value( $before ) !== sac_change_value( $reviewed_before )
            || sac_change_value( $expected ) !== sac_change_value( $reviewed_expected )
            || ( isset( $expected['seo'] ) && $payload['provider'] !== ( sac_seo_adapter()['source'] ?? null ) ) ) {
            sac_change_transition( $row, 0, 6 );
            return sac_oauth_error( 'sac_confirmation_version_conflict', '審核資料已過時，請重新準備變更。', 409 );
        }
        $payload['values'] = $values;
        $payload['expected'] = $expected;
        $payload['before'] = $before;
        $payload['approved_hash'] = sac_review_hash( sac_change_value( $expected ) );
        $payload['cache_context'] = sac_change_cache_context( $target['post'] );
        $payload['confirmed_at'] = gmdate( DATE_ATOM );
        if ( ! sac_change_transition( $row, 0, 1, $payload ) ) return sac_oauth_error( 'sac_confirmation_already_used', '此變更已由其他請求處理。', 409 );
        $error = null;
        $attempted = [];
        foreach ( [ 'media', 'content', 'seo', 'faqs' ] as $group ) {
            if ( ! array_key_exists( $group, $expected ) ) continue;
            $attempted[] = $group;
            try {
                if ( $group === 'content' ) $result = sac_content_save( $request, $target, $values['content'], 'apply', $payload['blocks'] );
                elseif ( $group === 'seo' ) $result = sac_store_seo_fields( $payload['post_id'], $expected['seo'], $payload['provider'] );
                elseif ( $group === 'media' ) $result = sac_apply_media_fields( $expected['media']['id'], array_diff_key( $expected['media'], [ 'id' => true ] ) );
                else $result = sac_content_store_faq( $payload['post_id'], $expected['faqs'] );
            } catch ( Throwable $exception ) {
                $result = sac_oauth_error( 'sac_write_failed', 'WordPress 或欄位適配器無法儲存。', 500 );
            }
            if ( is_wp_error( $result ) ) {
                $error = $result->get_error_code();
                break;
            }
        }
        $target['post'] = get_post( $payload['post_id'] );
        $actual = $target['post'] ? sac_change_snapshot( $target, $expected ) : null;
        $payload['verification'] = is_array( $actual ) ? sac_change_verification( $actual, $expected ) : [];
        $error = $error ?: ( count( $payload['verification'] ) && ! in_array( 'mismatch', $payload['verification'], true ) ? null : 'sac_verification_failed' );
        if ( $error && $target['post'] ) {
            $payload['recovery'] = sac_change_recover( $request, $target, $payload, $attempted );
        }
        $payload['error_code'] = $error;
        $payload['completed_at'] = gmdate( DATE_ATOM );
        if ( ! sac_change_transition( $row, 1, $error ? 3 : 2, $payload ) ) {
            return sac_oauth_error( 'sac_operation_receipt_failed', '寫入可能已完成，但結果無法記錄。請先核對 WordPress 內容。', 500 );
        }
        if ( ! $error && $target['post'] ) {
            $payload['cache'] = sac_change_purge( $target['post'], $payload['cache_context'] );
            sac_oauth_change( $row['id'], [ 'payload' => sac_review_json( $payload ) ] );
        }
        sac_change_audit( $row, $payload, ! $error );
        sac_invalidate_cache();
        return sac_change_receipt( sac_oauth_row( 'changes', (int) $row['id'], true ) );
    } finally {
        if ( is_string( $media_lock ) ) sac_post_write_unlock( $media_lock );
        sac_post_write_unlock( $lock );
    }
}

function sac_change_recover( $request, $target, $payload, $attempted ) {
    $current = sac_change_snapshot( $target, $payload['expected'] );
    if ( is_wp_error( $current ) ) return 'requires_manual_check';
    foreach ( array_reverse( $attempted ) as $group ) {
        $before = $payload['before'][ $group ];
        $after = $payload['expected'][ $group ];
        $actual = $current[ $group ] ?? null;
        if ( $actual === $before ) continue;
        try {
            if ( $group === 'faqs' ) {
                if ( $actual === $after ) sac_content_store_faq( $payload['post_id'], $before );
                continue;
            }
            if ( ! is_array( $actual ) ) continue;
            $restore = [];
            foreach ( $after as $field => $value ) {
                if ( sac_change_value( $actual[ $field ] ?? null ) === sac_change_value( $value )
                    && sac_change_value( $before[ $field ] ) !== sac_change_value( $value ) ) $restore[ $field ] = $before[ $field ];
            }
            if ( ! $restore ) continue;
            if ( $group === 'seo' && ( sac_seo_adapter()['source'] ?? null ) === $payload['provider'] ) {
                sac_store_seo_fields( $payload['post_id'], $restore, $payload['provider'] );
            } elseif ( $group === 'media' ) {
                sac_apply_media_fields( $before['id'], $restore );
            } elseif ( $group === 'content' ) {
                sac_content_save( $request, $target, $restore, 'apply', $payload['blocks'] );
            }
        } catch ( Throwable $exception ) {
            // The final reread reports fields still requiring attention.
        }
    }
    $target['post'] = get_post( $payload['post_id'] );
    $after = $target['post'] ? sac_change_snapshot( $target, $payload['expected'] ) : null;
    return is_array( $after ) && sac_change_value( $after ) === sac_change_value( $payload['before'] ) ? 'restored' : 'requires_manual_check';
}

function sac_change_review_page() {
    $secret = sac_review_page_start();
    $row = sac_review_lookup( 'changes', $secret );
    if ( ! $row || ! sac_change_grant( $row ) ) wp_die( '此審核不屬於目前帳號或授權已失效。', '', [ 'response' => 403 ] );
    $payload = $row['payload'];
    $request = new WP_REST_Request( 'POST', '/' . SAC_NAMESPACE . '/content/' . $payload['post_type'] . '/' . $payload['post_id'] . '/changes' );
    sac_authenticate( $request, sac_change_grant( $row ) );
    $target = sac_change_target( $request, $payload['post_type'], $payload['post_id'], true );
    if ( is_wp_error( $target ) ) wp_die( esc_html( $target->get_error_message() ), '', [ 'response' => 403 ] );
    $receipt = sac_change_receipt( $row );
    if ( ! $receipt['confirmation_required'] ) wp_die( esc_html( '操作 #' . $receipt['operation_id'] . ' · ' . $receipt['approval_status'] ), 'AI 連接器', [ 'response' => 200 ] );
    if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) === 'POST' ) {
        check_admin_referer( 'sac_review_changes_' . $secret );
        if ( ( $_POST['decision'] ?? '' ) === 'deny' ) {
            $denied = sac_change_transition( $row, 0, 4, array_diff_key( $payload, array_flip( [ 'before', 'values' ] ) ) );
            wp_die( $denied ? '已拒絕此變更。' : '變更已由其他請求處理。', 'AI 連接器', [ 'response' => $denied ? 200 : 409 ] );
        }
        if ( ( $_POST['decision'] ?? '' ) === 'revise' ) {
            $saved = sac_change_transition( $row, 0, 7 );
            wp_die( $saved ? '已要求重新調整。請在 AI 對話準備新的變更。' : '變更已由其他請求處理。', 'AI 連接器', [ 'response' => $saved ? 200 : 409 ] );
        }
        if ( ( $_POST['decision'] ?? '' ) !== 'approve' ) wp_die( '請選擇核准或拒絕。', '', [ 'response' => 400 ] );
        $allowed = array_diff( array_keys( $payload['expected'] ), [ 'content' ] );
        foreach ( array_keys( $payload['expected']['content'] ?? [] ) as $field ) $allowed[] = 'content.' . $field;
        $groups = array_intersect( $allowed, array_filter( (array) wp_unslash( $_POST['groups'] ?? [] ), 'is_string' ) );
        $result = sac_change_apply( $row, $request, $groups );
        if ( is_wp_error( $result ) ) wp_die( esc_html( $result->get_error_message() ), 'AI 連接器', [ 'response' => 409 ] );
        wp_die( esc_html( '操作 #' . $result['operation_id'] . ( $result['approval_status'] === 'applied' ? ' 已儲存並核對。' : ' 發生錯誤，請核對操作紀錄。' ) ),
            'AI 連接器', [ 'response' => 200 ] );
    }
    $preview = sac_review_overview( $row, $target ) . '<form method="post">';
    $preview .= wp_nonce_field( 'sac_review_changes_' . $secret, '_wpnonce', true, false );
    $labels = [ 'content' => '文章資料、正文、分類與精選圖片', 'seo' => 'SEO 與社群分享', 'faqs' => 'FAQ', 'media' => '媒體庫圖片' ];
    foreach ( $payload['expected'] as $group => $values ) {
        $preview .= '<fieldset><legend>' . esc_html( $labels[ $group ] ) . '</legend>';
        if ( $group !== 'content' ) $preview .= '<label><input type="checkbox" name="groups[]" value="' . esc_attr( $group ) . '" checked> 核准此組變更</label>';
        if ( $group === 'faqs' ) {
            $preview .= sac_review_render_value( $payload['before']['faqs'], $values );
        } else {
            foreach ( $values as $field => $value ) {
                if ( $group === 'content' ) $preview .= '<label><input type="checkbox" name="groups[]" value="' . esc_attr( 'content.' . $field ) . '" checked> ' . esc_html( '核准欄位 ' . $field ) . '</label>';
                $preview .= '<details><summary>' . esc_html( $field ) . '</summary>' . sac_review_render_value( $payload['before'][ $group ][ $field ] ?? null, $value ) . '</details>';
            }
        }
        $preview .= '</fieldset>';
    }
    $preview .= '<p><button class="button" name="decision" value="deny">拒絕變更</button> <button class="button" name="decision" value="revise">要求重新調整</button> <button class="button button-primary" name="decision" value="approve">核准並套用</button></p></form></section>';
    wp_die( $preview, '審核 AI 文章變更', [ 'response' => 200 ] );
}
add_action( 'admin_post_sac_review_changes', 'sac_change_review_page' );
add_action( 'admin_post_nopriv_sac_review_changes', 'sac_change_review_page' );

function sac_handle_change_tool( $request, $name ) {
    $path = $request->get_url_params();
    $grant = sac_authenticate( $request );
    if ( is_wp_error( $grant ) ) return $grant;
    if ( isset( $path['id'] ) ) {
        $target = sac_change_target( $request, $path['post_type'], (int) $path['id'], $name === 'prepare_content_changes' );
        if ( is_wp_error( $target ) ) return $target;
        if ( $name === 'list_content_blocks' ) return sac_list_blocks( $target['post'], $request->get_param( 'block_path' ), max( 0, (int) $request->get_param( 'offset' ) ) );
        if ( $name === 'prepare_content_changes' ) return sac_change_prepare( $request, $target, $request->get_json_params() ?: [] );
        global $wpdb;
        $table = sac_oauth_table();
        $before = (int) $request->get_param( 'before' );
        $results = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM $table WHERE kind = 'changes' AND family = %d AND owner = %d AND expires > %d AND id < %d ORDER BY id DESC LIMIT 100",
            $grant['id'], $grant['user_id'], time(), $before > 0 ? $before : PHP_INT_MAX
        ) );
        $receipts = [];
        foreach ( $results as $id ) {
            $row = sac_oauth_row( 'changes', (int) $id, true );
            if ( $row && (int) $row['payload']['post_id'] === (int) $path['id'] ) $receipts[] = sac_change_receipt( $row );
        }
        return [ 'operations' => $receipts, 'next_before' => count( $results ) === 100 ? (int) end( $results ) : null ];
    }
    $row = sac_oauth_row( 'changes', (int) $path['operation'], true );
    if ( ! $row || (int) $row['family'] !== $grant['id'] || (int) $row['client'] !== $grant['client'] || (int) $row['owner'] !== $grant['user_id'] || (int) $row['expires'] <= time() ) {
        return sac_oauth_error( 'sac_change_not_found', '找不到此授權的變更紀錄。', 404 );
    }
    $payload = $row['payload'];
    $target = sac_change_target( $request, $payload['post_type'], $payload['post_id'], in_array( $name, [ 'cancel_content_changes', 'rollback_content_changes', 'retry_content_cache_purge' ], true ) );
    if ( is_wp_error( $target ) ) return $target;
    if ( $name === 'retry_content_cache_purge' ) {
        if ( (int) $row['state'] !== 2 ) return sac_oauth_error( 'sac_change_not_applied', '只可重試已儲存的變更。', 409 );
        if ( ! sac_oauth_rate( 'retry_purge', 10, HOUR_IN_SECONDS, (string) $grant['id'] ) ) return sac_oauth_error( 'sac_rate_limit', '請稍後再清除快取。', 429 );
        $lock = sac_post_write_lock( $payload['post_id'] );
        if ( is_wp_error( $lock ) ) return $lock;
        try {
            $row = sac_oauth_row( 'changes', (int) $row['id'], true );
            if ( ! $row || ! sac_change_grant( $row ) ) return sac_oauth_error( 'sac_change_not_found', '變更授權已失效。', 404 );
            $row['payload']['cache'] = sac_change_purge( $target['post'], $row['payload']['cache_context'] ?? [] );
            if ( sac_oauth_change( $row['id'], [ 'payload' => sac_review_json( $row['payload'] ) ] ) === false ) {
                return sac_oauth_error( 'sac_cache_receipt_failed', '無法記錄快取結果。請重新核對前台。', 500 );
            }
            return sac_change_receipt( $row );
        } finally {
            sac_post_write_unlock( $lock );
        }
    }
    if ( $name === 'rollback_content_changes' ) {
        if ( (int) $row['state'] !== 2 ) return sac_oauth_error( 'sac_rollback_unavailable', '只有已完成核對的變更可準備還原。', 409 );
        $input = [ 'idempotency_key' => $request->get_param( 'idempotency_key' ) ];
        if ( isset( $payload['before']['content'] ) ) $input['content'] = $payload['before']['content'];
        if ( isset( $payload['before']['faqs'] ) ) $input['faqs'] = $payload['before']['faqs'];
        if ( isset( $payload['before']['media'] ) ) $input['media'] = $payload['before']['media'];
        if ( isset( $payload['before']['seo'] ) ) {
            if ( ( sac_seo_adapter()['source'] ?? null ) !== $payload['provider'] || empty( $payload['seo_before'] ) ) {
                return sac_oauth_error( 'sac_rollback_unavailable', 'SEO 欄位適配器已變更或缺少原始資料。', 409 );
            }
            $input['seo'] = $payload['seo_before'];
        }
        return sac_change_prepare( $request, $target, $input, [
            'id' => (int) $row['id'], 'blocks' => $payload['blocks'], 'before' => $payload['before'], 'after' => $payload['expected'],
        ] );
    }
    if ( $name === 'cancel_content_changes' ) {
        if ( ! sac_change_transition( $row, 0, 5, array_diff_key( $payload, array_flip( [ 'before', 'values' ] ) ) ) ) {
            return sac_oauth_error( 'sac_change_not_pending', '只有待審核的項目可以取消。', 409 );
        }
        $row['state'] = 5;
    }
    $receipt = sac_change_receipt( $row );
    if ( $name === 'verify_content_changes' && $receipt['approval_status'] === 'applied' ) {
        $expected = sac_change_validate( $request, $target, $payload['values'], $payload['blocks'] );
        if ( is_wp_error( $expected ) ) return $expected;
        $actual = sac_change_snapshot( $target, $payload['expected'] );
        if ( is_wp_error( $actual ) ) return $actual;
        $receipt['verification'] = sac_change_verification( $actual, $payload['expected'] );
        $db = in_array( 'mismatch', $receipt['verification'], true ) ? 'mismatch' : 'verified';
        $receipt['database_verification'] = [ 'state' => $db, 'items' => $receipt['verification'], 'checked_at' => gmdate( DATE_ATOM ) ];
        $receipt['verification_status'] = $db === 'verified' ? 'pending' : 'mismatch';
        foreach ( [ 'frontend', 'structured_data', 'media', 'sitemap' ] as $section ) $receipt[ $section . '_verification' ] = [ 'state' => 'pending' ];
        if ( rest_sanitize_boolean( $request->get_param( 'frontend' ) ) ) {
            if ( is_wp_error( sac_oauth_require_scope( $grant, 'seo:read' ) ) ) {
                foreach ( [ 'frontend', 'structured_data', 'media', 'sitemap' ] as $section ) $receipt[ $section . '_verification' ] = [ 'state' => 'unavailable', 'reason' => 'permission_denied' ];
            } elseif ( ! sac_oauth_rate( 'verify_public', 30, HOUR_IN_SECONDS, (string) $grant['id'] ) ) {
                return sac_oauth_error( 'sac_rate_limit', '公開驗收過於頻繁，請稍後再試。', 429 );
            } else {
                $receipt = array_replace( $receipt, sac_verify_public_change( $target['post'], $payload ) );
                $sitemap = sac_public_sitemap( $target['post'], true );
                $sitemap_state = $sitemap['state'] ?? 'unavailable';
                if ( $sitemap_state === 'verified' && empty( $sitemap['found'] ) ) {
                    $sitemap_state = ! empty( $payload['values']['seo']['noindex'] ) ? 'not_applicable' : 'mismatch';
                }
                $receipt['sitemap_verification'] = array_replace( $sitemap, [ 'state' => $sitemap_state ] );
            }
            $receipt['frontend'] = $receipt['frontend_verification'];
            $states = array_map( static fn( $key ) => $receipt[ $key . '_verification' ]['state'], [ 'frontend', 'structured_data', 'media', 'sitemap' ] );
            if ( $db === 'verified' ) {
                $receipt['verification_status'] = in_array( 'stale_cache', $states, true ) ? 'stale_cache' :
                    ( array_diff( $states, [ 'verified', 'not_applicable' ] ) || ( $receipt['cache_verification']['cache_purge_status'] ?? '' ) === 'failed' ? 'partial' : 'verified' );
            }
            $lock = sac_post_write_lock( $payload['post_id'] );
            if ( is_string( $lock ) ) {
                try {
                    $latest = sac_oauth_row( 'changes', (int) $row['id'], true );
                    if ( $latest && (int) $latest['state'] === 2 ) {
                        $latest['payload']['last_public_verification'] = [
                            'state' => $receipt['verification_status'], 'frontend' => $receipt['frontend_verification']['state'],
                            'sitemap' => $receipt['sitemap_verification']['state'], 'checked_at' => gmdate( DATE_ATOM ),
                        ];
                        sac_oauth_change( $row['id'], [ 'payload' => sac_review_json( $latest['payload'] ) ] );
                    }
                } finally {
                    sac_post_write_unlock( $lock );
                }
            }
        }
    }
    return $receipt;
}

add_action( 'rest_api_init', static function () {
    foreach ( sac_changes_definitions() as $tool ) {
        $route = strtr( $tool['path'], [ '{post_type}' => '(?P<post_type>[a-z0-9_-]+)', '{id}' => '(?P<id>\d+)', '{operation}' => '(?P<operation>\d+)' ] );
        register_rest_route( SAC_NAMESPACE, $route, [
            'methods' => $tool['method'], 'permission_callback' => 'sac_permission_read',
            'callback' => static fn( $request ) => sac_handle_change_tool( $request, $tool['name'] ),
        ] );
    }
} );
