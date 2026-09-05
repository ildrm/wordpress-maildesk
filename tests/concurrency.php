<?php
$path = getenv( 'WPMD_TEST_WP' ); if ( ! $path ) exit( 'Set WPMD_TEST_WP.' );
require $path . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' ) throw new RuntimeException( 'Local tests only.' );
use WPMailDesk\Infrastructure\Database\Repository;
$repo = new Repository();
if ( ( $argv[1] ?? '' ) === 'claim' ) { echo $repo->claim_outbox( (int) $argv[2] ) ? '1' : '0'; exit; }
$fixture = json_decode( file_get_contents( '/private/tmp/wpmd-test-account.json' ), true );
$id = $repo->queue_outbox( $fixture['account_id'], $fixture['owner_id'], array( 'to' => array( array( 'email' => 'to@example.test', 'name' => '' ) ), 'body_text' => 'Concurrent claim fixture' ), gmdate( 'Y-m-d H:i:s' ), wp_generate_uuid4() . '@example.test', wp_generate_uuid4() );
$children = array();
for ( $i = 0; $i < 8; $i++ ) {
    $process = proc_open( array( PHP_BINARY, __FILE__, 'claim', (string) $id ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
    if ( ! is_resource( $process ) ) throw new RuntimeException( 'Could not start claim worker.' );
    fclose( $pipes[0] ); $children[] = array( $process, $pipes );
}
$winners = 0;
foreach ( $children as [ $process, $pipes ] ) {
    $result = stream_get_contents( $pipes[1] ); $error = stream_get_contents( $pipes[2] ); fclose( $pipes[1] ); fclose( $pipes[2] );
    if ( proc_close( $process ) !== 0 || ! in_array( trim( $result ), array( '0', '1' ), true ) ) throw new RuntimeException( $error ?: 'Invalid worker result: ' . $result );
    $winners += (int) $result;
}
if ( $winners !== 1 ) throw new RuntimeException( 'Concurrent workers claimed the same send more than once.' );
echo "PASS exactly one of eight concurrent processes claims a delivery\n";
global $wpdb; $table = $wpdb->prefix . 'wpmd_outbox';
$wpdb->update( $table, array( 'updated_at' => gmdate( 'Y-m-d H:i:s', time() - 901 ) ), array( 'id' => $id ) );
$repo->due_outbox();
if ( $wpdb->get_var( $wpdb->prepare( "SELECT status FROM {$table} WHERE id=%d", $id ) ) !== 'uncertain' ) throw new RuntimeException( 'Stale delivery was not marked uncertain.' );
if ( $repo->claim_outbox( $id ) ) throw new RuntimeException( 'Uncertain delivery was reclaimed.' );
echo "PASS abandoned in-flight delivery is never automatically reclaimed\n2 concurrency checks passed.\n";
