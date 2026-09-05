<?php
namespace WPMailDesk\Application;

use RuntimeException;
use WPMailDesk\Infrastructure\Database\Repository;
use WPMailDesk\Infrastructure\Mail\ImapClient;
use WPMailDesk\Infrastructure\Mail\SmtpSender;
use WPMailDesk\Infrastructure\Mail\DeliveryUncertain;
use WPMailDesk\Infrastructure\Security\Crypto;
use WPMailDesk\Infrastructure\Security\HostPolicy;

final class MailService {
    public function __construct( private Repository $repo, private ImapClient $imap, private SmtpSender $smtp, private Crypto $crypto ) {}

    public function saveAccount( array $input, int $user ): int {
        $id = absint( $input["id"] ?? 0 );
        $existing = $id ? $this->repo->account( $id ) : null;
        if ( $id && ! $this->repo->can_manage_account( $user, $id ) ) throw new RuntimeException( "Account not accessible." );
        $in = array_merge( $existing ?: array(), $input );
        $email = trim( (string) ( $in["email"] ?? "" ) );
        if ( ! is_email( $email ) ) throw new RuntimeException( "A valid email address is required." );
        $data = array( "id" => $id, "email" => $email, "label" => sanitize_text_field( $in["label"] ?? $email ), "display_name" => sanitize_text_field( $in["display_name"] ?? "" ), "provider" => sanitize_key( $in["provider"] ?? "generic" ), "username" => trim( (string) ( $in["username"] ?? "" ) ) ?: $email, "auth_type" => $in["auth_type"] ?? "password", "sync_enabled" => rest_sanitize_boolean( $in["sync_enabled"] ?? true ) ? 1 : 0, "sync_days" => (int) ( $in["sync_days"] ?? 30 ), "cache_mode" => "balanced", "status" => "configured", "last_error" => null );
        if ( ! in_array( $data["auth_type"], array( "password", "oauth" ), true ) ) throw new RuntimeException( "Unsupported authentication type." );
        if ( $data["sync_days"] < 1 || $data["sync_days"] > 3650 ) throw new RuntimeException( "History must be between 1 and 3650 days." );
        if ( preg_match( "/[\r\n\x00]/", $data["username"] ) ) throw new RuntimeException( "Invalid username." );
        foreach ( array( "imap" => 993, "smtp" => 465 ) as $kind => $port ) {
            $data[$kind . "_host"] = trim( (string) ( $in[$kind . "_host"] ?? "" ) );
            HostPolicy::resolve( $data[$kind . "_host"], strtoupper( $kind ) );
            $data[$kind . "_security"] = $in[$kind . "_security"] ?? "ssl";
            HostPolicy::security( $data[$kind . "_security"], $in );
            $value = $in[$kind . "_port"] ?? $port;
            if ( false === filter_var( $value, FILTER_VALIDATE_INT, array( "options" => array( "min_range" => 1, "max_range" => 65535 ) ) ) ) throw new RuntimeException( "Port must be between 1 and 65535." );
            $data[$kind . "_port"] = (int) $value;
        }
        if ( isset( $input["secret"] ) && $input["secret"] !== "" ) {
            if ( ! is_string( $input["secret"] ) || preg_match( "/[\r\n\x00]/", $input["secret"] ) ) throw new RuntimeException( "Password contains unsupported control characters." );
            $data["secret_enc"] = $this->crypto->encrypt( $input["secret"] );
        }
        if ( $data["auth_type"] === "password" && empty( $data["secret_enc"] ) && empty( $existing["secret_enc"] ) ) throw new RuntimeException( "A password or app password is required." );
        if ( $data["auth_type"] === "oauth" && ! has_filter( "wpmd_oauth_access_token" ) && empty( $existing["oauth_access_enc"] ) ) throw new RuntimeException( "Connect OAuth accounts through a provider adapter first." );
        if ( ! $id ) return $this->repo->save_account( $data, $user );
        return $this->locked( "account:" . $id, fn() => $this->repo->save_account( $data, $user ) );
    }
    public function deleteAccount( int $id, int $user ): bool { return $this->locked( "account:" . $id, fn() => $this->repo->delete_account( $id, $user ) ); }
    public function testAccount( int $id, int $user ): array {
        if ( ! $this->repo->can_manage_account( $user, $id ) ) throw new RuntimeException( "Account not accessible." );
        $account = $this->repo->account( $id ); return array( "imap" => $this->imap->test( $account ), "smtp" => $this->smtp->test( $account ) );
    }
    public function queueSync( int $id, int $user ): array {
        if ( ! $this->repo->can_manage_account( $user, $id ) ) throw new RuntimeException( "Account not accessible." );
        return $this->locked( "account:" . $id, fn() => array( "job_id" => $this->repo->enqueue_job( "discover", array( "account_id" => $id ) ), "status" => "queued" ) );
    }
    public function syncAccount( int $id, int $user ): array { return $this->queueSync( $id, $user ); }
    public function runSyncJob( array $job ): void {
        if ( ! in_array( $job['type'], array( 'discover', 'sync_folder' ), true ) ) throw new RuntimeException( 'Unknown MailDesk background job type.' );
        $payload = json_decode( $job["payload_json"], true, 512, JSON_THROW_ON_ERROR ); $id = (int) $payload["account_id"];
        $this->locked( "account:" . $id, function () use ( $id, $payload, $job ) {
            $account = $this->repo->account( $id ); if ( ! $account ) return;
            if ( ! user_can( (int) $account['owner_user_id'], 'wpmd_access_mail' ) || ! user_can( (int) $account['owner_user_id'], 'wpmd_read_mail' ) ) throw new RuntimeException( 'The account owner no longer has mail access.' );
            try {
                if ( $job["type"] === "discover" ) {
                    $folders = $this->imap->folders( $account ); $ids = array();
                    foreach ( $folders as $folder ) {
                        $folder_id = $this->repo->upsert_folder( $id, $folder );
                        $ids[] = $folder_id;
                        $this->repo->enqueue_job( "sync_folder", array( "account_id" => $id, "folder_id" => $folder_id ) );
                    }
                    $this->repo->reconcile_folders( $id, $ids );
                    $this->repo->update_account( $id, array( "last_sync_at" => current_time( "mysql", true ), "status" => "syncing", "last_error" => null ) );
                } elseif ( $job["type"] === "sync_folder" ) {
                    $folder = $this->repo->folder( (int) $payload["folder_id"] ); if ( ! $folder || (int) $folder["account_id"] !== $id ) return;
                    $count = 0;
                    $result = $this->imap->fetchRecent( $account, $folder["remote_name"], (int) $account["sync_days"], 250,
                        function ( $record, $status ) use ( $id, $folder, $account, &$count ) {
                            $message_id = $this->repo->upsert_message( $id, (int) $folder["id"], (int) $status["uidvalidity"], (int) $record["uid"], $record["message"] );
                            if ( isset( $record["message"]["subject"] ) ) $this->applyRules( $account, $folder, $status, $record, $message_id );
                            $count++;
                        }, fn( $validity ) => $this->repo->cached_uids( (int) $folder["id"], $validity ) );
                    if ( ! $result["complete"] ) throw new RuntimeException( "Folder sync reached its time budget; cached progress will resume." );
                    $this->repo->finish_folder( (int) $folder["id"], $result["status"], $result["uids"] );
                    $this->repo->update_account( $id, array( "last_sync_at" => current_time( "mysql", true ), "status" => "synced", "last_error" => null ) );
                    $this->repo->log( "sync_folder", $id, null, "success", array( "folder_id" => $folder["id"], "messages" => $count ) );
                }
            } catch ( \Throwable $e ) {
                $this->repo->update_account( $id, array( "status" => "error", "last_error" => $e->getMessage(), "last_sync_at" => current_time( "mysql", true ) ) ); throw $e;
            }
        } );
    }
    public function setMessageState( int $id, int $user, array $fields ): bool {
        $message = $this->repo->message( $id, $user );
        if ( ! $message || ! $this->repo->user_can_access_account( $user, (int) $message["account_id"], "write" ) ) throw new RuntimeException( "Message is not writable by this user." );
        return $this->locked( "account:" . $message["account_id"], function () use ( $id, $user, $fields, $message ) {
            $mapping = $this->repo->message_mapping( $id ); if ( ! $mapping ) throw new RuntimeException( "Message is no longer available. Sync the account." );
            $this->imap->setFlags( $this->repo->account( (int) $message["account_id"] ), $mapping["remote_name"], (int) $mapping["uidvalidity"], (int) $mapping["remote_uid"], $fields );
            return $this->repo->set_message_state( $id, $user, $fields );
        } );
    }
    private function applyRules( array $account, array $folder, array $status, array $record, int $message_id ): void {
        $owner = (int) $account["owner_user_id"]; $fields = array();
        foreach ( $this->repo->simple_list( "rules", $owner ) as $rule ) {
            if ( ! $rule["enabled"] || ( $rule["account_id"] && (int) $rule["account_id"] !== (int) $account["id"] ) ) continue;
            $conditions = json_decode( $rule["conditions_json"], true );
            $actions = json_decode( $rule['actions_json'], true );
            if ( ! is_array( $conditions ) || ! $conditions || ! is_array( $actions ) ) continue;
            $matches = true;
            foreach ( $conditions as $condition ) {
                if ( ! is_array( $condition ) || ! in_array( $condition['field'] ?? '', array( 'from', 'subject' ), true ) || ! is_string( $condition['value'] ?? null ) || trim( $condition['value'] ) === '' ) { $matches = false; break; }
                $key = ( $condition["field"] ?? "" ) === "from" ? "from_json" : "subject";
                $position = function_exists( 'mb_stripos' ) ? mb_stripos( $record['message'][$key] ?? '', $condition['value'], 0, 'UTF-8' ) : stripos( $record['message'][$key] ?? '', $condition['value'] );
                if ( false === $position ) $matches = false;
            }
            if ( $matches ) $fields = array_merge( $fields, array_filter( array_intersect_key( $actions, array_flip( array( "is_read", "is_starred" ) ) ), 'is_bool' ) );
        }
        if ( $fields ) {
            // A second connection would close the active fetch stream. Defer changes as separate jobs.
            $this->repo->enqueue_job( "message_state", array( "account_id" => (int) $account["id"], "message_id" => $message_id, "user_id" => $owner, "fields" => $fields ) );
        }
    }
    public function moveMessage( int $id, int $user, int $target_id ): bool {
        $message = $this->repo->message( $id, $user );
        if ( ! $message || ! $this->repo->user_can_access_account( $user, (int) $message['account_id'], 'delete' ) ) throw new RuntimeException( 'Message is not movable by this user.' );
        $target = $this->repo->folder( $target_id );
        if ( ! $target || (int) $target['account_id'] !== (int) $message['account_id'] ) throw new RuntimeException( 'Choose a destination in the same account.' );
        return $this->locked( 'account:' . $message['account_id'], function () use ( $id, $message, $target ) {
            $source = $this->repo->message_mapping( $id );
            if ( ! $source ) throw new RuntimeException( 'Message is no longer available.' );
            if ( (int) $source['folder_id'] === (int) $target['id'] ) return true;
            try {
                $this->imap->move( $this->repo->account( (int) $message['account_id'] ), $source['remote_name'], $target['remote_name'], (int) $source['uidvalidity'], (int) $source['remote_uid'] );
                $this->repo->forget_message( $id );
            } finally {
                foreach ( array( $source['folder_id'], $target['id'] ) as $folder ) $this->repo->enqueue_job( 'sync_folder', array( 'account_id' => (int) $message['account_id'], 'folder_id' => (int) $folder ) );
            }
            return true;
        } );
    }
    public function runStateJob( array $job ): void {
        $p = json_decode( $job["payload_json"], true, 512, JSON_THROW_ON_ERROR );
        if ( ! user_can( (int) $p["user_id"], "wpmd_access_mail" ) || ! user_can( (int) $p["user_id"], "wpmd_read_mail" ) || ! user_can( (int) $p["user_id"], "wpmd_manage_rules" ) ) throw new RuntimeException( "Rule owner no longer has permission." );
        $this->setMessageState( (int) $p["message_id"], (int) $p["user_id"], $p["fields"] );
    }

    public function queueSend( int $account, int $user, array $input ): int {
        if ( ! $this->repo->user_can_access_account( $user, $account, "send" ) ) throw new RuntimeException( "Account not accessible for sending." );
        $uuid = $input["request_id"] ?? wp_generate_uuid4();
        if ( ! is_string( $uuid ) || ! preg_match( "/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i", $uuid ) ) throw new RuntimeException( "Invalid request ID." );
        $payload = $this->composePayload( $input );
        if ( ! $payload["to"] && ! $payload["cc"] && ! $payload["bcc"] ) throw new RuntimeException( "At least one recipient is required." );
        $time = time();
        if ( ! empty( $input["scheduled_at"] ) ) {
            if ( ! is_string( $input["scheduled_at"] ) || ! preg_match( "/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})$/", $input["scheduled_at"] ) || false === ( $time = strtotime( $input["scheduled_at"] ) ) ) throw new RuntimeException( "Schedule must be an ISO 8601 date with a timezone." );
            $parsed_date = date_parse( $input['scheduled_at'] );
            if ( $parsed_date['warning_count'] || $parsed_date['error_count'] ) throw new RuntimeException( 'Scheduled date is invalid.' );
            if ( $time < time() - 60 ) throw new RuntimeException( "Scheduled time is in the past." );
        }
        return $this->locked( "account:" . $account, function () use ( $account, $user, $input, $uuid, $payload, $time ) {
            $existing = $this->repo->outbox_by_uuid( $uuid, $user, $account );
            if ( $existing ) {
                if ( json_decode( $existing['payload_json'], true ) !== $payload || ( ! empty( $input['scheduled_at'] ) && $existing['scheduled_at'] !== gmdate( 'Y-m-d H:i:s', $time ) ) ) throw new RuntimeException( 'This request already queued different content. Check Outbox and start a new message if needed.' );
                return (int) $existing['id'];
            }
            $limit = max( 0, (int) apply_filters( "wpmd_send_rate_limit_per_hour", 100, $user, $account ) );
            if ( $this->repo->sent_count( $user, $account ) >= $limit ) throw new RuntimeException( "Hourly sending limit reached." );
            return $this->repo->transaction( function () use ( $account, $user, $input, $uuid, $payload, $time ) {
                if ( ! empty( $input["draft_id"] ) ) {
                    $draft = null;
                    foreach ( $this->repo->drafts( $user ) as $row ) if ( (int) $row["id"] === (int) $input["draft_id"] ) $draft = $row;
                    if ( ! $draft || (int) $draft["version"] !== (int) ( $input["draft_version"] ?? 0 ) ) throw new RuntimeException( "Draft changed. Reopen it before sending." );
                    $this->repo->consume_draft( (int) $draft["id"], $user, (int) $input['draft_version'] );
                }
                $host = wp_parse_url( home_url(), PHP_URL_HOST ) ?: "wordpress.local";
                return $this->repo->queue_outbox( $account, $user, $payload, gmdate( "Y-m-d H:i:s", $time ), $uuid . "@" . $host, $uuid );
            } );
        } );
    }
    public function composePayload( array $input ): array {
        $payload = array();
        foreach ( array( "to", "cc", "bcc", "reply_to" ) as $field ) $payload[$field] = $this->addresses( $input[$field] ?? array() );
        if ( count( $payload["to"] ) + count( $payload["cc"] ) + count( $payload["bcc"] ) > 100 ) throw new RuntimeException( "A message can have at most 100 recipients." );
        foreach ( array( "subject", "body_html", "body_text", "in_reply_to", "references" ) as $field ) {
            if ( isset( $input[$field] ) && ! is_string( $input[$field] ) ) throw new RuntimeException( "Invalid message field." );
        }
        if ( strlen( $input["body_html"] ?? "" ) + strlen( $input["body_text"] ?? "" ) > 1048576 ) throw new RuntimeException( "Message body exceeds 1 MiB." );
        $payload["subject"] = sanitize_text_field( $input["subject"] ?? "" );
        $payload["body_html"] = wp_kses_post( $input["body_html"] ?? "" );
        $payload["body_text"] = wp_check_invalid_utf8( str_replace( array( "\r\n", "\r", "\0" ), array( "\n", "\n", '' ), $input["body_text"] ?? "" ), true );
        foreach ( array( "in_reply_to", "references" ) as $field ) {
            $value = $input[$field] ?? "";
            if ( preg_match( "/[\r\n\x00]/", $value ) || strlen( $value ) > 2000 ) throw new RuntimeException( "Invalid reply header." );
            $payload[$field] = $value;
        }
        $payload["attachments"] = array(); $size = 0;
        if ( isset( $input["attachments"] ) && ! is_array( $input["attachments"] ) ) throw new RuntimeException( "Invalid attachments." );
        foreach ( $input["attachments"] ?? array() as $file ) {
            if ( ! is_array( $file ) || ! isset( $file["filename"], $file["content_base64"] ) || ! is_string( $file["content_base64"] ) ) throw new RuntimeException( "Invalid attachment." );
            if ( strlen( $file["content_base64"] ) > 14000000 ) throw new RuntimeException( "Attachment exceeds the 10 MiB limit." );
            $bytes = base64_decode( $file["content_base64"], true ); if ( false === $bytes ) throw new RuntimeException( "Invalid attachment data." );
            $size += strlen( $bytes ); if ( $size > 10485760 || count( $payload["attachments"] ) >= 20 ) throw new RuntimeException( "Attachments are limited to 20 files and 10 MiB total." );
            $payload["attachments"][] = array( "filename" => sanitize_file_name( $file["filename"] ) ?: "attachment", "content_base64" => base64_encode( $bytes ) );
        }
        return $payload;
    }
    public function sendOutbox( array $row ): void {
        $account_id = (int) $row["account_id"];
        $this->locked( "account:" . $account_id, function () use ( $row, $account_id ) {
            if ( ! $this->repo->claim_outbox( (int) $row["id"] ) ) return;
            $attempts = (int) $row["attempts"] + 1;
            $user = (int) $row["user_id"];
            if ( ! user_can( $user, "wpmd_access_mail" ) || ! user_can( $user, "wpmd_send_mail" ) || ! $this->repo->user_can_access_account( $user, $account_id, "send" ) ) {
                $this->repo->update_outbox( (int) $row["id"], array( "status" => "failed", "last_error" => "Sending permission was revoked or the account was removed." ) ); return;
            }
            try {
                $payload = json_decode( $row["payload_json"], true, 512, JSON_THROW_ON_ERROR );
                $this->smtp->send( $this->repo->account( $account_id ), $payload, $row["message_id_header"] );
            } catch ( \Throwable $e ) {
                $uncertain = $e instanceof DeliveryUncertain;
                $status = $uncertain ? "uncertain" : ( $attempts >= 5 ? "failed" : "retrying" );
                $this->repo->update_outbox( (int) $row["id"], array( "status" => $status, "last_error" => $e->getMessage(), "scheduled_at" => gmdate( "Y-m-d H:i:s", time() + min( 3600, 60 * ( 2 ** ( $attempts - 1 ) ) ) ) ) );
                $this->repo->log( "send_mail", $account_id, null, $status, array( "outbox_id" => (int) $row["id"] ) ); return;
            }
            // A database/log failure after SMTP success must never schedule a resend.
            $this->repo->update_outbox( (int) $row["id"], array( "status" => "sent", "sent_at" => current_time( "mysql", true ), "last_error" => null ) );
            $this->repo->log( "send_mail", $account_id, null, "success", array( "outbox_id" => (int) $row["id"] ) );
        } );
    }
    private function addresses( $input ): array {
        if ( is_string( $input ) ) $input = preg_split( "/[,;]+/", $input );
        if ( ! is_array( $input ) ) throw new RuntimeException( "Recipients must be a list of email addresses." );
        $out = array();
        foreach ( $input as $address ) {
            if ( is_string( $address ) ) { $email = trim( $address ); $name = ""; }
            elseif ( is_array( $address ) ) { $email = trim( (string) ( $address["email"] ?? "" ) ); $name = sanitize_text_field( $address["name"] ?? "" ); }
            else throw new RuntimeException( "Invalid recipient." );
            if ( $email === "" ) continue;
            if ( ! is_email( $email ) ) throw new RuntimeException( "Invalid recipient email address: " . sanitize_text_field( $email ) );
            $out[strtolower( $email )] = array( "email" => $email, "name" => $name );
        }
        return array_values( $out );
    }
    private function locked( string $key, callable $action ) {
        $token = $this->repo->acquire_lock( $key ); if ( ! $token ) throw new RuntimeException( "This account is busy. Please try again shortly." );
        try { return $action(); } finally { $this->repo->release_lock( $key, $token ); }
    }
}
