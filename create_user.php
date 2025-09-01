<?php
// create_user.php — إنشاء حساب جديد + تسجيل دخول تلقائي
require_once __DIR__ . '/config.php';

/* === عرض الأخطاء أثناء التطوير (احذفها لاحقًا) === */
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
/* ================================================ */

// فحص وجود عمود باستخدام INFORMATION_SCHEMA (آمن مع placeholders)
function column_exists(PDO $pdo, string $table, string $column): bool {
  $sql = "SELECT 1
            FROM INFORMATION_SCHEMA.COLUMNS
           WHERE TABLE_SCHEMA = DATABASE()
             AND TABLE_NAME   = ?
             AND COLUMN_NAME  = ?
           LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$table, $column]);
  return (bool)$st->fetchColumn();
}

$pdo = db();
$has_full_name = column_exists($pdo, 'users', 'full_name');
$has_role      = column_exists($pdo, 'users', 'role');

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username  = trim($_POST['username'] ?? '');
  $full_name = trim($_POST['full_name'] ?? '');
  $password  = (string)($_POST['password'] ?? '');
  $confirm   = (string)($_POST['confirm_password'] ?? '');

  if ($username === '' || $password === '') {
    $errors[] = 'اسم المستخدم وكلمة المرور إلزاميان.';
  }
  if ($password !== $confirm) {
    $errors[] = 'كلمتا المرور غير متطابقتان.';
  }
  if (mb_strlen($password) < 6) {
    $errors[] = 'طول كلمة المرور يجب أن يكون 6 أحرف على الأقل.';
  }

  if (!$errors) {
    // أول مستخدم = admin إن كان عمود role موجود
    $role = null;
    if ($has_role) {
      $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
      $role  = ($count === 0) ? 'admin' : 'user';
    }

    // نبني الأعمدة حسب الموجود فعليًا
    $cols = ['username', 'password_hash'];
    $vals = [$username, password_hash($password, PASSWORD_DEFAULT)];

    if ($has_full_name) {
      $cols[] = 'full_name';
      $vals[] = ($full_name !== '' ? $full_name : null);
    }
    if ($has_role && $role !== null) {
      $cols[] = 'role';
      $vals[] = $role;
    }

    $placeholders = implode(',', array_fill(0, count($cols), '?'));
    $columns_sql  = '`' . implode('`,`', $cols) . '`';
    $sql = "INSERT INTO `users` ($columns_sql) VALUES ($placeholders)";

    try {
      $stmt = $pdo->prepare($sql);
      $stmt->execute($vals);

      // دخول تلقائي
      $_SESSION['user_id']   = (int)$pdo->lastInsertId();
      $_SESSION['username']  = $username;
      $_SESSION['role']      = $role ?: 'user';
      $_SESSION['full_name'] = $has_full_name && $full_name !== '' ? $full_name : $username;

      header('Location: ./dashboard.php');
      exit;
    } catch (PDOException $e) {
      if ((int)$e->errorInfo[1] === 1062) {
        $errors[] = 'اسم المستخدم مستخدم مسبقاً.';
      } else {
        $errors[] = 'حدث خطأ غير متوقع: ' . $e->getMessage();
      }
    }
  }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>إنشاء حساب - موردن</title>
<style>
:root{--bg:#0a192f;--panel:#0e2444;--accent:#64ffda;--danger:#ff4d5a;--text:#e6f1ff}
*{box-sizing:border-box}
body{margin:0;font-family:Tahoma,Arial,sans-serif;background:linear-gradient(135deg,#071426,#0a192f);color:var(--text);min-height:100vh;display:grid;place-items:center;padding:20px}
.card{width:100%;max-width:520px;background:var(--panel);border-radius:18px;box-shadow:0 10px 40px rgba(0,0,0,.35);padding:24px}
h1{margin:0 0 14px;font-size:22px}.muted{margin:0 0 18px;color:#9fb3d1;font-size:14px}
label{display:block;margin:12px 0 8px;font-size:14px}
input[type=text],input[type=password]{width:100%;padding:12px 14px;border-radius:12px;border:1px solid #183152;background:#10294f;color:var(--text)}
.actions{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-top:16px}
button{cursor:pointer;border:0;border-radius:12px;padding:12px 16px}.btn-primary{background:var(--accent);color:#062427;font-weight:700}
a.btn-link{display:inline-block;text-decoration:none;color:#9fb3d1;padding:10px 0}
.error{margin:8px 0;border-radius:12px;padding:10px 12px;background:#2a0b0f;border:1px solid #7a2a33}
</style>
</head>
<body>
  <div class="card">
    <h1>إنشاء حساب</h1>
    <p class="muted">أدخل البيانات لإنشاء حساب جديد. إذا كان هذا أول حساب سيتم ضبطه كـ <b>admin</b> (إن كان عمود الدور موجود).</p>

    <?php foreach ($errors as $e): ?>
      <div class="error"><?= htmlspecialchars($e, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endforeach; ?>

    <form method="post" action="./create_user.php" autocomplete="on">
      <label>اسم المستخدم</label>
      <input type="text" name="username" required autofocus>

      <label>الاسم الكامل (اختياري)</label>
      <input type="text" name="full_name">

      <label>كلمة المرور</label>
      <input type="password" name="password" required>

      <label>تأكيد كلمة المرور</label>
      <input type="password" name="confirm_password" required>

      <div class="actions">
        <a class="btn-link" href="./index.php">رجوع لتسجيل الدخول</a>
        <button class="btn-primary" type="submit">إنشاء الحساب</button>
      </div>
    </form>
  </div>
</body>
</html>
