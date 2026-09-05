<?php
namespace WPMailDesk\REST;

use WP_Error;
use WP_REST_Request;
use WPMailDesk\Application\MailService;
use WPMailDesk\Infrastructure\Database\Repository;
use WPMailDesk\Infrastructure\Security\HtmlSanitizer;

final class ApiController {
    public function __construct( private MailService $service, private Repository $repo ) {}

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'routes' ) );
        add_filter( 'rest_endpoints', array( $this, 'guardEndpoints' ) );
    }

    private function permission( string $capability ): callable {
        return static fn(): bool => current_user_can( 'wpmd_access_mail' ) && current_user_can( $capability );
    }

    public function routes(): void {
        $ns = 'wpmd/v1';

        register_rest_route( $ns, '/bootstrap', array(
            'methods'             => 'GET',
            'permission_callback' => $this->permission( 'wpmd_access_mail' ),
            'callback'            => fn() => array(
                'accounts'   => $this->safeAccounts(),
                'capabilities' => array_values( array_filter( \WPMailDesk\WordPress\Capabilities::ALL, 'current_user_can' ) ),
                'contacts'   => current_user_can( 'wpmd_manage_contacts' ) ? $this->repo->contacts( get_current_user_id() ) : array(),
                'signatures' => current_user_can( 'wpmd_compose_mail' ) ? $this->repo->simple_list( 'signatures', get_current_user_id() ) : array(),
                'templates'  => current_user_can( 'wpmd_manage_templates' ) ? $this->repo->simple_list( 'templates', get_current_user_id() ) : array(),
                'rules'      => current_user_can( 'wpmd_manage_rules' ) ? $this->repo->simple_list( 'rules', get_current_user_id() ) : array(),
            ),
        ) );

        register_rest_route( $ns, '/accounts', array(
            array(
                'methods'             => 'GET',
                'permission_callback' => $this->permission( 'wpmd_access_mail' ),
                'callback'            => fn() => $this->safeAccounts(),
            ),
            array(
                'methods'             => 'POST',
                'permission_callback' => $this->permission( 'wpmd_manage_own_accounts' ),
                'callback'            => array( $this, 'saveAccount' ),
            ),
        ) );

        register_rest_route( $ns, '/accounts/(?P<id>\d+)/(test|sync)', array(
            'methods'             => 'POST',
            'permission_callback' => $this->permission( 'wpmd_manage_own_accounts' ),
            'callback'            => array( $this, 'accountAction' ),
        ) );

        register_rest_route( $ns, '/accounts/(?P<id>\d+)/folders', array(
            'methods'             => 'GET',
            'permission_callback' => $this->permission( 'wpmd_read_mail' ),
            'callback'            => function ( WP_REST_Request $request ) {
                $account = $this->repo->account( (int) $request['id'], get_current_user_id() );
                return $account
                    ? $this->repo->folders( (int) $request['id'] )
                    : new WP_Error( 'forbidden', 'Account not accessible.', array( 'status' => 403 ) );
            },
        ) );

        register_rest_route( $ns, '/messages', array(
            'methods'             => 'GET',
            'permission_callback' => $this->permission( 'wpmd_read_mail' ),
            'callback'            => function ( WP_REST_Request $request ) {
                return $this->repo->messages( get_current_user_id(), array(
                    'account_id' => absint( $request['account_id'] ),
                    'folder_id'  => absint( $request['folder_id'] ),
                    'search'     => sanitize_text_field( $request['search'] ?? '' ),
                    'limit'      => absint( $request['limit'] ?: 50 ),
                    'offset'     => absint( $request['offset'] ?? 0 ),
                ) );
            },
        ) );

        register_rest_route( $ns, '/messages/(?P<id>\d+)', array(
            'methods'             => 'GET',
            'permission_callback' => $this->permission( 'wpmd_read_mail' ),
            'callback'            => function ( WP_REST_Request $request ) {
                $message = $this->repo->message( (int) $request['id'], get_current_user_id() );
                if ( ! $message ) {
                    return new WP_Error( 'not_found', 'Message not found.', array( 'status' => 404 ) );
                }
                $message['body_html_safe'] = HtmlSanitizer::sanitize( (string) $message['body_html'] );
                unset( $message['body_html'] );
                $message['attachments'] = $this->repo->attachments( (int) $message['id'] );
                $message['writable'] = $this->repo->user_can_access_account( get_current_user_id(), (int) $message['account_id'], 'write' );
                $message['movable'] = current_user_can( 'wpmd_delete_mail' ) && $this->repo->user_can_access_account( get_current_user_id(), (int) $message['account_id'], 'delete' );
                return $message;
            },
        ) );

        register_rest_route( $ns, '/messages/(?P<id>\d+)/state', array(
            'methods'             => 'POST',
            'permission_callback' => $this->permission( 'wpmd_read_mail' ),
            'callback'            => function ( WP_REST_Request $request ) {
                $payload = (array) $request->get_json_params();
                $fields  = array();
                if ( array_key_exists( 'is_read', $payload ) ) {
                    if ( ! is_bool( $payload['is_read'] ) ) return new WP_Error( 'invalid_state', 'is_read must be a boolean.', array( 'status' => 400 ) );
                    $fields['is_read'] = $payload['is_read'] ? 1 : 0;
                }
                if ( array_key_exists( 'is_starred', $payload ) ) {
                    if ( ! is_bool( $payload['is_starred'] ) ) return new WP_Error( 'invalid_state', 'is_starred must be a boolean.', array( 'status' => 400 ) );
                    $fields['is_starred'] = $payload['is_starred'] ? 1 : 0;
                }
                if ( ! $fields ) return new WP_Error( 'invalid_state', 'No message state supplied.', array( 'status' => 400 ) );
                return $this->guard( fn() => array( 'ok' => $this->service->setMessageState( (int) $request['id'], get_current_user_id(), $fields ) ) );
            },
        ) );

        register_rest_route( $ns, '/messages/(?P<id>\d+)/move', array(
            'methods' => 'POST', 'permission_callback' => $this->permission( 'wpmd_delete_mail' ),
            'callback' => fn( WP_REST_Request $r ) => array( 'ok' => $this->service->moveMessage( (int) $r['id'], get_current_user_id(), absint( $r['folder_id'] ?? 0 ) ) ),
        ) );
        register_rest_route( $ns, '/send', array(
            'methods'             => 'POST',
            'permission_callback' => $this->permission( 'wpmd_send_mail' ),
            'callback'            => function ( WP_REST_Request $request ) {
                try {
                    $payload = (array) $request->get_json_params();
                    return array( 'outbox_id' => $this->service->queueSend( absint( $payload['account_id'] ?? 0 ), get_current_user_id(), $payload ) );
                } catch ( \RuntimeException $e ) {
                    return new WP_Error( 'send_error', $e->getMessage(), array( 'status' => 400 ) );
                }
            },
        ) );

        register_rest_route( $ns, '/drafts', array(
            array(
                'methods'             => 'GET',
                'permission_callback' => $this->permission( 'wpmd_compose_mail' ),
                'callback'            => fn() => $this->repo->drafts( get_current_user_id() ),
            ),
            array(
                'methods'             => 'POST',
                'permission_callback' => $this->permission( 'wpmd_compose_mail' ),
                'callback'            => function ( WP_REST_Request $request ) {
                    $payload = (array) $request->get_json_params();
                    $data = array(
                        'id'         => absint( $payload['id'] ?? 0 ),
                        'account_id' => absint( $payload['account_id'] ?? 0 ),
                        'data_json'  => wp_json_encode( $this->service->composePayload( (array) ( $payload['data'] ?? array() ) ) ),
                        'version'    => absint( $payload['version'] ?? 1 ),
                        'status'     => 'draft',
                    );
                    return array( 'id' => $this->repo->save_draft( $data, get_current_user_id() ), 'version' => $data['id'] ? $data['version'] + 1 : 1 );
                },
            ),
        ) );

        foreach ( array( 'contacts', 'signatures', 'templates', 'rules' ) as $type ) {
            $capability = $this->collectionCapability( $type );
            register_rest_route( $ns, '/' . $type, array(
                array(
                    'methods'             => 'GET',
                    'permission_callback' => $this->permission( $capability ),
                    'callback'            => function () use ( $type ) {
                        return 'contacts' === $type
                            ? $this->repo->contacts( get_current_user_id() )
                            : $this->repo->simple_list( $type, get_current_user_id() );
                    },
                ),
                array(
                    'methods'             => 'POST',
                    'permission_callback' => $this->permission( $capability ),
                    'callback'            => function ( WP_REST_Request $request ) use ( $type ) {
                        $payload = (array) $request->get_json_params();
                        if ( 'contacts' === $type ) {
                            $emails = (array) ( $payload['emails'] ?? array() );
                            foreach ( $emails as $email ) {
                                if ( ! is_email( is_array( $email ) ? ( $email['email'] ?? '' ) : $email ) ) throw new \RuntimeException( 'Invalid contact email.' );
                            }
                            $data = array(
                                'id'           => absint( $payload['id'] ?? 0 ),
                                'first_name'   => sanitize_text_field( $payload['first_name'] ?? '' ),
                                'last_name'    => sanitize_text_field( $payload['last_name'] ?? '' ),
                                'display_name' => sanitize_text_field( $payload['display_name'] ?? '' ),
                                'company'      => sanitize_text_field( $payload['company'] ?? '' ),
                                'job_title'    => sanitize_text_field( $payload['job_title'] ?? '' ),
                                'emails_json'  => wp_json_encode( $payload['emails'] ?? array() ),
                                'phones_json'  => wp_json_encode( $payload['phones'] ?? array() ),
                                'website'      => esc_url_raw( $payload['website'] ?? '' ),
                                'notes'        => sanitize_textarea_field( $payload['notes'] ?? '' ),
                                'tags_json'    => wp_json_encode( $payload['tags'] ?? array() ),
                            );
                            $id = $this->repo->save_contact( $data, get_current_user_id() );
                        } else {
                            $id = $this->repo->save_simple( $type, $this->sanitizeSimple( $type, $payload ), get_current_user_id() );
                        }
                        return array( 'id' => $id );
                    },
                ),
            ) );
        }

        register_rest_route( $ns, '/accounts/(?P<id>\\d+)', array(
            'methods' => 'DELETE', 'permission_callback' => $this->permission( 'wpmd_manage_own_accounts' ),
            'callback' => fn( WP_REST_Request $r ) => array( 'ok' => $this->service->deleteAccount( (int) $r['id'], get_current_user_id() ) ),
        ) );
        register_rest_route( $ns, '/accounts/(?P<id>\\d+)/shares', array(
            'methods' => 'GET,POST', 'permission_callback' => $this->permission( 'wpmd_manage_shared_accounts' ),
            'callback' => function ( WP_REST_Request $r ) {
                $id = (int) $r['id'];
                if ( ! $this->repo->can_manage_account( get_current_user_id(), $id ) ) return new WP_Error( 'forbidden', 'Account not manageable.', array( 'status' => 403 ) );
                if ( $r->get_method() === 'POST' ) {
                    $p = (array) $r->get_json_params(); $permissions = $p['permissions'] ?? array();
                    if ( ! is_array( $permissions ) || array_diff( $permissions, array( 'read', 'write', 'compose', 'send', 'delete' ) ) ) throw new \RuntimeException( 'Invalid shared permissions.' );
                    if ( $permissions && ! in_array( 'read', $permissions, true ) ) throw new \RuntimeException( 'Shared access requires read permission.' );
                    $this->repo->share( $id, absint( $p['user_id'] ?? 0 ), array_values( $permissions ) );
                }
                return $this->repo->shares( $id );
            },
        ) );
        foreach ( array( 'drafts', 'contacts', 'signatures', 'templates', 'rules' ) as $type ) {
            register_rest_route( $ns, '/' . $type . '/(?P<id>\\d+)', array(
                'methods' => 'DELETE', 'permission_callback' => $this->permission( $this->collectionCapability( $type ) ),
                'callback' => fn( WP_REST_Request $r ) => array( 'ok' => $this->repo->delete_personal( $type, (int) $r['id'], get_current_user_id() ) ),
            ) );
        }
        register_rest_route( $ns, '/outbox', array(
            'methods' => 'GET', 'permission_callback' => $this->permission( 'wpmd_send_mail' ),
            'callback' => fn() => $this->repo->outbox( get_current_user_id() ),
        ) );
        register_rest_route( $ns, '/outbox/(?P<id>\\d+)', array(
            'methods' => 'DELETE', 'permission_callback' => $this->permission( 'wpmd_send_mail' ),
            'callback' => fn( WP_REST_Request $r ) => array( 'ok' => $this->repo->cancel_outbox( (int) $r['id'], get_current_user_id() ) ),
        ) );
        register_rest_route( $ns, '/attachments/(?P<id>\\d+)', array(
            'methods' => 'GET', 'permission_callback' => $this->permission( 'wpmd_read_mail' ),
            'callback' => function ( WP_REST_Request $r ) {
                $attachment = $this->repo->attachment( (int) $r['id'], get_current_user_id() );
                if ( ! $attachment ) return new WP_Error( 'not_found', 'Attachment not found.', array( 'status' => 404 ) );
                return array_intersect_key( $attachment, array_flip( array( 'filename', 'content_base64', 'size_bytes' ) ) );
            },
        ) );

        register_rest_route( $ns, '/diagnostics', array(
            'methods'             => 'GET',
            'permission_callback' => $this->permission( 'wpmd_view_diagnostics' ),
            'callback'            => fn() => array(
                'stats'   => $this->repo->stats(),
                'cron'    => wp_next_scheduled( 'wpmd_queue_tick' ),
                'last_queue_run' => (int) get_option( 'wpmd_last_queue_run', 0 ),
                'php'     => PHP_VERSION,
                'sodium'  => function_exists( 'sodium_crypto_secretbox' ),
                'openssl' => extension_loaded( 'openssl' ),
                'logs'    => current_user_can( 'wpmd_view_logs' ) ? $this->repo->logs( get_current_user_id(), 50 ) : array(),
            ),
        ) );
    }

    public function guardEndpoints( array $endpoints ): array {
        foreach ( $endpoints as $route => &$handlers ) {
            if ( ! str_starts_with( $route, '/wpmd/v1/' ) ) continue;
            foreach ( $handlers as &$handler ) {
                if ( is_array( $handler ) && isset( $handler['callback'] ) ) {
                    $callback = $handler['callback'];
                    $handler['callback'] = fn( $request ) => $this->guard( fn() => $callback( $request ) );
                }
            }
            unset( $handler );
        }
        unset( $handlers ); return $endpoints;
    }
    private function guard( callable $action ) {
        try { return $action(); }
        catch ( \RuntimeException $e ) { return new WP_Error( 'wpmd_error', $e->getMessage(), array( 'status' => 400 ) ); }
        catch ( \Throwable $e ) { return new WP_Error( 'wpmd_invalid_request', 'The request could not be processed. Check the supplied values and try again.', array( 'status' => 400 ) ); }
    }
    private function collectionCapability( string $type ): string {
        return array( 'drafts' => 'wpmd_compose_mail', 'contacts' => 'wpmd_manage_contacts', 'signatures' => 'wpmd_compose_mail', 'templates' => 'wpmd_manage_templates', 'rules' => 'wpmd_manage_rules' )[$type];
    }
    private function validateRule( array $payload ): void {
        $account = absint( $payload['account_id'] ?? 0 );
        $row = $account ? $this->repo->account( $account ) : null;
        if ( $account && ( ! $row || (int) $row['owner_user_id'] !== get_current_user_id() ) ) throw new \RuntimeException( 'Rules can only target accounts you own.' );
        if ( empty( $payload['conditions'] ) || ! is_array( $payload['conditions'] ) || empty( $payload['actions'] ) || ! is_array( $payload['actions'] ) ) throw new \RuntimeException( 'A rule needs conditions and actions.' );
        foreach ( $payload['conditions'] as $condition ) {
            if ( ! is_array( $condition ) || ! in_array( $condition['field'] ?? '', array( 'subject', 'from' ), true ) || ! is_string( $condition['value'] ?? null ) || trim( $condition['value'] ) === '' || strlen( $condition['value'] ) > 200 ) throw new \RuntimeException( 'Rules support nonempty subject/from contains conditions, up to 200 bytes.' );
        }
        foreach ( $payload['actions'] as $key => $value ) {
            if ( ! in_array( $key, array( 'is_read', 'is_starred' ), true ) || ! is_bool( $value ) ) throw new \RuntimeException( 'Rules support boolean is_read and is_starred actions.' );
        }
    }

    private function safeAccounts(): array {
        $accounts = $this->repo->accounts_for_user( get_current_user_id() );
        foreach ( $accounts as &$account ) {
            unset( $account['secret_enc'], $account['oauth_refresh_enc'], $account['oauth_access_enc'] );
            $account['can_manage'] = $this->repo->can_manage_account( get_current_user_id(), (int) $account['id'] );
            $account['can_send'] = $this->repo->user_can_access_account( get_current_user_id(), (int) $account['id'], 'send' );
            $account['can_compose'] = $this->repo->user_can_access_account( get_current_user_id(), (int) $account['id'], 'compose' );
        }
        unset( $account );
        return array_values( $accounts );
    }

    public function saveAccount( WP_REST_Request $request ) {
        try {
            return array( 'id' => $this->service->saveAccount( (array) $request->get_json_params(), get_current_user_id() ) );
        } catch ( \RuntimeException $e ) {
            return new WP_Error( 'account_error', $e->getMessage(), array( 'status' => 400 ) );
        }
    }

    public function accountAction( WP_REST_Request $request ) {
        try {
            $id = (int) $request['id'];
            return str_ends_with( $request->get_route(), '/test' )
                ? $this->service->testAccount( $id, get_current_user_id() )
                : $this->service->syncAccount( $id, get_current_user_id() );
        } catch ( \RuntimeException $e ) {
            return new WP_Error( 'account_action_error', $e->getMessage(), array( 'status' => 400 ) );
        }
    }

    private function sanitizeSimple( string $type, array $payload ): array {
        if ( 'signatures' === $type ) {
            return array(
                'id'         => absint( $payload['id'] ?? 0 ),
                'account_id' => absint( $payload['account_id'] ?? 0 ) ?: null,
                'name'       => sanitize_text_field( $payload['name'] ?? '' ),
                'html'       => wp_kses_post( $payload['html'] ?? '' ),
                'text'       => $this->service->composePayload( array( 'body_text' => $payload['text'] ?? '' ) )['body_text'],
                'is_default' => empty( $payload['is_default'] ) ? 0 : 1,
            );
        }
        if ( 'templates' === $type ) {
            return array(
                'id'        => absint( $payload['id'] ?? 0 ),
                'name'      => sanitize_text_field( $payload['name'] ?? '' ),
                'subject'   => sanitize_text_field( $payload['subject'] ?? '' ),
                'body_html' => wp_kses_post( $payload['body_html'] ?? '' ),
                'body_text' => $this->service->composePayload( array( 'body_text' => $payload['body_text'] ?? '' ) )['body_text'],
            );
        }
        $this->validateRule( $payload );
        return array(
            'id'              => absint( $payload['id'] ?? 0 ),
            'account_id'      => absint( $payload['account_id'] ?? 0 ) ?: null,
            'name'            => sanitize_text_field( $payload['name'] ?? '' ),
            'enabled'         => empty( $payload['enabled'] ) ? 0 : 1,
            'priority'        => intval( $payload['priority'] ?? 100 ),
            'conditions_json' => wp_json_encode( $payload['conditions'] ?? array() ),
            'actions_json'    => wp_json_encode( $payload['actions'] ?? array() ),
        );
    }
}
