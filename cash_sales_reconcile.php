<?php
require_once __DIR__ . '/auth.php';
if(!function_exists('db')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/accounting.php';
$pdo=db();

/* جدول فواتير المبيعات (مفترض موجود؛ نضمن الأعمدة) */
$pdo->exec("CREATE TABLE IF NOT EXISTS sales_invoices(
  id INT AUTO_INCREMENT PRIMARY KEY,
  invoice_date DATE NOT NULL,
  supplier_id INT NOT NULL,
  note VARCHAR(255) NULL,
  total_amount DECIMAL(14,2) NOT NULL DEFAULT 0.00,
  reconciled TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

$errors=[];$ok=null;
if($_SERVER['REQUEST_METHOD']==='POST'){
  $id=(int)($_POST['invoice_id']??0);
  $val=(int)($_POST['val']??0);
  if($id>0){
    try{
      $pdo->beginTransaction();
      $r=$pdo->prepare("SELECT total_amount,invoice_date,supplier_id,reconciled FROM sales_invoices WHERE id=?");
      $r->execute([$id]); $row=$r->fetch();
      if(!$row) throw new Exception('الفاتورة غير موجودة.');
      $amt=(float)$row['total_amount']; $d=$row['invoice_date']; $sid=(int)$row['supplier_id']; $old=(int)$row['reconciled'];

      if($val && !$old){
        // تحقيق
        post_journal($pdo,'sales_cash_reconcile',$id,$d,"تحقيق فاتورة مبيعات #$id",[
          ['account'=>'الصندوق','debit'=>$amt,'credit'=>0],
          ['account'=>"مدينون - عميل/مورد #$sid",'debit'=>0,'credit'=>$amt],
        ]);
        $pdo->prepare("UPDATE sales_invoices SET reconciled=1 WHERE id=?")->execute([$id]);
        $ok='تم التحقيق.';
      }elseif(!$val && $old){
        // إلغاء التحقيق (قيد عكسي)
        post_journal($pdo,'sales_cash_unreconcile',$id,$d,"عكس تحقيق فاتورة مبيعات #$id",[
          ['account'=>"مدينون - عميل/مورد #$sid",'debit'=>$amt,'credit'=>0],
          ['account'=>'الصندوق','debit'=>0,'credit'=>$amt],
        ]);
        $pdo->prepare("UPDATE sales_invoices SET reconciled=0 WHERE id=?")->execute([$id]);
        $ok='تم إلغاء التحقيق.';
      } else {
        $ok='لا تغيير.';
      }
      $pdo->commit();
    }catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); $errors[]='خطأ: '.$e->getMessage(); }
  }
}
$list=$pdo->query("SELECT si.id,si.invoice_date,si.total_amount,si.reconciled,s.name supplier FROM sales_invoices si JOIN suppliers s ON s.id=si.supplier_id ORDER BY si.id DESC LIMIT 100")->fetchAll();
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>تحقيق فواتير المبيعات النقدية - موردن</title>
<style>body{margin:0;font-family:Tahoma;background:#f5f8fc}.header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;color:#fff;padding:12px}a{color:#64ffda;text-decoration:none}.wrap{padding:18px}.card{background:#fff;border:1px solid #dfe7f5;border-radius:12px;padding:16px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border-bottom:1px solid #e7eaf3;padding:8px;text-align:right}.btn{border:0;border-radius:8px;padding:6px 10px;background:#13c2ب3;color:#062427;font-weight:700;cursor:pointer}</style>
</head><body>
<div class="header"><div>تحقيق فواتير المبيعات النقدية</div><nav><a href="./dashboard.php">الرجوع</a></nav></div>
<div class="wrap">
  <div class="card">
    <?php foreach(($errors??[]) as $e):?><div style="background:#fee;border:1px solid #fbb;padding:8px;border-radius:8px;margin:6px 0"><?=htmlspecialchars($e)?></div><?php endforeach;?>
    <?php if($ok):?><div style="background:#e6fff7;border:1px solid #a7f3d0;padding:8px;border-radius:8px;margin:6px 0"><?=htmlspecialchars($ok)?></div><?php endif;?>
    <table><thead><tr><th>#</th><th>التاريخ</th><th>العميل/المورد</th><th>الإجمالي</th><th>الحالة</th><th>إجراء</th></tr></thead><tbody>
      <?php if(!$list):?><tr><td colspan="6">لا توجد فواتير</td></tr><?php else: foreach($list as $r):?>
        <tr>
          <td><?=$r['id']?></td><td><?=$r['invoice_date']?></td><td><?=htmlspecialchars($r['supplier'])?></td>
          <td><?=number_format($r['total_amount'],2)?></td><td><?=$r['reconciled']?'محقق':'غير محقق'?></td>
          <td>
            <form method="post" style="display:inline-block">
              <input type="hidden" name="invoice_id" value="<?=$r['id']?>">
              <input type="hidden" name="val" value="<?=$r['reconciled']?0:1?>">
              <button class="btn" type="submit"><?=$r['reconciled']?'إلغاء':'تحقيق'?></button>
            </form>
          </td>
        </tr>
      <?php endforeach; endif;?>
    </tbody></table>
  </div>
</div>
</body></html>
