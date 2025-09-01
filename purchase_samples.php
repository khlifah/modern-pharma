<?php
require_once __DIR__ . '/auth.php';
if (!function_exists('db')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/accounting.php';
$pdo = db();

/* جدول المنتجات */
$pdo->exec("CREATE TABLE IF NOT EXISTS products(
  id INT AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(100) UNIQUE,
  name VARCHAR(200) NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  cost_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sale_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* رأس المستند */
$pdo->exec("CREATE TABLE IF NOT EXISTS purchase_samples_headers(
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_date DATE NOT NULL,
  note VARCHAR(255) NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* تفاصيل المستند */
$pdo->exec("CREATE TABLE IF NOT EXISTS purchase_samples_items(
  id INT AUTO_INCREMENT PRIMARY KEY,
  header_id INT NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  cost_price DECIMAL(14,2) NOT NULL,
  expiry_date DATE NULL,
  FOREIGN KEY(header_id) REFERENCES purchase_samples_headers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* إضافة أعمدة تاريخ الإصدار والانتهاء إن لم توجد */
if (!function_exists('col_exists')) {
  function col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
  }
}
try { if (!col_exists($pdo, 'purchase_samples_items', 'expiry_date')) $pdo->exec("ALTER TABLE purchase_samples_items ADD COLUMN expiry_date DATE NULL AFTER cost_price"); } catch(Throwable $e){}

$products = $pdo->query("SELECT id,name,sku,quantity,cost_price FROM products ORDER BY name")->fetchAll();
$errors=[]; $ok=null;

/* حفظ */
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action']??'')==='save'){
  $date=$_POST['doc_date']??date('Y-m-d');
  $note=trim($_POST['note']??'');

  $pids=$_POST['item_product_id']??[];
  $qtys=$_POST['item_qty']??[];
  $issues=$_POST['item_issue_date']??[];
  $expiries=$_POST['item_expiry_date']??[];

  $items=[]; $total=0;
  for($i=0;$i<count($pids);$i++){
    $pid=(int)($pids[$i]??0);
    $q=(int)($qtys[$i]??0);
    $issue_date=trim($issues[$i]??'');
    $expiry_date = trim($_POST["item_{$i}_expiry_date"] ?? '');
    if($pid>0 && $q>0){
      $chk=$pdo->prepare("SELECT cost_price FROM products WHERE id=?");
      $chk->execute([$pid]);
      $c=(float)($chk->fetchColumn()?:0);
      $lt=$q*$c;
      $items[] = ['pid'=>$pid, 'qty'=>$q, 'cost'=>$c, 'expiry_date'=>$expiry_date];
      $total+=$lt;
    }
  }
  if(!$items) $errors[]='أضف صنف واحد على الأقل.';

  if(!$errors){
    try{
      $pdo->beginTransaction();

      $st=$pdo->prepare("INSERT INTO purchase_samples_headers(doc_date,note,total_amount) VALUES (?,?,?)");
      $st->execute([$date,$note?:null,$total]);
      $hid=(int)$pdo->lastInsertId();

      $sti=$pdo->prepare("INSERT INTO purchase_samples_items(header_id,product_id,quantity,cost_price,expiry_date) VALUES (?,?,?,?,?)");
      $upd=$pdo->prepare("UPDATE products SET quantity=quantity+? WHERE id=?");
      foreach($items as $r){
        $sti->execute([$hid,$r['pid'],$r['qty'],$r['cost'],$r['expiry_date']]);
        $upd->execute([$r['qty'],$r['pid']]);
      }

      $pdo->commit();

      // قيد يومية
      post_journal($pdo,'purchase_sample',$hid,$date,'عينات مجانية واردة',[
        ['account'=>'المخزون','debit'=>$total,'credit'=>0],
        ['account'=>'هبات / عينات مجانية','debit'=>0,'credit'=>$total],
      ]);
      
      $ok="تم إضافة عينات مجانية (#$hid) للمخزون.";
      $_POST=[];
    }catch(Throwable $e){
      if($pdo->inTransaction()) $pdo->rollBack();
      $errors[]='فشل الحفظ: '.$e->getMessage();
    }
  }
}

$list=$pdo->query("SELECT id,doc_date,total_amount FROM purchase_samples_headers ORDER BY id DESC LIMIT 50")->fetchAll();
$title="العينات المجانية الواردة";
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $title ?> - موردن</title>
<style>
body{margin:0;font-family:Tahoma;background:#f5f8fc;color:#333}
.header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;color:#fff;padding:12px}
a{color:#64ffda;text-decoration:none}
.wrap{padding:18px;display:grid;gap:16px}
.card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:16px}
label{display:block;margin:6px 0}input,select{width:100%;padding:8px;border:1px solid #ccc;border-radius:8px}
table{width:100%;border-collapse:collapse;margin-top:10px}
th,td{border-bottom:1px solid #eee;padding:8px;text-align:right}
.btn{background:#13c2b3;color:#062427;border:0;padding:8px 12px;border-radius:8px;cursor:pointer}
</style>
</head>
<body>
<div class="header"><div><?= $title ?></div><nav><a href="./dashboard.php">رجوع</a></nav></div>
<div class="wrap">
  <div class="card">
    <?php foreach($errors as $e):?><div style="background:#fee;border:1px solid #f99;padding:8px"><?=htmlspecialchars($e)?></div><?php endforeach;?>
    <?php if($ok):?><div style="background:#efe;border:1px solid #9f9;padding:8px"><?=htmlspecialchars($ok)?></div><?php endif;?>
    <form method="post">
      <input type="hidden" name="action" value="save">
      <label>التاريخ</label><input type="date" name="doc_date" value="<?=date('Y-m-d')?>">
      <label>ملاحظة</label><input name="note">
      <h3>العناصر</h3>
      <table id="t"><thead><tr><th>الصنف</th><th>كمية</th><th>تاريخ الانتهاء</th><th>—</th></tr></thead>
        <tbody>
          <tr>
            <td>
              <select name="item_product_id[]">
                <option value="">— اختر —</option>
                <?php foreach($products as $p):?>
                  <option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?></option>
                <?php endforeach;?>
              </select>
            </td>
            <td><input type="number" name="item_qty[]" value="1" min="1"></td>
            <td><input type="date" name="item_0_expiry_date" placeholder="تاريخ الانتهاء"></td>
            <td><button type="button" onclick="delRow(this)" class="btn" style="background:#f55;color:#fff">X</button></td>
          </tr>
        </tbody>
        <tfoot><tr><td colspan="5"><button type="button" onclick="addRow()" class="btn">+ إضافة</button></td></tr></tfoot>
      </table>
      <div><button class="btn" type="submit">حفظ</button></div>
    </form>
  </div>
  <div class="card">
    <h3>آخر العينات</h3>
    <table><tr><th>#</th><th>التاريخ</th><th>الإجمالي</th></tr>
      <?php if(!$list):?><tr><td colspan="3">لا يوجد</td></tr><?php endif;?>
      <?php foreach($list as $r):?><tr><td><?=$r['id']?></td><td><?=$r['doc_date']?></td><td><?=number_format($r['total_amount'],2)?></td></tr><?php endforeach;?>
    </table>
  </div>
</div>
<script>
function addRow(){
  const tb=document.querySelector('#t tbody');
  const tr=tb.rows[0].cloneNode(true);
  tr.querySelector('select').selectedIndex=0;
  tr.querySelector('input[type="number"]').value=1;
  const dateInputs = tr.querySelectorAll('input[type="date"]');
  dateInputs.forEach(input => input.value = '');
  tb.appendChild(tr);
}
function delRow(btn){
  const tb=document.querySelector('#t tbody');
  if(tb.rows.length>1) btn.closest('tr').remove();
}
</script>
</body></html>
