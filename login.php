<?php
// login.php — معالجة تسجيل الدخول عبر PDO
require_once __DIR__ . '/config.php';

function fail(string $msg): void {
  $_SESSION['flash'] = ['type' => 'error', 'msg' => $msg];
  header('Location: ./index.php');
  exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  fail('طريقة الوصول غير صحيحة.');
}

$username = trim($_POST['username'] ?? '');
$password = (string)($_POST['password'] ?? '');

if ($username === '' || $password === '') {
  fail('يرجى إدخال اسم المستخدم وكلمة المرور.');
}

$stmt = db()->prepare("SELECT id, username, password_hash, role FROM users WHERE username = ?");
$stmt->execute([$username]);
$user = $stmt->fetch();

if (!$user || !password_verify($password, $user['password_hash'])) {
  fail('بيانات الدخول غير صحيحة.');
}

// نجاح
$_SESSION['user_id']   = (int)$user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['role']      = $user['role'];
// بما إنه ما عندنا full_name في القاعدة، نخليها مساوية لاسم المستخدم
$_SESSION['full_name'] = $user['username'];

header('Location: ./dashboard.php');
exit;
