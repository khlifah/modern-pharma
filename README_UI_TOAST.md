# UI Toast + Email Guide

هذه الحزمة تضيف **تنبيهات تظهر داخل الصفحة (توست)** بدون أي مكتبات خارجية، وتستعمل جداول الإشعارات الموجودة أصلاً في مشروعك.
كما يعتمد الإرسال البريدي على الدالة `send_email_simple` في `notifications_lib.php`.

## ماذا تحتوي؟
- `ui_toast.css` و `ui_toast.js`: توست بسيط وجاهز.
- `ui_notifications_snippet.php`: جزء PHP يقرأ آخر 5 إشعارات غير مقروءة ويعرضها كتوست.

## كيفية التركيب (أقل تغييرات ممكنة):
1) ضع الملفات الثلاثة في جذر مشروعك (أو داخل `موردن2/` لو هذا جذر تطبيق الويب عندك).
2) في الصفحات التي تريد أن يظهر فيها الإشعار (مثل `dashboard.php` أو `index.php`):
   أضِف السطر التالي قبل `</body>`:
   ```php
   <?php include __DIR__ . '/ui_notifications_snippet.php'; ?>
   ```
   > هذا لا يعلّم الإشعارات كمقروء تلقائيًا. التعليم يتم من صفحة `notifications.php`.

3) **الإشعار البريدي**:
   - عند إنشاء حدث، استدعِ `notify_event($pdo, $event_type, $title, $message, $severity, $actor_user_id, $target_table, $target_id, /* $email_to */ 'user@example.com');`
   - تأكد من ضبط `NOTIFY_FROM` في `file.env`.
   - إن كان خادمك لا يرسل بريدًا عبر `mail()`، استبدل محتوى `send_email_simple` بتهيئة SMTP (PHPMailer) — أو أخبرني أجهز لك نسخة SMTP.

## ملاحظات
- إن لم يكن جدول `notifications` موجودًا، أنشئه بسكربت SQL الذي أرسلته لك سابقًا، أو استخدم الحزمة السابقة التي تضمن إنشاء الجدول تلقائيًا.
- لتفعيل عدّاد في القائمة، يمكنك استخدام:
  ```php
  $st = $pdo->query("SELECT COUNT(*) FROM notifications WHERE read_at IS NULL");
  $unread = (int)$st->fetchColumn();
  echo '<a href="notifications.php">🔔 الإشعارات '.($unread?'<span class="badge">'.$unread.'</span>':'').'</a>';
  ```
تاريخ: 2025-08-30T18:30:28.901297Z
