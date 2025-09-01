<?php
require_once __DIR__ . '/auth.php';
if(!function_exists('db')) require_once __DIR__ . '/config.php';
$pdo=db();

$q="SELECT 'فاتورة مشتريات' kind,id,invoice_date AS d,created_at FROM purchase_invoices
UNION ALL SELECT 'عينات واردة',id,doc_date,created_at FROM purchase_samples_headers
UNION ALL SELECT 'مردود مشتريات',id,doc_date,created_at FROM purchase_return_headers
UNION ALL SELECT 'فاتورة مبيعات',id,invoice_date,created_at FROM sales_invoices
UNION ALL SELECT 'مردود مبيعات',id,doc_date,created_at FROM sales_return_headers
UNION ALL SELECT 'أمر صرف مخزني',id,doc_date,created_at FROM stock_issues
ORDER BY d DESC, id DESC LIMIT 200";
$rows=[]; try{$rows=$pdo->query($q)->fetchAll();}catch(Throwable $e){}
?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>الأرشيف - المستندات المخزنية</title>
<style>body{margin:0;font-family:Tahoma;background:#f5f8fc}.header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;color:#fff;padding:12px}a{color:#64ffda;text-decoration:none}.wrap{padding:18px}.card{background:#fff;border:1px solid #dfe7f5;border-radius:12px;padding:16px}table{width:100%;border-collapse:collapse;margin-top:10px}th,td{border-bottom:1px solid #e7eaf3;padding:8px;text-align:right}th{color:#6b7280}</style></head>
<body>
<div class="header"><div>الأرشيف - المستندات المخزنية</div><nav><a href="./dashboard.php">الرجوع</a></nav></div>
<div class="wrap"><div class="card">
<table><thead><tr><th>النوع</th><th>الرقم</th><th>التاريخ</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="3">لا توجد بيانات</td></tr><?php else: foreach($rows as $r):?>
<tr><td><?=$r['kind']?></td><td><?=$r['id']?></td><td><?=$r['d']?></td></tr>
<?php endforeach; endif;?>
</tbody></table>
</div></div></body></html>
