<?php
namespace WPMailDesk\Infrastructure\Queue;

use WPMailDesk\Application\MailService;
use WPMailDesk\Infrastructure\Database\Repository;

final class QueueRunner {
    public function __construct( private MailService $service, private Repository $repo ) {}
    public function register(): void {
        add_filter( 'cron_schedules', static function ( array $schedules ): array {
            $schedules['wpmd_five_minutes'] = array( 'interval' => 300, 'display' => 'Every Five Minutes' ); return $schedules;
        } );
        add_action( 'wpmd_queue_tick', array( $this, 'run' ) );
        add_action( 'wpmd_queue_continue', array( $this, 'run' ) );
    }
    public function run(): void {
        $token = $this->repo->acquire_lock( 'queue' );
        if ( ! $token ) return;
        try {
            update_option( 'wpmd_last_queue_run', time(), false );
            $started = microtime( true );
            foreach ( $this->repo->due_outbox( 10 ) as $row ) {
                if ( microtime( true ) - $started > 45 ) break;
                try { $this->service->sendOutbox( $row ); }
                catch ( \Throwable $e ) { $this->repo->log( 'queue_error', (int) $row['account_id'], null, 'error', array( 'outbox_id' => (int) $row['id'], 'error' => $e->getMessage() ) ); }
            }
            foreach ( $this->repo->sync_candidates() as $account ) {
                if ( user_can( (int) $account['owner_user_id'], 'wpmd_manage_own_accounts' ) ) $this->repo->enqueue_job( 'discover', array( 'account_id' => (int) $account['id'] ) );
            }
            $processed = 0;
            while ( microtime( true ) - $started < 45 && $processed++ < 10 && ( $job = $this->repo->next_job() ) ) {
                try {
                    if ( 'message_state' === $job['type'] ) $this->service->runStateJob( $job );
                    else $this->service->runSyncJob( $job );
                    $this->repo->finish_job( $job );
                } catch ( \Throwable $e ) {
                    $this->repo->finish_job( $job, $e->getMessage() );
                    $this->repo->log( 'sync_error', null, null, 'error', array( 'job_id' => (int) $job['id'], 'error' => $e->getMessage() ) );
                }
            }
            $this->repo->prune_history();
            if ( $this->repo->has_due_work() && ! wp_next_scheduled( 'wpmd_queue_continue' ) ) wp_schedule_single_event( time() + 10, 'wpmd_queue_continue' );
        } finally { $this->repo->release_lock( 'queue', $token ); }
    }
}
