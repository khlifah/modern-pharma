<?php
// تضمين هذا الملف في أي صفحة تريد فيها ظهور توست الإشعارات (مثلاً داخل dashboard.php قبل </body>)
require_once __DIR__ . '/notifications_lib.php';
$pdo = notif_db();
// تأكد من وجود الجدول مرة واحدة (حزمتنا السابقة تضيفها تلقائياً أيضاً)
if (function_exists('ensure_notifications_schema')) { ensure_notifications_schema($pdo); }

// اجلب آخر 5 إشعارات غير مقروءة (لا نعلّمها كمقروء تلقائياً)
$list = [];
try {
  if (function_exists('get_notifications')) {
    $list = get_notifications($pdo, /*only_unread*/ true, /*limit*/ 5);
  }
} catch (Throwable $e) {
  // لا شيء: إن فشلت، لا نكسر الصفحة
}
?>
<link rel="stylesheet" href="/ui_toast.css">
<script src="/ui_toast.js"></script>
<script>
  document.addEventListener('DOMContentLoaded', function(){
    <?php foreach ($list as $r): ?>
      window.showToast({
        title: <?= json_encode($r['title'], JSON_UNESCAPED_UNICODE) ?>,
        message: <?= json_encode($r['message'], JSON_UNESCAPED_UNICODE) ?>,
        severity: <?= json_encode($r['severity']) ?>,
        meta: <?= json_encode('نوع: '.$r['event_type'].' • #'.$r['id'].' • '.($r['read_at']===null?'غير مقروء':'مقروء'), JSON_UNESCAPED_UNICODE) ?>,
        ttl: 8000
      });
    <?php endforeach; ?>
  });
</script>
