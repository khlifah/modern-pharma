<?php
// suppliers.php — إدارة الموردين (إنشاء الجدول/العمود تلقائيًا + إضافة/تعديل/حذف/عرض)
require_once __DIR__ . '/auth.php';
if (!function_exists('db')) { require_once __DIR__ . '/config.php'; }
$pdo = db();

/* إنشاء جدول الموردين إن لم يوجد */
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

/* لو الجدول قديم بدون opening_balance نضيفه */
$has_opening = (bool)$pdo->query("
  SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME   = 'suppliers'
    AND COLUMN_NAME  = 'opening_balance'
  LIMIT 1
")->fetchColumn();
if (!$has_opening) {
  $pdo->exec("ALTER TABLE suppliers ADD COLUMN opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER address;");
}

$errors = [];
$mode   = 'create'; // create | edit
$editRow = null;

/* حذف مورد */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $st = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
    $st->execute([$id]);
    $supplier = $st->fetch();
    
    if ($supplier) {
      $st = $pdo->prepare("DELETE FROM suppliers WHERE id = ?");
      $st->execute([$id]);
      
      // إضافة إشعار الحذف
      require_once __DIR__ . '/notifications_lib.php';
      notify_event(
        $pdo,
        'supplier_deleted',
        "حذف مورد",
        "تم حذف المورد: {$supplier['name']}",
        'warning',
        $_SESSION['user_id'] ?? null,
        'suppliers',
        $id,
        true
      );
      
      $_SESSION['flash'] = ['type'=>'','msg'=>'تم حذف المورد.'];
    }
  }
  header('Location: ./suppliers.php'); exit;
}

/* إنشاء مورد */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
  $name    = trim($_POST['name'] ?? '');
  $phone   = trim($_POST['phone'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $balance = (string)($_POST['opening_balance'] ?? '0');

  if ($name === '') $errors[] = 'اسم المورد إلزامي.';
  if (!is_numeric($balance)) $errors[] = 'الرصيد الافتتاحي يجب أن يكون رقمًا.';

  if (!$errors) {
    $st = $pdo->prepare("INSERT INTO suppliers (name,phone,address,opening_balance) VALUES (?,?,?,?)");
    $st->execute([$name, $phone !== '' ? $phone : null, $address !== '' ? $address : null, (float)$balance]);
    $supplierId = $pdo->lastInsertId();
    
    // إضافة إشعار الإضافة
    require_once __DIR__ . '/notifications_lib.php';
    notify_event(
      $pdo,
      'supplier_created',
      "إضافة مورد جديد",
      "تمت إضافة مورد جديد: $name",
      'success',
      $_SESSION['user_id'] ?? null,
      'suppliers',
      $supplierId,
      true
    );
    
    $_SESSION['flash'] = ['type'=>'','msg'=>'تم إضافة المورد بنجاح.'];
    header('Location: ./suppliers.php'); exit;
  }
}

/* عرض نموذج التعديل */
if (($_GET['action'] ?? '') === 'edit') {
  $mode = 'edit';
  $id = (int)($_GET['id'] ?? 0);
  if ($id > 0) {
    $st = $pdo->prepare("SELECT id,name,phone,address,opening_balance FROM suppliers WHERE id = ?");
    $st->execute([$id]);
    $editRow = $st->fetch();
    if (!$editRow) {
      $_SESSION['flash'] = ['type'=>'error','msg'=>'المورد غير موجود.'];
      header('Location: ./suppliers.php'); exit;
    }
  } else {
    header('Location: ./suppliers.php'); exit;
  }
}

/* حفظ التعديل */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
  $id      = (int)($_POST['id'] ?? 0);
  $name    = trim($_POST['name'] ?? '');
  $phone   = trim($_POST['phone'] ?? '');
  $address = trim($_POST['address'] ?? '');
  $balance = (string)($_POST['opening_balance'] ?? '0');

  if ($id <= 0) $errors[] = 'معرّف غير صالح.';
  if ($name === '') $errors[] = 'اسم المورد إلزامي.';
  if (!is_numeric($balance)) $errors[] = 'الرصيد الافتتاحي يجب أن يكون رقمًا.';

  if (!$errors) {
    // الحصول على البيانات القديمة قبل التحديث
    $st = $pdo->prepare("SELECT name FROM suppliers WHERE id = ?");
    $st->execute([$id]);
    $oldData = $st->fetch();
    
    // تنفيذ التحديث
    $st = $pdo->prepare("UPDATE suppliers SET name=?, phone=?, address=?, opening_balance=? WHERE id=?");
    $st->execute([$name, $phone !== '' ? $phone : null, $address !== '' ? $address : null, (float)$balance, $id]);
    
    // إضافة إشعار التعديل
    require_once __DIR__ . '/notifications_lib.php';
    $changes = [];
    if ($oldData && $oldData['name'] !== $name) {
      $changes[] = "الاسم: من {$oldData['name']} إلى $name";
    }
    
    notify_event(
      $pdo,
      'supplier_updated',
      "تحديث بيانات مورد",
      "تم تحديث بيانات المورد: $name\n" . implode("\n", $changes),
      'info',
      $_SESSION['user_id'] ?? null,
      'suppliers',
      $id,
      true
    );
    
    $_SESSION['flash'] = ['type'=>'','msg'=>'تم تعديل بيانات المورد.'];
    header('Location: ./suppliers.php'); exit;
  } else {
    // نعيد تعبئة editRow لعرضها مرة أخرى
    $editRow = ['id'=>$id,'name'=>$name,'phone'=>$phone,'address'=>$address,'opening_balance'=>$balance];
    $mode = 'edit';
  }
}

/* جلب الموردين */
$list = $pdo->query("SELECT id,name,phone,address,opening_balance,created_at FROM suppliers ORDER BY id DESC")->fetchAll();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة الموردين - موردن</title>
<style>
:root{--bg:#0a192f;--panel:#0e2444;--accent:#64ffda;--danger:#ff4d5a;--text:#e6f1ff}
*{box-sizing:border-box}
body{margin:0;font-family:Tahoma,Arial,sans-serif;background:#0a192f;color:var(--text)}
.header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;padding:12px 16px}
a{color:var(--accent);text-decoration:none}
.wrap{padding:18px;display:grid;gap:16px}
.card{background:#0e2444;border-radius:16px;padding:16px;box-shadow:0 6px 20px rgba(0,0,0,.3)}
h2{margin:0 0 10px}.muted{color:#9fb3d1}
label{display:block;margin:8px 0 6px}
input,textarea{width:100%;padding:10px;border-radius:10px;border:1px solid #183152;background:#10294f;color:#e6f1ff}
.btn{cursor:pointer;border:0;border-radius:10px;padding:10px 14px;background:var(--accent);color:#062427;font-weight:700}
.btn-danger{background:var(--danger);color:#fff}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:10px;border-bottom:1px solid #183152;text-align:right}
th{color:#9fb3d1;font-weight:700}
.flash{margin-bottom:10px;border-radius:10px;padding:10px;background:#0b2a22;border:1px solid #186b5a}
.error{margin-bottom:10px;border-radius:10px;padding:10px;background:#2a0b0f;border:1px solid #7a2a33}
.actions a,.actions form{display:inline-block}
</style>
</head>
<body>
  <div class="header">
    <div>إدارة الموردين</div>
    <nav><a href="./dashboard.php">الرجوع للوحة التحكم</a> | <a href="./suppliers_report.php">تقارير الموردين</a> | <a href="./supplier_statement.php">كشف حساب مورد</a></nav>
  </div>

  <div class="wrap">

    <div class="card">
      <h2><?= $mode === 'edit' ? 'تعديل مورد' : 'إضافة مورد' ?></h2>
      <?php foreach ($errors as $e): ?><div class="error"><?= htmlspecialchars($e,ENT_QUOTES,'UTF-8') ?></div><?php endforeach; ?>
      <?php if (!empty($_SESSION['flash'])): ?><div class="flash"><?= htmlspecialchars($_SESSION['flash']['msg']??'',ENT_QUOTES,'UTF-8'); unset($_SESSION['flash']); ?></div><?php endif; ?>

      <form method="post">
        <?php if ($mode === 'edit'): ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
        <?php else: ?>
          <input type="hidden" name="action" value="create">
        <?php endif; ?>

        <label>اسم المورد</label>
        <input name="name" required value="<?= htmlspecialchars($editRow['name'] ?? '',ENT_QUOTES,'UTF-8') ?>">

        <label>الهاتف</label>
        <input name="phone" value="<?= htmlspecialchars($editRow['phone'] ?? '',ENT_QUOTES,'UTF-8') ?>">

        <label>العنوان</label>
        <textarea name="address" rows="2"><?= htmlspecialchars($editRow['address'] ?? '',ENT_QUOTES,'UTF-8') ?></textarea>

        <label>الرصيد الافتتاحي</label>
        <input name="opening_balance" value="<?= htmlspecialchars($editRow['opening_balance'] ?? '0',ENT_QUOTES,'UTF-8') ?>">

        <div style="margin-top:10px;">
          <button class="btn" type="submit"><?= $mode === 'edit' ? 'حفظ التعديل' : 'حفظ' ?></button>
          <?php if ($mode === 'edit'): ?>
            <a class="btn" href="./suppliers.php">إلغاء</a>
          <?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <h2>قائمة الموردين</h2>
      <table>
        <thead><tr><th>#</th><th>الاسم</th><th>الهاتف</th><th>العنوان</th><th>الرصيد الافتتاحي</th><th>تاريخ الإضافة</th><th>إجراءات</th></tr></thead>
        <tbody>
          <?php if (!$list): ?>
            <tr><td colspan="7" class="muted">لا يوجد موردون بعد.</td></tr>
          <?php else: foreach ($list as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars($r['name'],ENT_QUOTES,'UTF-8') ?></td>
              <td><?= htmlspecialchars($r['phone']??'',ENT_QUOTES,'UTF-8') ?></td>
              <td><?= htmlspecialchars($r['address']??'',ENT_QUOTES,'UTF-8') ?></td>
              <td><?= number_format((float)$r['opening_balance'],2) ?></td>
              <td><?= htmlspecialchars($r['created_at'],ENT_QUOTES,'UTF-8') ?></td>
              <td class="actions">
                <a class="btn" href="./suppliers.php?action=edit&id=<?= (int)$r['id'] ?>">تعديل</a>
                <form method="post" onsubmit="return confirm('متأكد من الحذف؟');" style="margin-inline-start:6px">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn btn-danger" type="submit">حذف</button>
                </form>
              </td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</body>
</html>
