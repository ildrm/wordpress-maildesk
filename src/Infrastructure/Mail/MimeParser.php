<?php
namespace WPMailDesk\Infrastructure\Mail;

/** Bounded MIME decoding. Attachments are data, never filesystem paths. */
final class MimeParser {
    public function parse( string $raw, int $depth = 0, ?int &$budget = null ): array {
        $result = array( 'text' => '', 'html' => '', 'attachments' => array() );
        if ( $depth > 20 ) throw new \RuntimeException( 'Message MIME nesting exceeds the supported limit.' );
        $budget ??= 24 * 1048576;
        $budget -= strlen( $raw );
        if ( $budget < 0 ) throw new \RuntimeException( 'Message MIME complexity exceeds the supported limit.' );
        $parts = preg_split( '/\r?\n\r?\n/', $raw, 2 );
        $headers = $this->headers( $parts[0] );
        $body = $parts[1] ?? '';
        $type = $headers['content-type'] ?? 'text/plain';
        $media = strtolower( trim( explode( ';', $type )[0] ) );
        $disposition = $headers['content-disposition'] ?? '';
        $filename = $this->parameter( $disposition, 'filename' ) ?: $this->parameter( $type, 'name' );
        if ( str_starts_with( $media, 'multipart/' ) && ! $filename && ! preg_match( '/^attachment\b/i', $disposition ) ) {
            $boundary = $this->parameter( $type, 'boundary' );
            if ( ! $boundary ) throw new \RuntimeException( 'Multipart message has no boundary.' );
            preg_match_all( '/(?:^|\r?\n)--' . preg_quote( $boundary, '/' ) . '(--)?[ \t]*(?:\r?\n|$)/', $body, $matches, PREG_OFFSET_CAPTURE );
            if ( count( $matches[0] ) < 2 || ( end( $matches[1] )[0] ?? '' ) !== '--' ) throw new \RuntimeException( 'Multipart message is incomplete.' );
            for ( $i = 0; $i + 1 < count( $matches[0] ); $i++ ) {
                if ( ( $matches[1][$i][0] ?? '' ) === '--' ) break;
                $start = $matches[0][$i][1] + strlen( $matches[0][$i][0] );
                $child = $this->parse( substr( $body, $start, $matches[0][$i + 1][1] - $start ), $depth + 1, $budget );
                foreach ( array( 'text', 'html' ) as $format ) {
                    if ( $child[$format] !== '' ) $result[$format] .= ( $result[$format] !== '' ? "\n" : '' ) . $child[$format];
                }
                $result['attachments'] = array_merge( $result['attachments'], $child['attachments'] );
                if ( count( $result['attachments'] ) > 100 ) throw new \RuntimeException( 'Too many MIME attachments.' );
            }
            return $result;
        }
        $encoding = strtolower( trim( $headers['content-transfer-encoding'] ?? '' ) );
        if ( 'base64' === $encoding ) {
            $decoded = base64_decode( preg_replace( '/\s+/', '', $body ), true );
            if ( false === $decoded ) throw new \RuntimeException( 'Invalid base64 MIME content.' );
            $body = $decoded;
        } elseif ( 'quoted-printable' === $encoding ) $body = quoted_printable_decode( $body );
        if ( $filename || preg_match( '/^attachment\b/i', $disposition ) || ! in_array( $media, array( 'text/plain', 'text/html' ), true ) ) {
            $result['attachments'][] = array(
                'filename' => sanitize_file_name( $this->decodeHeader( $filename ?: 'attachment' ) ),
                'mime_type' => $media, 'size_bytes' => strlen( $body ),
                'content_id' => trim( $headers['content-id'] ?? '', '<> ' ),
                'disposition' => preg_match( '/^inline\b/i', $disposition ) ? 'inline' : 'attachment',
                'checksum' => hash( 'sha256', $body ), 'content_base64' => base64_encode( $body ), 'cache_status' => 'cached',
            );
        } else $result[ 'text/html' === $media ? 'html' : 'text' ] = $this->utf8( $body, $this->parameter( $type, 'charset' ) ?: 'UTF-8' );
        return $result;
    }

    public function headers( string $raw ): array {
        $raw = preg_replace( "/\r?\n[ \t]+/", ' ', $raw ); $headers = array();
        foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
            $colon = strpos( $line, ':' );
            if ( false !== $colon ) $headers[ strtolower( trim( substr( $line, 0, $colon ) ) ) ] = trim( substr( $line, $colon + 1 ) );
        }
        return $headers;
    }

    public function decodeHeader( string $value ): string {
        if ( function_exists( 'iconv_mime_decode' ) && preg_match( '/=\?[^?]+\?[bq]\?/i', $value ) ) {
            $decoded = @iconv_mime_decode( $value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8' );
            if ( false !== $decoded ) return $decoded;
        }
        return $this->utf8( $value, 'UTF-8' );
    }

    public function addresses( string $value ): array {
        $result = array(); $parts = array(); $part = ''; $quoted = false; $escaped = false;
        foreach ( str_split( $value ) as $char ) {
            if ( ',' === $char && ! $quoted ) { $parts[] = $part; $part = ''; continue; }
            $part .= $char;
            if ( '"' === $char && ! $escaped ) $quoted = ! $quoted;
            $escaped = '\\' === $char && ! $escaped;
        }
        $parts[] = $part;
        foreach ( $parts as $part ) {
            $name = '';
            if ( preg_match( '/^(.*)<([^>]+)>\s*$/s', trim( $part ), $match ) ) {
                $name = $this->decodeHeader( trim( $match[1], " \t\r\n\"" ) ); $part = $match[2];
            }
            $email = trim( $part );
            if ( is_email( $email ) ) $result[] = array( 'email' => $email, 'name' => $name );
        }
        return $result;
    }

    private function parameter( string $value, string $name ): string {
        if ( preg_match( '/;\s*' . preg_quote( $name, '/' ) . '\*=([^;]+)/i', $value, $match ) ) {
            $parts = explode( "'", trim( $match[1], " \t\"" ), 3 );
            return count( $parts ) === 3 ? $this->utf8( rawurldecode( $parts[2] ), $parts[0] ?: 'UTF-8' ) : rawurldecode( $parts[0] );
        }
        if ( preg_match( '/;\s*' . preg_quote( $name, '/' ) . '\s*=\s*(?:"((?:\\\\.|[^"\\\\])*)"|([^;\s]+))/i', $value, $match ) ) {
            return isset( $match[2] ) && $match[2] !== '' ? $match[2] : stripcslashes( $match[1] );
        }
        return '';
    }

    private function utf8( string $value, string $charset ): string {
        if ( function_exists( 'iconv' ) ) {
            $converted = @iconv( $charset, 'UTF-8//IGNORE', $value );
            if ( false !== $converted ) return $converted;
        }
        return wp_check_invalid_utf8( $value, true );
    }
}
