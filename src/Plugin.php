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

final class Plugin {
    public function boot(): void {
        Capabilities::register();
        $repository = new Repository();
        $crypto     = new Crypto();
        $imap       = new ImapClient( $crypto );
        $smtp       = new SmtpSender( $crypto );
        $service    = new MailService( $repository, $imap, $smtp, $crypto );

        ( new AdminApp() )->register();
        ( new ApiController( $service, $repository ) )->register();
        ( new QueueRunner( $service, $repository ) )->register();
        ( new SiteHealth( $repository ) )->register();
        ( new Privacy( $repository ) )->register();
    }
}
