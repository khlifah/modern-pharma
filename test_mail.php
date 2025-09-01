<?php
// test_mail.php — اختبار إرسال عبر PHPMailer/Gmail SMTP
error_reporting(E_ALL); ini_set('display_errors', 1);

require __DIR__ . '/vendor/autoload.php'; // تأكد composer require phpmailer/phpmailer
require __DIR__ . '/config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

try {
    $host   = getenv('SMTP_HOST')   ?: 'smtp.gmail.com';
    $port   = (int)(getenv('SMTP_PORT') ?: 587);
    $user   = getenv('SMTP_USER')   ?: getenv('SMTP_USERNAME');
    $pass   = getenv('SMTP_PASS')   ?: getenv('SMTP_PASSWORD');
    $secure = strtolower(getenv('SMTP_SECURE') ?: 'tls'); // tls | ssl

    $from   = getenv('NOTIFY_FROM') ?: $user;
    $to     = getenv('ADMIN_EMAIL') ?: $user;

    if (!$user || !$pass) {
        throw new Exception('بيانات SMTP ناقصة: SMTP_USER/SMTP_PASS');
    }

    $mail = new PHPMailer(true);
    $mail->SMTPDebug   = 2;      // 2 أو 3 لشرح أكثر
    $mail->Debugoutput = 'html';

    $mail->isSMTP();
    $mail->Host       = $host;
    $mail->SMTPAuth   = true;
    $mail->Username   = $user;
    $mail->Password   = $pass;
    $mail->CharSet    = 'UTF-8';

    if ($secure === 'ssl') {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = $port ?: 465;
    } else {
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $port ?: 587;
    }

    // اجعل المرسل نفس حساب SMTP
    $mail->setFrom($user, 'Modern Pharma');
    $mail->addAddress($to);

    $mail->isHTML(true);
    $mail->Subject = 'اختبار SMTP (PHPMailer)';
    $mail->Body    = '<b>إذا وصل هذا الإيميل فكل شيء تمام ✅</b>';
    $mail->AltBody = 'نجاح الاختبار';

    $mail->send();
    echo "✅ تم الإرسال إلى {$to}";
} catch (Exception $e) {
    echo "❌ فشل الإرسال: " . $e->getMessage();
}
