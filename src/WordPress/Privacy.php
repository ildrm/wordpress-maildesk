<?php
namespace WPMailDesk\WordPress;

use WPMailDesk\Infrastructure\Database\Repository;

final class Privacy {
    public function __construct( private Repository $repo ) {}
    public function register(): void {
        add_action( 'admin_init', static function (): void {
            if ( function_exists( 'wp_add_privacy_policy_content' ) ) wp_add_privacy_policy_content( 'MailDesk', '<p>' . esc_html__( 'MailDesk stores email account configuration, encrypted credentials, recent message bodies and attachments, contacts, drafts, writing tools and delivery history in the WordPress database. It connects to configured IMAP and SMTP providers. Recent mail is a bounded cache; activity and completed delivery history are retained for 30 days. WordPress personal data tools can export cached text and remove a user’s local MailDesk data. This does not erase mail at the external provider.', 'wp-maildesk' ) . '</p>' );
        } );
        add_filter( 'wp_privacy_personal_data_exporters', function ( array $exporters ): array {
            $exporters['wpmd'] = array( 'exporter_friendly_name' => 'MailDesk', 'callback' => array( $this, 'export' ) ); return $exporters;
        } );
        add_filter( 'wp_privacy_personal_data_erasers', function ( array $erasers ): array {
            $erasers['wpmd'] = array( 'eraser_friendly_name' => 'MailDesk', 'callback' => array( $this, 'erase' ) ); return $erasers;
        } );
        add_action( 'delete_user', function ( int $id ): void { $this->eraseUser( $id ); } );
        add_action( 'wpmu_delete_user', function ( int $id ): void {
            foreach ( get_blogs_of_user( $id ) as $blog ) {
                switch_to_blog( (int) $blog->userblog_id ); try { $this->eraseUser( $id ); } finally { restore_current_blog(); }
            }
        } );
    }
    public function export( string $email, int $page = 1 ): array {
        $user = get_user_by( 'email', $email );
        if ( ! $user ) return array( 'data' => array(), 'done' => true );
        global $wpdb; $data = array(); $more = false; $offset = max( 0, $page - 1 ) * 50;
        foreach ( array( 'accounts', 'contacts', 'drafts', 'signatures', 'templates', 'rules', 'outbox', 'messages' ) as $type ) {
            $table = $wpdb->prefix . 'wpmd_' . $type;
            $column = $type === 'accounts' ? 'owner_user_id' : 'user_id';
            $where = $type === 'messages' ? 'account_id IN (SELECT id FROM ' . $wpdb->prefix . 'wpmd_accounts WHERE owner_user_id=%d)' : $column . '=%d';
            $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where} ORDER BY id LIMIT 50 OFFSET %d", $user->ID, $offset ), ARRAY_A ) ?: array();
            if ( count( $rows ) === 50 ) $more = true;
            foreach ( $rows as $row ) {
                $id = $row['id'];
                foreach ( array( 'secret_enc', 'oauth_access_enc', 'oauth_refresh_enc', 'body_html' ) as $secret ) unset( $row[$secret] );
                if ( isset( $row['payload_json'] ) ) {
                    $payload = json_decode( $row['payload_json'], true ) ?: array(); unset( $payload['attachments'] ); $row['payload_json'] = wp_json_encode( $payload );
                }
                if ( isset( $row['data_json'] ) ) {
                    $draft = json_decode( $row['data_json'], true ) ?: array(); unset( $draft['attachments'] ); $row['data_json'] = wp_json_encode( $draft );
                }
                $fields = array(); foreach ( $row as $key => $value ) if ( null !== $value ) $fields[] = array( 'name' => $key, 'value' => (string) $value );
                $data[] = array( 'group_id' => 'wpmd-' . $type, 'group_label' => 'MailDesk ' . $type, 'item_id' => 'wpmd-' . $type . '-' . $id, 'data' => $fields );
            }
        }
        return array( 'data' => $data, 'done' => ! $more );
    }
    public function erase( string $email, int $page = 1 ): array {
        $user = get_user_by( 'email', $email );
        if ( ! $user ) return array( 'items_removed' => false, 'items_retained' => false, 'messages' => array(), 'done' => true );
        return $this->eraseUser( (int) $user->ID );
    }
    private function eraseUser( int $user ): array {
        global $wpdb; $retained = false; $removed = false;
        foreach ( $this->repo->accounts_for_user( $user ) as $account ) {
            if ( (int) $account['owner_user_id'] !== $user ) continue;
            $key = 'account:' . $account['id']; $token = $this->repo->acquire_lock( $key );
            if ( ! $token ) { $retained = true; continue; }
            try { $removed = $this->repo->delete_account( (int) $account['id'], $user ) || $removed; }
            catch ( \RuntimeException $e ) { $retained = true; }
            finally { $this->repo->release_lock( $key, $token ); }
        }
        foreach ( array( 'contacts', 'drafts', 'signatures', 'templates', 'rules', 'account_users', 'activity_log' ) as $type ) {
            $result = $wpdb->delete( $wpdb->prefix . 'wpmd_' . $type, array( 'user_id' => $user ) );
            if ( false === $result ) $retained = true; elseif ( $result ) $removed = true;
        }
        $outbox = $wpdb->prefix . 'wpmd_outbox';
        $result = $wpdb->query( $wpdb->prepare( "DELETE FROM {$outbox} WHERE user_id=%d AND status<>%s", $user, 'sending' ) );
        if ( false === $result ) $retained = true; elseif ( $result ) $removed = true;
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$outbox} WHERE user_id=%d LIMIT 1", $user ) ) ) $retained = true;
        return array( 'items_removed' => $removed, 'items_retained' => $retained, 'messages' => $retained ? array( 'Some MailDesk data is in use or could not be removed. Retry erasure after background work finishes.' ) : array(), 'done' => true );
    }
}
