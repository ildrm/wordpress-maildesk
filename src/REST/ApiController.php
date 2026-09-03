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
    }

    private function permission( string $capability ): callable {
        return static fn(): bool => current_user_can( $capability );
    }

    public function routes(): void {
        $ns = 'wpmd/v1';

        register_rest_route( $ns, '/bootstrap', array(
            'methods'             => 'GET',
            'permission_callback' => $this->permission( 'wpmd_access_mail' ),
            'callback'            => fn() => array(
                'accounts'   => $this->safeAccounts(),
                'contacts'   => $this->repo->contacts( get_current_user_id() ),
                'signatures' => $this->repo->simple_list( 'signatures', get_current_user_id() ),
                'templates'  => $this->repo->simple_list( 'templates', get_current_user_id() ),
                'rules'      => $this->repo->simple_list( 'rules', get_current_user_id() ),
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
                    $fields['is_read'] = $payload['is_read'] ? 1 : 0;
                }
                if ( array_key_exists( 'is_starred', $payload ) ) {
                    $fields['is_starred'] = $payload['is_starred'] ? 1 : 0;
                }
                return array( 'ok' => $this->repo->set_message_state( (int) $request['id'], get_current_user_id(), $fields ) );
            },
        ) );

        register_rest_route( $ns, '/send', array(
            'methods'             => 'POST',
            'permission_callback' => $this->permission( 'wpmd_send_mail' ),
            'callback'            => function ( WP_REST_Request $request ) {
                try {
                    $payload = (array) $request->get_json_params();
                    return array( 'outbox_id' => $this->service->queueSend( absint( $payload['account_id'] ?? 0 ), get_current_user_id(), $payload ) );
                } catch ( \Throwable $e ) {
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
                        'data_json'  => wp_json_encode( $payload['data'] ?? array() ),
                        'version'    => absint( $payload['version'] ?? 1 ),
                        'status'     => 'draft',
                    );
                    return array( 'id' => $this->repo->save_draft( $data, get_current_user_id() ) );
                },
            ),
        ) );

        foreach ( array( 'contacts', 'signatures', 'templates', 'rules' ) as $type ) {
            register_rest_route( $ns, '/' . $type, array(
                array(
                    'methods'             => 'GET',
                    'permission_callback' => $this->permission( 'wpmd_access_mail' ),
                    'callback'            => function () use ( $type ) {
                        return 'contacts' === $type
                            ? $this->repo->contacts( get_current_user_id() )
                            : $this->repo->simple_list( $type, get_current_user_id() );
                    },
                ),
                array(
                    'methods'             => 'POST',
                    'permission_callback' => $this->permission( 'wpmd_access_mail' ),
                    'callback'            => function ( WP_REST_Request $request ) use ( $type ) {
                        $payload = (array) $request->get_json_params();
                        if ( 'contacts' === $type ) {
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

        register_rest_route( $ns, '/diagnostics', array(
            'methods'             => 'GET',
            'permission_callback' => $this->permission( 'wpmd_view_diagnostics' ),
            'callback'            => fn() => array(
                'stats'   => $this->repo->stats(),
                'cron'    => wp_next_scheduled( 'wpmd_queue_tick' ),
                'php'     => PHP_VERSION,
                'sodium'  => function_exists( 'sodium_crypto_secretbox' ),
                'openssl' => extension_loaded( 'openssl' ),
                'logs'    => $this->repo->logs( get_current_user_id(), 50 ),
            ),
        ) );
    }

    private function safeAccounts(): array {
        $accounts = $this->repo->accounts_for_user( get_current_user_id() );
        foreach ( $accounts as &$account ) {
            unset( $account['secret_enc'], $account['oauth_refresh_enc'], $account['oauth_access_enc'] );
        }
        unset( $account );
        return array_values( $accounts );
    }

    public function saveAccount( WP_REST_Request $request ) {
        try {
            return array( 'id' => $this->service->saveAccount( (array) $request->get_json_params(), get_current_user_id() ) );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'account_error', $e->getMessage(), array( 'status' => 400 ) );
        }
    }

    public function accountAction( WP_REST_Request $request ) {
        try {
            $id = (int) $request['id'];
            return str_ends_with( $request->get_route(), '/test' )
                ? $this->service->testAccount( $id, get_current_user_id() )
                : $this->service->syncAccount( $id, get_current_user_id() );
        } catch ( \Throwable $e ) {
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
                'text'       => sanitize_textarea_field( $payload['text'] ?? '' ),
                'is_default' => empty( $payload['is_default'] ) ? 0 : 1,
            );
        }
        if ( 'templates' === $type ) {
            return array(
                'id'        => absint( $payload['id'] ?? 0 ),
                'name'      => sanitize_text_field( $payload['name'] ?? '' ),
                'subject'   => sanitize_text_field( $payload['subject'] ?? '' ),
                'body_html' => wp_kses_post( $payload['body_html'] ?? '' ),
                'body_text' => sanitize_textarea_field( $payload['body_text'] ?? '' ),
            );
        }
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
