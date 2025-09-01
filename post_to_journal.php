<?php
require_once __DIR__ . '/auth.php';
if (!function_exists('db')) require_once __DIR__ . '/config.php';
$pdo = db();

/* Helpers */
function table_exists(PDO $pdo, string $table): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1");
  $st->execute([$table]);
  return (bool)$st->fetchColumn();
}

function col_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}

function safe_count_unposted(PDO $pdo, ?string $table): int {
  if (!$table) return 0;
  if (!table_exists($pdo, $table)) return 0;
  if (!col_exists($pdo, $table, 'posted')) return 0;
  $st = $pdo->query("SELECT COUNT(*) FROM `$table` WHERE posted=0");
  return (int)$st->fetchColumn();
}

function safe_mark_posted(PDO $pdo, ?string $table, array &$notes): void {
  if (!$table) { $notes[] = "تخطي: لا يوجد جدول مناسب."; return; }
  if (!table_exists($pdo, $table)) { $notes[] = "تخطي: الجدول $table غير موجود."; return; }
  if (!col_exists($pdo, $table, 'posted')) { $notes[] = "تخطي: الجدول $table لا يحتوي عمود posted."; return; }
  $aff = $pdo->exec("UPDATE `$table` SET posted=1 WHERE posted=0");
  $notes[] = "تم تعليم $aff صف/صفوف كـ مُرحّلة في $table.";
}

/* Resolve table names available in DB */
$tblReceipt = table_exists($pdo,'receipts') ? 'receipts' :
              (table_exists($pdo,'receipt_vouchers') ? 'receipt_vouchers' : null);

$tblPayment = table_exists($pdo,'payments') ? 'payments' :
              (table_exists($pdo,'payment_vouchers') ? 'payment_vouchers' : null);

$tblAdjust  = table_exists($pdo,'adjustment_entries') ? 'adjustment_entries' : null;

$errors = [];
$notes  = [];
$ok     = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $what = $_POST['what'] ?? 'all';
  try {
    if ($what === 'receipt' || $what === 'all') safe_mark_posted($pdo, $tblReceipt, $notes);
    if ($what === 'payment' || $what === 'all') safe_mark_posted($pdo, $tblPayment, $notes);
    if ($what === 'adjust'  || $what === 'all') safe_mark_posted($pdo, $tblAdjust,  $notes);
    $ok = 'تمت العملية.';
  } catch (Throwable $e) {
    $errors[] = 'خطأ: ' . $e->getMessage();
  }
}

/* counts */
$rc = safe_count_unposted($pdo, $tblReceipt);
$pc = safe_count_unposted($pdo, $tblPayment);
$ac = safe_count_unposted($pdo, $tblAdjust);

?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ترحيل العمليات إلى قيود اليومية - موردن</title>
<style>
  body{margin:0;font-family:Tahoma,Arial,sans-serif;background:#f5f8fc;color:#1f2937}
  .header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;color:#fff;padding:12px 16px}
  a{color:#64ffda;text-decoration:none}
  .wrap{padding:18px}
  .card{background:#fff;border:1px solid #dfe7f5;border-radius:12px;padding:16px;max-width:760px}
  .btn{border:0;border-radius:10px;padding:10px 14px;background:#13c2b3;color:#062427;font-weight:700;cursor:pointer}
  .msg-ok{background:#e6fff7;border:1px solid #a7f3d0;padding:10px;border-radius:10px;margin:6px 0}
  .msg-err{background:#ffecec;border:1px solid #ffb3b3;padding:10px;border-radius:10px;margin:6px 0}
  .note{background:#f9fafb;border:1px dashed #d1d5db;padding:8px;border-radius:8px;margin:6px 0}
  select{padding:10px;border-radius:10px;border:1px solid #cfd9ec}
  form{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
</style>
</head>
<body>
  <div class="header">
    <div>ترحيل العمليات إلى قيود اليومية</div>
    <nav><a href="./dashboard.php">الرجوع</a></nav>
  </div>
  <div class="wrap">
    <div class="card">
      <?php foreach ($errors as $e): ?>
        <div class="msg-err"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endforeach; ?>
      <?php if ($ok): ?>
        <div class="msg-ok"><?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endif; ?>
      <?php foreach ($notes as $n): ?>
        <div class="note"><?= htmlspecialchars($n, ENT_QUOTES, 'UTF-8') ?></div>
      <?php endforeach; ?>

      <p>
        غير مُرحّل:
        قبض (<?= (int)$rc ?>) — صرف (<?= (int)$pc ?>) — تسويات (<?= (int)$ac ?>)
      </p>

      <form method="post">
        <select name="what">
          <option value="all">الكل</option>
          <option value="receipt">سندات قبض فقط<?= $tblReceipt ? '' : ' — (لا يوجد جدول)'; ?></option>
          <option value="payment">سندات صرف فقط<?= $tblPayment ? '' : ' — (لا يوجد جدول)'; ?></option>
          <option value="adjust">قيود تسوية فقط<?= $tblAdjust ? '' : ' — (لا يوجد جدول)'; ?></option>
        </select>
        <button class="btn" type="submit">ترحيل/تعليم</button>
      </form>

      <div class="note" style="margin-top:10px">
        ملاحظة: يتم استخدام عمود <b>posted</b> إن كان موجودًا في الجداول. إذا كان غير موجود، يتم التخطي مع عرض ملاحظة.
      </div>
    </div>
  </div>
</body>
</html>
