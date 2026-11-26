<?php
defined( 'ABSPATH' ) || exit;

/** Site-local storage for clients, grants, and hashed single-use credentials. */
function sac_oauth_table() {
    global $wpdb;
    return $wpdb->prefix . 'sac_oauth';
}

function sac_oauth_install() {
    global $wpdb;
    $table = sac_oauth_table();
    if ( get_option( 'sac_oauth_schema' ) === '1' ) return true;
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta( "CREATE TABLE $table (
        id bigint unsigned NOT NULL AUTO_INCREMENT,
        kind varchar(16) NOT NULL,
        digest char(64) NOT NULL,
        client bigint unsigned NOT NULL DEFAULT 0,
        family bigint unsigned NOT NULL DEFAULT 0,
        owner bigint unsigned NOT NULL DEFAULT 0,
        expires bigint unsigned NOT NULL,
        state int unsigned NOT NULL DEFAULT 0,
        touched bigint unsigned NOT NULL,
        payload longtext NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY digest (digest),
        KEY expiry (expires),
        KEY kind_client (kind,client),
        KEY family_kind (family,kind)
    ) ENGINE=InnoDB " . $wpdb->get_charset_collate() . ';' );
    $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name = %s', $table ) );
    if ( ! $status || strcasecmp( $status->Engine, 'InnoDB' ) !== 0 ) return false;
    update_option( 'sac_oauth_schema', '1', false );
    // These credentials belong exclusively to the old connector.
    foreach ( [ 'sac_api_keys', 'sac_upload_tokens', 'sac_platform_verification' ] as $option ) delete_option( $option );
    wp_clear_scheduled_hook( 'sac_cleanup_tokens' );
    if ( ! wp_next_scheduled( 'sac_oauth_cleanup' ) ) wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'sac_oauth_cleanup' );
    return true;
}

add_action( 'sac_oauth_cleanup', static function () {
    global $wpdb;
    $table = sac_oauth_table();
    $wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE expires < %d LIMIT 5000", time() ) );
} );

function sac_oauth_random() {
    return bin2hex( random_bytes( 32 ) );
}

function sac_oauth_insert( $kind, $secret, $expires, $payload = [], $client = 0, $family = 0, $owner = 0 ) {
    global $wpdb;
    $saved = $wpdb->insert( sac_oauth_table(), [
        'kind' => $kind, 'digest' => hash( 'sha256', $secret ), 'expires' => $expires,
        'payload' => wp_json_encode( $payload ), 'client' => $client, 'family' => $family,
        'owner' => $owner, 'touched' => time(),
    ], [ '%s', '%s', '%d', '%s', '%d', '%d', '%d', '%d' ] );
    return $saved === 1 ? (int) $wpdb->insert_id : 0;
}

function sac_oauth_row( $kind, $value, $by_id = false, $lock = false ) {
    global $wpdb;
    $table = sac_oauth_table();
    $where = $by_id ? 'id = %d' : 'digest = %s';
    $query = "SELECT * FROM $table WHERE kind = %s AND $where";
    if ( $lock ) $query .= ' FOR UPDATE';
    $row = $wpdb->get_row( $wpdb->prepare( $query, $kind, $by_id ? $value : hash( 'sha256', $value ) ), ARRAY_A );
    if ( $row ) $row['payload'] = json_decode( $row['payload'], true ) ?: [];
    return $row;
}

function sac_oauth_live( $row ) {
    return $row && (int) $row['state'] === 0 && (int) $row['expires'] > time();
}

function sac_oauth_change( $id, $values ) {
    global $wpdb;
    return $wpdb->update( sac_oauth_table(), $values, [ 'id' => (int) $id ] );
}

function sac_oauth_rate( $endpoint, $max, $window, $subject = '' ) {
    global $wpdb;
    $table = sac_oauth_table();
    $digest = hash_hmac( 'sha256', $endpoint . ':' . intdiv( time(), $window ) . ':' . $subject, wp_salt( 'nonce' ) );
    $saved = $wpdb->query( $wpdb->prepare(
        "INSERT INTO $table (kind,digest,expires,state,touched,payload) VALUES ('rate',%s,%d,1,%d,'{}')
        ON DUPLICATE KEY UPDATE state = state + 1",
        $digest, time() + $window, time()
    ) );
    return $saved !== false && (int) $wpdb->get_var( $wpdb->prepare( "SELECT state FROM $table WHERE digest = %s", $digest ) ) <= $max;
}

function sac_oauth_revoke( $kind, $id = 0 ) {
    global $wpdb;
    $table = sac_oauth_table();
    if ( $kind === 'client' ) {
        // Every token also checks the parent client's state, including grants created concurrently.
        return $wpdb->query( $wpdb->prepare( "UPDATE $table SET state = 1 WHERE (kind = 'client' AND id = %d) OR (kind = 'grant' AND client = %d)", $id, $id ) );
    }
    if ( $id ) return $wpdb->query( $wpdb->prepare( "UPDATE $table SET state = 1 WHERE kind = 'grant' AND id = %d", $id ) );
    return $wpdb->query( "UPDATE $table SET state = 1 WHERE kind = 'grant' AND state = 0" );
}
