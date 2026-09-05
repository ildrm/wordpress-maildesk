<?php
namespace WPMailDesk;

use WPMailDesk\Admin\AdminApp;
use WPMailDesk\Application\MailService;
use WPMailDesk\Infrastructure\Database\Repository;
use WPMailDesk\Infrastructure\Mail\ImapClient;
use WPMailDesk\Infrastructure\Mail\SmtpSender;
use WPMailDesk\Infrastructure\Queue\QueueRunner;
use WPMailDesk\Infrastructure\Security\Crypto;
use WPMailDesk\REST\ApiController;
use WPMailDesk\WordPress\Capabilities;
use WPMailDesk\WordPress\Privacy;
use WPMailDesk\WordPress\SiteHealth;
use WPMailDesk\WordPress\Activator;

final class Plugin {
    public function boot(): void {
        Capabilities::register();
        $repository = new Repository();
        $crypto     = new Crypto();
        $imap       = new ImapClient( $crypto );
        $smtp       = new SmtpSender( $crypto );
        $service    = new MailService( $repository, $imap, $smtp, $crypto );

        if ( get_option( 'wpmd_db_version' ) !== WPMD_VERSION ) {
            try { Activator::activate(); }
            catch ( \Throwable $e ) {
                add_action( 'admin_notices', static function (): void { echo '<div class="notice notice-error"><p>' . esc_html__( 'MailDesk could not upgrade its database. Check database permissions and retry activation.', 'wp-maildesk' ) . '</p></div>'; } );
                return;
            }
        }
        ( new AdminApp() )->register();
        ( new ApiController( $service, $repository ) )->register();
        ( new QueueRunner( $service, $repository ) )->register();
        if ( ! wp_next_scheduled( 'wpmd_queue_tick' ) ) wp_schedule_event( time() + 60, 'wpmd_five_minutes', 'wpmd_queue_tick' );
        ( new SiteHealth( $repository ) )->register();
        ( new Privacy( $repository ) )->register();
        add_action( 'wp_initialize_site', static function ( \WP_Site $site ): void {
            if ( ! function_exists( 'is_plugin_active_for_network' ) ) require_once ABSPATH . 'wp-admin/includes/plugin.php';
            if ( ! is_plugin_active_for_network( plugin_basename( WPMD_FILE ) ) ) return;
            switch_to_blog( (int) $site->blog_id );
            try { Activator::activate(); } finally { restore_current_blog(); }
        }, 200 );
    }
}
