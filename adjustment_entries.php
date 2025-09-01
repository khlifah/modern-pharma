<?php
require_once __DIR__ . '/auth.php';
if(!function_exists('db')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/accounting.php';
$pdo=db();

$pdo->exec("CREATE TABLE IF NOT EXISTS adjustment_entries(
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_date DATE NOT NULL,
  description VARCHAR(255) NOT NULL,
  debit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  credit DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  posted TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$errors=[];$ok=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
  $d=$_POST['doc_date']??date('Y-m-d');
  $desc=trim($_POST['description']??'');
  $debit=(float)($_POST['debit']??0);
  $credit=(float)($_POST['credit']??0);
  if($desc==='') $errors[]='الوصف مطلوب.';
  if($debit<=0 && $credit<=0) $errors[]='أدخل مدين أو دائن.';
  if(!$errors){
    try{
      $pdo->beginTransaction();
      $pdo->prepare("INSERT INTO adjustment_entries(doc_date,description,debit,credit) VALUES (?,?,?,?)")
          ->execute([$d,$desc,$debit,$credit]);
      $id=(int)$pdo->lastInsertId();
      // سنسجل القيد كما هو (لو قيمتين كلاهما > 0، يجب أن تكونا متساويتين ليتوازن القيد)
      $amt=max($debit,$credit);
      $pdo->commit();
      
      post_journal($pdo,'adjustment',$id,$d,"قيد تسوية: $desc",[
        ['account'=>"تسوية - مدين",'debit'=>$amt,'credit'=>0],
        ['account'=>"تسوية - دائن",'debit'=>0,'credit'=>$amt],
      ]);
      
      $ok='تم حفظ قيد التسوية وترحيله.'; 
      $_POST=[];
    }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); $errors[]='خطأ: '.$e->getMessage(); }
  }
}
$list=$pdo->query("SELECT * FROM adjustment_entries ORDER BY id DESC LIMIT 50")->fetchAll();
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>قيود التسوية - موردن</title>
<style>body{margin:0;font-family:Tahoma;background:#f5f8fc}.header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;color:#fff;padding:12px}a{color:#64ffda;text-decoration:none}.wrap{padding:18px;display:grid;gap:16px}.card{background:#fff;border:1px solid #dfe7f5;border-radius:12px;padding:16px}label{display:block;margin:8px 0 4px}input,textarea{width:100%;padding:10px;border:1px solid #cfd9ec;border-radius:10px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border-bottom:1px solid #e7eaf3;padding:8px;text-align:right}.btn{border:0;border-radius:10px;padding:10px 14px;background:#13c2ب3;color:#062427;font-weight:700;cursor:pointer}</style></head>
<body>
<div class="header"><div>قيود التسوية</div><nav><a href="./dashboard.php">الرجوع</a></nav></div>
<div class="wrap">
  <div class="card">
    <?php foreach($errors as $e):?><div style="background:#fee;border:1px solid #fbb;padding:8px;border-radius:8px;margin:6px 0"><?=htmlspecialchars($e)?></div><?php endforeach;?>
    <?php if($ok):?><div style="background:#e6fff7;border:1px solid #a7f3د0;padding:8px;border-radius:8px;margin:6px 0"><?=htmlspecialchars($ok)?></div><?php endif;?>
    <form method="post">
      <label>التاريخ</label><input type="date" name="doc_date" value="<?=htmlspecialchars($_POST['doc_date']??date('Y-m-d'))?>">
      <label>الوصف</label><input name="description" value="<?=htmlspecialchars($_POST['description']??'')?>">
      <label>مدين</label><input type="number" step="0.01" min="0" name="debit" value="<?=htmlspecialchars($_POST['debit']??'0')?>">
      <label>دائن</label><input type="number" step="0.01" min="0" name="credit" value="<?=htmlspecialchars($_POST['credit']??'0')?>">
      <div style="margin-top:10px"><button class="btn" type="submit">حفظ</button></div>
    </form>
  </div>
  <div class="card">
    <h3>آخر القيود</h3>
    <table><thead><tr><th>#</th><th>التاريخ</th><th>الوصف</th><th>مدين</th><th>دائن</th></tr></thead>
      <tbody><?php if(!$list):?><tr><td colspan="5">لا توجد بيانات</td></tr><?php else: foreach($list as $r):?><tr><td><?=$r['id']?></td><td><?=$r['doc_date']?></td><td><?=htmlspecialchars($r['description'])?></td><td><?=number_format($r['debit'],2)?></td><td><?=number_format($r['credit'],2)?></td></tr><?php endforeach; endif;?></tbody>
    </table>
  </div>
</div>
</body></html>
