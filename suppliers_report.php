<?php
require_once __DIR__ . '/auth.php';
if (!function_exists('db')) { require_once __DIR__ . '/config.php'; }
$pdo = db();

// أنشئ الجدول لو غير موجود
$pdo->exec("
CREATE TABLE IF NOT EXISTS suppliers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(50) DEFAULT NULL,
  address VARCHAR(255) DEFAULT NULL,
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

// لو الجدول كان موجود بدون opening_balance، أضفه
$has_opening = (bool)$pdo->query("
  SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='suppliers' AND COLUMN_NAME='opening_balance'
  LIMIT 1
")->fetchColumn();

if (!$has_opening) {
  $pdo->exec("ALTER TABLE suppliers ADD COLUMN opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER address;");
}

$total_suppliers = (int)$pdo->query("SELECT COUNT(*) FROM suppliers")->fetchColumn();
$total_opening   = (float)$pdo->query("SELECT COALESCE(SUM(opening_balance),0) FROM suppliers")->fetchColumn();
?>
<!doctype html><html lang="ar" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>تقارير الموردين - موردن</title>
<style>
:root{--bg:#0a192f;--panel:#0e2444;--accent:#64ffda;--text:#e6f1ff}
*{box-sizing:border-box}body{margin:0;font-family:Tahoma,Arial,sans-serif;background:#0a192f;color:var(--text)}
.header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;padding:12px 16px}
a{color:var(--accent);text-decoration:none}
.wrap{padding:18px;display:grid;gap:16px}
.card{background:#0e2444;border-radius:16px;padding:16px;box-shadow:0 6px 20px rgba(0,0,0,.3)}
h2{margin:0 0 10px}.muted{color:#9fb3d1}
.kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px;margin-top:8px}
.kpi{background:#10294f;border:1px solid #183152;border-radius:12px;padding:14px}
.kpi h3{margin:0 0 6px;font-size:16px;color:#9fb3d1}
.kpi .v{font-size:22px;font-weight:700}
</style></head><body>
  <div class="header">
    <div>تقارير الموردين</div>
    <nav><a href="./dashboard.php">الرجوع للوحة التحكم</a></nav>
  </div>
  <div class="wrap">
    <div class="card">
      <h2>نظرة عامة</h2>
      <div class="kpis">
        <div class="kpi"><h3>عدد الموردين</h3><div class="v"><?= $total_suppliers ?></div></div>
        <div class="kpi"><h3>إجمالي الأرصدة الافتتاحية</h3><div class="v"><?= number_format($total_opening,2) ?></div></div>
      </div>
      <p class="muted" style="margin-top:10px">يمكن لاحقاً إضافة مشتريات/مدفوعات لاحتساب الرصيد التفصيلي.</p>
    </div>
  </div>
</body></html>
