<?php
// purchase_returns.php — مردود مشتريات
require_once __DIR__ . '/auth.php';
if (!function_exists('db')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/accounting.php';
$pdo = db();

/* جداول أساسية */
$pdo->exec("CREATE TABLE IF NOT EXISTS suppliers(
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(150) NOT NULL,
  phone VARCHAR(50) NULL,
  address VARCHAR(255) NULL,
  opening_balance DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$pdo->exec("CREATE TABLE IF NOT EXISTS products(
  id INT AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(100) UNIQUE,
  name VARCHAR(200) NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  cost_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sale_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* هيدر وتفاصيل مردود المشتريات */
$pdo->exec("CREATE TABLE IF NOT EXISTS purchase_return_headers(
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_date DATE NOT NULL,
  supplier_id INT NULL,
  note VARCHAR(255) NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$pdo->exec("CREATE TABLE IF NOT EXISTS purchase_return_items(
  id INT AUTO_INCREMENT PRIMARY KEY,
  header_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  cost_price DECIMAL(14,2) NOT NULL,
  line_total DECIMAL(14,2) NOT NULL,
  expiry_date DATE NULL,
  FOREIGN KEY(header_id) REFERENCES purchase_return_headers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* إضافة أعمدة تاريخ الإصدار والانتهاء إن لم توجد */
if (!function_exists('col_exists')) {
  function col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
  }
}
try { if (!col_exists($pdo, 'purchase_return_items', 'expiry_date')) $pdo->exec("ALTER TABLE purchase_return_items ADD COLUMN expiry_date DATE NULL AFTER line_total"); } catch(Throwable $e){}

/* بيانات الواجهة */
$suppliers = $pdo->query("SELECT id,name FROM suppliers ORDER BY name")->fetchAll();
$products  = $pdo->query("SELECT id,name,sku,quantity,cost_price FROM products ORDER BY name")->fetchAll();

$errors=[]; $ok=null;

/* حفظ مردود المشتريات */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save') {
  $date = $_POST['doc_date'] ?? date('Y-m-d');
  $sid  = (int)($_POST['supplier_id'] ?? 0);
  $note = trim($_POST['note'] ?? '');

  $pids  = $_POST['item_product_id'] ?? [];
  $qtys  = $_POST['item_qty'] ?? [];
  $costs = $_POST['item_cost'] ?? [];
  $issues = $_POST['item_issue_date'] ?? [];
  $expiries = $_POST['item_expiry_date'] ?? [];

  $items=[]; $total=0;

  // تجميع/تحقق العناصر
  for ($i=0; $i<count($pids); $i++) {
    $pid = (int)($pids[$i] ?? 0);
    $q   = (int)($qtys[$i] ?? 0);
    $c   = (float)($costs[$i] ?? 0);

    if ($pid>0 && $q>0 && $c>=0) {
      // تحقق المخزون يكفي للخصم
      $chk = $pdo->prepare("SELECT quantity,name FROM products WHERE id=?");
      $chk->execute([$pid]);
      $p = $chk->fetch();
      if (!$p) { $errors[] = 'الصنف غير موجود (ID='.$pid.')'; continue; }
      if ((int)$p['quantity'] < $q) {
        $errors[] = 'المتوفر لا يكفي للصنف: '.$p['name'].' (المتاح: '.$p['quantity'].')';
        continue;
      }
      $expiry_date = trim($_POST["item_{$i}_expiry_date"] ?? '');
      $lt = round($q * $c, 2);
      $items[]=['pid'=>$pid,'qty'=>$q,'cost'=>$c,'line'=>$lt,'expiry_date'=>$expiry_date?:null];
      $total += $lt;
    }
  }

  if (!$items) $errors[]='أضف سطرًا واحدًا على الأقل.';

  if (!$errors) {
    try {
      $pdo->beginTransaction();

      // رأس المستند
      $st = $pdo->prepare("INSERT INTO purchase_return_headers(doc_date,supplier_id,note,total_amount) VALUES (?,?,?,?)");
      $st->execute([$date, $sid?:null, $note?:null, $total]);
      $hid = (int)$pdo->lastInsertId();

      // تفاصيل + خصم المخزون
      $sti = $pdo->prepare("INSERT INTO purchase_return_items(header_id,product_id,quantity,cost_price,line_total,expiry_date) VALUES (?,?,?,?,?,?)");
      $upd = $pdo->prepare("UPDATE products SET quantity=quantity-? WHERE id=?");
      foreach($items as $r){ $sti->execute([$hid,$r['pid'],$r['qty'],$r['cost'],$r['line'],$r['expiry_date']]); $upd->execute([$r['qty'],$r['pid']]); }

      $pdo->commit();

      // قيد يومية: مدين دائنون-مورد / دائن المخزون
      $acc_ap = $sid>0 ? "دائنون - مورد #$sid" : "مورد غير محدد";
      post_journal($pdo, 'purchase_return', $hid, $date, 'مردود مشتريات', [
        ['account'=>$acc_ap,  'debit'=>$total, 'credit'=>0],
        ['account'=>'المخزون','debit'=>0,      'credit'=>$total],
      ]);
      $ok = "تم حفظ مردود المشتريات #$hid وخصم المخزون وترحيل القيد.";
      $_POST=[];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) { $pdo->rollBack(); }
      $errors[] = 'فشل الحفظ: '.$e->getMessage();
    }
  }
}

/* قائمة مختصرة */
$list = $pdo->query("
  SELECT h.id,h.doc_date,h.total_amount,s.name supplier
  FROM purchase_return_headers h
  LEFT JOIN suppliers s ON s.id=h.supplier_id
  ORDER BY h.id DESC
  LIMIT 50
")->fetchAll();

$title = 'مردود مشتريات';
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $title ?> - موردن</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Tahoma,Arial,sans-serif;background:#f5f8fc;color:#1f2937}
.header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;color:#fff;padding:12px 16px}
a{color:#64ffda;text-decoration:none}
.wrap{padding:18px;display:grid;gap:16px}
.card{background:#fff;border:1px solid #dfe7f5;border-radius:12px;padding:16px}
label{display:block;margin:8px 0 4px}
input,select,textarea{width:100%;padding:10px;border-radius:10px;border:1px solid #cfd9ec}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{border-bottom:1px solid #e7eaf3;padding:8px;text-align:right}
th{color:#6b7280}
.btn{border:0;border-radius:10px;padding:9px 12px;background:#13c2b3;color:#062427;font-weight:700;cursor:pointer}
.total{font-weight:700}
</style>
</head>
<body>
  <div class="header"><div><?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?></div><nav><a href="./dashboard.php">الرجوع</a></nav></div>
  <div class="wrap">
    <div class="card">
      <?php foreach($errors as $e): ?>
        <div style="background:#ffecec;border:1px solid #ffb3b3;padding:10px;border-radius:10px;margin:6px 0"><?= htmlspecialchars($e,ENT_QUOTES,'UTF-8') ?></div>
      <?php endforeach; ?>
      <?php if ($ok): ?>
        <div style="background:#e6fff7;border:1px solid #a7f3d0;padding:10px;border-radius:10px;margin:6px 0"><?= htmlspecialchars($ok,ENT_QUOTES,'UTF-8') ?></div>
      <?php endif; ?>

      <form method="post" id="f">
        <input type="hidden" name="action" value="save">
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
          <div><label>تاريخ المستند</label><input type="date" name="doc_date" value="<?= htmlspecialchars($_POST['doc_date']??date('Y-m-d'),ENT_QUOTES,'UTF-8') ?>"></div>
          <div>
            <label>المورد (اختياري)</label>
            <select name="supplier_id">
              <option value="">— اختر —</option>
              <?php foreach($suppliers as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= ((int)($_POST['supplier_id']??0)===(int)$s['id'])?'selected':'' ?>>
                  <?= htmlspecialchars($s['name'],ENT_QUOTES,'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="grid-column:1/-1"><label>ملاحظة</label><textarea name="note" rows="2"><?= htmlspecialchars($_POST['note']??'',ENT_QUOTES,'UTF-8') ?></textarea></div>
        </div>

        <h3 style="margin-top:12px">عناصر المردود</h3>
        <table id="t">
          <thead><tr><th>الصنف</th><th>متوفر</th><th>كمية</th><th>تكلفة</th><th>تاريخ الانتهاء</th><th>الإجمالي</th><th>—</th></tr></thead>
          <tbody>
            <tr>
              <td>
                <select name="item_product_id[]" class="prod">
                  <option value="">— اختر —</option>
                  <?php foreach($products as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" data-qty="<?= (int)$p['quantity'] ?>" data-cost="<?= (float)$p['cost_price'] ?>">
                      <?= htmlspecialchars($p['name'],ENT_QUOTES,'UTF-8') ?><?= $p['sku']?' ('.htmlspecialchars($p['sku'],ENT_QUOTES,'UTF-8').')':'' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="avail">0</td>
              <td><input type="number" name="item_qty[]" class="qty" min="1" step="1" value="1"></td>
              <td><input type="number" name="item_cost[]" class="cost" min="0" step="0.01" value="0"></td>
              <td><input type="date" name="item_0_expiry_date" placeholder="تاريخ الانتهاء"></td>
              <td class="line">0.00</td>
              <td><button type="button" class="btn" style="background:#ff4d5a;color:#fff" onclick="delRow(this)">حذف</button></td>
            </tr>
          </tbody>
          <tfoot>
            <tr><td colspan="8"><button type="button" class="btn" onclick="addRow()">+ إضافة سطر</button></td></tr>
            <tr><td colspan="6" class="total">الإجمالي</td><td id="gt" class="total">0.00</td><td></td></tr>
          </tfoot>
        </table>

        <div style="margin-top:10px"><button class="btn" type="submit">حفظ</button></div>
      </form>
    </div>

    <div class="card">
      <h3>آخر مردودات</h3>
      <table>
        <thead><tr><th>#</th><th>التاريخ</th><th>المورد</th><th>الإجمالي</th></tr></thead>
        <tbody>
          <?php if(!$list): ?>
            <tr><td colspan="4">لا يوجد</td></tr>
          <?php else: foreach($list as $r): ?>
            <tr>
              <td><?= $r['id'] ?></td>
              <td><?= $r['doc_date'] ?></td>
              <td><?= htmlspecialchars($r['supplier']??'',ENT_QUOTES,'UTF-8') ?></td>
              <td><?= number_format($r['total_amount'],2) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

<script>
function recalc(){
  let t=0;
  document.querySelectorAll('#t tbody tr').forEach(tr=>{
    const q=parseFloat(tr.querySelector('.qty').value||0);
    const c=parseFloat(tr.querySelector('.cost').value||0);
    const l=(q>0&&c>=0)?q*c:0;
    tr.querySelector('.line').textContent=l.toFixed(2);
    t+=l;
  });
  document.getElementById('gt').textContent=t.toFixed(2);
}
function bind(tr){
  const pr=tr.querySelector('.prod'), qty=tr.querySelector('.qty'), cost=tr.querySelector('.cost'), av=tr.querySelector('.avail');
  pr.addEventListener('change', ()=>{
    const o=pr.selectedOptions[0];
    const q=o?parseInt(o.getAttribute('data-qty')||'0',10):0;
    const c=o?parseFloat(o.getAttribute('data-cost')||'0'):0;
    av.textContent=isNaN(q)?'0':q;
    if(!cost.value||parseFloat(cost.value)===0) cost.value=isNaN(c)?0:c;
    recalc();
  });
  qty.addEventListener('input', recalc);
  cost.addEventListener('input', recalc);
}
function addRow(){
  const tb=document.querySelector('#t tbody'), tr=tb.rows[0].cloneNode(true);
  tr.querySelector('.prod').selectedIndex=0;
  tr.querySelector('.avail').textContent='0';
  tr.querySelector('.qty').value=1;
  tr.querySelector('.cost').value=0;
  tr.querySelector('input[name*="expiry_date"]').value='';
  tr.querySelector('.line').textContent='0.00';
  tb.appendChild(tr); bind(tr); recalc();
}
function delRow(btn){
  const tb=document.querySelector('#t tbody');
  if(tb.rows.length===1){
    const tr=tb.rows[0];
    tr.querySelector('.prod').selectedIndex=0;
    tr.querySelector('.avail').textContent='0';
    tr.querySelector('.qty').value=1;
    tr.querySelector('.cost').value=0;
    tr.querySelector('input[name*="expiry_date"]').value='';
    tr.querySelector('.line').textContent='0.00';
  } else {
    btn.closest('tr').remove();
  }
  recalc();
}
bind(document.querySelector('#t tbody tr')); recalc();
</script>
</body>
</html>
