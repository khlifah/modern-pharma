<?php
// sales_invoice.php — فاتورة مبيعات (مرن) + إشعار بريد/نظام
require_once __DIR__ . '/auth.php';
if (!function_exists('db')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/accounting.php';
require_once __DIR__ . '/notifications_lib.php';

$pdo = db();

/* دوال مساعدة */
function col_exists_local(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}
function table_cols(PDO $pdo, string $table): array {
  $st = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
  $st->execute([$table]);
  return array_map(fn($r)=>$r['COLUMN_NAME'], $st->fetchAll(PDO::FETCH_ASSOC));
}

/* جداول أساسية */
$pdo->exec("CREATE TABLE IF NOT EXISTS customers(
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
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* ترقية products لو ناقص */
try { if (!col_exists_local($pdo,'products','quantity'))   $pdo->exec("ALTER TABLE products ADD COLUMN quantity INT NOT NULL DEFAULT 0"); } catch(Throwable $e){}
try { if (!col_exists_local($pdo,'products','cost_price'))  $pdo->exec("ALTER TABLE products ADD COLUMN cost_price DECIMAL(14,2) NOT NULL DEFAULT 0.00"); } catch(Throwable $e){}
try { if (!col_exists_local($pdo,'products','sale_price'))  $pdo->exec("ALTER TABLE products ADD COLUMN sale_price DECIMAL(14,2) NOT NULL DEFAULT 0.00"); } catch(Throwable $e){}

/* جداول المبيعات */
$pdo->exec("CREATE TABLE IF NOT EXISTS sales_invoices(
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_date DATE NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$pdo->exec("CREATE TABLE IF NOT EXISTS sales_items(
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  sale_price DECIMAL(14,2) NOT NULL,
  line_total DECIMAL(14,2) NOT NULL,
  expiry_date DATE NULL,
  FOREIGN KEY (invoice_id) REFERENCES sales_invoices(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* ترقية sales_invoices */
try { if (!col_exists_local($pdo,'sales_invoices','customer_id'))  $pdo->exec("ALTER TABLE sales_invoices ADD COLUMN customer_id INT NULL"); } catch(Throwable $e){}
try { if (!col_exists_local($pdo,'sales_invoices','note'))         $pdo->exec("ALTER TABLE sales_invoices ADD COLUMN note VARCHAR(255) NULL"); } catch(Throwable $e){}
try { if (!col_exists_local($pdo,'sales_invoices','total_amount'))  $pdo->exec("ALTER TABLE sales_invoices ADD COLUMN total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00"); } catch(Throwable $e){}
try { if (!col_exists_local($pdo,'sales_invoices','reconciled'))    $pdo->exec("ALTER TABLE sales_invoices ADD COLUMN reconciled TINYINT(1) NOT NULL DEFAULT 0"); } catch(Throwable $e){}

/* بيانات الواجهة */
$customers = $pdo->query("SELECT id,name FROM customers ORDER BY name")->fetchAll();
$products  = $pdo->query("
  SELECT id,name,sku,
         ".(col_exists_local($pdo,'products','quantity')?'quantity':'0 AS quantity').",
         ".(col_exists_local($pdo,'products','cost_price')?'cost_price':'0.00 AS cost_price').",
         ".(col_exists_local($pdo,'products','sale_price')?'sale_price':'0.00 AS sale_price')."
  FROM products ORDER BY name
")->fetchAll();

$errors=[]; $ok=null;

/* حفظ الفاتورة */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save'){
  $date = $_POST['invoice_date'] ?? date('Y-m-d');
  $cid  = (int)($_POST['customer_id'] ?? 0);
  $note = trim($_POST['note'] ?? '');

  $pids    = $_POST['item_product_id'] ?? [];
  $qtys    = $_POST['item_qty'] ?? [];
  $prices  = $_POST['item_price'] ?? [];
  $expiries= $_POST['item_expiry_date'] ?? [];

  $items=[]; $total=0; $cogs_total=0;

  for ($i=0; $i<count($pids); $i++){
    $pid = (int)($pids[$i] ?? 0);
    $q   = (int)($qtys[$i] ?? 0);
    $p   = (float)($prices[$i] ?? 0);
    if ($pid>0 && $q>0 && $p>=0){
      // تحقق من المخزون والتكلفة
      $st = $pdo->prepare("SELECT name,".
          (col_exists_local($pdo,'products','quantity')?'quantity':'0 AS quantity').",".
          (col_exists_local($pdo,'products','cost_price')?'cost_price':'0.00 AS cost_price').
          " FROM products WHERE id=?");
      $st->execute([$pid]);
      $prod = $st->fetch();
      if (!$prod){ $errors[]="الصنف ($pid) غير موجود."; continue; }
      if (col_exists_local($pdo,'products','quantity') && (int)$prod['quantity'] < $q){
        $errors[] = 'المتوفر لا يكفي للصنف: '.$prod['name'].' (المتاح: '.$prod['quantity'].')';
        continue;
      }
      $expiry = trim($expiries[$i] ?? '');
      $lt = round($q*$p,2);
      $items[] = ['pid'=>$pid,'qty'=>$q,'price'=>$p,'line'=>$lt,'expiry_date'=>$expiry];
      $total  += $lt;
      $cogs_total += round($q*(float)$prod['cost_price'], 2);
    }
  }

  if (!$items) $errors[] = 'أضف سطرًا واحدًا على الأقل.';
  if ($total<=0) $errors[] = 'الإجمالي يجب أن يكون أكبر من صفر.';

  if (!$errors){
    $inv_id = null;
    try{
      if (!$pdo->inTransaction()) $pdo->beginTransaction();

      // رأس الفاتورة ديناميكي
      $avail = table_cols($pdo,'sales_invoices');
      $cols = ['invoice_date']; $vals = [$date];
      if (in_array('customer_id',$avail,true))  { $cols[]='customer_id';  $vals[] = ($cid?:null); }
      if (in_array('note',$avail,true))         { $cols[]='note';         $vals[] = ($note!==''?$note:null); }
      if (in_array('total_amount',$avail,true)) { $cols[]='total_amount'; $vals[] = $total; }
      if (in_array('reconciled',$avail,true))   { $cols[]='reconciled';   $vals[] = 0; }

      $ph = '(' . implode(',', array_fill(0,count($cols),'?')) . ')';
      $sqlH = "INSERT INTO sales_invoices(".implode(',',$cols).") VALUES $ph";
      $stH = $pdo->prepare($sqlH);
      $stH->execute($vals);
      $inv_id = (int)$pdo->lastInsertId();

      // تفاصيل + خصم المخزون
      $insI = $pdo->prepare("INSERT INTO sales_items(invoice_id,product_id,quantity,sale_price,line_total,expiry_date) VALUES (?,?,?,?,?,?)");
      $updQ = col_exists_local($pdo,'products','quantity') ? $pdo->prepare("UPDATE products SET quantity=quantity-? WHERE id=?") : null;

      foreach ($items as $r){
        $insI->execute([$inv_id,$r['pid'],$r['qty'],$r['price'],$r['line'],$r['expiry_date'] ?: null]);
        if ($updQ) $updQ->execute([$r['qty'],$r['pid']]);
      }

      if ($pdo->inTransaction()) $pdo->commit();

      // نحاول ترحيل القيد المحاسبي بشكل منفصل لعدم إسقاط الفاتورة لو فشل القيد
      $journalOk = true;
      try {
        $acc_ar = $cid>0 ? "مدينون - عميل #$cid" : "مبيعات نقدية معلقة";
        post_journal($pdo, 'sales', $inv_id, $date, 'فاتورة مبيعات', [
          ['account'=>$acc_ar,        'debit'=>$total,     'credit'=>0],
          ['account'=>'المبيعات',     'debit'=>0,          'credit'=>$total],
          ['account'=>'تكلفة المبيعات','debit'=>$cogs_total,'credit'=>0],
          ['account'=>'المخزون',      'debit'=>0,          'credit'=>$cogs_total],
        ]);
      } catch (Throwable $je) {
        $journalOk = false;
        error_log('[sales_journal] '.$je->getMessage());
        $errors[] = 'تم حفظ الفاتورة لكن فشل ترحيل القيد: '.$je->getMessage();
      }

      // إشعار نظام + بريد
      if ($inv_id) {
        notify_event(
          $pdo,
          'sale_created',
          "فاتورة مبيعات #$inv_id",
          "تم حفظ فاتورة مبيعات بقيمة: ".number_format($total,2)." ريال.".($journalOk?'':"\n(تنبيه: تعذّر ترحيل القيد المحاسبي)"),
          $journalOk ? 'success' : 'warning',
          $_SESSION['user_id'] ?? null,
          'sales_invoices',
          $inv_id,
          true
        );
      }

      $ok = $journalOk ? "تم حفظ فاتورة المبيعات #$inv_id بنجاح." : "تم حفظ فاتورة المبيعات #$inv_id (تنبيه: فشل ترحيل القيد).";
      $_POST = [];
    }catch(Throwable $e){
      if ($pdo->inTransaction()) $pdo->rollBack();
      $errors[] = 'فشل الحفظ: '.$e->getMessage();
    }
  }
}

/* قائمة آخر الفواتير */
$list = $pdo->query("
  SELECT si.id,si.invoice_date,".(col_exists_local($pdo,'sales_invoices','total_amount')?'si.total_amount':'0 AS total_amount').",
         c.name customer
  FROM sales_invoices si
  LEFT JOIN customers c ON c.id=si.customer_id
  ORDER BY si.id DESC LIMIT 50
")->fetchAll();

$title = 'فاتورة مبيعات';
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
          <div><label>تاريخ الفاتورة</label><input type="date" name="invoice_date" value="<?= htmlspecialchars($_POST['invoice_date']??date('Y-m-d'),ENT_QUOTES,'UTF-8') ?>"></div>
          <div>
            <label>العميل (اختياري)</label>
            <select name="customer_id">
              <option value="">— اختر —</option>
              <?php foreach($customers as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)($_POST['customer_id']??0)===(int)$c['id'])?'selected':'' ?>>
                  <?= htmlspecialchars($c['name'],ENT_QUOTES,'UTF-8') ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div style="grid-column:1/-1"><label>ملاحظة</label><textarea name="note" rows="2"><?= htmlspecialchars($_POST['note']??'',ENT_QUOTES,'UTF-8') ?></textarea></div>
        </div>

        <h3 style="margin-top:12px">عناصر الفاتورة</h3>
        <table id="t">
          <thead><tr><th>الصنف</th><th>متوفر</th><th>كمية</th><th>سعر البيع</th><th>تاريخ الانتهاء</th><th>الإجمالي</th><th>—</th></tr></thead>
          <tbody>
            <tr>
              <td>
                <select name="item_product_id[]" class="prod">
                  <option value="">— اختر —</option>
                  <?php foreach($products as $p): ?>
                    <option value="<?= (int)$p['id'] ?>" data-qty="<?= (int)$p['quantity'] ?>" data-sale="<?= (float)$p['sale_price'] ?>">
                      <?= htmlspecialchars($p['name'],ENT_QUOTES,'UTF-8') ?><?= $p['sku']?' ('.htmlspecialchars($p['sku'],ENT_QUOTES,'UTF-8').')':'' ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </td>
              <td class="avail">0</td>
              <td><input type="number" name="item_qty[]" class="qty" min="1" step="1" value="1"></td>
              <td><input type="number" name="item_price[]" class="price" min="0" step="0.01" value="0"></td>
              <td><input type="date" name="item_expiry_date[]" class="expiry_date"></td>
              <td class="line">0.00</td>
              <td><button type="button" class="btn" style="background:#ff4d5a;color:#fff" onclick="delRow(this)">حذف</button></td>
            </tr>
          </tbody>
          <tfoot>
            <tr><td colspan="7"><button type="button" class="btn" onclick="addRow()">+ إضافة سطر</button></td></tr>
            <tr><td colspan="5" class="total">الإجمالي</td><td id="gt" class="total">0.00</td><td></td></tr>
          </tfoot>
        </table>

        <div style="margin-top:10px"><button class="btn" type="submit">حفظ</button></div>
      </form>
    </div>

    <div class="card">
      <h3>آخر فواتير</h3>
      <table>
        <thead><tr><th>#</th><th>التاريخ</th><th>العميل</th><th>الإجمالي</th></tr></thead>
        <tbody>
          <?php if(!$list): ?>
            <tr><td colspan="4">لا يوجد</td></tr>
          <?php else: foreach($list as $r): ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td><?= htmlspecialchars($r['invoice_date'],ENT_QUOTES,'UTF-8') ?></td>
              <td><?= htmlspecialchars($r['customer']??'',ENT_QUOTES,'UTF-8') ?></td>
              <td><?= number_format((float)$r['total_amount'],2) ?></td>
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
    const p=parseFloat(tr.querySelector('.price').value||0);
    const l=(q>0&&p>=0)?q*p:0;
    tr.querySelector('.line').textContent=l.toFixed(2);
    t+=l;
  });
  document.getElementById('gt').textContent=t.toFixed(2);
}
function bind(tr){
  const pr=tr.querySelector('.prod'), qty=tr.querySelector('.qty'), price=tr.querySelector('.price'), av=tr.querySelector('.avail');
  pr.addEventListener('change', ()=>{
    const o=pr.selectedOptions[0];
    const q=o?parseInt(o.getAttribute('data-qty')||'0',10):0;
    const s=o?parseFloat(o.getAttribute('data-sale')||'0'):0;
    av.textContent=isNaN(q)?'0':q;
    if(!price.value||parseFloat(price.value)===0) price.value=isNaN(s)?0:s;
    recalc();
  });
  qty.addEventListener('input', recalc);
  price.addEventListener('input', recalc);
}
function addRow(){
  const tb=document.querySelector('#t tbody'), tr=tb.rows[0].cloneNode(true);
  tr.querySelector('.prod').selectedIndex=0;
  tr.querySelector('.avail').textContent='0';
  tr.querySelector('.qty').value=1;
  tr.querySelector('.price').value=0;
  tr.querySelector('.expiry_date').value='';
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
    tr.querySelector('.price').value=0;
    tr.querySelector('.expiry_date').value='';
    tr.querySelector('.line').textContent='0.00';
  } else btn.closest('tr').remove();
  recalc();
}
bind(document.querySelector('#t tbody tr')); recalc();
</script>
</body>
</html>
