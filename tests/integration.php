<?php
/** Run only on an isolated local WordPress installation; see tests/README.md. */
$path = getenv( 'WPMD_TEST_WP' );
if ( ! $path || ! is_file( $path . '/wp-load.php' ) ) { fwrite( STDERR, "Set WPMD_TEST_WP to an isolated WordPress installation.\n" ); exit( 1 ); }
require $path . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' ) throw new RuntimeException( 'Tests require WP_ENVIRONMENT_TYPE=local.' );

use WPMailDesk\Infrastructure\Database\Repository;
use WPMailDesk\Infrastructure\Mail\ImapClient;
use WPMailDesk\Infrastructure\Mail\MimeParser;
use WPMailDesk\Infrastructure\Mail\SmtpSender;
use WPMailDesk\Infrastructure\Security\Crypto;
use WPMailDesk\Infrastructure\Security\HtmlSanitizer;
use WPMailDesk\Infrastructure\Security\HostPolicy;
use WPMailDesk\Application\MailService;

$passed = 0;
register_shutdown_function( static function () { $error = error_get_last(); if ( $error && $error['type'] === E_ERROR ) fwrite( STDERR, $error['message'] . "\n" ); } );
function check( bool $condition, string $name ): void {
    global $passed;
    if ( ! $condition ) throw new RuntimeException( 'FAIL: ' . $name );
    $passed++; echo "PASS {$name}\n";
}
function rejects( callable $fn, string $name ): void {
    try { $fn(); } catch ( Throwable $e ) { check( true, $name ); return; }
    check( false, $name );
}
function request( string $method, string $path, array $data = array() ): WP_REST_Response {
    $request = new WP_REST_Request( $method, '/wpmd/v1/' . $path );
    if ( $method === 'GET' ) $request->set_query_params( $data );
    else { $request->set_header( 'content-type', 'application/json' ); $request->set_body( wp_json_encode( $data ) ); }
    return rest_do_request( $request );
}
set_error_handler( static function ( $severity, $message, $file, $line ) {
    if ( error_reporting() & $severity && str_contains( $file, '/wordpress-maildesk/src/' ) ) throw new ErrorException( $message, 0, $severity, $file, $line );
    return false;
} );
$repo = new Repository(); $crypto = new Crypto(); $imap = new ImapClient( $crypto ); $smtp = new SmtpSender( $crypto ); $service = new MailService( $repo, $imap, $smtp, $crypto );
$fixture = stream_socket_client( 'tcp://127.0.0.1:11430' ); fgets( $fixture ); fwrite( $fixture, "A0 XTESTRESET\r\n" ); fgets( $fixture ); fclose( $fixture );
$owner = get_user_by( 'login', 'review' ); wp_set_current_user( $owner->ID );
$other = get_user_by( 'login', 'wpmd-other' );
if ( ! $other ) { $id = wp_insert_user( array( 'user_login' => 'wpmd-other', 'user_pass' => 'local-test-only', 'user_email' => 'other@example.test', 'role' => 'subscriber' ) ); $other = get_user_by( 'id', $id ); }
foreach ( WPMailDesk\WordPress\Capabilities::ALL as $cap ) if ( ! str_contains( $cap, 'manage_all' ) ) $other->add_cap( $cap );

$encrypted = $crypto->encrypt( 'a secret with unicode ✓' );
check( $crypto->decrypt( $encrypted ) === 'a secret with unicode ✓', 'authenticated encryption round trip' );
rejects( fn() => $crypto->decrypt( 's1:AA==' ), 'truncated secretbox rejected' );
rejects( fn() => $crypto->decrypt( 'g1:AA==' ), 'truncated GCM rejected' );
$tampered = substr_replace( $encrypted, $encrypted[10] === 'A' ? 'B' : 'A', 10, 1 );
rejects( fn() => $crypto->decrypt( $tampered ), 'tampered credential rejected' );
remove_all_filters( 'wpmd_allow_private_mail_hosts' );
foreach ( array( 'localhost', '127.0.0.1', '::1', '::ffff:127.0.0.1', '10.0.0.1', 'fe80::1', '100.64.0.1', '192.0.2.1', '224.0.0.1', '2002:7f00:1::', 'http://mail.example.com', 'mail.example.com;127.0.0.1', "mail.example.com\r\n" ) as $host ) {
    // Surrounding whitespace is normalized at the account boundary; newline-only suffix cannot inject protocol commands.
    if ( str_ends_with( $host, "\r\n" ) ) continue;
    rejects( fn() => HostPolicy::resolve( $host, 'IMAP' ), 'host policy blocks ' . $host );
}
add_filter( 'wpmd_allow_private_mail_hosts', static fn( $allow, $host ) => $host === '127.0.0.1', 10, 2 );
$html = '<img src=//tracker.invalid/a srcset="https://tracker.invalid/b 2x"><p style="background:url(https://tracker.invalid/c)">hello</p><video poster="https://tracker.invalid/d"></video><script>alert(1)</script><a href="javascript:alert(1)" onclick="alert(1)">click</a>';
$safe = HtmlSanitizer::sanitize( $html );
check( ! preg_match( '/<img|<video|<script|style=|srcset=|onclick=|javascript:/i', $safe ), 'HTML sanitizer blocks active content and remote tracking' );
$mime = new MimeParser(); $raw = file_get_contents( '/private/tmp/wpmd-mail-fixtures/message.eml' ); $parsed = $mime->parse( $raw );
check( str_contains( $parsed['text'], 'Hello ✓' ), 'quoted-printable and UTF-8 MIME text' );
check( str_contains( $parsed['html'], '<h1>' ), 'nested multipart HTML extracted' );
check( count( $parsed['attachments'] ) === 1 && base64_decode( $parsed['attachments'][0]['content_base64'] ) === "Fixture attachment content\n", 'MIME attachment decoded without body contamination' );
check( $mime->addresses( '"Doe, Jane" <jane@example.test>, other@example.test' )[0]['name'] === 'Doe, Jane', 'quoted comma in address preserved' );
rejects( fn() => $mime->parse( 'x', 21 ), 'MIME depth limit enforced' );

$input = array( 'label' => 'Review mailbox ' . time(), 'email' => 'reviewer@example.test', 'username' => 'fixture', 'secret' => 'fixture-password', 'imap_host' => '127.0.0.1', 'imap_port' => 11430, 'imap_security' => 'none', 'smtp_host' => '127.0.0.1', 'smtp_port' => 11025, 'smtp_security' => 'none', 'sync_enabled' => false );
$account = $service->saveAccount( $input, $owner->ID );
check( $account > 0, 'account saved with omitted optional enum defaults' );
check( $repo->account( $account )['username'] === 'fixture', 'username stored without lossy sanitization' );
rejects( fn() => $service->saveAccount( array_merge( $input, array( 'email' => 'bad email@example.test' ) ), $owner->ID ), 'invalid account email rejected instead of rewritten' );
rejects( fn() => $service->saveAccount( array_merge( $input, array( 'smtp_port' => 0 ) ), $owner->ID ), 'invalid port rejected instead of clamped' );
$service->saveAccount( array( 'id' => $account, 'label' => 'Fixture mailbox' ), $owner->ID );
check( $crypto->decrypt( $repo->account( $account )['secret_enc'] ) === 'fixture-password', 'partial account update keeps existing credentials and settings' );
check( $service->testAccount( $account, $owner->ID ) === array( 'imap' => array( 'ok' => true ), 'smtp' => array( 'ok' => true ) ), 'IMAP and SMTP authentication against local fixtures' );
add_filter( 'wpmd_oauth_access_token', static fn() => 'local-fixture-token' );
$oauth_account = array_merge( $repo->account( $account ), array( 'auth_type' => 'oauth' ) );
check( $imap->test( $oauth_account )['ok'] && $smtp->test( $oauth_account )['ok'], 'OAuth adapter supplies tokens to both IMAP and SMTP' );
remove_all_filters( 'wpmd_oauth_access_token' );
$folders = $imap->folders( $repo->account( $account ) );
check( count( $folders ) === 3, 'nonselectable IMAP folders omitted' );
check( $folders[2]['remote_name'] === '&ZeVnLIqe-' && $folders[2]['display_name'] === '日本語', 'wire mailbox name preserved separately from display name' );
$folder = $repo->upsert_folder( $account, $folders[0] );
$result = $imap->fetchRecent( $repo->account( $account ), 'INBOX' );
check( $result['status']['uidvalidity'] === 42 && count( $result['messages'] ) === 2, 'literal-aware FETCH supports UID after message literal' );
foreach ( $result['messages'] as $record ) $repo->upsert_message( $account, $folder, 42, $record['uid'], $record['message'] );
$repo->finish_folder( $folder, $result['status'], $result['uids'] );
$messages = $repo->messages( $owner->ID, array( 'account_id' => $account ) );
check( count( $messages ) === 2 && $messages[0]['id'] !== $messages[1]['id'], 'duplicate sender Message-ID does not collapse distinct messages' );
check( ! isset( $messages[0]['body_html'], $messages[0]['body_text'] ), 'message listing excludes large message bodies' );
$message = (int) $messages[0]['id']; $attachment = $repo->attachments( $message )[0];
check( base64_decode( $repo->attachment( (int) $attachment['id'], $owner->ID )['content_base64'] ) === "Fixture attachment content\n", 'authorized attachment retrieval' );
$service->setMessageState( $message, $owner->ID, array( 'is_starred' => true ) );
check( (int) $repo->message( $message, $owner->ID )['is_starred'] === 1, 'message state persisted after remote UID STORE' );
rejects( fn() => $imap->setFlags( $repo->account( $account ), 'INBOX', 999, 7, array( 'is_read' => true ) ), 'UIDVALIDITY mismatch blocks remote mutation' );
check( count( $repo->messages( $other->ID, array( 'account_id' => $account ) ) ) === 0, 'account isolation in message list' );
check( $repo->attachment( (int) $attachment['id'], $other->ID ) === null, 'cross-user attachment isolation' );
$repo->share( $account, $other->ID, array( 'read' ) );
check( $repo->user_can_access_account( $other->ID, $account ) && ! $repo->user_can_access_account( $other->ID, $account, 'send' ), 'read-only shared ACL does not grant sending' );
rejects( fn() => $service->queueSend( $account, $other->ID, array( 'to' => 'to@example.test' ) ), 'shared reader cannot queue mail' );
rejects( fn() => $service->setMessageState( $message, $other->ID, array( 'is_read' => true ) ), 'shared reader cannot modify server flags' );
rejects( fn() => $repo->save_draft( array( 'account_id' => $account, 'data_json' => '{}', 'status' => 'draft' ), $other->ID ), 'draft save checks account compose permission' );

wp_set_current_user( $other->ID );
$other->remove_cap( 'wpmd_manage_contacts' );
wp_set_current_user( 0 ); wp_set_current_user( $other->ID );
check( request( 'POST', 'contacts', array( 'display_name' => 'Unauthorized' ) )->get_status() === 403, 'contacts endpoint requires contacts capability' );
check( request( 'GET', 'bootstrap' )->get_data()['contacts'] === array(), 'bootstrap respects collection permissions' );
check( request( 'POST', 'messages/' . $message . '/state', array( 'is_read' => 'false' ) )->get_status() === 400, 'REST state requires actual booleans' );
check( request( 'POST', 'accounts', array_merge( $input, array( 'id' => $account ) ) )->get_status() === 400, 'shared reader cannot edit account credentials' );
wp_set_current_user( $owner->ID );
$boot = request( 'GET', 'bootstrap' )->get_data();
check( ! str_contains( wp_json_encode( $boot ), 'fixture-password' ) && ! str_contains( wp_json_encode( $boot ), 'secret_enc' ), 'REST bootstrap never exposes credentials' );
$draft = request( 'POST', 'drafts', array( 'account_id' => $account, 'data' => array( 'to' => 'to@example.test', 'subject' => 'Saved draft', 'body_text' => 'Body' ) ) )->get_data();
check( ! empty( $draft['id'] ) && $draft['version'] === 1, 'REST draft create returns version' );
$updated = request( 'POST', 'drafts', array( 'id' => $draft['id'], 'version' => 1, 'account_id' => $account, 'data' => array( 'subject' => 'Updated draft' ) ) );
check( $updated->get_status() === 200 && $updated->get_data()['version'] === 2, 'draft update increments version' );
check( request( 'POST', 'drafts', array( 'id' => $draft['id'], 'version' => 1, 'account_id' => $account, 'data' => array() ) )->get_status() === 400, 'stale draft update rejected' );
check( $service->composePayload( array( 'body_text' => '<code>literal plain text</code>' ) )['body_text'] === '<code>literal plain text</code>', 'plain-text composition preserves literal markup' );
$signature_input = array( 'name' => 'Default A', 'text' => 'Signature A', 'is_default' => true, 'account_id' => $account );
$signature_a = request( 'POST', 'signatures', $signature_input )->get_data()['id'];
$signature_b = request( 'POST', 'signatures', array_merge( $signature_input, array( 'name' => 'Default B' ) ) )->get_data()['id'];
$defaults = array_filter( $repo->simple_list( 'signatures', $owner->ID ), static fn( $s ) => (int) $s['account_id'] === $account && (int) $s['is_default'] === 1 );
check( count( $defaults ) === 1 && (int) array_values( $defaults )[0]['id'] === $signature_b, 'one default signature per account is enforced' );
check( request( 'POST', 'templates', array( 'id' => 999999, 'name' => 'Missing' ) )->get_status() === 400, 'missing record is an error rather than false success' );
check( request( 'POST', 'rules', array( 'name' => 'Invalid rule', 'conditions' => array( array( 'field' => 'subject', 'value' => 'Hi' ) ), 'actions' => array( 'delete' => true ) ) )->get_status() === 400, 'unsupported rule actions rejected' );
check( request( 'POST', 'send', array( 'account_id' => $account, 'to' => 'good@example.test, bad-address' ) )->get_status() === 400, 'invalid recipient rejects the entire send' );
check( request( 'POST', 'send', array( 'account_id' => $account, 'to' => 'to@example.test', 'scheduled_at' => 'tomorrow' ) )->get_status() === 400, 'ambiguous scheduling dates rejected' );
check( request( 'POST', 'drafts', array( 'account_id' => $account, 'data' => array( 'attachments' => array( array( 'path' => '/etc/passwd' ) ) ) ) )->get_status() === 400, 'filesystem attachment paths rejected' );

check( request( 'POST', 'send', array( 'account_id' => $account, 'to' => 'to@example.test', 'scheduled_at' => '2027-02-30T12:00:00Z' ) )->get_status() === 400, 'nonexistent calendar date rejected' );
rejects( fn() => $repo->consume_draft( (int) $draft['id'], $owner->ID, 1 ), 'send cannot consume a newer draft version' );
$key = 'regression-lock'; $token = $repo->acquire_lock( $key );
check( $token !== null && $repo->acquire_lock( $key ) === null, 'atomic lock excludes overlapping workers' );
$repo->release_lock( $key, 'wrong-token' ); check( $repo->acquire_lock( $key ) === null, 'wrong worker cannot release a lock' ); $repo->release_lock( $key, $token );
$uuid = wp_generate_uuid4(); $payload = array( 'to' => 'to@example.test', 'subject' => 'Fixture send', 'body_html' => '<b>Hello</b>', 'request_id' => $uuid, 'attachments' => array( array( 'filename' => 'test.txt', 'content_base64' => base64_encode( 'outgoing attachment' ) ) ) );
$send = $service->queueSend( $account, $owner->ID, $payload );
check( $service->queueSend( $account, $owner->ID, $payload ) === $send, 'idempotent send request returns original outbox record' );
rejects( fn() => $service->queueSend( $account, $owner->ID, array_merge( $payload, array( 'subject' => 'Different content' ) ) ), 'reused send key cannot silently accept different content' );
$row = $repo->outbox_by_uuid( $uuid, $owner->ID, $account ); $service->sendOutbox( $row );
check( $repo->outbox_by_uuid( $uuid, $owner->ID, $account )['status'] === 'sent', 'SMTP message and attachment accepted by fixture' );
$delivery_file = '/private/tmp/wpmd-mail-fixtures/deliveries.jsonl'; $before = count( file( $delivery_file ) );
$service->sendOutbox( $row ); check( count( file( $delivery_file ) ) === $before, 'stale outbox snapshot cannot send twice' );
$delivery = json_decode( trim( file( $delivery_file )[$before - 1] ), true );
check( str_contains( $delivery['body'], 'multipart/alternative' ) && str_contains( $delivery['body'], 'text/plain' ), 'HTML email gets a nonempty text alternative' );
$uuid_drop = wp_generate_uuid4(); $service->queueSend( $account, $owner->ID, array( 'to' => 'drop@example.test', 'body_text' => 'Uncertain delivery', 'request_id' => $uuid_drop ) );
$service->sendOutbox( $repo->outbox_by_uuid( $uuid_drop, $owner->ID, $account ) );
check( $repo->outbox_by_uuid( $uuid_drop, $owner->ID, $account )['status'] === 'uncertain', 'SMTP disconnect after DATA is not automatically retried' );
$uuid_partial = wp_generate_uuid4(); $service->queueSend( $account, $owner->ID, array( 'to' => 'to@example.test,reject@example.test', 'body_text' => 'Partial recipients', 'request_id' => $uuid_partial ) );
$service->sendOutbox( $repo->outbox_by_uuid( $uuid_partial, $owner->ID, $account ) );
check( $repo->outbox_by_uuid( $uuid_partial, $owner->ID, $account )['status'] === 'uncertain', 'partial recipient delivery is not retried to accepted recipients' );
$uuid_cancel = wp_generate_uuid4(); $cancel_id = $service->queueSend( $account, $owner->ID, array( 'bcc' => 'hidden@example.test', 'body_text' => 'Bcc only', 'request_id' => $uuid_cancel ) );
check( $cancel_id > 0, 'Bcc-only mail can be queued' ); $repo->cancel_outbox( $cancel_id, $owner->ID );
check( ! $repo->claim_outbox( $cancel_id ), 'cancelled delivery cannot be claimed' );

$long_a = str_repeat( 'a', 195 ) . 'A'; $long_b = str_repeat( 'a', 195 ) . 'B';
$fa = $repo->upsert_folder( $account, array( 'remote_name' => $long_a, 'display_name' => 'Long A' ) );
$fb = $repo->upsert_folder( $account, array( 'remote_name' => $long_b, 'display_name' => 'Long B' ) );
check( $fa !== $fb, 'long folder names do not collide at index prefix' );
$case_a = $repo->upsert_folder( $account, array( 'remote_name' => 'Case', 'display_name' => 'Case' ) );
$case_b = $repo->upsert_folder( $account, array( 'remote_name' => 'case', 'display_name' => 'case' ) );
check( $case_a !== $case_b, 'case-sensitive remote mailbox identities preserved' );
$old_message = $result['messages'][0]['message'];
$new_id = $repo->upsert_message( $account, $folder, 43, 7, $old_message );
$repo->finish_folder( $folder, array( 'uidvalidity' => 43 ), array( 7 ) );
check( count( $repo->messages( $owner->ID, array( 'folder_id' => $folder ) ) ) === 1, 'UIDVALIDITY reset removes old folder mappings' );
check( $repo->message( $message, $owner->ID ) === null, 'invalidated orphan message removed' );
$repo->finish_folder( $folder, array( 'uidvalidity' => 43 ), array() );
check( $repo->message( $new_id, $owner->ID ) === null && ! $repo->attachments( $new_id ), 'expunged cache entries and attachment bytes removed' );

$service->queueSync( $account, $owner->ID );
for ( $i = 0; $i < 4; $i++ ) { $job = $repo->next_job(); if ( ! $job ) break; $service->runSyncJob( $job ); $repo->finish_job( $job ); }
check( count( $repo->messages( $owner->ID, array( 'account_id' => $account ) ) ) === 6, 'background discovery and all selectable folders synchronize' );
check( $repo->account( $account )['status'] === 'synced', 'successful sync updates account diagnostics' );
wp_set_current_user( 0 ); check( request( 'GET', 'bootstrap' )->get_status() === 401, 'anonymous REST request denied' ); wp_set_current_user( $owner->ID );
rejects( fn() => $service->moveMessage( (int) $repo->messages( $owner->ID, array( 'account_id' => $account ) )[0]['id'], $other->ID, $folder ), 'read-only shared user cannot move or trash messages' );
$inbox_message = $repo->messages( $owner->ID, array( 'folder_id' => $folder ) )[0];
$sent_folder = array_values( array_filter( $repo->folders( $account ), static fn( $f ) => $f['remote_name'] === 'Sent' ) )[0];
$service->moveMessage( (int) $inbox_message['id'], $owner->ID, (int) $sent_folder['id'] );
check( $repo->message( (int) $inbox_message['id'], $owner->ID ) === null, 'successful UID MOVE removes the obsolete local source message' );
for ( $i = 0; $i < 2; $i++ ) { $job = $repo->next_job(); if ( $job ) { $service->runSyncJob( $job ); $repo->finish_job( $job ); } }
check( count( $repo->messages( $owner->ID, array( 'folder_id' => $folder ) ) ) === 1 && count( $repo->messages( $owner->ID, array( 'folder_id' => (int) $sent_folder['id'] ) ) ) === 3, 'move reconciles source and destination without losing the message' );
$repo->share( $account, $other->ID, array( 'read', 'compose', 'send' ) );
$revoked_uuid = wp_generate_uuid4(); $service->queueSend( $account, $other->ID, array( 'to' => 'to@example.test', 'body_text' => 'Revoked', 'request_id' => $revoked_uuid ) );
$repo->share( $account, $other->ID, array( 'read' ) );
$service->sendOutbox( $repo->outbox_by_uuid( $revoked_uuid, $other->ID, $account ) );
check( $repo->outbox_by_uuid( $revoked_uuid, $other->ID, $account )['status'] === 'failed', 'queued delivery rechecks revoked account permission' );
$bad_account = $service->saveAccount( array_merge( $input, array( 'smtp_port' => 11026 ) ), $owner->ID );
$retry_uuid = wp_generate_uuid4(); $retry_id = $service->queueSend( $bad_account, $owner->ID, array( 'to' => 'to@example.test', 'body_text' => 'Retry', 'request_id' => $retry_uuid ) );
for ( $i = 0; $i < 5; $i++ ) {
    $repo->update_outbox( $retry_id, array( 'scheduled_at' => gmdate( 'Y-m-d H:i:s', time() - 1 ) ) );
    $service->sendOutbox( $repo->outbox_by_uuid( $retry_uuid, $owner->ID, $bad_account ) );
    if ( $i === 0 ) check( $repo->outbox_by_uuid( $retry_uuid, $owner->ID, $bad_account )['status'] === 'retrying', 'pre-submission connection failures enter retry backoff' );
}
check( $repo->outbox_by_uuid( $retry_uuid, $owner->ID, $bad_account )['status'] === 'failed', 'retry policy stops after five attempts' );
$service->deleteAccount( $bad_account, $owner->ID );
check( $repo->account( $bad_account ) === null, 'account removal clears failed deliveries and account data' );
$privacy = new WPMailDesk\WordPress\Privacy( $repo ); $export = $privacy->export( $owner->user_email );
check( ! str_contains( wp_json_encode( $export ), 'secret_enc' ) && ! str_contains( wp_json_encode( $export ), 'fixture-password' ), 'privacy export excludes encrypted and plaintext credentials' );
$indexes = $wpdb->get_results( 'SHOW INDEX FROM ' . $wpdb->prefix . 'wpmd_outbox', ARRAY_A );
check( in_array( 'uuid', array_column( $indexes, 'Key_name' ), true ) && in_array( 'queue', array_column( $indexes, 'Key_name' ), true ), 'MySQL idempotency and queue indexes installed' );
$history_ids = array();
foreach ( array( 'sent', 'cancelled', 'failed', 'uncertain' ) as $status ) {
    $history_id = $repo->queue_outbox( $account, $owner->ID, array(), gmdate( 'Y-m-d H:i:s' ), wp_generate_uuid4() . '@example.test', wp_generate_uuid4() );
    $wpdb->update( $wpdb->prefix . 'wpmd_outbox', array( 'status' => $status, 'created_at' => '2000-01-01 00:00:00' ), array( 'id' => $history_id ) ); $history_ids[] = $history_id;
}
$repo->prune_history();
$retained_statuses = $wpdb->get_col( 'SELECT status FROM ' . $wpdb->prefix . 'wpmd_outbox WHERE id IN (' . implode( ',', $history_ids ) . ') ORDER BY id' );
check( $retained_statuses === array( 'failed', 'uncertain' ), 'retention removes completed history while preserving unresolved delivery evidence' );
check( $wpdb->last_error === '', 'no final database errors' );
file_put_contents( '/private/tmp/wpmd-test-account.json', wp_json_encode( array( 'account_id' => $account, 'owner_id' => $owner->ID ) ) );
echo "\n{$passed} integration checks passed.\n";
