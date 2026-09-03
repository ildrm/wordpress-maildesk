<?php
namespace WPMailDesk\Infrastructure\Security;

final class HtmlSanitizer {
    public static function sanitize( string $html ): string {
        $allowed = wp_kses_allowed_html( 'post' );
        foreach ( array( 'form','input','button','textarea','select','option','iframe','object','embed','script','style','svg','math','meta','link' ) as $tag ) {
            unset( $allowed[ $tag ] );
        }
        foreach ( $allowed as &$attrs ) {
            if ( is_array( $attrs ) ) {
                foreach ( array_keys( $attrs ) as $attr ) {
                    if ( str_starts_with( strtolower( $attr ), 'on' ) ) {
                        unset( $attrs[ $attr ] );
                    }
                }
            }
        }
        unset( $attrs );
        $sanitized = wp_kses( $html, $allowed, array( 'http', 'https', 'mailto', 'cid' ) );
        $sanitized = preg_replace( '/(<img\b[^>]*?)\s+src=("|\')(https?:\/\/[^"\']+)\2/i', '$1 data-wpmd-remote-src=$2$3$2 src=""', $sanitized );
        return (string) $sanitized;
    }
}
