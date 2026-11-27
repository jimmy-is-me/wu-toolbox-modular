<?php
defined( 'ABSPATH' ) || exit;

/** Index original byte ranges so edits never reserialize untouched third-party blocks. */
function sac_block_index( $content ) {
    if ( strlen( $content ) > sac_review_max_bytes() ) return sac_oauth_error( 'sac_blocks_too_large', '正文超過區塊操作上限。', 413 );
    $parser = new WP_Block_Parser();
    $parser->document = $content;
    $parser->offset = 0;
    $stack = [];
    $items = [];
    $counts = [ '' => 0 ];
    while ( true ) {
        [ $kind, $name, $attributes, $start, $length ] = $parser->next_token();
        if ( $kind === 'no-more-tokens' ) break;
        $end = $start + $length;
        $parser->offset = $end;
        if ( $kind === 'block-closer' ) {
            $path = array_pop( $stack );
            if ( $path === null || $items[ $path ]['name'] !== $name ) return sac_content_error( 'blocks', '原始區塊結構未正確閉合。' );
            $items[ $path ]['end'] = $end;
            continue;
        }
        $parent = $stack ? end( $stack ) : '';
        $index = $counts[ $parent ] ?? 0;
        $counts[ $parent ] = $index + 1;
        $path = $parent === '' ? (string) $index : $parent . '.' . $index;
        $items[ $path ] = [ 'path' => $path, 'name' => $name, 'attributes' => $attributes, 'start' => $start, 'end' => $end ];
        if ( count( $items ) > 4000 || count( $stack ) >= 40 ) return sac_content_error( 'blocks', '區塊數量或巢狀層數超過上限。' );
        if ( $kind === 'block-opener' ) $stack[] = $path;
    }
    return $stack ? sac_content_error( 'blocks', '原始區塊結構未正確閉合。' ) : $items;
}

function sac_block_match( $items, $content, $path, $hash ) {
    if ( ! is_string( $path ) || ! is_string( $hash ) || ! isset( $items[ $path ] ) ) return null;
    $item = $items[ $path ];
    return hash_equals( hash( 'sha256', substr( $content, $item['start'], $item['end'] - $item['start'] ) ), $hash ) ? $item : null;
}

function sac_apply_block_edits( $content, $version, $edits ) {
    if ( ! is_string( $version ) || ! hash_equals( hash( 'sha256', $content ), $version ) ) {
        return sac_oauth_error( 'sac_block_version_conflict', '正文已變更。請重新列出區塊並準備變更。', 409 );
    }
    if ( ! is_array( $edits ) || ! $edits || count( $edits ) > 40 ) return sac_content_error( 'block_edits', '請提供 1 至 40 個區塊操作。' );
    $items = sac_block_index( $content );
    if ( is_wp_error( $items ) ) return $items;
    $writes = [];
    foreach ( $edits as $edit ) {
        if ( ! is_array( $edit ) ) return sac_content_error( 'block_edits', '每個操作須提供物件。' );
        $operation = $edit['operation'] ?? '';
        if ( ! in_array( $operation, [ 'insert_before', 'insert_after', 'prepend', 'append', 'replace', 'delete', 'move_before', 'move_after' ], true ) ) {
            return sac_content_error( 'operation', '區塊操作不受支援。' );
        }
        $start = 0;
        $end = 0;
        if ( $operation === 'append' ) {
            $start = $end = strlen( $content );
        } elseif ( $operation !== 'prepend' ) {
            $item = sac_block_match( $items, $content, $edit['path'] ?? null, $edit['hash'] ?? null );
            if ( ! $item ) return sac_oauth_error( 'sac_block_version_conflict', '找不到相符的區塊路徑或雜湊。', 409 );
            $start = $item['start'];
            $end = $item['end'];
            if ( $operation === 'insert_before' ) $end = $start;
            if ( $operation === 'insert_after' ) $start = $end;
        }
        $fragment = '';
        if ( str_starts_with( $operation, 'move_' ) ) {
            $target = sac_block_match( $items, $content, $edit['target_path'] ?? null, $edit['target_hash'] ?? null );
            if ( ! $target || ( $target['start'] < $end && $target['end'] > $start ) ) return sac_content_error( 'target_path', '移動目標不存在或與來源重疊。' );
            $fragment = substr( $content, $start, $end - $start );
            $position = $operation === 'move_before' ? $target['start'] : $target['end'];
            $writes[] = [ $position, $position, $fragment ];
            $fragment = '';
        } elseif ( $operation !== 'delete' ) {
            if ( ! is_string( $edit['markup'] ?? null ) ) return sac_content_error( 'markup', '請提供 Gutenberg 區塊標記。' );
            $fragment = wp_kses_post( $edit['markup'] );
            $blocks = sac_block_index( $fragment );
            if ( is_wp_error( $blocks ) || ! $blocks ) return sac_content_error( 'markup', '區塊標記無效。' );
            foreach ( parse_blocks( $fragment ) as $block ) {
                if ( $block['blockName'] === null && trim( $block['innerHTML'] ) !== '' ) return sac_content_error( 'markup', '新增內容須包含在 Gutenberg 區塊內。' );
            }
        }
        $writes[] = [ $start, $end, $fragment ];
    }
    usort( $writes, static fn( $a, $b ) => [ $a[0], $a[1] ] <=> [ $b[0], $b[1] ] );
    $output = '';
    $offset = 0;
    foreach ( $writes as [ $start, $end, $fragment ] ) {
        if ( $start < $offset ) return sac_content_error( 'block_edits', '區塊操作彼此重疊，請分開準備。' );
        $output .= substr( $content, $offset, $start - $offset ) . $fragment;
        $offset = $end;
    }
    $output .= substr( $content, $offset );
    $index = sac_block_index( $output );
    return is_wp_error( $index ) ? $index : $output;
}

function sac_list_blocks( $post, $path = null, $offset = 0 ) {
    $content = $post->post_content;
    $items = sac_block_index( $content );
    if ( is_wp_error( $items ) ) return $items;
    if ( $path !== null && ( ! is_string( $path ) || ! isset( $items[ $path ] ) ) ) return sac_oauth_error( 'sac_block_not_found', '找不到指定區塊。', 404 );
    $result = [];
    foreach ( $path !== null ? [ $items[ $path ] ] : array_slice( $items, $offset, 100 ) as $item ) {
        $markup = substr( $content, $item['start'], $item['end'] - $item['start'] );
        $block = [
            'path' => $item['path'], 'name' => $item['name'], 'hash' => hash( 'sha256', $markup ),
            'heading_level' => $item['name'] === 'core/heading' ? (int) ( $item['attributes']['level'] ?? 2 ) : null,
        ];
        if ( $path !== null ) $block['markup'] = $markup;
        $result[] = $block;
    }
    return [ 'version' => hash( 'sha256', $content ), 'blocks' => $result, 'total' => count( $items ) ];
}
