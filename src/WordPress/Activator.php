<?php
namespace WPMailDesk\WordPress;

final class Activator {
    public static function activate( bool $network_wide = false ): void {
        if ( is_multisite() && $network_wide ) {
            foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
                switch_to_blog( (int) $site_id );
                try { self::activate(); } finally { restore_current_blog(); }
            }
            return;
        }
        self::create_tables();
        Capabilities::ensure_caps();
        add_filter( 'cron_schedules', static function ( array $schedules ): array { $schedules['wpmd_five_minutes'] = array( 'interval' => 300, 'display' => 'Every Five Minutes' ); return $schedules; } );
        if ( ! wp_next_scheduled( 'wpmd_queue_tick' ) ) {
            wp_schedule_event( time() + 60, 'wpmd_five_minutes', 'wpmd_queue_tick' );
        }
        update_option( 'wpmd_db_version', WPMD_VERSION, false );
    }

    public static function deactivate( bool $network_wide = false ): void {
        if ( is_multisite() && $network_wide ) {
            foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $site_id ) {
                switch_to_blog( (int) $site_id );
                try { self::deactivate(); } finally { restore_current_blog(); }
            }
            return;
        }
        wp_clear_scheduled_hook( 'wpmd_queue_tick' );
        wp_clear_scheduled_hook( 'wpmd_queue_continue' );
    }

    private static function create_tables(): void {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $c = $wpdb->get_charset_collate();
        $p = $wpdb->prefix . 'wpmd_';

        $sql = array();
        $sql[] = "CREATE TABLE {$p}accounts (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            owner_user_id bigint unsigned NOT NULL,
            label varchar(190) NOT NULL,
            email varchar(320) NOT NULL,
            display_name varchar(190) NOT NULL DEFAULT '',
            provider varchar(50) NOT NULL DEFAULT 'generic',
            auth_type varchar(30) NOT NULL DEFAULT 'password',
            username varchar(320) NOT NULL DEFAULT '',
            secret_enc longtext NULL,
            oauth_refresh_enc longtext NULL,
            oauth_access_enc longtext NULL,
            oauth_expires_at datetime NULL,
            imap_host varchar(255) NOT NULL DEFAULT '',
            imap_port smallint unsigned NOT NULL DEFAULT 993,
            imap_security varchar(20) NOT NULL DEFAULT 'ssl',
            smtp_host varchar(255) NOT NULL DEFAULT '',
            smtp_port smallint unsigned NOT NULL DEFAULT 465,
            smtp_security varchar(20) NOT NULL DEFAULT 'ssl',
            sync_enabled tinyint(1) NOT NULL DEFAULT 1,
            sync_days smallint unsigned NOT NULL DEFAULT 30,
            cache_mode varchar(20) NOT NULL DEFAULT 'balanced',
            status varchar(30) NOT NULL DEFAULT 'new',
            last_error text NULL,
            last_sync_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), KEY owner_user_id (owner_user_id), KEY email (email)
        ) $c;";
        $sql[] = "CREATE TABLE {$p}account_users (
            account_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            permissions text NOT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (account_id,user_id), KEY user_id (user_id)
        ) $c;";
        $sql[] = "CREATE TABLE {$p}folders (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            account_id bigint unsigned NOT NULL,
            remote_name varchar(500) NOT NULL,
            remote_hash char(64) NULL,
            display_name varchar(255) NOT NULL,
            delimiter varchar(10) NULL,
            special_use varchar(40) NULL,
            uidvalidity bigint unsigned NULL,
            uidnext bigint unsigned NULL,
            highestmodseq bigint unsigned NULL,
            last_synced_uid bigint unsigned NULL,
            unread_count int unsigned NOT NULL DEFAULT 0,
            message_count int unsigned NOT NULL DEFAULT 0,
            last_sync_at datetime NULL,
            PRIMARY KEY (id), UNIQUE KEY account_remote_hash (account_id,remote_hash), KEY special_use (special_use)
        ) $c;";
        $sql[] = "CREATE TABLE {$p}threads (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            account_id bigint unsigned NOT NULL,
            provider_thread_id varchar(255) NULL,
            normalized_subject varchar(500) NOT NULL DEFAULT '',
            snippet text NULL,
            participants_cache text NULL,
            message_count int unsigned NOT NULL DEFAULT 0,
            unread_count int unsigned NOT NULL DEFAULT 0,
            last_message_at datetime NULL,
            PRIMARY KEY (id), KEY account_last (account_id,last_message_at)
        ) $c;";
        $sql[] = "CREATE TABLE {$p}messages (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            account_id bigint unsigned NOT NULL,
            thread_id bigint unsigned NULL,
            internet_message_id varchar(500) NULL,
            provider_message_id varchar(255) NULL,
            provider_thread_id varchar(255) NULL,
            subject text NOT NULL,
            normalized_subject varchar(500) NOT NULL DEFAULT '',
            from_json longtext NULL,
            to_json longtext NULL,
            cc_json longtext NULL,
            bcc_json longtext NULL,
            reply_to_json longtext NULL,
            headers_json longtext NULL,
            body_text longtext NULL,
            body_html longtext NULL,
            body_preview text NULL,
            sent_at datetime NULL,
            received_at datetime NULL,
            size_bytes bigint unsigned NOT NULL DEFAULT 0,
            has_attachments tinyint(1) NOT NULL DEFAULT 0,
            importance varchar(20) NOT NULL DEFAULT 'normal',
            is_starred tinyint(1) NOT NULL DEFAULT 0,
            is_read tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), KEY account_received (account_id,received_at), KEY thread_id (thread_id), KEY internet_message_id (internet_message_id(190))
        ) $c;";
        $sql[] = "CREATE TABLE {$p}message_folders (
            message_id bigint unsigned NOT NULL,
            folder_id bigint unsigned NOT NULL,
            remote_uid bigint unsigned NOT NULL,
            uidvalidity bigint unsigned NOT NULL DEFAULT 0,
            modseq bigint unsigned NULL,
            flags text NULL,
            PRIMARY KEY (folder_id,uidvalidity,remote_uid), KEY message_id (message_id)
        ) $c;";
        $sql[] = "CREATE TABLE {$p}attachments (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            message_id bigint unsigned NOT NULL,
            filename varchar(500) NOT NULL,
            mime_type varchar(190) NOT NULL DEFAULT 'application/octet-stream',
            size_bytes bigint unsigned NOT NULL DEFAULT 0,
            content_id varchar(500) NULL,
            disposition varchar(30) NULL,
            remote_part_id varchar(100) NULL,
            checksum varchar(128) NULL,
            cache_status varchar(30) NOT NULL DEFAULT 'remote',
            scan_status varchar(30) NOT NULL DEFAULT 'unknown',
            local_storage_key varchar(500) NULL,
            content_base64 longtext NULL,
            PRIMARY KEY (id), KEY message_id (message_id)
        ) $c;";
        $sql[] = "CREATE TABLE {$p}drafts (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            account_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            remote_folder_id bigint unsigned NULL,
            remote_uid bigint unsigned NULL,
            data_json longtext NOT NULL,
            version int unsigned NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL DEFAULT 'draft',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), KEY account_user (account_id,user_id)
        ) $c;";
        $sql[] = "CREATE TABLE {$p}outbox (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            uuid char(36) NOT NULL,
            account_id bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL,
            message_id_header varchar(500) NOT NULL,
            payload_json longtext NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'queued',
            attempts smallint unsigned NOT NULL DEFAULT 0,
            scheduled_at datetime NOT NULL,
            last_error text NULL,
            sent_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), UNIQUE KEY uuid (uuid), KEY queue (status,scheduled_at)
        ) $c;";
        $sql[] = "CREATE TABLE {$p}contacts (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            first_name varchar(190) NOT NULL DEFAULT '',
            last_name varchar(190) NOT NULL DEFAULT '',
            display_name varchar(190) NOT NULL DEFAULT '',
            company varchar(190) NOT NULL DEFAULT '',
            job_title varchar(190) NOT NULL DEFAULT '',
            emails_json longtext NULL,
            phones_json longtext NULL,
            website varchar(500) NULL,
            notes text NULL,
            tags_json longtext NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), KEY user_id (user_id)
        ) $c;";
        $sql[] = "CREATE TABLE {$p}signatures (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            account_id bigint unsigned NULL,
            name varchar(190) NOT NULL,
            html longtext NOT NULL,
            text longtext NULL,
            is_default tinyint(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id), KEY user_id (user_id)
        ) $c;";
        $sql[] = "CREATE TABLE {$p}templates (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            name varchar(190) NOT NULL,
            subject text NOT NULL,
            body_html longtext NOT NULL,
            body_text longtext NULL,
            PRIMARY KEY (id), KEY user_id (user_id)
        ) $c;";
        $sql[] = "CREATE TABLE {$p}rules (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NOT NULL,
            account_id bigint unsigned NULL,
            name varchar(190) NOT NULL,
            enabled tinyint(1) NOT NULL DEFAULT 1,
            priority int NOT NULL DEFAULT 100,
            conditions_json longtext NOT NULL,
            actions_json longtext NOT NULL,
            PRIMARY KEY (id), KEY user_priority (user_id,priority)
        ) $c;";
        $sql[] = "CREATE TABLE {$p}jobs (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            account_id bigint unsigned NULL,
            type varchar(100) NOT NULL,
            payload_json longtext NOT NULL,
            status varchar(30) NOT NULL DEFAULT 'queued',
            attempts smallint unsigned NOT NULL DEFAULT 0,
            available_at datetime NOT NULL,
            locked_at datetime NULL,
            lock_token varchar(64) NULL,
            last_error text NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id), KEY work (status,available_at), KEY account_id (account_id)
        ) $c;";
        $sql[] = "CREATE TABLE {$p}activity_log (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint unsigned NULL,
            account_id bigint unsigned NULL,
            action varchar(100) NOT NULL,
            object_type varchar(80) NULL,
            object_id bigint unsigned NULL,
            result varchar(30) NOT NULL DEFAULT 'success',
            context_json longtext NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id), KEY account_date (account_id,created_at), KEY action (action)
        ) $c;";

        foreach ( $sql as $statement ) {
            // dbDelta requires each index definition on a separate line.
            $statement = str_replace( array( ', KEY ', ', UNIQUE KEY ' ), array( ",\n            KEY ", ",\n            UNIQUE KEY " ), $statement );
            $statement = str_replace( ") $c;", ") ENGINE=InnoDB $c;", $statement );
            dbDelta( $statement );
            if ( $wpdb->last_error ) throw new \RuntimeException( 'MailDesk database installation failed. Check database permissions.' );
            preg_match( '/CREATE TABLE ([^\s]+)/', $statement, $table_match );
            $table = $table_match[1];
            $status = $wpdb->get_row( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $wpdb->esc_like( $table ) ), ARRAY_A );
            if ( ! $status ) throw new \RuntimeException( 'MailDesk table installation could not be verified.' );
            if ( strcasecmp( $status['Engine'] ?? '', 'InnoDB' ) !== 0 && false === $wpdb->query( "ALTER TABLE {$table} ENGINE=InnoDB" ) ) throw new \RuntimeException( 'MailDesk requires transactional InnoDB tables.' );
        }
        $wpdb->query( "UPDATE {$p}folders SET remote_hash=SHA2(remote_name,256) WHERE remote_hash IS NULL" );
        $old_index = $wpdb->get_row( "SHOW INDEX FROM {$p}folders WHERE Key_name='account_folder'" );
        if ( $old_index ) $wpdb->query( "ALTER TABLE {$p}folders DROP INDEX account_folder" );
        foreach ( $wpdb->get_results( "SELECT id,payload_json FROM {$p}jobs WHERE account_id IS NULL", ARRAY_A ) ?: array() as $job ) {
            $payload = json_decode( $job['payload_json'], true ) ?: array();
            $wpdb->update( $p . 'jobs', array( 'account_id' => absint( $payload['account_id'] ?? 0 ) ), array( 'id' => $job['id'] ) );
        }
    }
}
