<?php require_once __DIR__ . '/auth.php'; ?>
<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>العمليات المالية - موردن</title>
<style>body{margin:0;font-family:Tahoma;background:#f5f8fc}.header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;color:#fff;padding:12px}a{color:#64ffda;text-decoration:none}.wrap{padding:18px}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.tile{padding:18px;border:1px solid #e7eaf3;border-radius:12px;background:#fff}</style>
</head><body>
<div class="header"><div>العمليات المالية</div><nav><a href="./dashboard.php">الرجوع</a></nav></div>
<div class="wrap">
  <div class="grid">
    <a class="tile" href="./receipt_voucher.php">سند قبض</a>
    <a class="tile" href="./payment_voucher.php">سند صرف</a>
    <a class="tile" href="./adjustment_entries.php">قيود التسوية</a>
    <a class="tile" href="./post_to_journal.php">ترحيل العمليات إلى قيود اليومية</a>
  </div>
</div>
</body></html>
