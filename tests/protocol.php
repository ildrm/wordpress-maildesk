<?php
$path = getenv( 'WPMD_TEST_WP' );
if ( ! $path ) { fwrite( STDERR, "Set WPMD_TEST_WP.\n" ); exit( 1 ); }
require $path . '/wp-load.php';
if ( wp_get_environment_type() !== 'local' ) throw new RuntimeException( 'Local tests only.' );
use WPMailDesk\Infrastructure\Mail\ImapClient;
use WPMailDesk\Infrastructure\Security\Crypto;

$passed = 0;
function protocol_check( bool $ok, string $name ): void {
    global $passed;
    if ( ! $ok ) throw new RuntimeException( 'FAIL: ' . $name );
    echo 'PASS ' . $name . "\n"; $passed++;
}
function exchange( string $response, string $command = 'NOOP', ?string $continuation = null ): array {
    $pair = stream_socket_pair( STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP );
    stream_set_timeout( $pair[0], 1 ); stream_set_timeout( $pair[1], 1 );
    $client = new ImapClient( new Crypto() );
    $property = new ReflectionProperty( $client, 'stream' ); $property->setAccessible( true ); $property->setValue( $client, $pair[0] );
    fwrite( $pair[1], $response ); stream_socket_shutdown( $pair[1], STREAM_SHUT_WR );
    $method = new ReflectionMethod( $client, 'command' ); $method->setAccessible( true );
    try { $tokens = $method->invoke( $client, $command, $continuation ); return $tokens; }
    finally { fclose( $pair[0] ); fclose( $pair[1] ); }
}
function protocol_rejects( callable $fn, string $name ): void {
    try { $fn(); } catch ( Throwable $e ) { protocol_check( true, $name ); return; }
    protocol_check( false, $name );
}
protocol_check( count( exchange( "* OK server response\r\nA0001 OK completed\r\n" ) ) === 2, 'tagged completion accepted' );
protocol_rejects( fn() => exchange( "* OK response without completion\r\n" ), 'EOF before tagged completion is an error' );
protocol_rejects( fn() => exchange( "* 1 FETCH (BODY[] {9}\r\nshort" ), 'truncated literal terminates without infinite loop' );
protocol_rejects( fn() => exchange( "* 1 FETCH (BODY[] {999999999}\r\n" ), 'oversized literal rejected before allocation' );
$raw = "* 1 FETCH fake\r\nA0001 OK spoof\r\n)\r\n";
$tokens = exchange( '* 1 FETCH (BODY[] {' . strlen( $raw ) . "}\r\n" . $raw . " UID 7)\r\nA0001 OK done\r\n", 'UID FETCH 7 (BODY.PEEK[])' );
protocol_check( $tokens[1]['literal'] === $raw && count( $tokens ) === 4, 'literal content cannot spoof protocol lines or completion' );
$failed = '';
try { exchange( "A0001 NO echo-secret-password\r\n", 'LOGIN fixture password' ); } catch ( Throwable $e ) { $failed = $e->getMessage(); }
protocol_check( $failed !== '' && ! str_contains( $failed, 'echo-secret-password' ) && ! str_contains( $failed, 'fixture password' ), 'server error cannot leak credentials through diagnostics' );
protocol_check( count( exchange( "+ continue\r\nA0001 OK authorized\r\n", 'AUTHENTICATE XOAUTH2', 'fixture-token' ) ) === 1, 'XOAUTH2 challenge-response completes' );
$client = new ImapClient( new Crypto() ); $quote = new ReflectionMethod( $client, 'quote' ); $quote->setAccessible( true );
$parse = new ReflectionMethod( $client, 'parseFetch' ); $parse->setAccessible( true );
$records = $parse->invoke( $client, array( array( 'line' => '* 1 FETCH (UID 1 FLAGS (\\SEEN \\flagged))' ) ) );
protocol_check( $records[0]['flags'] === array( '\\Seen', '\\Flagged' ), 'system flags are parsed case-insensitively' );
protocol_rejects( fn() => $quote->invoke( $client, "mailbox\r\nA9 DELETE INBOX" ), 'mailbox command injection rejected' );
$parser = new WPMailDesk\Infrastructure\Mail\MimeParser();
protocol_rejects( fn() => $parser->parse( "Content-Type: multipart/mixed; boundary=x\r\n\r\n--x\r\nContent-Type: text/plain\r\n\r\ntruncated" ), 'incomplete MIME multipart is explicit failure' );
$budget = 1;
protocol_rejects( static function () use ( $parser, &$budget ) { $parser->parse( "Subject: oversized aggregate\r\n\r\ntext", 0, $budget ); }, 'aggregate MIME parsing budget limits recursive copying' );
$normalize = new ReflectionMethod( $client, 'normalizeMessage' ); $normalize->setAccessible( true );
$malformed = $normalize->invoke( $client, array( 'raw' => "Subject: malformed\r\nContent-Type: multipart/mixed; boundary=x\r\n\r\ntruncated", 'date' => '', 'uid' => 7, 'flags' => array(), 'size' => 100 ), false );
protocol_check( str_contains( $malformed['message']['body_text'], 'could not be safely decoded' ), 'malformed MIME shows a notice without blocking mailbox sync' );
$parsed = $parser->parse( "Content-Type: text/plain; charset=ISO-8859-1\r\nContent-Transfer-Encoding: base64\r\n\r\n" . base64_encode( "caf\xe9" ) );
protocol_check( $parsed['text'] === 'café', 'base64 body is converted from its declared charset' );
$attachment = $parser->parse( "Content-Type: application/octet-stream\r\nContent-Disposition: attachment; filename*=UTF-8''caf%C3%A9.txt\r\n\r\ndata" );
protocol_check( $attachment['attachments'][0]['filename'] === sanitize_file_name( 'café.txt' ), 'extended UTF-8 attachment filename decoded and sanitized' );
echo "{$passed} protocol checks passed.\n";
