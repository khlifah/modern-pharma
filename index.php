<?php
// index.php
require_once __DIR__ . '/config.php';

// إذا المستخدم مسجل دخول، ودّه للوحة التحكم
if (!empty($_SESSION['user_id'])) {
  header('Location: ./dashboard.php');
  exit;
}

// قراءة رسالة فلاش (إن وجدت)
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>تسجيل الدخول - موردن</title>
<style>
:root{--bg:#0a192f;--panel:#0e2444;--accent:#64ffda;--danger:#ff4d5a;--text:#e6f1ff}
*{box-sizing:border-box}
body{
  margin:0;font-family:Tahoma,Arial,sans-serif;
  background:linear-gradient(135deg,#071426,#0a192f);
  color:var(--text);min-height:100vh;display:grid;place-items:center;padding:20px
}
.card{
  width:100%;max-width:420px;background:var(--panel);
  border-radius:18px;box-shadow:0 10px 40px rgba(0,0,0,.35);padding:24px
}
h1{margin:0 0 14px;font-size:22px}
.muted{margin:0 0 18px;color:#9fb3d1;font-size:14px}
label{display:block;margin:12px 0 8px;font-size:14px}
input[type=text],input[type=password]{
  width:100%;padding:12px 14px;border-radius:12px;border:1px solid #183152;
  background:#10294f;color:var(--text)
}
.actions{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-top:16px}
button{cursor:pointer;border:0;border-radius:12px;padding:12px 16px}
.btn-primary{background:var(--accent);color:#062427;font-weight:700}
a.btn-link{display:inline-block;text-decoration:none;color:var(--accent);font-weight:700;padding:10px 0}
.flash{margin-bottom:10px;border-radius:12px;padding:10px 12px;background:#0b2a22;border:1px solid #186b5a}
.flash.error{background:#2a0b0f;border-color:#7a2a33}
.small{font-size:13px;color:#9fb3d1}
.footer{text-align:center;margin-top:14px}
</style>
</head>
<body>
  <div class="card">
    <h1>تسجيل الدخول</h1>
    <p class="muted">أدخل اسم المستخدم وكلمة المرور للدخول للنظام.</p>

    <?php if ($flash): ?>
      <div class="flash <?= $flash['type'] ?? '' ?>">
        <?= htmlspecialchars($flash['msg'], ENT_QUOTES, 'UTF-8') ?>
      </div>
    <?php endif; ?>

    <form method="post" action="./login.php" autocomplete="on">
      <label>اسم المستخدم</label>
      <input type="text" name="username" required autofocus>

      <label>كلمة المرور</label>
      <input type="password" name="password" required>

      <div class="actions">
        <button class="btn-primary" type="submit">دخول</button>
        <span class="small">نسيت كلمة المرور؟ (لاحقاً)</span>
      </div>
    </form>

    <div class="footer">
      <a class="btn-link" href="./create_user.php">ما عندك حساب؟ أنشئ حساب الآن</a>
    </div>
  </div>
</body>
</html>
