<?php
/**
 * Plugin Name: MailDesk for WordPress
 * Plugin URI: https://github.com/ildrm/wordpress-maildesk
 * Description: Secure multi-account email client for WordPress with IMAP synchronization, SMTP sending, drafts, contacts, rules, signatures, templates, shared access, diagnostics, and a modern admin interface.
 * Version: 1.1.0
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Shahin Ilderemi
 * Author URI: https://ildrm.com
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wp-maildesk
 */

defined( 'ABSPATH' ) || exit;

define( 'WPMD_VERSION', '1.1.0' );
define( 'WPMD_FILE', __FILE__ );
define( 'WPMD_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPMD_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
    static function ( string $class ): void {
        $prefix = 'WPMailDesk\\';
        if ( 0 !== strpos( $class, $prefix ) ) {
            return;
        }
        $relative = str_replace( '\\', DIRECTORY_SEPARATOR, substr( $class, strlen( $prefix ) ) );
        $file     = WPMD_PATH . 'src/' . $relative . '.php';
        if ( is_readable( $file ) ) {
            require_once $file;
        }
    }
);

register_activation_hook( __FILE__, array( 'WPMailDesk\\WordPress\\Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WPMailDesk\\WordPress\\Activator', 'deactivate' ) );

add_action(
    'plugins_loaded',
    static function (): void {
        if ( version_compare( PHP_VERSION, '8.1', '<' ) ) {
            add_action( 'admin_notices', static function (): void {
                echo '<div class="notice notice-error"><p>' . esc_html__( 'MailDesk requires PHP 8.1 or newer.', 'wp-maildesk' ) . '</p></div>';
            } );
            return;
        }
        ( new WPMailDesk\Plugin() )->boot();
    }
);
