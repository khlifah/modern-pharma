<?php
// events.php — غلاف بسيط حول مكتبة الإشعارات
require_once __DIR__ . '/notifications_lib.php';

/**
 * إشعار موحد للنظام + بريد.
 * لا يرمي أخطاء (حتى لو تعطل البريد) حتى لا يعطّل حفظ العملية.
 */
function app_notify(
  PDO $pdo,
  string $event_type,   // مثال: 'product_created', 'purchase_created', 'sale_created', ...
  string $title,        // مثال: "فاتورة مبيعات #15"
  string $message,      // مثال: "إجمالي الفاتورة 1,250.00 ريال"
  string $severity='info', // info|success|warning|error
  ?int $actor_user_id = null,
  ?string $target_table = null,
  ?int $target_id = null,
  bool $send_email = true
): void {
  try {
    notify_event(
      $pdo, $event_type, $title, $message, $severity,
      $actor_user_id, $target_table, $target_id, $send_email
    );
  } catch (Throwable $e) {
    // تجاهل أي خطأ إشعار (لا نكسر العملية الأساسية)
    error_log('[app_notify] '.$e->getMessage());
  }
}
