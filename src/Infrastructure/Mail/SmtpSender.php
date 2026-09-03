<?php
namespace WPMailDesk\Infrastructure\Mail;

use RuntimeException;
use WPMailDesk\Infrastructure\Security\Crypto;

final class SmtpSender {
    public function __construct(private Crypto $crypto){}
    public function test(array$a):array{try{$m=$this->mailer($a);if(!$m->smtpConnect())throw new RuntimeException($m->ErrorInfo?:'SMTP connection failed.');$m->smtpClose();return array('ok'=>true);}catch(\Throwable$e){return array('ok'=>false,'error'=>$e->getMessage());}}
    public function send(array$a,array$p,string$messageId):void{
        $mail=$this->mailer($a);$mail->setFrom($a['email'],$a['display_name']?:$a['email']);
        foreach((array)($p['to']??array())as$x)$mail->addAddress($x['email']??$x,$x['name']??'');
        foreach((array)($p['cc']??array())as$x)$mail->addCC($x['email']??$x,$x['name']??'');
        foreach((array)($p['bcc']??array())as$x)$mail->addBCC($x['email']??$x,$x['name']??'');
        if(!empty($p['reply_to']))foreach((array)$p['reply_to']as$x)$mail->addReplyTo($x['email']??$x,$x['name']??'');
        if(!$mail->getToAddresses())throw new RuntimeException('At least one valid recipient is required.');
        $mail->Subject=(string)($p['subject']??'');$mail->MessageID='<'.trim($messageId,'<>').'>';$html=(string)($p['body_html']??'');$text=(string)($p['body_text']??wp_strip_all_tags($html));
        if($html){$mail->isHTML(true);$mail->Body=$html;$mail->AltBody=$text;}else{$mail->Body=$text;}
        foreach((array)($p['attachments']??array())as$file){if(!empty($file['path'])&&is_readable($file['path']))$mail->addAttachment($file['path'],sanitize_file_name($file['name']??basename($file['path'])));}
        if(!$mail->send())throw new RuntimeException($mail->ErrorInfo?:'SMTP send failed.');
    }
    private function mailer(array$a):\PHPMailer\PHPMailer\PHPMailer{
        if(!class_exists('PHPMailer\\PHPMailer\\PHPMailer')){require_once ABSPATH.WPINC.'/PHPMailer/PHPMailer.php';require_once ABSPATH.WPINC.'/PHPMailer/SMTP.php';require_once ABSPATH.WPINC.'/PHPMailer/Exception.php';}
        $m=new \PHPMailer\PHPMailer\PHPMailer(true);$m->isSMTP();$m->Host=(string)$a['smtp_host'];$m->Port=(int)$a['smtp_port'];$m->SMTPAuth=true;$m->Timeout=20;$m->SMTPAutoTLS=true;
        $sec=(string)$a['smtp_security'];if('ssl'===$sec)$m->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;elseif('tls'===$sec)$m->SMTPSecure=\PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;else$m->SMTPSecure='';
        $m->Username=(string)($a['username']?:$a['email']);
        if('oauth'===$a['auth_type'])throw new RuntimeException('OAuth SMTP requires the provider OAuth adapter; use an app password in this standalone build or extend wpmd_smtp_mailer.');
        $m->Password=(string)$this->crypto->decrypt($a['secret_enc']??null);$m->CharSet='UTF-8';
        return apply_filters('wpmd_smtp_mailer',$m,$a);
    }
}
