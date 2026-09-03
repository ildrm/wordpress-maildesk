<?php
namespace WPMailDesk\Infrastructure\Queue;
use WPMailDesk\Application\MailService;use WPMailDesk\Infrastructure\Database\Repository;
final class QueueRunner{public function __construct(private MailService$s,private Repository$r){}public function register():void{add_filter('cron_schedules',function($x){$x['wpmd_five_minutes']=array('interval'=>300,'display'=>'Every Five Minutes');return$x;});add_action('wpmd_queue_tick',array($this,'run'));}public function run():void{foreach($this->r->due_outbox(10)as$row){try{$this->s->sendOutbox($row);}catch(\Throwable$e){}}}}
