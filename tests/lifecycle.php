<?php
$path = getenv( 'WPMD_TEST_WP' );
if ( ! $path ) exit( 'Set WPMD_TEST_WP.' );
require $path . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' ) throw new RuntimeException( 'Local tests only.' );
use WPMailDesk\WordPress\Activator;
use WPMailDesk\Infrastructure\Database\Repository;

global $wpdb; $original = $wpdb->prefix; $scratch = $original . 'wpmd_lifecycle_'; $passed = 0;
function lifecycle_check( bool $ok, string $name ): void { global $passed; if ( ! $ok ) throw new RuntimeException( 'FAIL: ' . $name ); echo "PASS {$name}\n"; $passed++; }
try {
    $wpdb->prefix = $scratch;
    $table = $scratch . 'wpmd_folders';
    $wpdb->query( "CREATE TABLE {$table} (id bigint unsigned NOT NULL AUTO_INCREMENT, account_id bigint unsigned NOT NULL, remote_name varchar(500) NOT NULL, display_name varchar(255) NOT NULL, PRIMARY KEY(id), UNIQUE KEY account_folder(account_id,remote_name(190))) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4" );
    $wpdb->insert( $table, array( 'account_id' => 1, 'remote_name' => 'INBOX', 'display_name' => 'Inbox' ) );
    Activator::activate();
    $engine = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
    lifecycle_check( $engine['Engine'] === 'InnoDB', 'legacy tables gain transactional storage' );
    $row = $wpdb->get_row( "SELECT * FROM {$table}", ARRAY_A );
    lifecycle_check( $row && $row['remote_hash'] === hash( 'sha256', 'INBOX' ), 'upgrade backfills folder identity without losing existing rows' );
    $indexes = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
    lifecycle_check( ! in_array( 'account_folder', array_column( $indexes, 'Key_name' ), true ) && in_array( 'account_remote_hash', array_column( $indexes, 'Key_name' ), true ), 'upgrade replaces colliding legacy folder index' );
    Activator::activate();
    lifecycle_check( (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) === 1, 'repeated activation is nondestructive' );
    $repo = new Repository();
    lifecycle_check( $repo->upsert_folder( 1, array( 'remote_name' => str_repeat( 'a', 190 ) . 'x', 'display_name' => 'x' ) ) !== $repo->upsert_folder( 1, array( 'remote_name' => str_repeat( 'a', 190 ) . 'y', 'display_name' => 'y' ) ), 'upgraded index accepts distinct long names' );
    lifecycle_check( (bool) wp_next_scheduled( 'wpmd_queue_tick' ), 'activation schedules background processing' );
    Activator::deactivate(); lifecycle_check( ! wp_next_scheduled( 'wpmd_queue_tick' ), 'deactivation clears background processing' );
    define( 'WP_UNINSTALL_PLUGIN', true );
    update_option( 'wpmd_delete_data_on_uninstall', false );
    require dirname( __DIR__ ) . '/uninstall.php';
    lifecycle_check( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) === $table, 'default uninstall preserves cached data' );
    update_option( 'wpmd_delete_data_on_uninstall', true );
    require dirname( __DIR__ ) . '/uninstall.php';
    lifecycle_check( ! $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ), 'opt-in uninstall removes plugin tables' );
    lifecycle_check( $wpdb->last_error === '', 'lifecycle operations complete without SQL errors' );
} finally {
    foreach ( array( 'activity_log', 'jobs', 'rules', 'templates', 'signatures', 'contacts', 'outbox', 'drafts', 'attachments', 'message_folders', 'messages', 'threads', 'folders', 'account_users', 'accounts' ) as $name ) $wpdb->query( 'DROP TABLE IF EXISTS `' . $scratch . 'wpmd_' . $name . '`' );
    $wpdb->prefix = $original; delete_option( 'wpmd_delete_data_on_uninstall' ); Activator::activate();
}
echo "{$passed} lifecycle checks passed.\n";
