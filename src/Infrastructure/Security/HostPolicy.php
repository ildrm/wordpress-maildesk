<?php
namespace WPMailDesk\Infrastructure\Security;

use RuntimeException;

/** Validate and pin the destination before every connection, including queued work. */
final class HostPolicy {
    public static function resolve( string $host, string $kind ): string {
        $host = trim( $host );
        if ( ! filter_var( $host, FILTER_VALIDATE_IP ) && ! filter_var( $host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME ) ) {
            throw new RuntimeException( $kind . ' host must be a hostname or IP address, without a scheme or port.' );
        }
        $allow_private = (bool) apply_filters( 'wpmd_allow_private_mail_hosts', false, $host, $kind );
        $ips = filter_var( $host, FILTER_VALIDATE_IP ) ? array( $host ) : array();
        if ( ! $ips ) {
            foreach ( (array) @dns_get_record( $host, DNS_A | DNS_AAAA ) as $record ) {
                $ip = $record['ip'] ?? $record['ipv6'] ?? null;
                if ( $ip ) $ips[] = $ip;
            }
        }
        if ( ! $ips ) throw new RuntimeException( $kind . ' host could not be resolved.' );
        foreach ( $ips as $ip ) {
            // IPv4-mapped IPv6 must be checked as IPv4 as well.
            $packed = @inet_pton( $ip );
            $check = $packed && strlen( $packed ) === 16 && substr( $packed, 0, 12 ) === str_repeat( "\0", 10 ) . "\xff\xff"
                ? inet_ntop( substr( $packed, 12 ) ) : $ip;
            if ( ! $allow_private && ! self::isPublic( $check ) ) {
                throw new RuntimeException( $kind . ' host resolves to a private or reserved address.' );
            }
        }
        return $ips[0];
    }

    private static function isPublic( string $ip ): bool {
        if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) return false;
        if ( str_contains( $ip, ':' ) ) {
            $packed = inet_pton( $ip );
            // Global unicast only; exclude documentation and transition mechanisms.
            return ( ord( $packed[0] ) & 0xe0 ) === 0x20
                && substr( $packed, 0, 4 ) !== hex2bin( '20010db8' )
                && substr( $packed, 0, 2 ) !== hex2bin( '2002' )
                && ! ( substr( $packed, 0, 2 ) === hex2bin( '2001' ) && ord( $packed[2] ) < 2 );
        }
        $value = ip2long( $ip );
        foreach ( array( '0.0.0.0/8', '100.64.0.0/10', '192.0.0.0/24', '192.0.2.0/24', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24', '224.0.0.0/4', '240.0.0.0/4' ) as $range ) {
            [ $network, $bits ] = explode( '/', $range ); $mask = -1 << ( 32 - (int) $bits );
            if ( ( $value & $mask ) === ( ip2long( $network ) & $mask ) ) return false;
        }
        return true;
    }

    public static function security( string $security, array $account ): void {
        if ( ! in_array( $security, array( 'ssl', 'tls', 'none' ), true ) ) throw new RuntimeException( 'Invalid transport security.' );
        if ( 'none' === $security && ! apply_filters( 'wpmd_allow_insecure_mail_transport', false, $account ) ) {
            throw new RuntimeException( 'Unencrypted mail transport is disabled.' );
        }
    }
}
