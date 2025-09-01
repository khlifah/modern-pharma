<?php
require_once __DIR__ . '/auth.php';
if(!function_exists('db')) require_once __DIR__ . '/config.php';
$pdo=db();

$q="SELECT 'سند قبض' kind,id,doc_date AS d,posted,created_at FROM receipt_vouchers
UNION ALL SELECT 'سند صرف',id,doc_date,posted,created_at FROM payment_vouchers
UNION ALL SELECT 'قيد تسوية',id,doc_date,posted,created_at FROM adjustment_entries
ORDER BY d DESC, id DESC LIMIT 200";
$rows=[]; try{$rows=$pdo->query($q)->fetchAll();}catch(Throwable $e){}
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>الأرشيف - المستندات المالية</title>
<style>body{margin:0;font-family:Tahoma;background:#f5f8fc}.header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;color:#fff;padding:12px}a{color:#64ffda;text-decoration:none}.wrap{padding:18px}.card{background:#fff;border:1px solid #dfe7f5;border-radius:12px;padding:16px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border-bottom:1px solid #e7eaf3;padding:8px;text-align:right}.badge{display:inline-block;padding:2px 8px;border-radius:999px;border:1px solid #e7eaf3}</style></head>
<body>
<div class="header"><div>الأرشيف - المستندات المالية</div><nav><a href="./dashboard.php">الرجوع</a></nav></div>
<div class="wrap"><div class="card">
<table><thead><tr><th>النوع</th><th>الرقم</th><th>التاريخ</th><th>الحالة</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="4">لا توجد بيانات</td></tr><?php else: foreach($rows as $r):?>
<tr><td><?=$r['kind']?></td><td><?=$r['id']?></td><td><?=$r['d']?></td><td><span class="badge"><?=$r['posted']?'مُرحّل':'غير مُرحّل'?></span></td></tr>
<?php endforeach; endif;?>
</tbody></table>
</div></div></body></html>
