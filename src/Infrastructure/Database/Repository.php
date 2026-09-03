<?php
namespace WPMailDesk\Infrastructure\Database;

final class Repository {
    private function t( string $name ): string { global $wpdb; return $wpdb->prefix . 'wpmd_' . $name; }
    private function now(): string { return current_time( 'mysql', true ); }

    public function accounts_for_user( int $user_id ): array {
        global $wpdb;
        $accounts = $this->t( 'accounts' );
        $shared   = $this->t( 'account_users' );
        return $wpdb->get_results( $wpdb->prepare(
            "SELECT DISTINCT a.* FROM {$accounts} a LEFT JOIN {$shared} au ON au.account_id=a.id WHERE a.owner_user_id=%d OR au.user_id=%d ORDER BY a.label,a.email",
            $user_id, $user_id
        ), ARRAY_A ) ?: array();
    }

    public function account( int $id, int $user_id = 0 ): ?array {
        global $wpdb;
        $table = $this->t( 'accounts' );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id=%d", $id ), ARRAY_A );
        if ( ! $row ) return null;
        if ( $user_id && ! $this->user_can_access_account( $user_id, $id ) ) return null;
        return $row;
    }

    public function user_can_access_account( int $user_id, int $account_id ): bool {
        global $wpdb;
        $accounts = $this->t( 'accounts' );
        $shared = $this->t( 'account_users' );
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$accounts} a LEFT JOIN {$shared} au ON au.account_id=a.id WHERE a.id=%d AND (a.owner_user_id=%d OR au.user_id=%d)",
            $account_id, $user_id, $user_id
        ) );
        return $count > 0;
    }

    public function save_account( array $data, int $user_id ): int {
        global $wpdb;
        $table = $this->t( 'accounts' );
        $now = $this->now();
        $id = absint( $data['id'] ?? 0 );
        unset( $data['id'] );
        $data['updated_at'] = $now;
        if ( $id ) {
            $existing = $this->account( $id, $user_id );
            if ( ! $existing || ( (int) $existing['owner_user_id'] !== $user_id && ! current_user_can( 'wpmd_manage_all_accounts' ) ) ) return 0;
            $wpdb->update( $table, $data, array( 'id' => $id ) );
            return $id;
        }
        $data['owner_user_id'] = $user_id;
        $data['created_at'] = $now;
        $wpdb->insert( $table, $data );
        return (int) $wpdb->insert_id;
    }

    public function delete_account( int $id, int $user_id ): bool {
        global $wpdb;
        $a = $this->account( $id, $user_id );
        if ( ! $a || ( (int) $a['owner_user_id'] !== $user_id && ! current_user_can( 'wpmd_manage_all_accounts' ) ) ) return false;
        foreach ( array( 'attachments','message_folders','messages','threads','folders','drafts','outbox','account_users' ) as $name ) {
            $table = $this->t( $name );
            if ( 'attachments' === $name ) {
                $messages = $this->t( 'messages' );
                $wpdb->query( $wpdb->prepare( "DELETE at FROM {$table} at INNER JOIN {$messages} m ON m.id=at.message_id WHERE m.account_id=%d", $id ) );
            } elseif ( 'message_folders' === $name ) {
                $folders = $this->t( 'folders' );
                $wpdb->query( $wpdb->prepare( "DELETE mf FROM {$table} mf INNER JOIN {$folders} f ON f.id=mf.folder_id WHERE f.account_id=%d", $id ) );
            } else {
                $col = 'account_users' === $name ? 'account_id' : 'account_id';
                $wpdb->delete( $table, array( $col => $id ) );
            }
        }
        return false !== $wpdb->delete( $this->t( 'accounts' ), array( 'id' => $id ) );
    }

    public function upsert_folder( int $account_id, array $data ): int {
        global $wpdb;
        $table = $this->t( 'folders' );
        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE account_id=%d AND remote_name=%s", $account_id, $data['remote_name'] ) );
        if ( $existing ) {
            $wpdb->update( $table, $data, array( 'id' => (int) $existing ) );
            return (int) $existing;
        }
        $data['account_id'] = $account_id;
        $wpdb->insert( $table, $data );
        return (int) $wpdb->insert_id;
    }

    public function folders( int $account_id ): array {
        global $wpdb; $t=$this->t('folders');
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE account_id=%d ORDER BY CASE special_use WHEN '\\Inbox' THEN 0 WHEN '\\Drafts' THEN 1 WHEN '\\Sent' THEN 2 WHEN '\\Junk' THEN 3 WHEN '\\Trash' THEN 4 ELSE 10 END,display_name", $account_id ), ARRAY_A ) ?: array();
    }

    public function folder( int $id ): ?array { global $wpdb; $t=$this->t('folders'); return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$t} WHERE id=%d",$id),ARRAY_A) ?: null; }

    public function upsert_message( int $account_id, int $folder_id, int $uidvalidity, int $uid, array $message ): int {
        global $wpdb;
        $mf = $this->t('message_folders'); $mt=$this->t('messages');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT message_id FROM {$mf} WHERE folder_id=%d AND uidvalidity=%d AND remote_uid=%d",$folder_id,$uidvalidity,$uid));
        $now=$this->now();
        $flags=$message['_flags']??array();unset($message['_flags']);
        if ($existing) {
            $message['updated_at']=$now;
            $wpdb->update($mt,$message,array('id'=>(int)$existing));
            $wpdb->update($mf,array('flags'=>wp_json_encode($flags)),array('folder_id'=>$folder_id,'uidvalidity'=>$uidvalidity,'remote_uid'=>$uid));
            return (int)$existing;
        }
        if ( ! empty($message['internet_message_id']) ) {
            $same = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$mt} WHERE account_id=%d AND internet_message_id=%s LIMIT 1",$account_id,$message['internet_message_id']));
            if ($same) {
                $message['updated_at']=$now;
                $wpdb->update($mt,$message,array('id'=>(int)$same));
                $wpdb->insert($mf,array('message_id'=>(int)$same,'folder_id'=>$folder_id,'remote_uid'=>$uid,'uidvalidity'=>$uidvalidity,'modseq'=>null,'flags'=>wp_json_encode($flags)));
                return (int)$same;
            }
        }
        $message['account_id']=$account_id; $message['created_at']=$now; $message['updated_at']=$now;
        $wpdb->insert($mt,$message); $id=(int)$wpdb->insert_id;
        $wpdb->insert($mf,array('message_id'=>$id,'folder_id'=>$folder_id,'remote_uid'=>$uid,'uidvalidity'=>$uidvalidity,'flags'=>wp_json_encode($flags)));
        return $id;
    }

    public function messages( int $user_id, array $args ): array {
        global $wpdb;
        $m=$this->t('messages'); $mf=$this->t('message_folders'); $f=$this->t('folders');
        $where=array('1=1'); $params=array();
        if (!empty($args['account_id'])) { if(!$this->user_can_access_account($user_id,(int)$args['account_id'])) return array(); $where[]='m.account_id=%d';$params[]=(int)$args['account_id']; }
        if (!empty($args['folder_id'])) { $folder=$this->folder((int)$args['folder_id']); if(!$folder||!$this->user_can_access_account($user_id,(int)$folder['account_id'])) return array(); $where[]='mf.folder_id=%d';$params[]=(int)$args['folder_id']; }
        if (isset($args['unread']) && ''!==$args['unread']) {$where[]='m.is_read=%d';$params[]=$args['unread']?0:1;}
        if (!empty($args['search'])) { $like='%'.$wpdb->esc_like((string)$args['search']).'%';$where[]='(m.subject LIKE %s OR m.body_preview LIKE %s OR m.from_json LIKE %s)';array_push($params,$like,$like,$like);}
        if (empty($args['account_id']) && empty($args['folder_id'])) {
            $ids=array_map('intval',wp_list_pluck($this->accounts_for_user($user_id),'id'));
            if(!$ids)return array(); $where[]='m.account_id IN ('.implode(',',array_fill(0,count($ids),'%d')).')';$params=array_merge($params,$ids);
        }
        $limit=max(1,min(100,(int)($args['limit']??50))); $offset=max(0,(int)($args['offset']??0));
        $sql="SELECT DISTINCT m.* FROM {$m} m LEFT JOIN {$mf} mf ON mf.message_id=m.id WHERE ".implode(' AND ',$where)." ORDER BY COALESCE(m.received_at,m.sent_at,m.created_at) DESC LIMIT %d OFFSET %d";
        $params[]=$limit;$params[]=$offset;
        return $wpdb->get_results($wpdb->prepare($sql,$params),ARRAY_A)?:array();
    }

    public function message( int $id, int $user_id ): ?array {
        global $wpdb;$m=$this->t('messages');
        $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$m} WHERE id=%d",$id),ARRAY_A);
        if(!$row||!$this->user_can_access_account($user_id,(int)$row['account_id']))return null;
        return $row;
    }

    public function set_message_state( int $id, int $user_id, array $fields ): bool {
        global $wpdb; if(!$this->message($id,$user_id))return false;
        return false!==$wpdb->update($this->t('messages'),$fields,array('id'=>$id));
    }

    public function save_draft( array $data, int $user_id ): int {
        global $wpdb;$t=$this->t('drafts');$id=absint($data['id']??0);unset($data['id']);$data['user_id']=$user_id;$data['updated_at']=$this->now();
        if($id){$owner=$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$t} WHERE id=%d",$id));if((int)$owner!==$user_id)return 0;$wpdb->update($t,$data,array('id'=>$id));return $id;}
        $data['created_at']=$this->now();$wpdb->insert($t,$data);return(int)$wpdb->insert_id;
    }
    public function drafts( int $user_id ): array {global$wpdb;$t=$this->t('drafts');return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE user_id=%d AND status='draft' ORDER BY updated_at DESC",$user_id),ARRAY_A)?:array();}

    public function queue_outbox( int $account_id, int $user_id, array $payload, string $scheduled_at, string $message_id ): int {
        global $wpdb;$t=$this->t('outbox');$now=$this->now();$uuid=wp_generate_uuid4();
        $wpdb->insert($t,array('uuid'=>$uuid,'account_id'=>$account_id,'user_id'=>$user_id,'message_id_header'=>$message_id,'payload_json'=>wp_json_encode($payload),'status'=>'queued','attempts'=>0,'scheduled_at'=>$scheduled_at,'created_at'=>$now,'updated_at'=>$now));return(int)$wpdb->insert_id;
    }
    public function due_outbox( int $limit=10 ): array {global$wpdb;$t=$this->t('outbox');return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE status IN ('queued','retrying') AND scheduled_at<=%s ORDER BY scheduled_at ASC LIMIT %d",$this->now(),$limit),ARRAY_A)?:array();}
    public function update_outbox( int $id,array $data ): void {global$wpdb;$data['updated_at']=$this->now();$wpdb->update($this->t('outbox'),$data,array('id'=>$id));}

    public function contacts( int $user_id ): array {global$wpdb;$t=$this->t('contacts');return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE user_id=%d ORDER BY display_name,first_name,last_name",$user_id),ARRAY_A)?:array();}
    public function save_contact(array $data,int$user_id):int{global$wpdb;$t=$this->t('contacts');$id=absint($data['id']??0);unset($data['id']);$data['user_id']=$user_id;$data['updated_at']=$this->now();if($id){$own=(int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$t} WHERE id=%d",$id));if($own!==$user_id)return 0;$wpdb->update($t,$data,array('id'=>$id));return$id;}$data['created_at']=$this->now();$wpdb->insert($t,$data);return(int)$wpdb->insert_id;}

    public function simple_list(string$type,int$user_id):array{global$wpdb;if(!in_array($type,array('signatures','templates','rules'),true))return array();$t=$this->t($type);$order='rules'===$type?'priority ASC,id ASC':'id DESC';return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE user_id=%d ORDER BY {$order}",$user_id),ARRAY_A)?:array();}
    public function save_simple(string$type,array$data,int$user_id):int{global$wpdb;if(!in_array($type,array('signatures','templates','rules'),true))return 0;$t=$this->t($type);$id=absint($data['id']??0);unset($data['id']);$data['user_id']=$user_id;if($id){$own=(int)$wpdb->get_var($wpdb->prepare("SELECT user_id FROM {$t} WHERE id=%d",$id));if($own!==$user_id)return 0;$wpdb->update($t,$data,array('id'=>$id));return$id;}$wpdb->insert($t,$data);return(int)$wpdb->insert_id;}

    public function log( string $action, ?int $account_id=null, ?int $object_id=null, string $result='success', array $context=array() ): void {global$wpdb;$wpdb->insert($this->t('activity_log'),array('user_id'=>get_current_user_id()?:null,'account_id'=>$account_id,'action'=>$action,'object_type'=>$object_id?'message':null,'object_id'=>$object_id,'result'=>$result,'context_json'=>wp_json_encode($context),'created_at'=>$this->now()));}
    public function logs(int$user_id,int$limit=100):array{global$wpdb;$t=$this->t('activity_log');if(current_user_can('wpmd_manage_all_accounts'))return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} ORDER BY created_at DESC LIMIT %d",$limit),ARRAY_A)?:array();return$wpdb->get_results($wpdb->prepare("SELECT * FROM {$t} WHERE user_id=%d ORDER BY created_at DESC LIMIT %d",$user_id,$limit),ARRAY_A)?:array();}

    public function stats(): array {global$wpdb;$out=array();foreach(array('accounts','folders','messages','attachments','outbox','jobs')as$n){$t=$this->t($n);$out[$n]=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$t}");}return$out;}
}
