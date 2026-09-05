<?php
namespace WPMailDesk\Infrastructure\Mail;

use RuntimeException;
use WPMailDesk\Infrastructure\Security\Crypto;
use WPMailDesk\Infrastructure\Security\HostPolicy;

final class ImapClient {
    public const MAX_MESSAGE_BYTES = 10485760;
    private $stream = null;
    private int $tag = 0;
    private array $lastSelectStatus = array();
    public function __construct( private Crypto $crypto ) {}

    public function test( array $account ): array {
        try { $this->connect( $account ); $this->command( 'CAPABILITY' ); return array( 'ok' => true ); }
        catch ( \Throwable $e ) { return array( 'ok' => false, 'error' => $e->getMessage() ); }
        finally { $this->disconnect(); }
    }

    public function folders( array $account ): array {
        try {
            $this->connect( $account ); $tokens = $this->command( 'LIST "" "*"' ); $out = array();
            foreach ( $tokens as $i => $token ) {
                if ( ! isset( $token['line'] ) || ! preg_match( '/^\* LIST \(([^)]*)\)\s+("(?:\\\\.|[^"\\\\])*"|NIL)\s+(.+)$/i', $token['line'], $m ) ) continue;
                $flags = array_map( 'strtolower', preg_split( '/\s+/', trim( $m[1] ) ) );
                if ( in_array( '\\noselect', $flags, true ) ) continue;
                $name = preg_match( '/^\{\d+\}$/', $m[3] ) ? ( $tokens[$i + 1]['literal'] ?? '' ) : $this->unquote( $m[3] );
                if ( '' === $name ) continue;
                $special = null;
                foreach ( array( '\\Sent', '\\Drafts', '\\Trash', '\\Junk', '\\Archive', '\\All', '\\Flagged' ) as $flag ) {
                    if ( in_array( strtolower( $flag ), $flags, true ) ) $special = $flag;
                }
                if ( 'INBOX' === strtoupper( $name ) ) $special = '\\Inbox';
                $display = function_exists( 'mb_convert_encoding' ) ? mb_convert_encoding( $name, 'UTF-8', 'UTF7-IMAP' ) : $name;
                $out[] = array( 'remote_name' => $name, 'display_name' => $display, 'delimiter' => 'NIL' === strtoupper( $m[2] ) ? null : $this->unquote( $m[2] ), 'special_use' => $special );
            }
            return $out;
        } finally { $this->disconnect(); }
    }

    public function fetchRecent( array $account, string $folder, int $days = 30, int $limit = 250, ?callable $consume = null, ?callable $cached = null ): array {
        try {
            $this->connect( $account ); $this->select( $folder, false ); $status = $this->lastSelectStatus;
            $since = gmdate( 'd-M-Y', time() - DAY_IN_SECONDS * max( 1, $days ) ); $uids = array();
            foreach ( $this->command( 'UID SEARCH SINCE ' . $since ) as $token ) {
                if ( isset( $token['line'] ) && preg_match( '/^\* SEARCH(?: (.*))?$/i', $token['line'], $m ) ) $uids = array_values( array_filter( array_map( 'intval', preg_split( '/\s+/', $m[1] ?? '' ) ) ) );
            }
            sort( $uids, SORT_NUMERIC ); $uids = array_slice( $uids, -max( 1, min( 250, $limit ) ) ); $messages = array();
            $known = $cached ? array_flip( $cached( (int) $status['uidvalidity'] ) ) : array();
            $fetch_uids = $uids;
            // Cache uncached messages first so a slow folder can make progress on retry.
            usort( $fetch_uids, static fn( $a, $b ) => ( isset( $known[$a] ) <=> isset( $known[$b] ) ) ?: ( $b <=> $a ) );
            $started = microtime( true ); $complete = true;
            foreach ( $fetch_uids as $uid ) {
                if ( microtime( true ) - $started > 45 ) { $complete = false; break; }
                $metadata = $this->parseFetch( $this->command( 'UID FETCH ' . $uid . ' (UID FLAGS RFC822.SIZE INTERNALDATE)' ) );
                if ( ! $metadata ) continue;
                if ( isset( $known[$uid] ) && $consume ) {
                    $flags = $metadata[0]['flags'];
                    $consume( array( 'uid' => $uid, 'message' => array( '_flags' => $flags, 'is_read' => in_array( '\\Seen', $flags, true ) ? 1 : 0, 'is_starred' => in_array( '\\Flagged', $flags, true ) ? 1 : 0 ) ), $status );
                    continue;
                }
                $large = $metadata[0]['size'] > self::MAX_MESSAGE_BYTES;
                $records = $this->parseFetch( $this->command( 'UID FETCH ' . $uid . ' (UID FLAGS RFC822.SIZE INTERNALDATE BODY.PEEK[' . ( $large ? 'HEADER' : '' ) . '])' ) );
                if ( $records ) {
                    $normalized = $this->normalizeMessage( $records[0], $large );
                    if ( $consume ) $consume( $normalized, $status ); else $messages[] = $normalized;
                }
            }
            return array( 'status' => $status, 'messages' => $messages, 'uids' => $uids, 'complete' => $complete );
        } finally { $this->disconnect(); }
    }

    public function setFlags( array $account, string $folder, int $uidvalidity, int $uid, array $fields ): void {
        try {
            $this->connect( $account ); $this->select( $folder, true );
            if ( $uidvalidity !== (int) $this->lastSelectStatus['uidvalidity'] ) throw new RuntimeException( 'Mailbox identity changed. Sync before modifying this message.' );
            if ( ! $this->parseFetch( $this->command( 'UID FETCH ' . $uid . ' (UID FLAGS)' ) ) ) throw new RuntimeException( 'Message no longer exists in this folder. Sync the account.' );
            foreach ( array( 'is_read' => '\\Seen', 'is_starred' => '\\Flagged' ) as $field => $flag ) {
                if ( array_key_exists( $field, $fields ) ) $this->command( 'UID STORE ' . $uid . ( $fields[$field] ? ' +FLAGS.SILENT (' : ' -FLAGS.SILENT (' ) . $flag . ')' );
            }
        } finally { $this->disconnect(); }
    }

    private function select( string $folder, bool $write ): void {
        $this->command( ( $write ? 'SELECT ' : 'EXAMINE ' ) . $this->quote( $folder ) );
        if ( empty( $this->lastSelectStatus['uidvalidity'] ) ) throw new RuntimeException( 'IMAP server omitted UIDVALIDITY.' );
    }

    public function move( array $account, string $source, string $target, int $validity, int $uid ): void {
        try {
            $this->connect( $account );
            $capabilities = implode( ' ', array_column( $this->command( 'CAPABILITY' ), 'line' ) );
            if ( ! preg_match( '/\bMOVE\b|\bIMAP4rev2\b/i', $capabilities ) ) throw new RuntimeException( 'This server does not support safe UID MOVE. Move the message in your provider client.' );
            $this->select( $source, true );
            if ( (int) $this->lastSelectStatus['uidvalidity'] !== $validity ) throw new RuntimeException( 'Mailbox identity changed. Sync before moving this message.' );
            if ( ! $this->parseFetch( $this->command( 'UID FETCH ' . $uid . ' (UID FLAGS)' ) ) ) throw new RuntimeException( 'Message no longer exists. Sync the account.' );
            $this->command( 'UID MOVE ' . $uid . ' ' . $this->quote( $target ) );
        } finally { $this->disconnect(); }
    }

    private function connect( array $a ): void {
        $this->disconnect(); $host = (string) $a['imap_host']; $ip = HostPolicy::resolve( $host, 'IMAP' );
        $security = (string) $a['imap_security']; HostPolicy::security( $security, $a );
        $ctx = stream_context_create( array( 'ssl' => array( 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $host, 'SNI_enabled' => true ) ) );
        $destination = ( 'ssl' === $security ? 'ssl://' : 'tcp://' ) . ( str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip ) . ':' . (int) $a['imap_port'];
        $this->stream = @stream_socket_client( $destination, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx );
        if ( ! $this->stream ) throw new RuntimeException( 'IMAP connection failed. Check the host, port and TLS certificate.' );
        stream_set_timeout( $this->stream, 20 );
        if ( ! preg_match( '/^\* OK\b/i', $this->readLine() ) ) throw new RuntimeException( 'Unexpected IMAP greeting.' );
        if ( 'tls' === $security ) {
            $this->command( 'STARTTLS' );
            if ( true !== stream_socket_enable_crypto( $this->stream, true, STREAM_CRYPTO_METHOD_TLS_CLIENT ) ) throw new RuntimeException( 'Unable to enable IMAP TLS.' );
        }
        $username = (string) ( $a['username'] ?: $a['email'] );
        if ( 'oauth' === $a['auth_type'] ) {
            $token = apply_filters( 'wpmd_oauth_access_token', $this->crypto->decrypt( $a['oauth_access_enc'] ?? null ), $a );
            if ( ! is_string( $token ) || '' === $token ) throw new RuntimeException( 'OAuth access token unavailable; reconnect through the provider adapter.' );
            $this->command( 'AUTHENTICATE XOAUTH2', base64_encode( "user={$username}\x01auth=Bearer {$token}\x01\x01" ) );
        } else {
            $secret = $this->crypto->decrypt( $a['secret_enc'] ?? null );
            if ( null === $secret ) throw new RuntimeException( 'Account password or app password is unavailable.' );
            $this->command( 'LOGIN ' . $this->quote( $username ) . ' ' . $this->quote( $secret ) );
        }
    }

    private function disconnect(): void {
        if ( is_resource( $this->stream ) ) fclose( $this->stream );
        $this->stream = null;
    }

    private function command( string $command, ?string $continuation = null ): array {
        if ( ! is_resource( $this->stream ) ) throw new RuntimeException( 'IMAP not connected.' );
        $tag = 'A' . str_pad( (string) ++$this->tag, 4, '0', STR_PAD_LEFT ); $this->write( $tag . ' ' . $command . "\r\n" );
        $tokens = array(); $this->lastSelectStatus = array(); $bytes = 0; $deadline = microtime( true ) + 60;
        while ( microtime( true ) < $deadline ) {
            $line = $this->readLine(); $bytes += strlen( $line );
            if ( $bytes > self::MAX_MESSAGE_BYTES + 1048576 ) throw new RuntimeException( 'IMAP response exceeds the supported size.' );
            if ( str_starts_with( $line, '+' ) ) { $this->write( ( $continuation ?? '' ) . "\r\n" ); $continuation = null; continue; }
            $tokens[] = array( 'line' => $line );
            foreach ( array( 'UIDVALIDITY', 'UIDNEXT' ) as $field ) {
                if ( preg_match( '/\[' . $field . '\s+(\d+)\]/i', $line, $m ) ) $this->lastSelectStatus[ strtolower( $field ) ] = (int) $m[1];
            }
            if ( str_starts_with( $line, $tag . ' ' ) ) {
                if ( ! preg_match( '/^' . $tag . ' OK\b/i', $line ) ) throw new RuntimeException( 'IMAP ' . explode( ' ', $command )[0] . ' failed. Check credentials, mailbox and server permissions.' );
                return $tokens;
            }
            if ( preg_match( '/\{(\d+)\}$/', $line, $m ) ) {
                $length = (int) $m[1];
                if ( $length > self::MAX_MESSAGE_BYTES || $bytes + $length > self::MAX_MESSAGE_BYTES + 1048576 ) throw new RuntimeException( 'IMAP literal exceeds the supported size.' );
                $literal = '';
                while ( strlen( $literal ) < $length ) {
                    if ( microtime( true ) >= $deadline ) throw new RuntimeException( 'IMAP response timed out.' );
                    $chunk = fread( $this->stream, min( 65536, $length - strlen( $literal ) ) );
                    if ( false === $chunk || '' === $chunk ) throw new RuntimeException( 'IMAP literal was interrupted or timed out.' );
                    $literal .= $chunk;
                }
                $bytes += $length; $tokens[] = array( 'literal' => $literal );
            }
        }
        throw new RuntimeException( 'IMAP response timed out.' );
    }

    private function write( string $data ): void {
        while ( '' !== $data ) {
            $written = fwrite( $this->stream, $data );
            if ( false === $written || 0 === $written ) throw new RuntimeException( 'IMAP write failed.' );
            $data = substr( $data, $written );
        }
    }
    private function readLine(): string {
        $line = fgets( $this->stream, 1048576 );
        if ( false === $line || ! str_ends_with( $line, "\n" ) ) throw new RuntimeException( 'IMAP response was interrupted, timed out or exceeded the line limit.' );
        return rtrim( $line, "\r\n" );
    }
    private function quote( string $value ): string {
        if ( preg_match( '/[\r\n\x00]/', $value ) ) throw new RuntimeException( 'Invalid IMAP string.' );
        return '"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $value ) . '"';
    }
    private function unquote( string $value ): string {
        return str_starts_with( $value, '"' ) ? preg_replace( '/\\\\(["\\\\])/', '$1', substr( $value, 1, -1 ) ) : $value;
    }

    private function parseFetch( array $tokens ): array {
        $records = array(); $current = null;
        foreach ( $tokens as $token ) {
            if ( isset( $token['literal'] ) ) { if ( null !== $current ) $current['raw'] = $token['literal']; continue; }
            $line = $token['line'];
            if ( preg_match( '/^\* \d+ FETCH \(/i', $line ) ) {
                if ( $current && $current['uid'] ) $records[] = $current;
                $current = array( 'uid' => 0, 'flags' => array(), 'size' => 0, 'raw' => '', 'date' => '' );
            }
            if ( null === $current ) continue;
            if ( preg_match( '/\bUID\s+(\d+)/i', $line, $m ) ) $current['uid'] = (int) $m[1];
            if ( preg_match( '/\bRFC822\.SIZE\s+(\d+)/i', $line, $m ) ) $current['size'] = (int) $m[1];
            if ( preg_match( '/\bFLAGS\s+\(([^)]*)\)/i', $line, $m ) ) {
                $current['flags'] = array_map( static fn( $flag ) => match ( strtolower( $flag ) ) { '\\seen' => '\\Seen', '\\flagged' => '\\Flagged', default => $flag }, preg_split( '/\s+/', trim( $m[1] ), -1, PREG_SPLIT_NO_EMPTY ) );
            }
            if ( preg_match( '/\bINTERNALDATE\s+"([^"]+)"/i', $line, $m ) ) $current['date'] = $m[1];
        }
        if ( $current && $current['uid'] ) $records[] = $current;
        return $records;
    }

    private function normalizeMessage( array $record, bool $large ): array {
        $parser = new MimeParser(); $headers = $parser->headers( preg_split( '/\r?\n\r?\n/', $record['raw'], 2 )[0] );
        try {
            $mime = $large ? array( 'text' => 'This message exceeds the 10 MiB cache limit. Open it in your provider mail client to read the full message and attachments.', 'html' => '', 'attachments' => array() ) : $parser->parse( $record['raw'] );
        } catch ( RuntimeException $e ) {
            // One malformed or adversarial message must not block an entire mailbox.
            $mime = array( 'text' => 'This message could not be safely decoded. Open it in your provider mail client. ' . $e->getMessage(), 'html' => '', 'attachments' => array() );
        }
        $text = $mime['text'] ?: html_entity_decode( wp_strip_all_tags( $mime['html'] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $subject = $parser->decodeHeader( $headers['subject'] ?? '' ); $received = strtotime( $record['date'] ) ?: time(); $sent = strtotime( $headers['date'] ?? '' ) ?: $received;
        $preview = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
        $message = array(
            'internet_message_id' => substr( trim( $headers['message-id'] ?? '', '<> ' ), 0, 500 ),
            'subject' => $subject, 'normalized_subject' => function_exists( 'mb_substr' ) ? mb_substr( preg_replace( '/^(?:(?:re|fwd?|aw):\s*)+/i', '', $subject ), 0, 500 ) : substr( $subject, 0, 500 ),
            'headers_json' => wp_json_encode( array_intersect_key( $headers, array_flip( array( 'message-id', 'in-reply-to', 'references', 'date', 'content-type' ) ) ) ),
            'body_text' => $text, 'body_html' => $mime['html'], 'body_preview' => function_exists( 'mb_substr' ) ? mb_substr( $preview, 0, 280 ) : substr( $preview, 0, 280 ),
            'received_at' => gmdate( 'Y-m-d H:i:s', $received ), 'sent_at' => gmdate( 'Y-m-d H:i:s', $sent ),
            'size_bytes' => $record['size'], 'has_attachments' => $mime['attachments'] ? 1 : 0,
            'is_read' => in_array( '\\Seen', $record['flags'], true ) ? 1 : 0, 'is_starred' => in_array( '\\Flagged', $record['flags'], true ) ? 1 : 0,
            '_flags' => $record['flags'], '_attachments' => $mime['attachments'],
        );
        foreach ( array( 'from', 'to', 'cc', 'bcc', 'reply-to' ) as $field ) $message[ str_replace( '-', '_', $field ) . '_json' ] = wp_json_encode( $parser->addresses( $headers[$field] ?? '' ) );
        return array( 'uid' => $record['uid'], 'message' => $message );
    }
}
