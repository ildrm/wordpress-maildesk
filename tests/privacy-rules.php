<?php
$path = getenv( 'WPMD_TEST_WP' ); if ( ! $path ) exit( 'Set WPMD_TEST_WP.' );
require $path . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' ) throw new RuntimeException( 'Local tests only.' );
use WPMailDesk\Infrastructure\Database\Repository;
use WPMailDesk\Infrastructure\Security\Crypto;
use WPMailDesk\Infrastructure\Mail\ImapClient;
use WPMailDesk\Infrastructure\Mail\SmtpSender;
use WPMailDesk\Application\MailService;
$passed = 0;
function privacy_check( bool $condition, string $name ): void { global $passed; if ( ! $condition ) throw new RuntimeException( 'FAIL: ' . $name ); echo "PASS {$name}\n"; $passed++; }
$repo = new Repository(); $crypto = new Crypto(); $service = new MailService( $repo, new ImapClient( $crypto ), new SmtpSender( $crypto ), $crypto );
$login = 'wpmd-privacy-' . wp_generate_password( 8, false );
$user = wp_insert_user( array( 'user_login' => $login, 'user_pass' => wp_generate_password(), 'user_email' => $login . '@example.test', 'role' => 'administrator' ) );
if ( is_wp_error( $user ) ) throw new RuntimeException( $user->get_error_message() ); wp_set_current_user( $user );
$input = array( 'label' => 'Privacy fixture', 'email' => $login . '@example.test', 'secret' => 'privacy-fixture-secret', 'imap_host' => '127.0.0.1', 'imap_port' => 11430, 'imap_security' => 'none', 'smtp_host' => '127.0.0.1', 'smtp_port' => 11025, 'smtp_security' => 'none', 'sync_enabled' => false );
$account = $service->saveAccount( $input, $user );
$repo->save_contact( array( 'display_name' => 'Private contact', 'emails_json' => wp_json_encode( array( 'private@example.test' ) ) ), $user );
$repo->save_simple( 'rules', array( 'account_id' => $account, 'name' => 'Malformed legacy rule', 'enabled' => 1, 'priority' => 0, 'conditions_json' => '"legacy format"', 'actions_json' => '[]' ), $user );
$repo->save_simple( 'rules', array( 'account_id' => $account, 'name' => 'Star fixture', 'enabled' => 1, 'priority' => 1, 'conditions_json' => wp_json_encode( array( array( 'field' => 'subject', 'value' => 'Hello' ) ) ), 'actions_json' => wp_json_encode( array( 'is_starred' => true ) ) ), $user );
$service->queueSync( $account, $user );
for ( $i = 0; $i < 30; $i++ ) {
    $job = $repo->next_job(); if ( ! $job ) break;
    if ( $job['type'] === 'message_state' ) $service->runStateJob( $job ); else $service->runSyncJob( $job );
    $repo->finish_job( $job );
}
$messages = $repo->messages( $user, array( 'account_id' => $account ) );
privacy_check( count( $messages ) > 0 && count( array_filter( $messages, static fn( $m ) => (int) $m['is_starred'] !== 1 ) ) === 0, 'matching rules apply remote and cached message state' );
$service->queueSend( $account, $user, array( 'to' => 'to@example.test', 'body_text' => 'Pending privacy data' ) );
$service->queueSync( $account, $user );
$privacy = new WPMailDesk\WordPress\Privacy( $repo ); $export = $privacy->export( $input['email'] );
privacy_check( ! empty( $export['data'] ) && ! str_contains( wp_json_encode( $export ), 'privacy-fixture-secret' ), 'personal exporter includes owned data without credentials' );
$result = $privacy->erase( $input['email'] );
privacy_check( $result['items_removed'] && ! $result['items_retained'], 'personal erasure reports successful local cleanup' );
privacy_check( $repo->account( $account ) === null && ! $repo->contacts( $user ) && ! $repo->outbox( $user ), 'personal erasure removes accounts, contacts and pending delivery data' );
global $wpdb;
privacy_check( !(int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . $wpdb->prefix . 'wpmd_jobs WHERE account_id=%d', $account ) ), 'personal erasure removes account background job payloads' );
require_once ABSPATH . 'wp-admin/includes/user.php'; wp_delete_user( $user );
echo "{$passed} privacy/rules checks passed.\n";
