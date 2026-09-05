<?php
namespace WPMailDesk\WordPress;

use WPMailDesk\Infrastructure\Database\Repository;

final class SiteHealth {
    public function __construct( private Repository $repo ) {}
    public function register(): void {
        add_filter( 'site_status_tests', function ( array $tests ): array {
            $tests['direct']['wpmd_crypto'] = array( 'label' => 'MailDesk encryption', 'test' => array( $this, 'crypto' ) );
            $tests['direct']['wpmd_cron'] = array( 'label' => 'MailDesk queue', 'test' => array( $this, 'cron' ) ); return $tests;
        } );
    }
    public function crypto(): array {
        $crypto = function_exists( 'sodium_crypto_secretbox' ) || ( function_exists( 'openssl_get_cipher_methods' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) );
        $tls = extension_loaded( 'openssl' ); $ok = $crypto && $tls;
        return $this->result( 'crypto', $ok ? 'MailDesk encryption and TLS are available' : 'MailDesk requires encryption and TLS support', $ok ? 'good' : 'critical', $ok ? 'Authenticated encryption and TLS are available.' : 'Enable OpenSSL for TLS and Sodium or AES-256-GCM for credential encryption.' );
    }
    public function cron(): array {
        $next = wp_next_scheduled( 'wpmd_queue_tick' ); $last = (int) get_option( 'wpmd_last_queue_run', 0 );
        $ok = $next && $last && time() - $last < 900;
        return $this->result( 'cron', $ok ? 'MailDesk background processing is active' : 'Check MailDesk background processing', $ok ? 'good' : 'recommended', $ok ? 'The queue has run within the last 15 minutes.' : 'A scheduled event alone does not prove delivery is running. Invoke WordPress cron regularly and check Outbox and Diagnostics.' );
    }
    private function result( string $name, string $label, string $status, string $description ): array {
        return array( 'label' => $label, 'status' => $status, 'badge' => array( 'label' => 'MailDesk', 'color' => 'blue' ), 'description' => '<p>' . esc_html( $description ) . '</p>', 'actions' => '', 'test' => 'wpmd_' . $name );
    }
}
