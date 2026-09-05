<?php
namespace WPMailDesk\Infrastructure\Database;

use RuntimeException;

final class Repository {
    private function t( string $name ): string { global $wpdb; return $wpdb->prefix . "wpmd_" . $name; }
    private function now(): string { return current_time( "mysql", true ); }
    private function check( $result ): void { if ( false === $result ) throw new RuntimeException( "MailDesk could not save data. Check database health and retry." ); }
    public function transaction( callable $operation ) {
        global $wpdb; $this->check( $wpdb->query( "START TRANSACTION" ) );
        try { $result = $operation(); $this->check( $wpdb->query( "COMMIT" ) ); return $result; }
        catch ( \Throwable $e ) { $wpdb->query( "ROLLBACK" ); throw $e; }
    }

    public function accounts_for_user( int $user ): array {
        global $wpdb; $a = $this->t( "accounts" ); $s = $this->t( "account_users" );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT a.* FROM {$a} a LEFT JOIN {$s} s ON s.account_id=a.id WHERE a.owner_user_id=%d OR s.user_id=%d ORDER BY a.label,a.email", $user, $user ), ARRAY_A ) ?: array();
        return array_values( array_filter( $rows, fn( $row ) => $this->user_can_access_account( $user, (int) $row["id"] ) ) );
    }
    public function account( int $id, int $user = 0 ): ?array {
        global $wpdb; $t = $this->t( "accounts" );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d", $id ), ARRAY_A );
        return $row && ( ! $user || $this->user_can_access_account( $user, $id ) ) ? $row : null;
    }
    public function user_can_access_account( int $user, int $id, string $permission = "read" ): bool {
        if ( $user <= 0 ) return false;
        $account = $this->account( $id );
        if ( ! $account ) return false;
        if ( (int) $account["owner_user_id"] === $user ) return true;
        global $wpdb; $t = $this->t( "account_users" );
        $permissions = json_decode( (string) $wpdb->get_var( $wpdb->prepare( "SELECT permissions FROM {$t} WHERE account_id=%d AND user_id=%d", $id, $user ) ), true );
        return is_array( $permissions ) && ( in_array( $permission, $permissions, true ) || ( $permissions[$permission] ?? false ) === true );
    }
    public function can_manage_account( int $user, int $id ): bool {
        $a = $this->account( $id );
        return $a && ( (int) $a["owner_user_id"] === $user || user_can( $user, "wpmd_manage_all_accounts" ) );
    }
    public function save_account( array $data, int $user ): int {
        global $wpdb; $id = (int) ( $data["id"] ?? 0 ); unset( $data["id"] ); $data["updated_at"] = $this->now();
        if ( $id ) {
            if ( ! $this->can_manage_account( $user, $id ) ) throw new RuntimeException( "Account is not manageable by this user." );
            $this->check( $wpdb->update( $this->t( "accounts" ), $data, array( "id" => $id ) ) ); return $id;
        }
        $data["owner_user_id"] = $user; $data["created_at"] = $this->now();
        $this->check( $wpdb->insert( $this->t( "accounts" ), $data ) ); return (int) $wpdb->insert_id;
    }
    public function update_account( int $id, array $fields ): void {
        global $wpdb; $fields["updated_at"] = $this->now(); $this->check( $wpdb->update( $this->t( "accounts" ), $fields, array( "id" => $id ) ) );
    }
    public function delete_account( int $id, int $user ): bool {
        if ( ! $this->can_manage_account( $user, $id ) ) throw new RuntimeException( "Account is not manageable by this user." );
        global $wpdb;
        $outbox = $this->t( "outbox" );
        if ( $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$outbox} WHERE account_id=%d AND status=%s LIMIT 1", $id, "sending" ) ) ) throw new RuntimeException( "Wait for the active delivery to finish before removing this account." );
        return $this->transaction( function () use ( $id, $wpdb ) {
            $messages = $this->t( "messages" ); $attachments = $this->t( "attachments" ); $mappings = $this->t( "message_folders" ); $folders = $this->t( "folders" );
            $this->check( $wpdb->query( $wpdb->prepare( "DELETE FROM {$attachments} WHERE message_id IN (SELECT id FROM {$messages} WHERE account_id=%d)", $id ) ) );
            $this->check( $wpdb->query( $wpdb->prepare( "DELETE FROM {$mappings} WHERE folder_id IN (SELECT id FROM {$folders} WHERE account_id=%d)", $id ) ) );
            foreach ( array( "messages", "threads", "folders", "drafts", "outbox", "account_users", "signatures", "rules", "activity_log", "jobs" ) as $name ) $this->check( $wpdb->delete( $this->t( $name ), array( "account_id" => $id ) ) );
            $this->check( $wpdb->delete( $this->t( "accounts" ), array( "id" => $id ) ) ); return true;
        } );
    }
    public function shares( int $id ): array {
        global $wpdb; $t = $this->t( "account_users" );
        return $wpdb->get_results( $wpdb->prepare( "SELECT user_id,permissions FROM {$t} WHERE account_id=%d", $id ), ARRAY_A ) ?: array();
    }
    public function share( int $id, int $user, array $permissions ): void {
        global $wpdb;
        if ( ! get_user_by( "id", $user ) ) throw new RuntimeException( "WordPress user not found." );
        if ( ! $permissions ) { $this->check( $wpdb->delete( $this->t( "account_users" ), array( "account_id" => $id, "user_id" => $user ) ) ); return; }
        $this->check( $wpdb->replace( $this->t( "account_users" ), array( "account_id" => $id, "user_id" => $user, "permissions" => wp_json_encode( $permissions ), "created_at" => $this->now() ) ) );
    }

    public function upsert_folder( int $account, array $data ): int {
        global $wpdb; $t = $this->t( "folders" );
        $data["remote_hash"] = hash( "sha256", $data["remote_name"] );
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE account_id=%d AND remote_hash=%s", $account, $data['remote_hash'] ) );
        if ( $id ) { $this->check( $wpdb->update( $t, $data, array( "id" => $id ) ) ); return $id; }
        $data["account_id"] = $account; $this->check( $wpdb->insert( $t, $data ) ); return (int) $wpdb->insert_id;
    }
    public function folders( int $account ): array {
        global $wpdb; $t = $this->t( "folders" );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE account_id=%d ORDER BY display_name,id", $account ), ARRAY_A ) ?: array();
        usort( $rows, static fn( $a, $b ) => ( strcasecmp( $a["remote_name"], "INBOX" ) === 0 ? -1 : ( strcasecmp( $b["remote_name"], "INBOX" ) === 0 ? 1 : strcmp( $a["display_name"], $b["display_name"] ) ) ) ); return $rows;
    }
    public function folder( int $id ): ?array {
        global $wpdb; $t = $this->t( "folders" ); return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d", $id ), ARRAY_A ) ?: null;
    }
    public function reconcile_folders( int $account, array $ids ): void {
        $this->transaction( function () use ( $account, $ids ) {
            global $wpdb; $folders = $this->t( 'folders' ); $mappings = $this->t( 'message_folders' );
            $where = $wpdb->prepare( 'account_id=%d', $account );
            if ( $ids ) $where .= ' AND id NOT IN (' . implode( ',', array_map( 'intval', $ids ) ) . ')';
            $this->check( $wpdb->query( "DELETE FROM {$mappings} WHERE folder_id IN (SELECT id FROM {$folders} WHERE {$where})" ) );
            $this->check( $wpdb->query( "DELETE FROM {$folders} WHERE {$where}" ) ); $this->remove_orphans();
        } );
    }
    public function upsert_message( int $account, int $folder, int $validity, int $uid, array $message ): int {
        return $this->transaction( function () use ( $account, $folder, $validity, $uid, $message ) {
            global $wpdb; $mt = $this->t( "messages" ); $mf = $this->t( "message_folders" );
            $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT message_id FROM {$mf} WHERE folder_id=%d AND uidvalidity=%d AND remote_uid=%d", $folder, $validity, $uid ) );
            $flags = $message["_flags"] ?? array(); $attachments = $message["_attachments"] ?? null;
            unset( $message["_flags"], $message["_attachments"] ); $message["updated_at"] = $this->now();
            // Message-ID is sender-controlled, not a unique identity. Never merge unrelated UIDs by it.
            if ( $id ) $this->check( $wpdb->update( $mt, $message, array( "id" => $id ) ) );
            else {
                $message["account_id"] = $account; $message["created_at"] = $this->now();
                $this->check( $wpdb->insert( $mt, $message ) ); $id = (int) $wpdb->insert_id;
            }
            $this->check( $wpdb->replace( $mf, array( "message_id" => $id, "folder_id" => $folder, "remote_uid" => $uid, "uidvalidity" => $validity, "flags" => wp_json_encode( $flags ) ) ) );
            if ( null !== $attachments ) {
                $this->check( $wpdb->delete( $this->t( "attachments" ), array( "message_id" => $id ) ) );
                foreach ( $attachments as $attachment ) { $attachment["message_id"] = $id; $this->check( $wpdb->insert( $this->t( "attachments" ), $attachment ) ); }
            }
            return $id;
        } );
    }
    public function cached_uids( int $folder, int $validity ): array {
        global $wpdb; $t = $this->t( "message_folders" );
        return array_map( "intval", $wpdb->get_col( $wpdb->prepare( "SELECT remote_uid FROM {$t} WHERE folder_id=%d AND uidvalidity=%d", $folder, $validity ) ) ?: array() );
    }
    public function finish_folder( int $folder, array $status, array $uids ): void {
        $this->transaction( function () use ( $folder, $status, $uids ) {
            global $wpdb; $mf = $this->t( "message_folders" ); $m = $this->t( "messages" );
            $sql = $wpdb->prepare( "DELETE FROM {$mf} WHERE folder_id=%d AND (uidvalidity<>%d", $folder, $status["uidvalidity"] );
            $sql .= $uids ? " OR remote_uid NOT IN (" . implode( ",", array_map( "intval", $uids ) ) . "))" : " OR 1=1)";
            $this->check( $wpdb->query( $sql ) ); $this->remove_orphans();
            $counts = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS total, SUM(CASE WHEN m.is_read=0 THEN 1 ELSE 0 END) AS unread FROM {$mf} mf INNER JOIN {$m} m ON m.id=mf.message_id WHERE mf.folder_id=%d", $folder ), ARRAY_A );
            $this->check( $wpdb->update( $this->t( "folders" ), array( "uidvalidity" => $status["uidvalidity"], "uidnext" => $status["uidnext"] ?? null, "message_count" => (int) $counts["total"], "unread_count" => (int) $counts["unread"], "last_sync_at" => $this->now() ), array( "id" => $folder ) ) );
        } );
    }
    private function remove_orphans(): void {
        global $wpdb; $m = $this->t( "messages" ); $mf = $this->t( "message_folders" ); $a = $this->t( "attachments" );
        $this->check( $wpdb->query( "DELETE FROM {$a} WHERE message_id IN (SELECT id FROM {$m} WHERE id NOT IN (SELECT message_id FROM {$mf}))" ) );
        $this->check( $wpdb->query( "DELETE FROM {$m} WHERE id NOT IN (SELECT message_id FROM {$mf})" ) );
    }
    public function forget_message( int $id ): void {
        $this->transaction( function () use ( $id ) {
            global $wpdb; $this->check( $wpdb->delete( $this->t( 'message_folders' ), array( 'message_id' => $id ) ) ); $this->remove_orphans();
        } );
    }
    public function messages( int $user, array $args ): array {
        global $wpdb; $m = $this->t( "messages" ); $mf = $this->t( "message_folders" );
        $ids = array_map( "intval", wp_list_pluck( $this->accounts_for_user( $user ), "id" ) ); if ( ! $ids ) return array();
        $where = array( "m.account_id IN (" . implode( ",", $ids ) . ")" ); $params = array();
        foreach ( array( "account_id" => "m.account_id", "folder_id" => "mf.folder_id" ) as $key => $column ) {
            if ( ! empty( $args[$key] ) ) { $where[] = $column . "=%d"; $params[] = (int) $args[$key]; }
        }
        if ( ! empty( $args["search"] ) ) {
            $where[] = "(m.subject LIKE %s OR m.body_preview LIKE %s OR m.from_json LIKE %s)";
            $like = "%" . $wpdb->esc_like( $args["search"] ) . "%"; array_push( $params, $like, $like, $like );
        }
        if ( isset( $args["unread"] ) ) { $where[] = "m.is_read=%d"; $params[] = $args["unread"] ? 0 : 1; }
        $params[] = max( 1, min( 100, (int) ( $args["limit"] ?? 50 ) ) ); $params[] = max( 0, (int) ( $args["offset"] ?? 0 ) );
        $sql = "SELECT m.id,m.account_id,m.subject,m.from_json,m.to_json,m.body_preview,m.received_at,m.sent_at,m.is_read,m.is_starred,m.has_attachments,mf.folder_id FROM {$m} m INNER JOIN {$mf} mf ON mf.message_id=m.id WHERE " . implode( " AND ", $where ) . " ORDER BY m.received_at DESC,m.id DESC LIMIT %d OFFSET %d";
        return $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) ?: array();
    }
    public function message( int $id, int $user ): ?array {
        global $wpdb; $m = $this->t( "messages" ); $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$m} WHERE id=%d", $id ), ARRAY_A );
        return $row && $this->user_can_access_account( $user, (int) $row["account_id"] ) ? $row : null;
    }
    public function message_mapping( int $id ): ?array {
        global $wpdb; $mf = $this->t( "message_folders" ); $f = $this->t( "folders" );
        return $wpdb->get_row( $wpdb->prepare( "SELECT mf.*,f.remote_name FROM {$mf} mf INNER JOIN {$f} f ON f.id=mf.folder_id WHERE mf.message_id=%d LIMIT 1", $id ), ARRAY_A ) ?: null;
    }
    public function set_message_state( int $id, int $user, array $fields ): bool {
        global $wpdb; if ( ! $this->message( $id, $user ) ) throw new RuntimeException( "Message not accessible." );
        $fields = array_intersect_key( $fields, array_flip( array( "is_read", "is_starred" ) ) );
        if ( ! $fields ) throw new RuntimeException( "No message state supplied." );
        $fields["updated_at"] = $this->now(); $this->check( $wpdb->update( $this->t( "messages" ), $fields, array( "id" => $id ) ) );
        $mapping = $this->message_mapping( $id );
        if ( $mapping ) {
            $m = $this->t( "messages" ); $mf = $this->t( "message_folders" );
            $unread = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$m} m INNER JOIN {$mf} mf ON mf.message_id=m.id WHERE mf.folder_id=%d AND m.is_read=0", $mapping["folder_id"] ) );
            $this->check( $wpdb->update( $this->t( "folders" ), array( "unread_count" => $unread ), array( "id" => $mapping["folder_id"] ) ) );
        }
        return true;
    }
    public function attachments( int $message ): array {
        global $wpdb; $t = $this->t( "attachments" ); return $wpdb->get_results( $wpdb->prepare( "SELECT id,filename,mime_type,size_bytes FROM {$t} WHERE message_id=%d", $message ), ARRAY_A ) ?: array();
    }
    public function attachment( int $id, int $user ): ?array {
        global $wpdb; $t = $this->t( "attachments" ); $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id=%d", $id ), ARRAY_A );
        return $row && $this->message( (int) $row["message_id"], $user ) ? $row : null;
    }

    public function drafts( int $user ): array { return array_values( array_filter( $this->personal_list( "drafts", $user ), fn( $d ) => $d["status"] === "draft" && $this->user_can_access_account( $user, (int) $d["account_id"], "compose" ) ) ); }
    public function save_draft( array $data, int $user ): int {
        if ( ! $this->user_can_access_account( $user, (int) $data["account_id"], "compose" ) ) throw new RuntimeException( "Account not accessible for composing." );
        global $wpdb; $t = $this->t( "drafts" ); $id = (int) ( $data["id"] ?? 0 );
        if ( $id ) {
            $version = (int) $data["version"]; unset( $data["id"] ); $data["version"] = $version + 1; $data["updated_at"] = $this->now();
            $updated = $wpdb->update( $t, $data, array( "id" => $id, "user_id" => $user, "version" => $version, "status" => "draft" ) );
            $this->check( $updated ); if ( ! $updated ) throw new RuntimeException( "Draft changed in another window or is no longer available. Reopen it before saving." ); return $id;
        }
        $data["version"] = 1; return $this->save_personal( "drafts", $data, $user );
    }
    public function personal_list( string $type, int $user ): array {
        $this->assert_personal( $type ); global $wpdb; $t = $this->t( $type );
        $order = $type === "rules" ? "priority ASC,id ASC" : "id DESC";
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id=%d ORDER BY {$order} LIMIT 1000", $user ), ARRAY_A ) ?: array();
    }
    public function contacts( int $user ): array { return $this->personal_list( "contacts", $user ); }
    public function simple_list( string $type, int $user ): array { return $this->personal_list( $type, $user ); }
    public function save_contact( array $data, int $user ): int { return $this->save_personal( "contacts", $data, $user ); }
    public function save_simple( string $type, array $data, int $user ): int {
        if ( trim( $data['name'] ?? '' ) === '' ) throw new RuntimeException( 'A name is required.' );
        if ( $type !== 'signatures' || empty( $data['is_default'] ) ) return $this->save_personal( $type, $data, $user );
        $key = 'signatures:' . $user; $token = $this->acquire_lock( $key );
        if ( ! $token ) throw new RuntimeException( 'Signatures are being updated. Try again shortly.' );
        try {
            return $this->transaction( function () use ( $type, $data, $user ) {
                global $wpdb; $id = $this->save_personal( $type, $data, $user ); $t = $this->t( 'signatures' );
                $scope = empty( $data['account_id'] ) ? 'account_id IS NULL' : $wpdb->prepare( 'account_id=%d', $data['account_id'] );
                $this->check( $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET is_default=0 WHERE user_id=%d AND id<>%d AND {$scope}", $user, $id ) ) );
                return $id;
            } );
        } finally { $this->release_lock( $key, $token ); }
    }
    private function assert_personal( string $type ): void {
        if ( ! in_array( $type, array( "contacts", "drafts", "signatures", "templates", "rules" ), true ) ) throw new RuntimeException( "Invalid collection." );
    }
    private function save_personal( string $type, array $data, int $user ): int {
        $this->assert_personal( $type ); global $wpdb; $t = $this->t( $type ); $id = (int) ( $data["id"] ?? 0 ); unset( $data["id"] );
        if ( ! empty( $data["account_id"] ) && ! $this->user_can_access_account( $user, (int) $data["account_id"] ) ) throw new RuntimeException( "Account not accessible." );
        if ( in_array( $type, array( "contacts", "drafts" ), true ) ) $data["updated_at"] = $this->now();
        if ( $id ) {
            if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT user_id FROM {$t} WHERE id=%d", $id ) ) !== $user ) throw new RuntimeException( "Record not found." );
            $this->check( $wpdb->update( $t, $data, array( "id" => $id, "user_id" => $user ) ) ); return $id;
        }
        $data["user_id"] = $user;
        if ( in_array( $type, array( "contacts", "drafts" ), true ) ) $data["created_at"] = $this->now();
        $this->check( $wpdb->insert( $t, $data ) ); return (int) $wpdb->insert_id;
    }
    public function delete_personal( string $type, int $id, int $user ): bool {
        $this->assert_personal( $type ); global $wpdb;
        $result = $wpdb->delete( $this->t( $type ), array( "id" => $id, "user_id" => $user ) ); $this->check( $result );
        if ( ! $result ) throw new RuntimeException( "Record not found." ); return true;
    }
    public function consume_draft( int $id, int $user, int $version ): void {
        global $wpdb;
        $result = $wpdb->delete( $this->t( 'drafts' ), array( 'id' => $id, 'user_id' => $user, 'version' => $version, 'status' => 'draft' ) );
        $this->check( $result );
        if ( 1 !== $result ) throw new RuntimeException( 'Draft changed. Reopen it before sending.' );
    }

    public function queue_outbox( int $account, int $user, array $payload, string $scheduled, string $message_id, string $uuid ): int {
        global $wpdb; $t = $this->t( "outbox" ); $now = $this->now();
        $existing = $this->outbox_by_uuid( $uuid, $user, $account ); if ( $existing ) return (int) $existing["id"];
        $this->check( $wpdb->insert( $t, array( "uuid" => $uuid, "account_id" => $account, "user_id" => $user, "message_id_header" => $message_id, "payload_json" => wp_json_encode( $payload ), "status" => "queued", "attempts" => 0, "scheduled_at" => $scheduled, "created_at" => $now, "updated_at" => $now ) ) ); return (int) $wpdb->insert_id;
    }
    public function outbox_by_uuid( string $uuid, int $user, int $account ): ?array {
        global $wpdb; $t = $this->t( "outbox" ); return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE uuid=%s AND user_id=%d AND account_id=%d", $uuid, $user, $account ), ARRAY_A ) ?: null;
    }
    public function outbox( int $user ): array {
        global $wpdb; $t = $this->t( "outbox" );
        return $wpdb->get_results( $wpdb->prepare( "SELECT id,account_id,status,attempts,scheduled_at,sent_at,last_error,created_at FROM {$t} WHERE user_id=%d ORDER BY id DESC LIMIT 100", $user ), ARRAY_A ) ?: array();
    }
    public function due_outbox( int $limit = 10 ): array {
        global $wpdb; $t = $this->t( "outbox" );
        $this->check( $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status=%s,last_error=%s WHERE status=%s AND updated_at<%s", "uncertain", "Worker stopped before delivery was confirmed. Check the provider before resending.", "sending", gmdate( "Y-m-d H:i:s", time() - 900 ) ) ) );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE status IN (%s,%s) AND scheduled_at<=%s ORDER BY scheduled_at,id LIMIT %d", "queued", "retrying", $this->now(), $limit ), ARRAY_A ) ?: array();
    }
    public function claim_outbox( int $id ): bool {
        global $wpdb; $t = $this->t( "outbox" );
        $result = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status=%s,attempts=attempts+1,updated_at=%s WHERE id=%d AND status IN (%s,%s) AND scheduled_at<=%s", "sending", $this->now(), $id, "queued", "retrying", $this->now() ) ); $this->check( $result ); return 1 === $result;
    }
    public function update_outbox( int $id, array $data ): void {
        global $wpdb; $data["updated_at"] = $this->now(); $this->check( $wpdb->update( $this->t( "outbox" ), $data, array( "id" => $id ) ) );
    }
    public function cancel_outbox( int $id, int $user ): bool {
        global $wpdb; $t = $this->t( "outbox" );
        $result = $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status=%s,updated_at=%s WHERE id=%d AND user_id=%d AND status IN (%s,%s)", "cancelled", $this->now(), $id, $user, "queued", "retrying" ) ); $this->check( $result );
        if ( ! $result ) throw new RuntimeException( "Only queued or retrying deliveries can be cancelled." ); return true;
    }
    public function sent_count( int $user, int $account ): int {
        global $wpdb; $t = $this->t( "outbox" );
        return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE user_id=%d AND account_id=%d AND created_at>=%s", $user, $account, gmdate( "Y-m-d H:i:s", time() - HOUR_IN_SECONDS ) ) );
    }

    public function acquire_lock( string $key ): ?string {
        global $wpdb; $name = "wpmd_lock_" . hash( "sha256", $key ); $token = time() . ":" . wp_generate_uuid4();
        // SQL compare-and-swap avoids stale object-cache reads and releasing another worker lock.
        $old = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", $name ) );
        if ( $old && (int) $old < time() - 900 ) $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", $name, $old ) );
        $result = $wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name,option_value,autoload) VALUES (%s,%s,%s)", $name, $token, "off" ) );
        $this->check( $result ); return 1 === $result ? $token : null;
    }
    public function release_lock( string $key, string $token ): void {
        global $wpdb; $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name=%s AND option_value=%s", "wpmd_lock_" . hash( "sha256", $key ), $token ) );
    }
    public function enqueue_job( string $type, array $payload ): int {
        global $wpdb; $t = $this->t( "jobs" ); $json = wp_json_encode( $payload );
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE type=%s AND payload_json=%s AND status IN (%s,%s) LIMIT 1", $type, $json, "queued", "running" ) ); if ( $id ) return $id;
        $this->check( $wpdb->insert( $t, array( 'account_id' => absint( $payload['account_id'] ?? 0 ), "type" => $type, "payload_json" => $json, "status" => "queued", "attempts" => 0, "available_at" => $this->now(), "created_at" => $this->now(), "updated_at" => $this->now() ) ) ); return (int) $wpdb->insert_id;
    }
    public function next_job(): ?array {
        global $wpdb; $t = $this->t( "jobs" );
        $wpdb->query( $wpdb->prepare( "UPDATE {$t} SET status=%s WHERE status=%s AND locked_at<%s", "queued", "running", gmdate( "Y-m-d H:i:s", time() - 900 ) ) );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE status=%s AND available_at<=%s ORDER BY id LIMIT 1", "queued", $this->now() ), ARRAY_A );
        if ( ! $row ) return null;
        $token = wp_generate_uuid4();
        $claimed = $wpdb->update( $t, array( "status" => "running", "attempts" => (int) $row["attempts"] + 1, "lock_token" => $token, "locked_at" => $this->now() ), array( "id" => $row["id"], "status" => "queued" ) );
        if ( 1 !== $claimed ) return null; $row["lock_token"] = $token; return $row;
    }
    public function finish_job( array $job, ?string $error = null ): void {
        global $wpdb;
        $status = null === $error ? "done" : ( (int) $job["attempts"] >= 4 ? "failed" : "queued" );
        $this->check( $wpdb->update( $this->t( "jobs" ), array( "status" => $status, "last_error" => $error, "available_at" => gmdate( "Y-m-d H:i:s", time() + 300 ), "updated_at" => $this->now() ), array( "id" => $job["id"], "lock_token" => $job["lock_token"] ) ) );
    }
    public function sync_candidates(): array {
        global $wpdb; $t = $this->t( "accounts" );
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE sync_enabled=1 AND (last_sync_at IS NULL OR last_sync_at<%s) ORDER BY last_sync_at,id LIMIT 5", gmdate( "Y-m-d H:i:s", time() - 900 ) ), ARRAY_A ) ?: array();
    }
    public function has_due_work(): bool {
        global $wpdb; $jobs = $this->t( 'jobs' ); $outbox = $this->t( 'outbox' );
        return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$jobs} WHERE status=%s AND available_at<=%s LIMIT 1", 'queued', $this->now() ) )
            || (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$outbox} WHERE status IN (%s,%s) AND scheduled_at<=%s LIMIT 1", 'queued', 'retrying', $this->now() ) );
    }
    public function log( string $action, ?int $account = null, ?int $object = null, string $result = "success", array $context = array() ): void {
        global $wpdb; $wpdb->insert( $this->t( "activity_log" ), array( "user_id" => get_current_user_id() ?: null, "account_id" => $account, "action" => $action, "object_id" => $object, "result" => $result, "context_json" => wp_json_encode( $context ), "created_at" => $this->now() ) );
    }
    public function logs( int $user, int $limit = 100 ): array {
        global $wpdb; $t = $this->t( "activity_log" );
        if ( user_can( $user, "wpmd_manage_all_accounts" ) ) return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ) ?: array();
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id=%d ORDER BY id DESC LIMIT %d", $user, $limit ), ARRAY_A ) ?: array();
    }
    public function stats(): array {
        global $wpdb; $out = array();
        foreach ( array( "accounts", "folders", "messages", "attachments", "outbox", "jobs" ) as $name ) { $t = $this->t( $name ); $out[$name] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t}" ); } return $out;
    }
    public function prune_history(): void {
        global $wpdb; $cutoff = gmdate( "Y-m-d H:i:s", time() - 30 * DAY_IN_SECONDS );
        foreach ( array( "activity_log", "jobs", "outbox" ) as $name ) {
            $t = $this->t( $name ); $sql = "DELETE FROM {$t} WHERE created_at<%s"; $params = array( $cutoff );
            if ( $name !== 'activity_log' ) { $sql .= ' AND status IN (%s,%s)'; $params = array_merge( $params, $name === 'jobs' ? array( 'done', 'failed' ) : array( 'sent', 'cancelled' ) ); }
            $this->check( $wpdb->query( $wpdb->prepare( $sql, $params ) ) );
        }
    }
}
