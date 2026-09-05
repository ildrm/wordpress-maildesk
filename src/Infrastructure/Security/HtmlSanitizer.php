<?php
namespace WPMailDesk\Infrastructure\Security;

final class HtmlSanitizer {
    public static function sanitize( string $html ): string {
        $allowed = wp_kses_allowed_html( 'post' );
        foreach ( array( 'form','input','button','textarea','select','option','iframe','object','embed','script','style','svg','math','meta','link','img','video','audio','source','track','picture' ) as $tag ) {
            unset( $allowed[ $tag ] );
        }
        foreach ( $allowed as &$attrs ) {
            if ( is_array( $attrs ) ) {
                foreach ( array_keys( $attrs ) as $attr ) {
                    if ( str_starts_with( strtolower( $attr ), 'on' ) || in_array( strtolower( $attr ), array( 'style', 'background', 'src', 'srcset', 'poster', 'ping', 'id', 'class', 'data', 'formaction' ), true ) ) {
                        unset( $attrs[ $attr ] );
                    }
                }
            }
        }
        unset( $attrs );
        return wp_kses( $html, $allowed, array( 'http', 'https', 'mailto' ) );
    }
}
