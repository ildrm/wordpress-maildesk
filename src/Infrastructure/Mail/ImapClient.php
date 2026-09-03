<?php
namespace WPMailDesk\Infrastructure\Mail;

use RuntimeException;
use WPMailDesk\Infrastructure\Security\Crypto;

final class ImapClient {
    private Crypto $crypto;
    private $stream = null;
    private int $tag = 0;
    public function __construct(Crypto $crypto){$this->crypto=$crypto;}

    public function test(array $account): array {
        try {$this->connect($account);$caps=$this->command('CAPABILITY');$this->disconnect();return array('ok'=>true,'capabilities'=>$caps);}catch(\Throwable$e){$this->disconnect();return array('ok'=>false,'error'=>$e->getMessage());}
    }

    public function folders(array $account): array {
        $this->connect($account);$lines=$this->command('LIST "" "*"');$this->disconnect();$out=array();
        foreach($lines as$l){if(!str_starts_with($l,'* LIST '))continue;if(preg_match('/^\* LIST \(([^)]*)\)\s+("[^"]*"|NIL)\s+(.+)$/i',$l,$m)){$flags=preg_split('/\s+/',trim($m[1]));$delimiter='NIL'===strtoupper($m[2])?null:trim($m[2],'"');$name=$this->decodeMailbox(trim($m[3],'"'));$special=null;foreach(array('\\Inbox','\\Sent','\\Drafts','\\Trash','\\Junk','\\Archive','\\All','\\Flagged')as$s){if(in_array($s,$flags,true)){$special=$s;break;}}if('INBOX'===strtoupper($name))$special='\\Inbox';$out[]=array('remote_name'=>$name,'display_name'=>$name,'delimiter'=>$delimiter,'special_use'=>$special);}}
        return$out;
    }

    public function fetchRecent(array $account,string $folder,int $days=30,int $limit=250): array {
        $this->connect($account);$this->command('SELECT '.$this->quoteMailbox($folder));$status=$this->lastSelectStatus;
        $since=gmdate('d-M-Y',time()-DAY_IN_SECONDS*max(1,$days));$search=$this->command('UID SEARCH SINCE '.$since);$uids=array();foreach($search as$l){if(str_starts_with($l,'* SEARCH')){$parts=preg_split('/\s+/',trim(substr($l,8)));$uids=array_values(array_filter(array_map('intval',$parts)));}}
        if(count($uids)>$limit)$uids=array_slice($uids,-$limit);
        $messages=array();
        foreach(array_chunk($uids,25)as$chunk){if(!$chunk)continue;$set=implode(',',$chunk);$lines=$this->command('UID FETCH '.$set.' (UID FLAGS RFC822.SIZE BODY.PEEK[HEADER.FIELDS (MESSAGE-ID IN-REPLY-TO REFERENCES SUBJECT FROM TO CC BCC REPLY-TO DATE CONTENT-TYPE)] BODY.PEEK[TEXT]<0.8192>)',true);$messages=array_merge($messages,$this->parseFetch($lines));}
        $this->disconnect();return array('status'=>$status,'messages'=>$messages);
    }

    private array $lastSelectStatus=array();
    private function connect(array $a):void{
        $host=(string)$a['imap_host'];$port=(int)$a['imap_port'];$sec=(string)$a['imap_security'];if(!$host||!$port)throw new RuntimeException('IMAP host/port not configured.');
        $transport='ssl'===$sec?'ssl://':'tcp://';$ctx=stream_context_create(array('ssl'=>array('verify_peer'=>true,'verify_peer_name'=>true,'allow_self_signed'=>false,'SNI_enabled'=>true)));
        $this->stream=@stream_socket_client($transport.$host.':'.$port,$errno,$errstr,15,STREAM_CLIENT_CONNECT,$ctx);if(!$this->stream)throw new RuntimeException("IMAP connection failed: {$errstr} ({$errno})");stream_set_timeout($this->stream,20);$greet=$this->readLine();if(!str_starts_with($greet,'* OK'))throw new RuntimeException('Unexpected IMAP greeting.');
        if('tls'===$sec){$this->command('STARTTLS');if(!stream_socket_enable_crypto($this->stream,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new RuntimeException('Unable to enable IMAP TLS.');}
        $username=(string)($a['username']?:$a['email']);$secret=$this->crypto->decrypt($a['secret_enc']??null);
        if('oauth'===$a['auth_type']){$token=$this->crypto->decrypt($a['oauth_access_enc']??null);if(!$token)throw new RuntimeException('OAuth access token unavailable.');$auth=base64_encode("user={$username}\x01auth=Bearer {$token}\x01\x01");$this->command('AUTHENTICATE XOAUTH2 '.$auth);}else{if(null===$secret)throw new RuntimeException('Account password/app password unavailable.');$this->command('LOGIN '.$this->quote($username).' '.$this->quote($secret));}
    }
    private function disconnect():void{if(is_resource($this->stream)){try{$this->command('LOGOUT');}catch(\Throwable$e){}fclose($this->stream);} $this->stream=null;}
    private function command(string $command,bool $allowLiteral=false):array{$tag='A'.str_pad((string)++$this->tag,4,'0',STR_PAD_LEFT);if(!is_resource($this->stream))throw new RuntimeException('IMAP not connected.');fwrite($this->stream,$tag.' '.$command."\r\n");$lines=array();$this->lastSelectStatus=array();while(!feof($this->stream)){$line=$this->readLine();$lines[]=$line;if(str_starts_with($line,'* OK [UIDVALIDITY ')&&preg_match('/UIDVALIDITY\s+(\d+)/',$line,$m))$this->lastSelectStatus['uidvalidity']=(int)$m[1];if(str_starts_with($line,'* OK [UIDNEXT ')&&preg_match('/UIDNEXT\s+(\d+)/',$line,$m))$this->lastSelectStatus['uidnext']=(int)$m[1];if(str_starts_with($line,$tag.' ')){if(!str_starts_with($line,$tag.' OK'))throw new RuntimeException('IMAP command failed: '.preg_replace('/^'.preg_quote($tag,'/').'\s+/','',$line));break;}if($allowLiteral&&preg_match('/\{(\d+)\}$/',$line,$m)){$len=(int)$m[1];$literal='';while(strlen($literal)<$len&&!feof($this->stream)){$literal.=fread($this->stream,$len-strlen($literal));}$lines[]=$literal;}}
        return$lines;}
    private function readLine():string{$line=fgets($this->stream,65536);if(false===$line)throw new RuntimeException('IMAP connection closed unexpectedly.');return rtrim($line,"\r\n");}
    private function quote(string$s):string{return '"'.str_replace(array('\\','"'),array('\\\\','\\"'),$s).'"';}
    private function quoteMailbox(string$s):string{return$this->quote($s);}
    private function decodeMailbox(string$s):string{return function_exists('mb_convert_encoding')?(string)@mb_convert_encoding($s,'UTF-8','UTF7-IMAP'):$s;}

    private function parseFetch(array$lines):array{$out=array();$current=null;for($i=0;$i<count($lines);$i++){$l=$lines[$i];if(preg_match('/^\* \d+ FETCH \((.*)$/',$l,$m)){$current=array('uid'=>0,'flags'=>array(),'size'=>0,'headers'=>'','body'=>'');if(preg_match('/\bUID\s+(\d+)/',$l,$x))$current['uid']=(int)$x[1];if(preg_match('/\bRFC822\.SIZE\s+(\d+)/',$l,$x))$current['size']=(int)$x[1];if(preg_match('/\bFLAGS\s+\(([^)]*)\)/',$l,$x))$current['flags']=preg_split('/\s+/',trim($x[1]));continue;}if(null!==$current&&''!==$l&&$l[0]!=='*'&&!preg_match('/^A\d{4}\s/',$l)){if(''===$current['headers'])$current['headers']=$l;else$current['body'].=$l."\n";}if(null!==$current&&')'===$l){$out[]=$this->normalizeMessage($current);$current=null;}}
        if($current&&$current['uid'])$out[]=$this->normalizeMessage($current);return$out;}
    private function normalizeMessage(array$r):array{$headers=$this->parseHeaders($r['headers']);$subject=$this->decodeHeader($headers['subject']??'');$from=$this->parseAddresses($headers['from']??'');$to=$this->parseAddresses($headers['to']??'');$date=!empty($headers['date'])?strtotime($headers['date']):time();return array('uid'=>$r['uid'],'flags'=>$r['flags'],'message'=>array('internet_message_id'=>trim((string)($headers['message-id']??''),'<> '),'subject'=>$subject,'normalized_subject'=>preg_replace('/^(re|fwd?|aw):\s*/i','',$subject),'from_json'=>wp_json_encode($from),'to_json'=>wp_json_encode($to),'cc_json'=>wp_json_encode($this->parseAddresses($headers['cc']??'')),'bcc_json'=>wp_json_encode($this->parseAddresses($headers['bcc']??'')),'reply_to_json'=>wp_json_encode($this->parseAddresses($headers['reply-to']??'')),'headers_json'=>wp_json_encode(array_intersect_key($headers,array_flip(array('message-id','in-reply-to','references','date','content-type')))),'body_text'=>wp_strip_all_tags($r['body']),'body_html'=>'','body_preview'=>mb_substr(trim(preg_replace('/\s+/u',' ',wp_strip_all_tags($r['body']))),0,280),'received_at'=>gmdate('Y-m-d H:i:s',$date?:time()),'sent_at'=>gmdate('Y-m-d H:i:s',$date?:time()),'size_bytes'=>$r['size'],'has_attachments'=>0,'is_read'=>in_array('\\Seen',$r['flags'],true)?1:0,'is_starred'=>in_array('\\Flagged',$r['flags'],true)?1:0,'_flags'=>$r['flags']));}
    private function parseHeaders(string$raw):array{$raw=preg_replace("/\r?\n[ \t]+/",' ',$raw);$h=array();foreach(preg_split('/\r?\n/',$raw)as$l){$pos=strpos($l,':');if(false===$pos)continue;$k=strtolower(trim(substr($l,0,$pos)));$h[$k]=trim(substr($l,$pos+1));}return$h;}
    private function decodeHeader(string$s):string{if(function_exists('iconv_mime_decode')){$d=@iconv_mime_decode($s,ICONV_MIME_DECODE_CONTINUE_ON_ERROR,'UTF-8');if(false!==$d)return$d;}return$s;}
    private function parseAddresses(string$s):array{$out=array();foreach(str_getcsv($s,',')as$p){$p=trim($p);if(!$p)continue;if(preg_match('/^(.*)<([^>]+)>$/',$p,$m))$out[]=array('name'=>trim($this->decodeHeader(trim($m[1]))," \t\n\r\0\x0B\""),'email'=>sanitize_email(trim($m[2])));else$out[]=array('name'=>'','email'=>sanitize_email(trim($p," <>")));}return array_values(array_filter($out,fn($a)=>!empty($a['email'])));}
}
