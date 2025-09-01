<?php
require_once __DIR__ . '/auth.php';
if(!function_exists('db')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/accounting.php';
$pdo=db();

$pdo->exec("CREATE TABLE IF NOT EXISTS products(
  id INT AUTO_INCREMENT PRIMARY KEY,
  sku VARCHAR(100) UNIQUE,
  name VARCHAR(200) NOT NULL,
  quantity INT NOT NULL DEFAULT 0,
  cost_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  sale_price DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$pdo->exec("CREATE TABLE IF NOT EXISTS stock_issues(
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_date DATE NOT NULL,
  product_id INT NOT NULL,
  quantity INT NOT NULL,
  reason VARCHAR(150) NULL,
  note VARCHAR(255) NULL,
  total_cost DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  expiry_date DATE NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

/* إضافة أعمدة تاريخ الإصدار والانتهاء إن لم توجد */
if (!function_exists('col_exists')) {
  function col_exists(PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1");
    $st->execute([$table, $col]);
    return (bool)$st->fetchColumn();
  }
}
try { if (!col_exists($pdo, 'stock_issues', 'expiry_date')) $pdo->exec("ALTER TABLE stock_issues ADD COLUMN expiry_date DATE NULL AFTER total_cost"); } catch(Throwable $e){}

$products=$pdo->query("SELECT id,name,sku,quantity,cost_price FROM products ORDER BY name")->fetchAll();

$errors=[];$ok=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
  $d=$_POST['doc_date']??date('Y-m-d');
  $pid=(int)($_POST['product_id']??0);
  $q=(int)($_POST['quantity']??0);
  $reason=trim($_POST['reason']??''); $note=trim($_POST['note']??'');
  $expiry_date=trim($_POST['expiry_date']??'');
  if($pid<=0) $errors[]='اختر الصنف.'; if($q<=0) $errors[]='الكمية > 0';

  if(!$errors){
    $pr=$pdo->prepare("SELECT quantity,cost_price,name FROM products WHERE id=?"); $pr->execute([$pid]); $p=$pr->fetch();
    if(!$p){ $errors[]='الصنف غير موجود.'; }
    elseif((int)$p['quantity']<$q){ $errors[]='المتوفر لا يكفي (المتاح: '.$p['quantity'].')'; }
    else{
      try{
        $pdo->beginTransaction();
        $total = round($q * (float)$p['cost_price'], 2);
        $st=$pdo->prepare("INSERT INTO stock_issues(doc_date,product_id,quantity,reason,note,total_cost,expiry_date) VALUES (?,?,?,?,?,?,?)");
        $st->execute([$d,$pid,$q,$reason?:null,$note?:null,$total,$expiry_date?:null]);
        $id=(int)$pdo->lastInsertId();

        $pdo->prepare("UPDATE products SET quantity=quantity-? WHERE id=?")->execute([$q,$pid]);

        $pdo->commit();

        // ترحيل القيد بعد إتمام المعاملة الأساسية
        post_journal($pdo,'stock_issue',$id,$d,'أمر صرف مخزني',[
          ['account'=>'مصروف صرف مخزني','debit'=>$total,'credit'=>0],
          ['account'=>'المخزون','debit'=>0,'credit'=>$total],
        ]);

        $ok="تم تسجيل أمر الصرف #$id وخصم المخزون وترحيل القيد."; $_POST=[];
      }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); $errors[]='خطأ: '.$e->getMessage(); }
    }
  }
}
$list=$pdo->query("SELECT si.id,si.doc_date,p.name product,si.quantity,si.reason,si.total_cost FROM stock_issues si JOIN products p ON p.id=si.product_id ORDER BY si.id DESC LIMIT 50")->fetchAll();
$title='أمر صرف مخزني';
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= $title ?> - موردن</title>
<style>body{margin:0;font-family:Tahoma;background:#f5f8fc}.header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;color:#fff;padding:12px}a{color:#64ffda;text-decoration:none}.wrap{padding:18px;display:grid;gap:16px}.card{background:#fff;border:1px solid #dfe7f5;border-radius:12px;padding:16px}label{display:block;margin:8px 0 4px}input,select,textarea{width:100%;padding:10px;border:1px solid #cfd9ec;border-radius:10px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border-bottom:1px solid #e7eaf3;padding:8px;text-align:right}.btn{border:0;border-radius:10px;padding:9px 12px;background:#13c2b3;color:#062427;font-weight:700;cursor:pointer}</style></head>
<body>
<div class="header"><div><?= $title ?></div><nav><a href="./dashboard.php">الرجوع</a></nav></div>
<div class="wrap">
  <div class="card">
    <?php foreach($errors as $e):?><div style="background:#fee;border:1px solid #fbb;padding:10px;border-radius:10px;margin:6px 0"><?=htmlspecialchars($e)?></div><?php endforeach;?>
    <?php if($ok):?><div style="background:#e6fff7;border:1px solid #a7f3d0;padding:10px;border-radius:10px;margin:6px 0"><?=htmlspecialchars($ok)?></div><?php endif;?>
    <form method="post">
      <label>التاريخ</label><input type="date" name="doc_date" value="<?=htmlspecialchars($_POST['doc_date']??date('Y-m-d'))?>">
      <label>الصنف</label><select name="product_id"><option value="">— اختر —</option><?php foreach($products as $p):?><option value="<?=$p['id']?>"><?=htmlspecialchars($p['name'])?> — متوفر: <?=$p['quantity']?></option><?php endforeach;?></select>
      <label>الكمية</label><input type="number" name="quantity" min="1" step="1" value="<?=htmlspecialchars($_POST['quantity']??'1')?>">
      <label>تاريخ الانتهاء</label><input type="date" name="expiry_date" value="<?=htmlspecialchars($_POST['expiry_date']??'')?>">
      <label>السبب</label><input name="reason" value="<?=htmlspecialchars($_POST['reason']??'')?>">
      <label>ملاحظة</label><textarea name="note" rows="2"><?=htmlspecialchars($_POST['note']??'')?></textarea>
      <div style="margin-top:10px"><button class="btn" type="submit">حفظ</button></div>
    </form>
  </div>
  <div class="card">
    <h3>آخر الأوامر</h3>
    <table><thead><tr><th>#</th><th>التاريخ</th><th>الصنف</th><th>كمية</th><th>سبب</th><th>التكلفة</th></tr></thead><tbody>
    <?php if(!$list):?><tr><td colspan="6">لا يوجد</td></tr><?php else: foreach($list as $r):?><tr><td><?=$r['id']?></td><td><?=$r['doc_date']?></td><td><?=htmlspecialchars($r['product'])?></td><td><?=$r['quantity']?></td><td><?=htmlspecialchars($r['reason']??'')?></td><td><?=number_format($r['total_cost'],2)?></td></tr><?php endforeach; endif;?>
    </tbody></table>
  </div>
</div>
</body></html>
