<?php
// inventory.php — إدارة المخزون (يتأكد من الأعمدة + إضافة/تعديل/حذف/عرض)
require_once __DIR__ . '/auth.php';
if (!function_exists('db')) { require_once __DIR__ . '/config.php'; }
$pdo = db();

/* إنشاء جدول المنتجات إن لم يوجد */
$pdo->exec("
CREATE TABLE IF NOT EXISTS products (
  id INT AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(100) UNIQUE,
  name VARCHAR(200) NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  cost_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sale_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
");

/* تأكيد الأعمدة المطلوبة */
function ensure_column(PDO $pdo, string $table, string $col, string $definition) {
  $exists = (bool)$pdo->query("
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = '$table'
      AND COLUMN_NAME  = '$col'
    LIMIT 1
  ")->fetchColumn();
  if (!$exists) {
    $pdo->exec("ALTER TABLE `$table` ADD COLUMN $col $definition;");
  }
}
ensure_column($pdo, 'products', 'sku',        "VARCHAR(100) UNIQUE AFTER id");
ensure_column($pdo, 'products', 'cost_price', "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER quantity");
ensure_column($pdo, 'products', 'sale_price', "DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER cost_price");

$errors = [];
$mode   = 'create';
$editRow = null;

/* حذف منتج */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
  $id = (int)($_POST['id'] ?? 0);
  if ($id > 0) {
    $st = $pdo->prepare("DELETE FROM products WHERE id = ?");
    $st->execute([$id]);
    $_SESSION['flash'] = ['type'=>'','msg'=>'تم حذف المنتج.'];
  }
  header('Location: ./inventory.php'); exit;
}

/* إضافة منتج */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
  $sku   = trim($_POST['sku'] ?? '');
  $name  = trim($_POST['name'] ?? '');
  $qty   = (string)($_POST['quantity'] ?? '0');
  $cost  = (string)($_POST['cost_price'] ?? '0');
  $price = (string)($_POST['sale_price'] ?? '0');

  if ($name === '') $errors[] = 'اسم المنتج إلزامي.';
  if (!is_numeric($qty)) $errors[] = 'الكمية يجب أن تكون رقم.';
  if (!is_numeric($cost) || !is_numeric($price)) $errors[] = 'الأسعار يجب أن تكون أرقام.';

  if (!$errors) {
    try {
      $st = $pdo->prepare("INSERT INTO products (sku,name,quantity,cost_price,sale_price) VALUES (?,?,?,?,?)");
      $st->execute([$sku !== '' ? $sku : null, $name, (int)$qty, (float)$cost, (float)$price]);
      
      // إرسال إشعار بإضافة منتج جديد
      require_once __DIR__ . '/notifications_lib.php';
      notify_event(
        $pdo,
        'product_created',
        "إضافة صنف",
        "تمت إضافة الصنف: {$name} (الكمية: {$qty})",
        'info',
        $_SESSION['user_id'] ?? null,
        'products',
        (int)$pdo->lastInsertId(),
        true
      );
      
      $_SESSION['flash'] = ['type'=>'','msg'=>'تم إضافة المنتج بنجاح.'];
      header('Location: ./inventory.php'); exit;
    } catch (PDOException $e) {
      if ((int)($e->errorInfo[1] ?? 0) === 1062) $errors[] = 'الرمز SKU مستخدم مسبقاً.';
      else $errors[] = 'خطأ غير متوقع: '.$e->getMessage();
    }
  }
}

/* عرض نموذج التعديل */
if (($_GET['action'] ?? '') === 'edit') {
  $mode = 'edit';
  $id = (int)($_GET['id'] ?? 0);
  if ($id > 0) {
    $st = $pdo->prepare("SELECT id,sku,name,quantity,cost_price,sale_price FROM products WHERE id = ?");
    $st->execute([$id]);
    $editRow = $st->fetch();
    if (!$editRow) {
      $_SESSION['flash'] = ['type'=>'error','msg'=>'المنتج غير موجود.'];
      header('Location: ./inventory.php'); exit;
    }
  } else {
    header('Location: ./inventory.php'); exit;
  }
}

/* حفظ التعديل */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
  $id    = (int)($_POST['id'] ?? 0);
  $sku   = trim($_POST['sku'] ?? '');
  $name  = trim($_POST['name'] ?? '');
  $qty   = (string)($_POST['quantity'] ?? '0');
  $cost  = (string)($_POST['cost_price'] ?? '0');
  $price = (string)($_POST['sale_price'] ?? '0');

  if ($id <= 0) $errors[] = 'معرّف غير صالح.';
  if ($name === '') $errors[] = 'اسم المنتج إلزامي.';
  if (!is_numeric($qty)) $errors[] = 'الكمية يجب أن تكون رقم.';
  if (!is_numeric($cost) || !is_numeric($price)) $errors[] = 'الأسعار يجب أن تكون أرقام.';

  if (!$errors) {
    try {
      $st = $pdo->prepare("UPDATE products SET sku=?, name=?, quantity=?, cost_price=?, sale_price=? WHERE id=?");
      $st->execute([$sku !== '' ? $sku : null, $name, (int)$qty, (float)$cost, (float)$price, $id]);
      $_SESSION['flash'] = ['type'=>'','msg'=>'تم تعديل المنتج.'];
      header('Location: ./inventory.php'); exit;
    } catch (PDOException $e) {
      if ((int)($e->errorInfo[1] ?? 0) === 1062) $errors[] = 'الرمز SKU مستخدم مسبقاً لمنتج آخر.';
      else $errors[] = 'خطأ غير متوقع: '.$e->getMessage();
      $mode = 'edit';
      $editRow = ['id'=>$id,'sku'=>$sku,'name'=>$name,'quantity'=>$qty,'cost_price'=>$cost,'sale_price'=>$price];
    }
  } else {
    $mode = 'edit';
    $editRow = ['id'=>$id,'sku'=>$sku,'name'=>$name,'quantity'=>$qty,'cost_price'=>$cost,'sale_price'=>$price];
  }
}

/* جلب المنتجات */
$list = $pdo->query("SELECT id,sku,name,quantity,cost_price,sale_price,created_at FROM products ORDER BY id DESC")->fetchAll();
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إدارة المخزون - موردن</title>
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
input{width:100%;padding:10px;border-radius:10px;border:1px solid #183152;background:#10294f;color:#e6f1ff}
.btn{cursor:pointer;border:0;border-radius:10px;padding:10px 14px;background:var(--accent);color:#062427;font-weight:700}
.btn-danger{background:var(--danger);color:#fff}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{padding:10px;border-bottom:1px solid #183152;text-align:right}
th{color:#9fb3d1;font-weight:700}
.actions a,.actions form{display:inline-block}
.flash{margin-bottom:10px;border-radius:10px;padding:10px;background:#0b2a22;border:1px solid #186b5a}
.error{margin-bottom:10px;border-radius:10px;padding:10px;background:#2a0b0f;border:1px solid #7a2a33}
</style>
</head>
<body>
  <div class="header">
    <div>إدارة المخزون</div>
    <nav><a href="./dashboard.php">الرجوع للوحة التحكم</a></nav>
  </div>

  <div class="wrap">
    <div class="card">
      <h2><?= $mode === 'edit' ? 'تعديل منتج' : 'إضافة منتج' ?></h2>

      <?php foreach ($errors as $e): ?><div class="error"><?= htmlspecialchars($e,ENT_QUOTES,'UTF-8') ?></div><?php endforeach; ?>
      <?php if (!empty($_SESSION['flash'])): ?><div class="flash"><?= htmlspecialchars($_SESSION['flash']['msg']??'',ENT_QUOTES,'UTF-8'); unset($_SESSION['flash']); ?></div><?php endif; ?>

      <form method="post">
        <?php if ($mode === 'edit'): ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int)$editRow['id'] ?>">
        <?php else: ?>
          <input type="hidden" name="action" value="create">
        <?php endif; ?>

        <label>SKU (اختياري)</label><input name="sku" value="<?= htmlspecialchars($editRow['sku'] ?? '',ENT_QUOTES,'UTF-8') ?>" placeholder="مثل: P-1001">
        <label>اسم المنتج</label><input name="name" required value="<?= htmlspecialchars($editRow['name'] ?? '',ENT_QUOTES,'UTF-8') ?>">
        <label>الكمية</label><input name="quantity" value="<?= htmlspecialchars($editRow['quantity'] ?? '0',ENT_QUOTES,'UTF-8') ?>">
        <label>تكلفة الشراء</label><input name="cost_price" value="<?= htmlspecialchars($editRow['cost_price'] ?? '0',ENT_QUOTES,'UTF-8') ?>">
        <label>سعر البيع</label><input name="sale_price" value="<?= htmlspecialchars($editRow['sale_price'] ?? '0',ENT_QUOTES,'UTF-8') ?>">

        <div style="margin-top:10px;">
          <button class="btn" type="submit"><?= $mode === 'edit' ? 'حفظ التعديل' : 'حفظ' ?></button>
          <?php if ($mode === 'edit'): ?><a class="btn" href="./inventory.php">إلغاء</a><?php endif; ?>
        </div>
      </form>
    </div>

    <div class="card">
      <h2>قائمة المنتجات</h2>
      <table>
        <thead><tr><th>#</th><th>SKU</th><th>الاسم</th><th>الكمية</th><th>تكلفة</th><th>سعر بيع</th><th>أضيف في</th><th>إجراءات</th></tr></thead>
        <tbody>
          <?php if (!$list): ?>
            <tr><td colspan="8" class="muted">لا توجد منتجات بعد.</td></tr>
          <?php else: foreach ($list as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars($r['sku']??'',ENT_QUOTES,'UTF-8') ?></td>
              <td><?= htmlspecialchars($r['name'],ENT_QUOTES,'UTF-8') ?></td>
              <td><?= (int)$r['quantity'] ?></td>
              <td><?= number_format((float)$r['cost_price'],2) ?></td>
              <td><?= number_format((float)$r['sale_price'],2) ?></td>
              <td><?= htmlspecialchars($r['created_at'],ENT_QUOTES,'UTF-8') ?></td>
              <td class="actions">
                <a class="btn" href="./inventory.php?action=edit&id=<?= (int)$r['id'] ?>">تعديل</a>
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
