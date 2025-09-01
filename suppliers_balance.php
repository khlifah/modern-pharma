
<?php require_once __DIR__.'/auth.php'; require_login(); require_once __DIR__.'/db_connect.php';
$pdo=db();
$rows=$pdo->query("SELECT id, name AS supplier_name, 'مدين' AS type, 0 AS amount FROM suppliers ORDER BY id LIMIT 15")->fetchAll();
?>
<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8">
<title>الأرصدة الإفتتاحية للموردين</title>
<link rel="stylesheet" href="assets/style.css">
<div class="page">
  <header><a class="btn small" href="home.php">↩ رجوع</a><h2>الأرصدة الإفتتاحية للموردين</h2></header>
  <div class="card">
    <div class="form-grid">
      <label>تاريخ القيد <input type="date" value="<?=date('Y-m-d')?>"></label>
      <label>بحث المورد <input placeholder="اسم المورد..."></label>
      <button class="btn">بحث</button>
      <button class="btn">إضافة رصيد</button>
    </div>
    <table class="table">
      <tr><th>#</th><th>المورد</th><th>النوع</th><th>المبلغ</th><th>إجراءات</th></tr>
      <?php foreach($rows as $i=>$r): ?>
      <tr>
        <td><?=$i+1?></td><td><?=htmlspecialchars($r['supplier_name'])?></td>
        <td><?=$r['type']?></td><td><?=$r['amount']?></td>
        <td><button class="btn small">تعديل</button> <button class="btn small danger">حذف</button></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div class="toolbar">
      <button class="btn">حفظ</button>
      <button class="btn">طباعة</button>
      <button class="btn secondary">تصدير</button>
    </div>
  </div>
</div>
