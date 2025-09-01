
<?php require_once __DIR__.'/auth.php'; require_login(); ?>
<!doctype html><html lang="ar" dir="rtl"><meta charset="utf-8">
<title>التقارير</title>
<link rel="stylesheet" href="assets/style.css">
<div class="page">
  <header><a class="btn small" href="home.php">↩ رجوع</a><h2>التقارير</h2></header>
  <div class="grid-2">
    <div class="card">
      <h3 id="purchase">تقارير المشتريات</h3>
      <a href="#" class="link">تقرير المشتريات حسب المورد</a>
      <a href="#" class="link">تحليل أسعار الشراء</a>
    </div>
    <div class="card">
      <h3 id="finance">تقارير مالية</h3>
      <a href="#" class="link">كشف حساب</a>
      <a href="#" class="link">حركة الصندوق</a>
    </div>
  </div>
</div>
