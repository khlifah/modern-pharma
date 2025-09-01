<?php
// report_cash_book.php — يومية الصندوق (تصحيح HY093 + توحيد collation)
require_once __DIR__ . '/auth.php';
if (!function_exists('db')) require_once __DIR__ . '/config.php';
$pdo = db();

$title = "يومية الصندوق";

/* ===== Helpers ===== */
function col_exists_rb(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
                       WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
  $st->execute([$table,$col]);
  return (bool)$st->fetchColumn();
}
function pick_col_rb(PDO $pdo, string $table, array $candidates, ?string $fallback = null): ?string {
  foreach ($candidates as $c) { if (col_exists_rb($pdo,$table,$c)) return $c; }
  return $fallback;
}

/* ===== اختيار الأعمدة ===== */
$rv_tbl   = 'receipt_vouchers';
$rv_date  = pick_col_rb($pdo,$rv_tbl, ['doc_date','receipt_date','date','created_at'], 'created_at') ?: 'created_at';
$rv_amount= pick_col_rb($pdo,$rv_tbl, ['amount','total_amount','total','value'], null) ?: null;
$rv_party = pick_col_rb($pdo,$rv_tbl, ['payer','customer','from_party','party','name'], '') ?: '';
$rv_method= pick_col_rb($pdo,$rv_tbl, ['method','payment_method','pay_method'], null);

$pv_tbl   = 'payment_vouchers';
$pv_date  = pick_col_rb($pdo,$pv_tbl, ['doc_date','payment_date','date','created_at'], 'created_at') ?: 'created_at';
$pv_amount= pick_col_rb($pdo,$pv_tbl, ['amount','total_amount','total','value'], null) ?: null;
$pv_party = pick_col_rb($pdo,$pv_tbl, ['payee','supplier','to_party','vendor','party','name'], '') ?: '';
$pv_method= pick_col_rb($pdo,$pv_tbl, ['method','payment_method','pay_method'], null);

$si_tbl   = 'sales_invoices';
$si_date  = pick_col_rb($pdo,$si_tbl, ['invoice_date','date','created_at'], 'created_at') ?: 'created_at';
$si_total = pick_col_rb($pdo,$si_tbl, ['total_amount','amount','total','value'], '0') ?: '0';
$si_custid= pick_col_rb($pdo,$si_tbl, ['customer_id','client_id'], null);

/* ===== Filters ===== */
$from = isset($_GET['from']) && $_GET['from'] !== '' ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to   = isset($_GET['to'])   && $_GET['to']   !== '' ? $_GET['to']   : date('Y-m-d');

/* توحيد ترميز/Collation للنصوص داخل UNION */
$TYPE_RV = "CONVERT('سند قبض' USING utf8mb4) COLLATE utf8mb4_unicode_ci";
$TYPE_PV = "CONVERT('سند صرف' USING utf8mb4) COLLATE utf8mb4_unicode_ci";
$TYPE_SI = "CONVERT('فاتورة مبيعات' USING utf8mb4) COLLATE utf8mb4_unicode_ci";
$EMPTY   = "CONVERT('' USING utf8mb4) COLLATE utf8mb4_unicode_ci";

/* ===== بناء UNION بمعاملات موقعية ===== */
$parts = [];
$params = [];

/* 1) سندات القبض (مدين) */
if ($rv_amount) {
  $partyExpr = $rv_party ? "CONVERT(rv.`$rv_party` USING utf8mb4) COLLATE utf8mb4_unicode_ci" : $EMPTY;
  $sql = "SELECT
            rv.id,
            DATE(rv.`$rv_date`) AS tdate,
            CAST(rv.`$rv_amount` AS DECIMAL(14,2)) AS amount,
            $TYPE_RV AS type,
            $partyExpr AS party
          FROM `$rv_tbl` rv
          WHERE rv.`$rv_date` BETWEEN ? AND ?";
  if ($rv_method) $sql .= " AND (rv.`$rv_method` IS NULL OR rv.`$rv_method`='cash')";
  $parts[] = $sql;
  $params[] = $from; $params[] = $to;
}

/* 2) سندات الصرف (دائن) */
if ($pv_amount) {
  $partyExpr = $pv_party ? "CONVERT(pv.`$pv_party` USING utf8mb4) COLLATE utf8mb4_unicode_ci" : $EMPTY;
  $sql = "SELECT
            pv.id,
            DATE(pv.`$pv_date`) AS tdate,
            CAST(pv.`$pv_amount` * -1 AS DECIMAL(14,2)) AS amount,
            $TYPE_PV AS type,
            $partyExpr AS party
          FROM `$pv_tbl` pv
          WHERE pv.`$pv_date` BETWEEN ? AND ?";
  if ($pv_method) $sql .= " AND (pv.`$pv_method` IS NULL OR pv.`$pv_method`='cash')";
  $parts[] = $sql;
  $params[] = $from; $params[] = $to;
}

/* 3) فواتير المبيعات (نقدي/عام) */
$parts[] = "
SELECT
  si.id,
  DATE(si.`$si_date`) AS tdate,
  CAST(" . ($si_total ? "si.`$si_total`" : "0") . " AS DECIMAL(14,2)) AS amount,
  $TYPE_SI AS type,
  CONVERT(COALESCE(c.name,'') USING utf8mb4) COLLATE utf8mb4_unicode_ci AS party
FROM `$si_tbl` si
LEFT JOIN customers c ON ".($si_custid ? "c.id = si.`$si_custid`" : "1=0")."
WHERE si.`$si_date` BETWEEN ? AND ?
";
$params[] = $from; $params[] = $to;

/* ===== تنفيذ ===== */
$sql = implode("\nUNION ALL\n", $parts) . "\nORDER BY tdate, id";
$st = $pdo->prepare($sql);
$st->execute($params);
$list = $st->fetchAll();

/* ===== عرض ===== */
$balance = 0.0;
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?> - موردن</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Tahoma,Arial,sans-serif;background:#f5f8fc;color:#1f2937}
.header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;color:#fff;padding:12px 16px}
a{color:#64ffda;text-decoration:none}
.wrap{padding:18px;display:grid;gap:16px}
.card{background:#fff;border:1px solid #dfe7f5;border-radius:12px;padding:16px}
table{width:100%;border-collapse:collapse;background:#fff}
th,td{border-bottom:1px solid #e7eaf3;padding:8px;text-align:right}
th{color:#6b7280}
.filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
input[type=date]{padding:8px;border:1px solid #cfd9ec;border-radius:8px}
.btn{border:0;border-radius:8px;padding:8px 12px;background:#13c2b3;color:#062427;font-weight:700;cursor:pointer}
.total{font-weight:700}
.muted{color:#6b7280}
</style>
</head>
<body>
<div class="header">
  <div><?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?></div>
  <nav><a href="./dashboard.php">الرجوع</a></nav>
</div>

<div class="wrap">
  <div class="card">
    <form class="filters" method="get">
      <div>من: <input type="date" name="from" value="<?= htmlspecialchars($from,ENT_QUOTES,'UTF-8') ?>"></div>
      <div>إلى: <input type="date" name="to" value="<?= htmlspecialchars($to,ENT_QUOTES,'UTF-8') ?>"></div>
      <button class="btn" type="submit">تصفية</button>
      <span class="muted">يعرض قبض/صرف (نقدي إذا وُجد عمود الطريقة) + فواتير المبيعات خلال الفترة.</span>
    </form>

    <table>
      <thead>
        <tr>
          <th>التاريخ</th>
          <th>النوع</th>
          <th>الطرف</th>
          <th>مدين</th>
          <th>دائن</th>
          <th>الرصيد</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$list): ?>
        <tr><td colspan="6" class="muted">لا توجد حركات في الفترة المحددة.</td></tr>
      <?php else: ?>
        <?php foreach($list as $r):
          $amount = (float)$r['amount'];
          $balance += $amount;
        ?>
          <tr>
            <td><?= htmlspecialchars($r['tdate'] ?? '',ENT_QUOTES,'UTF-8') ?></td>
            <td><?= htmlspecialchars($r['type'] ?? '',ENT_QUOTES,'UTF-8') ?></td>
            <td><?= htmlspecialchars($r['party'] ?? '',ENT_QUOTES,'UTF-8') ?></td>
            <td><?= $amount > 0 ? number_format($amount,2) : '' ?></td>
            <td><?= $amount < 0 ? number_format(abs($amount),2) : '' ?></td>
            <td class="total"><?= number_format($balance,2) ?></td>
          </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
</body>
</html>
