<?php
namespace WPMailDesk\Infrastructure\Mail;

use RuntimeException;
use WPMailDesk\Infrastructure\Security\Crypto;
use WPMailDesk\Infrastructure\Security\HostPolicy;

final class SmtpSender {
    public function __construct( private Crypto $crypto ) {}

    public function test( array $account ): array {
        $mail = null;
        try {
            $mail = $this->mailer( $account );
            if ( ! $mail->smtpConnect() ) throw new RuntimeException( 'SMTP authentication failed.' );
            return array( 'ok' => true );
        } catch ( \Throwable $e ) { return array( 'ok' => false, 'error' => 'SMTP connection or authentication failed. Check your host, port, TLS and credentials.' ); }
        finally { if ( $mail ) $mail->smtpClose(); }
    }

    public function send( array $account, array $payload, string $message_id ): void {
        $mail = $this->mailer( $account );
        try {
            $mail->setFrom( $account['email'], $account['display_name'] ?: $account['email'] );
            foreach ( array( 'to' => 'addAddress', 'cc' => 'addCC', 'bcc' => 'addBCC', 'reply_to' => 'addReplyTo' ) as $key => $method ) {
                foreach ( $payload[$key] ?? array() as $address ) $mail->$method( $address['email'], $address['name'] ?? '' );
            }
            if ( ! $mail->getAllRecipientAddresses() ) throw new RuntimeException( 'At least one valid recipient is required.' );
            $mail->Subject = $payload['subject'] ?? '';
            $mail->MessageID = '<' . trim( $message_id, '<>' ) . '>';
            $html = $payload['body_html'] ?? '';
            $text = ( $payload['body_text'] ?? '' ) ?: html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
            $mail->isHTML( '' !== $html ); $mail->Body = $html ?: $text; $mail->AltBody = $html ? $text : '';
            foreach ( array( 'in_reply_to' => 'In-Reply-To', 'references' => 'References' ) as $key => $header ) {
                if ( ! empty( $payload[$key] ) ) $mail->addCustomHeader( $header, $payload[$key] );
            }
            foreach ( $payload['attachments'] ?? array() as $file ) {
                $content = base64_decode( $file['content_base64'], true );
                if ( false === $content ) throw new RuntimeException( 'Invalid attachment data.' );
                $mail->addStringAttachment( $content, $file['filename'], 'base64', 'application/octet-stream' );
            }
            // These failures occur before any message is submitted and can be retried.
            try { if ( ! $mail->smtpConnect() ) throw new RuntimeException(); }
            catch ( \Throwable $e ) { throw new RuntimeException( 'SMTP connection or authentication failed. Check the account settings.' ); }
            try {
                if ( ! $mail->send() ) throw new RuntimeException();
            } catch ( \Throwable $e ) {
                throw new DeliveryUncertain( 'Delivery could not be confirmed. Check the recipient or provider before sending again.' );
            }
        } finally { $mail->smtpClose(); }
    }

    private function mailer( array $account ): \PHPMailer\PHPMailer\PHPMailer {
        foreach ( array( 'Exception', 'SMTP', 'PHPMailer' ) as $class ) {
            if ( ! class_exists( 'PHPMailer\\PHPMailer\\' . $class ) && ! interface_exists( 'PHPMailer\\PHPMailer\\' . $class ) ) require_once ABSPATH . WPINC . '/PHPMailer/' . $class . '.php';
        }
        $security = (string) $account['smtp_security']; HostPolicy::security( $security, $account );
        $host = (string) $account['smtp_host']; $ip = HostPolicy::resolve( $host, 'SMTP' );
        $mail = new \PHPMailer\PHPMailer\PHPMailer( true ); $mail->isSMTP(); $mail->AllowEmpty = true;
        $mail->Host = str_contains( $ip, ':' ) ? '[' . $ip . ']' : $ip;
        $mail->Port = (int) $account['smtp_port']; $mail->SMTPAuth = true; $mail->Timeout = 20; $mail->getSMTPInstance()->Timelimit = 30;
        $mail->SMTPAutoTLS = 'none' !== $security;
        $mail->SMTPSecure = 'ssl' === $security ? 'ssl' : ( 'tls' === $security ? 'tls' : '' );
        $mail->SMTPOptions = array( 'ssl' => array( 'verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false, 'peer_name' => $host, 'SNI_enabled' => true ) );
        $mail->Username = (string) ( $account['username'] ?: $account['email'] ); $mail->CharSet = 'UTF-8';
        if ( 'oauth' === $account['auth_type'] ) {
            if ( ! interface_exists( 'PHPMailer\\PHPMailer\\OAuthTokenProvider' ) ) {
                $contract = ABSPATH . WPINC . '/PHPMailer/OAuthTokenProvider.php';
                require_once is_readable( $contract ) ? $contract : __DIR__ . '/OAuthTokenProvider.php';
            }
            $token = apply_filters( 'wpmd_oauth_access_token', $this->crypto->decrypt( $account['oauth_access_enc'] ?? null ), $account );
            if ( ! is_string( $token ) || '' === $token ) throw new RuntimeException( 'OAuth access token unavailable.' );
            $mail->AuthType = 'XOAUTH2';
            $mail->setOAuth( new class( $mail->Username, $token ) implements \PHPMailer\PHPMailer\OAuthTokenProvider {
                public function __construct( private string $username, private string $token ) {}
                public function getOauth64(): string { return base64_encode( "user={$this->username}\x01auth=Bearer {$this->token}\x01\x01" ); }
            } );
        } else {
            $password = $this->crypto->decrypt( $account['secret_enc'] ?? null );
            if ( null === $password ) throw new RuntimeException( 'Account password or app password is unavailable.' );
            $mail->Password = $password;
        }
        return apply_filters( 'wpmd_smtp_mailer', $mail, $account );
    }
}
