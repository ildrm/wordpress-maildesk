<?php
namespace WPMailDesk\Infrastructure\Security;

use RuntimeException;

final class Crypto {
    private function key(): string {
        $material = defined( 'WPMD_ENCRYPTION_KEY' ) ? (string) WPMD_ENCRYPTION_KEY : wp_salt( 'auth' ) . wp_salt( 'secure_auth' );
        if ( '' === $material ) throw new RuntimeException( 'Encryption key must not be empty.' );
        return hash( 'sha256', $material, true );
    }

    public function encrypt( ?string $plaintext ): ?string {
        if ( null === $plaintext || '' === $plaintext ) {
            return null;
        }
        if ( function_exists( 'sodium_crypto_secretbox' ) ) {
            $nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
            $box   = sodium_crypto_secretbox( $plaintext, $nonce, $this->key() );
            return 's1:' . base64_encode( $nonce . $box );
        }
        if ( function_exists( 'openssl_encrypt' ) ) {
            $iv  = random_bytes( 12 );
            $tag = '';
            $ct  = openssl_encrypt( $plaintext, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag );
            if ( false === $ct ) {
                throw new RuntimeException( 'Encryption failed.' );
            }
            return 'g1:' . base64_encode( $iv . $tag . $ct );
        }
        throw new RuntimeException( 'No authenticated encryption backend is available.' );
    }

    public function decrypt( ?string $ciphertext ): ?string {
        if ( null === $ciphertext || '' === $ciphertext ) {
            return null;
        }
        $prefix = substr( $ciphertext, 0, 3 );
        $raw    = base64_decode( substr( $ciphertext, 3 ), true );
        if ( false === $raw ) {
            throw new RuntimeException( 'Invalid encrypted payload.' );
        }
        if ( 's1:' === $prefix ) {
            if ( ! function_exists( 'sodium_crypto_secretbox_open' ) || strlen( $raw ) < 40 ) {
                throw new RuntimeException( 'Invalid secretbox payload or unavailable Sodium backend.' );
            }
            $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
            $pt = sodium_crypto_secretbox_open( substr( $raw, $n ), substr( $raw, 0, $n ), $this->key() );
            if ( false === $pt ) {
                throw new RuntimeException( 'Unable to decrypt secret.' );
            }
            return $pt;
        }
        if ( 'g1:' === $prefix ) {
            if ( ! function_exists( 'openssl_decrypt' ) || strlen( $raw ) < 29 ) {
                throw new RuntimeException( 'Invalid GCM payload or unavailable OpenSSL backend.' );
            }
            $iv  = substr( $raw, 0, 12 );
            $tag = substr( $raw, 12, 16 );
            $pt  = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag );
            if ( false === $pt ) {
                throw new RuntimeException( 'Unable to decrypt secret.' );
            }
            return $pt;
        }
        throw new RuntimeException( 'Unknown encrypted payload format.' );
    }
}
