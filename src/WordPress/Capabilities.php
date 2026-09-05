<?php
namespace WPMailDesk\WordPress;

final class Capabilities {
    public const ALL = array(
        'wpmd_access_mail', 'wpmd_read_mail', 'wpmd_compose_mail', 'wpmd_send_mail', 'wpmd_delete_mail',
        'wpmd_manage_own_accounts', 'wpmd_manage_all_accounts', 'wpmd_manage_shared_accounts',
        'wpmd_manage_contacts', 'wpmd_manage_rules', 'wpmd_manage_templates', 'wpmd_manage_settings',
        'wpmd_view_diagnostics', 'wpmd_view_logs',
    );

    public static function register(): void {
        // Capabilities are installed on activation/migration. Do not undo a site's
        // deliberate role restrictions on every admin request.
    }

    public static function ensure_caps(): void {
        $administrator = get_role( 'administrator' );
        if ( $administrator ) {
            foreach ( self::ALL as $cap ) {
                $administrator->add_cap( $cap );
            }
        }
    }
}
