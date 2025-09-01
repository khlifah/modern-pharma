<?php
require_once __DIR__ . '/auth.php';
if(!function_exists('db')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/accounting.php';
$pdo=db();

$pdo->exec("CREATE TABLE IF NOT EXISTS payment_vouchers(
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_date DATE NOT NULL,
  payee VARCHAR(150) NOT NULL,
  amount DECIMAL(14,2) NOT NULL,
  method ENUM('cash','bank') NOT NULL DEFAULT 'cash',
  note VARCHAR(255) NULL,
  posted TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$errors=[];$ok=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
  $d=$_POST['doc_date']??date('Y-m-d');
  $payee=trim($_POST['payee']??''); $amount=(float)($_POST['amount']??0);
  $method=$_POST['method']??'cash'; $note=trim($_POST['note']??'');
  if($payee==='') $errors[]='اسم المستفيد مطلوب.'; if($amount<=0) $errors[]='المبلغ > 0';
  if(!$errors){
    try{
      $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO payment_vouchers(doc_date,payee,amount,method,note) VALUES (?,?,?,?,?)")
          ->execute([$d,$payee,$amount,$method,$note?:null]);
      $id=(int)$pdo->lastInsertId();
      $cashAcc=$method==='bank'?'البنك':'الصندوق';
      $pdo->commit();
      
      post_journal($pdo,'payment',$id,$d,"سند صرف إلى $payee",[
        ['account'=>"مصروفات عامة - $payee",'debit'=>$amount,'credit'=>0],
        ['account'=>$cashAcc,'debit'=>0,'credit'=>$amount],
      ]);
      
      $ok="تم حفظ سند الصرف #$id وترحيل القيد."; 
      $_POST=[];
    }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); $errors[]='خطأ: '.$e->getMessage(); }
  }
}
$list=$pdo->query("SELECT * FROM payment_vouchers ORDER BY id DESC LIMIT 50")->fetchAll();
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>سند صرف - موردن</title>
<style>body{margin:0;font-family:Tahoma;background:#f5f8fc}.header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;color:#fff;padding:12px}a{color:#64ffda;text-decoration:none}.wrap{padding:18px;display:grid;gap:16px}.card{background:#fff;border:1px solid #dfe7f5;border-radius:12px;padding:16px}label{display:block;margin:8px 0 4px}input,select,textarea{width:100%;padding:10px;border:1px solid #cfd9ec;border-radius:10px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border-bottom:1px solid #e7eaf3;padding:8px;text-align:right}.btn{border:0;border-radius:10px;padding:10px 14px;background:#13c2b3;color:#062427;font-weight:700;cursor:pointer}</style></head>
<body>
<div class="header"><div>سند صرف</div><nav><a href="./dashboard.php">الرجوع</a></nav></div>
<div class="wrap">
  <div class="card">
    <?php foreach($errors as $e):?><div style="background:#fee;border:1px solid #fbb;padding:8px;border-radius:8px;margin:6px 0"><?=htmlspecialchars($e)?></div><?php endforeach;?>
    <?php if($ok):?><div style="background:#e6fff7;border:1px solid #a7f3d0;padding:8px;border-radius:8px;margin:6px 0"><?=htmlspecialchars($ok)?></div><?php endif;?>
    <form method="post">
      <label>التاريخ</label><input type="date" name="doc_date" value="<?=htmlspecialchars($_POST['doc_date']??date('Y-m-d'))?>">
      <label>المستفيد</label><input name="payee" value="<?=htmlspecialchars($_POST['payee']??'')?>">
      <label>المبلغ</label><input type="number" step="0.01" min="0.01" name="amount" value="<?=htmlspecialchars($_POST['amount']??'0')?>">
      <label>الطريقة</label><select name="method"><option value="cash">نقد</option><option value="bank" <?= (($_POST['method']??'cash')==='bank')?'selected':'' ?>>بنك</option></select>
      <label>ملاحظة</label><textarea name="note" rows="2"><?=htmlspecialchars($_POST['note']??'')?></textarea>
      <div style="margin-top:10px"><button class="btn" type="submit">حفظ</button></div>
    </form>
  </div>
  <div class="card">
    <h3>آخر السندات</h3>
    <table><thead><tr><th>#</th><th>التاريخ</th><th>المستفيد</th><th>المبلغ</th><th>طريقة</th></tr></thead>
      <tbody><?php if(!$list):?><tr><td colspan="5">لا توجد بيانات</td></tr><?php else: foreach($list as $r):?><tr><td><?=$r['id']?></td><td><?=$r['doc_date']?></td><td><?=htmlspecialchars($r['payee'])?></td><td><?=number_format($r['amount'],2)?></td><td><?=$r['method']=='bank'?'بنك':'نقد'?></td></tr><?php endforeach; endif;?></tbody>
    </table>
  </div>
</div>
</body></html>
