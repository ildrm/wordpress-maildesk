<?php
defined('WP_UNINSTALL_PLUGIN')||exit;
if(!get_option('wpmd_delete_data_on_uninstall',false))return;
global$wpdb;
foreach(array('activity_log','jobs','rules','templates','signatures','contacts','outbox','drafts','attachments','message_folders','messages','threads','folders','account_users','accounts')as$n){$wpdb->query('DROP TABLE IF EXISTS `'.$wpdb->prefix.'wpmd_'.$n.'`');}
delete_option('wpmd_db_version');delete_option('wpmd_delete_data_on_uninstall');
