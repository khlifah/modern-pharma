<?php
// supplier_statement.php — كشف حساب مورد (مبدئي)
require_once __DIR__ . '/auth.php';
if (!function_exists('db')) { require_once __DIR__ . '/config.php'; }
$pdo = db();

/* تأكيد جدول الموردين والعمود */
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
$has_opening = (bool)$pdo->query("
  SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='suppliers' AND COLUMN_NAME='opening_balance'
  LIMIT 1
")->fetchColumn();
if (!$has_opening) {
  $pdo->exec("ALTER TABLE suppliers ADD COLUMN opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER address;");
}

/* قائمة الموردين للاختيار */
$suppliers = $pdo->query("SELECT id,name FROM suppliers ORDER BY name ASC")->fetchAll();

$selected_id = isset($_GET['supplier_id']) ? (int)$_GET['supplier_id'] : 0;
$info = null;
if ($selected_id > 0) {
  $st = $pdo->prepare("SELECT id,name,phone,address,opening_balance,created_at FROM suppliers WHERE id=?");
  $st->execute([$selected_id]);
  $info = $st->fetch();
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>كشف حساب مورد - موردن</title>
<style>
:root{--bg:#0a192f;--panel:#0e2444;--accent:#64ffda;--text:#e6f1ff}
*{box-sizing:border-box}
body{margin:0;font-family:Tahoma,Arial,sans-serif;background:#0a192f;color:var(--text)}
.header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;padding:12px 16px}
a{color:var(--accent);text-decoration:none}
.wrap{padding:18px;display:grid;gap:16px}
.card{background:#0e2444;border-radius:16px;padding:16px;box-shadow:0 6px 20px rgba(0,0,0,.3)}
h2{margin:0 0 10px}.muted{color:#9fb3d1}
label{display:block;margin:8px 0 6px}
select{width:100%;padding:10px;border-radius:10px;border:1px solid #183152;background:#10294f;color:#e6f1ff}
.table{margin-top:10px}
td{padding:6px 0}
</style>
</head>
<body>
  <div class="header">
    <div>كشف حساب مورد</div>
    <nav><a href="./dashboard.php">الرجوع للوحة التحكم</a></nav>
  </div>

  <div class="wrap">
    <div class="card">
      <h2>اختيار مورد</h2>
      <form method="get" action="./supplier_statement.php">
        <label>المورد</label>
        <select name="supplier_id" onchange="this.form.submit()">
          <option value="">— اختر مورد —</option>
          <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $selected_id===(int)$s['id']?'selected':'' ?>>
              <?= htmlspecialchars($s['name'],ENT_QUOTES,'UTF-8') ?>
            </option>
          <?php endforeach; ?>
        </select>
      </form>
    </div>

    <?php if ($info): ?>
      <div class="card">
        <h2>بيانات المورد</h2>
        <table class="table">
          <tr><td>الاسم:</td><td><?= htmlspecialchars($info['name'],ENT_QUOTES,'UTF-8') ?></td></tr>
          <tr><td>الهاتف:</td><td><?= htmlspecialchars($info['phone']??'',ENT_QUOTES,'UTF-8') ?></td></tr>
          <tr><td>العنوان:</td><td><?= htmlspecialchars($info['address']??'',ENT_QUOTES,'UTF-8') ?></td></tr>
          <tr><td>الرصيد الافتتاحي:</td><td><?= number_format((float)$info['opening_balance'],2) ?></td></tr>
          <tr><td>أضيف في:</td><td><?= htmlspecialchars($info['created_at'],ENT_QUOTES,'UTF-8') ?></td></tr>
        </table>
        <p class="muted">— لاحقًا سنضيف المشتريات والمدفوعات لاحتساب الرصيد الجاري والتفاصيل بالفترات.</p>
      </div>
    <?php endif; ?>
  </div>
</body>
</html>
