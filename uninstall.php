<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;
require_once __DIR__ . '/src/WordPress/Capabilities.php';

$cleanup = static function (): void {
    wp_clear_scheduled_hook( 'wpmd_queue_tick' );
    wp_clear_scheduled_hook( 'wpmd_queue_continue' );
    foreach ( wp_roles()->role_objects as $role ) {
        foreach ( \WPMailDesk\WordPress\Capabilities::ALL as $cap ) $role->remove_cap( $cap );
    }
    if ( ! get_option( 'wpmd_delete_data_on_uninstall', false ) ) return;
    global $wpdb;
    foreach ( array( 'activity_log', 'jobs', 'rules', 'templates', 'signatures', 'contacts', 'outbox', 'drafts', 'attachments', 'message_folders', 'messages', 'threads', 'folders', 'account_users', 'accounts' ) as $name ) {
        $wpdb->query( 'DROP TABLE IF EXISTS `' . $wpdb->prefix . 'wpmd_' . $name . '`' );
    }
    foreach ( array( 'wpmd_db_version', 'wpmd_delete_data_on_uninstall', 'wpmd_last_queue_run' ) as $name ) delete_option( $name );
    foreach ( array( 'wpmd_lock_', '_transient_wpmd_', '_transient_timeout_wpmd_' ) as $prefix ) {
        $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $wpdb->esc_like( $prefix ) . '%' ) );
    }
};
if ( is_multisite() ) {
    foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
        switch_to_blog( (int) $site_id );
        try { $cleanup(); } finally { restore_current_blog(); }
    }
} else $cleanup();
