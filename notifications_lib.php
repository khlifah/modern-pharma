<?php
// notifications_lib.php — تسجيل وإرسال إشعارات النظام والبريد (مرن مع/بدون PHPMailer)
require_once __DIR__ . '/config.php';

/** اتصال قاعدة البيانات */
function notif_db(): PDO { return db(); }

/** فحص وجود عمود */
function col_exists_notif(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $st->execute([$table,$col]);
  return (bool)$st->fetchColumn();
}

/** إنشاء/ترقية جدول الإشعارات (آمن) */
function ensure_notifications_schema(PDO $pdo): void {
  // إنشاء أساسي
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS notifications (
      id INT AUTO_INCREMENT PRIMARY KEY,
      event_type VARCHAR(50) NOT NULL,
      title VARCHAR(200) NOT NULL,
      message TEXT NOT NULL,
      severity ENUM('info','success','warning','error') NOT NULL DEFAULT 'info',
      actor_user_id INT NULL,
      target_table VARCHAR(100) NULL,
      target_id INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      read_at DATETIME NULL,
      INDEX idx_event_type(event_type),
      INDEX idx_created_at(created_at),
      INDEX idx_read(read_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  // ترقيات آمنة لو الجدول كان قديم
  $adds = [
    'title'         => "ADD COLUMN title VARCHAR(200) NOT NULL AFTER event_type",
    'message'       => "ADD COLUMN message TEXT NOT NULL AFTER title",
    'severity'      => "ADD COLUMN severity ENUM('info','success','warning','error') NOT NULL DEFAULT 'info' AFTER message",
    'actor_user_id' => "ADD COLUMN actor_user_id INT NULL AFTER severity",
    'target_table'  => "ADD COLUMN target_table VARCHAR(100) NULL AFTER actor_user_id",
    'target_id'     => "ADD COLUMN target_id INT NULL AFTER target_table",
    'read_at'       => "ADD COLUMN read_at DATETIME NULL AFTER created_at",
  ];
  foreach ($adds as $col => $ddl) {
    if (!col_exists_notif($pdo,'notifications',$col)) {
      try { $pdo->exec("ALTER TABLE notifications $ddl"); } catch (Throwable $e) {}
    }
  }
}

/** إرسال بريد — يختار آليًا بين PHPMailer SMTP (إن وُجد) و mail() */
function send_email_simple(string $to, string $subject, string $html): bool {
  // نحاول تحميل PHPMailer عبر Composer إن وُجد
  $autoload = __DIR__ . '/vendor/autoload.php';
  if (is_file($autoload)) require_once $autoload;

  $hasPHPMailer = class_exists('PHPMailer\\PHPMailer\\PHPMailer');

  // إعدادات من file.env
  $smtpHost   = getenv('SMTP_HOST')   ?: '';
  $smtpPort   = (int)(getenv('SMTP_PORT') ?: 0);
  $smtpUser   = getenv('SMTP_USER')   ?: getenv('SMTP_USERNAME') ?: '';
  $smtpPass   = getenv('SMTP_PASS')   ?: getenv('SMTP_PASSWORD') ?: '';
  $smtpSecure = strtolower(getenv('SMTP_SECURE') ?: 'tls'); // tls | ssl

  // خلي المرسل يطابق حساب SMTP لتفادي الرفض
  $notifyFrom = getenv('NOTIFY_FROM') ?: $smtpUser;

  $canSMTP = $hasPHPMailer && $smtpHost && $smtpPort && $smtpUser && $smtpPass;

  // SMTP عبر PHPMailer أولاً
  if ($canSMTP) {
    try {
      $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
      $mail->isSMTP();
      $mail->Host       = $smtpHost;
      $mail->SMTPAuth   = true;
      $mail->Username   = $smtpUser;
      $mail->Password   = $smtpPass;
      $mail->CharSet    = 'UTF-8';

      if ($smtpSecure === 'ssl') {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        $mail->Port       = $smtpPort ?: 465;
      } else {
        $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $smtpPort ?: 587;
      }

      // اجعل المرسل نفس حساب SMTP
      $mail->setFrom($smtpUser, 'Modern Pharma');
      $mail->addAddress($to);

      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body    = $html;
      $mail->AltBody = strip_tags($html);

      $mail->send();
      return true;
    } catch (\Throwable $e) {
      error_log('[send_email_simple][SMTP] '.$e->getMessage());
      // السقوط إلى mail() بالأسفل
    }
  }

  // Fallback: mail()
  $headers  = "MIME-Version: 1.0\r\n";
  $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
  $headers .= "From: Modern Pharma <{$notifyFrom}>\r\n";
  $sub = '=?UTF-8?B?'.base64_encode($subject).'?=';
  $ok = @mail($to, $sub, $html, $headers);
  if (!$ok) error_log('[send_email_simple][mail] failed sending to '.$to);
  return $ok;
}

/** إنشاء إشعار وتخزينه وإرسال بريد */
function notify_event(
  PDO $pdo,
  string $event_type,
  string $title,
  string $message,
  string $severity = 'info',
  ?int $actor_user_id = null,
  ?string $target_table = null,
  ?int $target_id = null,
  bool $send_email = true
): int {
  ensure_notifications_schema($pdo);

  // قصّ طول العنوان (احتياط)
  if (mb_strlen($title,'UTF-8') > 200) {
    $title = mb_substr($title, 0, 200, 'UTF-8');
  }

  // حفظ الإشعار
  $st = $pdo->prepare("
    INSERT INTO notifications(event_type,title,message,severity,actor_user_id,target_table,target_id)
    VALUES (?,?,?,?,?,?,?)
  ");
  $st->execute([$event_type,$title,$message,$severity,$actor_user_id,$target_table,$target_id]);
  $id = (int)$pdo->lastInsertId();

  // إرسال بريد (اختياري)
  if ($send_email) {
    $to = getenv('ADMIN_EMAIL') ?: 'admin@example.com';
    if ($to) {
      $subject = "إشعار: $title";
      $html = "
        <div style='font-family:Tahoma,Arial,sans-serif'>
          <h3 style='margin:0 0 8px;'>".htmlspecialchars($title,ENT_QUOTES,'UTF-8')."</h3>
          <p style='margin:0 0 8px;white-space:pre-line'>".nl2br(htmlspecialchars($message,ENT_QUOTES,'UTF-8'))."</p>
          <p style='color:#6b7280;margin-top:14px'>نوع الحدث: <b>".htmlspecialchars($event_type,ENT_QUOTES,'UTF-8')."</b> — الشدة: <b>".htmlspecialchars($severity,ENT_QUOTES,'UTF-8')."</b></p>
          <p style='font-size:12px;color:#9CA3AF'>Modern Pharma</p>
        </div>
      ";
      @send_email_simple($to, $subject, $html);
    }
  }

  return $id;
}

/** تعليم إشعار واحد كمقروء */
function mark_notification_read(PDO $pdo, int $id): void {
  $st = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id=? AND read_at IS NULL");
  $st->execute([$id]);
}

/** تعليم كل الإشعارات كمقروء */
function mark_all_notifications_read(PDO $pdo): int {
  return $pdo->exec("UPDATE notifications SET read_at = NOW() WHERE read_at IS NULL");
}

/** جلب الإشعارات (مع خيار غير مقروء فقط) */
function get_notifications(PDO $pdo, bool $only_unread = false, int $limit = 200): array {
  ensure_notifications_schema($pdo);
  $sql = "SELECT * FROM notifications ".($only_unread?"WHERE read_at IS NULL ":"")."ORDER BY id DESC LIMIT ?";
  $st = $pdo->prepare($sql);
  $st->bindValue(1, $limit, PDO::PARAM_INT);
  $st->execute();
  return $st->fetchAll();
}
