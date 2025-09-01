<?php
require_once __DIR__ . '/auth.php';
if(!function_exists('db')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/accounting.php';
$pdo=db();

/* جداول */
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
$pdo->exec("CREATE TABLE IF NOT EXISTS sales_return_headers(
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_date DATE NOT NULL,
  supplier_id INT NOT NULL,
  note VARCHAR(255) NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$pdo->exec("CREATE TABLE IF NOT EXISTS sales_return_items(
  id INT AUTO_INCREMENT PRIMARY KEY,
  header_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  price DECIMAL(14,2) NOT NULL,
  cost DECIMAL(14,2) NOT NULL,
  line_total DECIMAL(14,2) NOT NULL,
  expiry_date DATE NULL,
  FOREIGN KEY(header_id) REFERENCES sales_return_headers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* إضافة أعمدة تاريخ الإصدار والانتهاء إن لم توجد */
if (!function_exists('col_exists')) {
  function col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
  }
}
try { if (!col_exists($pdo, 'sales_return_items', 'expiry_date')) $pdo->exec("ALTER TABLE sales_return_items ADD COLUMN expiry_date DATE NULL AFTER line_total"); } catch(Throwable $e){}

$suppliers=$pdo->query("SELECT id,name FROM suppliers ORDER BY name")->fetchAll();
$products =$pdo->query("SELECT id,name,sku,quantity,sale_price,cost_price FROM products ORDER BY name")->fetchAll();

$errors=[];$ok=null;
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save'){
  $d=$_POST['doc_date']??date('Y-m-d');
  $sid=(int)($_POST['supplier_id']??0);
  $note=trim($_POST['note']??'');
  if($sid<=0) $errors[]='اختر العميل/المورد.';

  $pids=$_POST['item_product_id']??[];
  $qtys=$_POST['item_qty']??[];
  $prices=$_POST['item_price']??[];
  $issues=$_POST['item_issue_date']??[];
  $expiries=$_POST['item_expiry_date']??[];

  $items=[]; $total=0; $sum_cogs=0;
  for($i=0;$i<count($pids);$i++){
    $pid=(int)($pids[$i]??0); $q=(int)($qtys[$i]??0); $pr=(float)($prices[$i]??0);
    if($pid>0 && $q>0 && $pr>=0){
      $p=$pdo->prepare("SELECT cost_price,name FROM products WHERE id=?");
      $p->execute([$pid]); $row=$p->fetch();
      if(!$row){ $errors[]='صنف غير موجود'; continue; }
      $expiry_date = trim($_POST["item_{$i}_expiry_date"] ?? '');
      $lt=round($q*$pr,2);
      $items[] = ['pid'=>$pid, 'qty'=>$q, 'price'=>$pr, 'cost'=>(float)$row['cost_price'],'line'=>$lt,'expiry_date'=>$expiry_date?:null];
      $total+=$lt; $sum_cogs += $q*(float)$row['cost_price'];
    }
  }
  if(!$items) $errors[]='أضف سطرًا واحدًا على الأقل.';

  if(!$errors){
    try{
      $pdo->beginTransaction();
      $h=$pdo->prepare("INSERT INTO sales_return_headers(doc_date,supplier_id,note,total_amount) VALUES (?,?,?,?)");
      $h->execute([$d,$sid,$note?:null,$total]); $hid=(int)$pdo->lastInsertId();

      $it=$pdo->prepare("INSERT INTO sales_return_items(header_id,product_id,quantity,price,cost,line_total,expiry_date) VALUES (?,?,?,?,?,?,?)");
      $upd=$pdo->prepare("UPDATE products SET quantity=quantity+? WHERE id=?");
      foreach($items as $r){ $it->execute([$hid,$r['pid'],$r['qty'],$r['price'],$r['cost'],$r['line'],$r['expiry_date']]); $upd->execute([$r['qty'],$r['pid']]); }

      $pdo->commit();

      post_journal($pdo,'sales_return',$hid,$d,"مردود مبيعات للعميل/المورد #$sid",[
        ['account'=>'مرتجعات المبيعات',       'debit'=>$total,    'credit'=>0],
        ['account'=>"مدينون - عميل/مورد #$sid",'debit'=>0,         'credit'=>$total],
        ['account'=>'المخزون',                 'debit'=>$sum_cogs, 'credit'=>0],
        ['account'=>'تكلفة البضاعة المباعة',   'debit'=>0,         'credit'=>$sum_cogs],
      ]);
      
      // إضافة إشعار بإنشاء مردود مبيعات
      require_once __DIR__ . '/notifications_lib.php';
      $supplier_name = $pdo->query("SELECT name FROM suppliers WHERE id = $sid")->fetchColumn();
      notify_event(
        $pdo,
        'sales_return_created',
        "تم إنشاء مردود مبيعات #$hid",
        "تم إنشاء مردود مبيعات جديد برقم #$hid\nالعميل/المورد: $supplier_name\nالمجموع: " . number_format($total, 2) . "\nالتاريخ: $d",
        'info',
        $_SESSION['user_id'] ?? null,
        'sales_return_headers',
        $hid,
        true
      );
      
      $ok="تم حفظ مردود المبيعات #$hid وترحيل القيد وزيادة المخزون."; 
      $_POST=[];
    }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); $errors[]='فشل الحفظ: '.$e->getMessage(); }
  }
}

$list=$pdo->query("SELECT h.id,h.doc_date,h.total_amount,s.name supplier FROM sales_return_headers h JOIN suppliers s ON s.id=h.supplier_id ORDER BY h.id DESC LIMIT 50")->fetchAll();
$title='مردود مبيعات';
?>
<!doctype html><html lang="ar" dir="rtl"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $title ?> - موردن</title>
<style>body{margin:0;font-family:Tahoma;background:#f5f8fc} .header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;color:#fff;padding:12px} a{color:#64ffda;text-decoration:none}.wrap{padding:18px;display:grid;gap:16px}.card{background:#fff;border:1px solid #dfe7f5;border-radius:12px;padding:16px}label{display:block;margin:8px 0 4px}input,select,textarea{width:100%;padding:10px;border:1px solid #cfd9ec;border-radius:10px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border-bottom:1px solid #e7eaf3;padding:8px;text-align:right}.btn{border:0;border-radius:10px;padding:9px 12px;background:#13c2b3;color:#062427;font-weight:700;cursor:pointer}</style>
</head><body>
<div class="header"><div><?= $title ?></div><nav><a href="./dashboard.php">الرجوع</a></nav></div>
<div class="wrap">
  <div class="card">
    <?php foreach($errors as $e):?><div style="background:#ffecec;border:1px solid #ffb3b3;padding:10px;border-radius:10px;margin:6px 0"><?=htmlspecialchars($e)?></div><?php endforeach;?>
    <?php if($ok):?><div style="background:#e6fff7;border:1px solid #a7f3د0;padding:10px;border-radius:10px;margin:6px 0"><?=htmlspecialchars($ok)?></div><?php endif;?>
    <form method="post">
      <input type="hidden" name="action" value="save">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px">
        <div><label>التاريخ</label><input type="date" name="doc_date" value="<?=htmlspecialchars($_POST['doc_date']??date('Y-m-d'))?>"></div>
        <div><label>العميل/المورد</label><select name="supplier_id" required><option value="">— اختر —</option><?php foreach($suppliers as $s):?><option value="<?=$s['id']?>" <?=((int)($_POST['supplier_id']??0)===$s['id'])?'selected':''?>><?=htmlspecialchars($s['name'])?></option><?php endforeach;?></select></div>
        <div style="grid-column:1/-1"><label>ملاحظة</label><textarea name="note" rows="2"><?=htmlspecialchars($_POST['note']??'')?></textarea></div>
      </div>
      <h3>العناصر</h3>
      <table id="t"><thead><tr><th>الصنف</th><th>كمية</th><th>سعر البيع</th><th>تاريخ الانتهاء</th><th>—</th></tr></thead>
        <tbody>
          <tr>
            <td><select name="item_product_id[]"><option value="">— اختر —</option><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?></option><?php endforeach;?></select></td>
            <td><input type="number" name="item_qty[]" value="1" min="1"></td>
            <td><input type="number" name="item_price[]" value="0" min="0" step="0.01"></td>
            <td><input type="date" name="item_0_expiry_date" placeholder="تاريخ الانتهاء"></td>
            <td><button type="button" class="btn" style="background:#ff4d5a;color:#fff" onclick="delRow(this)">حذف</button></td>
          </tr>
        </tbody>
        <tfoot><tr><td colspan="6"><button class="btn" type="button" onclick="addRow()">+ إضافة سطر</button></td></tr></tfoot>
      </table>
      <div style="margin-top:10px"><button class="btn" type="submit">حفظ</button></div>
    </form>
  </div>
  <div class="card">
    <h3>آخر مردودات</h3>
    <table><thead><tr><th>#</th><th>التاريخ</th><th>العميل/المورد</th><th>الإجمالي</th></tr></thead><tbody><?php if(!$list):?><tr><td colspan="4">لا يوجد</td></tr><?php else: foreach($list as $r):?><tr><td><?=$r['id']?></td><td><?=$r['doc_date']?></td><td><?=htmlspecialchars($r['supplier'])?></td><td><?=number_format($r['total_amount'],2)?></td></tr><?php endforeach; endif;?></tbody></table>
  </div>
</div>
<script>
function addRow(){const tb=document.querySelector('#t tbody');const tr=tb.rows[0].cloneNode(true);tr.querySelector('select').selectedIndex=0;const inputs=tr.querySelectorAll('input');inputs[0].value=1;inputs[1].value=0;inputs[2].value='';inputs[3].value='';tb.appendChild(tr);}
function delRow(btn){const tb=document.querySelector('#t tbody');if(tb.rows.length===1)return;btn.closest('tr').remove();}
</script>
</body></html>
