<?php
// home.php
require_once __DIR__ . '/auth.php';
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>الصفحة الرئيسية - موردن</title>
<style>
:root{--bg:#0a192f;--panel:#0e2444;--accent:#64ffda;--text:#e6f1ff}
*{box-sizing:border-box}
body{margin:0;font-family:Tahoma,Arial,sans-serif;background:#0a192f;color:var(--text);min-height:100vh}
.header{background:#0c2140;color:#fff;padding:14px 18px;display:flex;justify-content:space-between}
a{color:var(--accent);text-decoration:none}
.main{padding:20px}
.card{background:#0e2444;border-radius:16px;padding:20px;box-shadow:0 6px 20px rgba(0,0,0,.3)}
</style>
</head>
<body>
  <div class="header">
    <div>مرحباً، <?= htmlspecialchars($_SESSION['username'], ENT_QUOTES, 'UTF-8') ?></div>
    <nav><a href="./logout.php">تسجيل الخروج</a></nav>
  </div>
  <div class="main">
    <div class="card">
      <h2>الصفحة الرئيسية</h2>
      <p>أهلاً بك في النظام. من هنا تقدر تنتقل إلى لوحة التحكم أو أي قسم آخر.</p>
      <p><a href="./dashboard.php">اذهب إلى لوحة التحكم</a></p>
    </div>
  </div>
</body>
</html>
