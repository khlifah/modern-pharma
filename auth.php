<?php
// auth.php
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}
require_once __DIR__ . '/config.php'; // هذا يعرّف db()

if (empty($_SESSION['user_id'])) {
  $_SESSION['flash'] = ['type'=>'error','msg'=>'يرجى تسجيل الدخول أولاً.'];
  header('Location: ./index.php');
  exit;
}
