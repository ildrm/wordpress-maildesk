<?php
namespace WPMailDesk\WordPress;
use WPMailDesk\Infrastructure\Database\Repository;
final class Privacy{public function __construct(private Repository$r){}public function register():void{add_action('admin_init',function(){if(function_exists('wp_add_privacy_policy_content'))wp_add_privacy_policy_content('MailDesk','<p>'.esc_html__('MailDesk connects to configured email servers and may cache message metadata, message bodies, contacts, drafts and account configuration in the WordPress database. Account secrets are encrypted. Site administrators should define retention and access policies appropriate to their organization.','wp-maildesk').'</p>');});}}
