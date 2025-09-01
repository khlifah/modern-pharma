<?php
// notifications.php — مركز الإشعارات (محسن: بحث/فلترة/ترقيم صفحات/CSRF)
require_once __DIR__ . '/auth.php';
if (!function_exists('db')) require_once __DIR__ . '/config.php';
require_once __DIR__ . '/notifications_lib.php';

$pdo = db();
ensure_notifications_schema($pdo);

// ===== CSRF =====
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];
function check_csrf() {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $t = $_POST['csrf'] ?? '';
    if (!$t || !hash_equals($_SESSION['csrf'] ?? '', $t)) {
      throw new Exception('رمز الأمان غير صالح. أعد المحاولة.');
    }
  }
}

// ===== فلاتر ومدخلات =====
$filter     = $_GET['f'] ?? 'all';           // all | unread
$onlyUnread = ($filter === 'unread');
$q          = trim((string)($_GET['q'] ?? '')); // بحث نصي (العنوان/النص/نوع الحدث)
$sev        = trim((string)($_GET['sev'] ?? '')); // info|success|warning|error|'' (الكل)
$page       = max(1, (int)($_GET['page'] ?? 1));
$limit      = 25;
$offset     = ($page - 1) * $limit;

$errors = [];
$ok     = null;

// ===== عمليات POST =====
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'read_one') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) { mark_notification_read($pdo, $id); $ok = "تم تعليم الإشعار #$id كمقروء."; }
    } elseif ($action === 'read_all') {
      $count = mark_all_notifications_read($pdo);
      $ok = "تم تعليم $count إشعار/إشعارات كمقروء.";
    }
  }
} catch (Throwable $e) {
  $errors[] = 'خطأ: ' . $e->getMessage();
}

// ===== بناء شروط الاستعلام (فلترة + بحث) =====
$where = [];
$args  = [];

if ($onlyUnread) {
  $where[] = "read_at IS NULL";
}
if ($q !== '') {
  $where[] = "(title LIKE ? OR message LIKE ? OR event_type LIKE ?)";
  $args[] = "%$q%";
  $args[] = "%$q%";
  $args[] = "%$q%";
}
if ($sev !== '' && in_array($sev, ['info','success','warning','error'], true)) {
  $where[] = "severity = ?";
  $args[] = $sev;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ===== إجمالي غير مقروء (للعداد) =====
try {
  $unread_count = (int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE read_at IS NULL")->fetchColumn();
} catch (Throwable $e) {
  $unread_count = 0;
}

// ===== عدد النتائج (لصفحات الترقيم) =====
$sqlCount = "SELECT COUNT(*) FROM notifications $whereSql";
$stc = $pdo->prepare($sqlCount);
$stc->execute($args);
$totalRows = (int)$stc->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));
if ($page > $totalPages) { $page = $totalPages; $offset = ($page-1)*$limit; }

// ===== جلب الصفحة الحالية =====
$sqlList = "SELECT id,event_type,title,message,severity,actor_user_id,target_table,target_id,created_at,read_at
            FROM notifications
            $whereSql
            ORDER BY id DESC
            LIMIT $limit OFFSET $offset";
$st = $pdo->prepare($sqlList);
$st->execute($args);
$list = $st->fetchAll();

$title = 'الإشعارات';

// دالة مساعدة لبناء روابط الصفحات مع الحفاظ على الفلاتر
function build_query(array $params): string {
  $base = $_GET;
  foreach ($params as $k => $v) {
    if ($v === null) unset($base[$k]); else $base[$k] = $v;
  }
  return http_build_query($base);
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?> - موردن</title>
<style>
:root{
  --primary:#4f46e5;--primary-light:#eef2ff;--primary-dark:#4338ca;
  --border:#e5e7eb;--panel:#fff;--bg:#f9fafb;--text:#111827;--muted:#6b7280;
  --success-bg:#ecfdf5;--success-bd:#bbf7d0;--success-tx:#065f46;
  --error-bg:#fef2f2;--error-bd:#fecaca;--error-tx:#b91c1c
}
*{box-sizing:border-box}body{margin:0;font-family:Tahoma,Arial,sans-serif;background:var(--bg);color:var(--text)}
a{color:var(--primary);text-decoration:none}
.header{display:flex;justify-content:space-between;align-items:center;background:#fff;border-bottom:1px solid var(--border);padding:12px 16px}
.wrap{max-width:1100px;margin:18px auto;padding:0 12px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:16px}
.toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}
select,input[type=text],button{padding:10px;border:1px solid var(--border);border-radius:10px}
.btn{background:var(--primary);color:#fff;border:0;cursor:pointer}
.note{background:#f9fafb;border:1px dashed var(--border);padding:10px;border-radius:8px;margin:6px 0}
.note.ok{background:var(--success-bg);border-color:var(--success-bd);color:var(--success-tx)}
.note.err{background:var(--error-bg);border-color:var(--error-bd);color:var(--error-tx)}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;font-weight:700}
.b-info{background:var(--primary-light);color:var(--primary-dark)}
.b-success{background:#ecfdf5;color:#059669}
.b-warning{background:#fff7ed;color:#c2410c}
.b-error{background:#fee2e2;color:#b91c1c}
.item{display:grid;grid-template-columns:1fr auto;gap:10px;border-bottom:1px solid var(--border);padding:12px 0}
.title{font-weight:700}
.msg{color:#374151;white-space:pre-line}
.meta{color:var(--muted);font-size:13px;margin-top:6px}
.actions form{display:inline}
.empty{padding:30px;text-align:center;color:var(--muted)}
.counter{display:inline-flex;gap:6px;align-items:center}
.counter .dot{display:inline-block;width:8px;height:8px;border-radius:50%;background:#ef4444}
.pager{display:flex;gap:8px;align-items:center;justify-content:center;margin-top:12px;flex-wrap:wrap}
.pager a,.pager span{padding:8px 12px;border:1px solid var(--border);border-radius:8px;background:#fff}
.pager .current{background:var(--primary);color:#fff;border-color:var(--primary)}
.filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
</style>
</head>
<body>
<div class="header">
  <div class="counter">
    <span><?= htmlspecialchars($title,ENT_QUOTES,'UTF-8') ?></span>
    <?php if ($unread_count>0): ?>
      <span class="dot" title="غير مقروء"></span><span>غير مقروء: <?= $unread_count ?></span>
    <?php else: ?>
      <span style="color:#6b7280">لا إشعارات جديدة</span>
    <?php endif; ?>
  </div>
  <nav><a href="./dashboard.php">الرجوع</a></nav>
</div>

<div class="wrap">
  <div class="card">
    <?php foreach($errors as $e): ?><div class="note err"><?= htmlspecialchars($e,ENT_QUOTES,'UTF-8') ?></div><?php endforeach; ?>
    <?php if ($ok): ?><div class="note ok"><?= htmlspecialchars($ok,ENT_QUOTES,'UTF-8') ?></div><?php endif; ?>

    <form class="toolbar" method="get" action="">
      <div class="filters">
        <select name="f">
          <option value="all"   <?= $filter==='all'?'selected':'' ?>>الكل</option>
          <option value="unread"<?= $filter==='unread'?'selected':'' ?>>غير مقروء</option>
        </select>
        <select name="sev">
          <option value="">كل الشدّات</option>
          <option value="info"    <?= $sev==='info'?'selected':'' ?>>info</option>
          <option value="success" <?= $sev==='success'?'selected':'' ?>>success</option>
          <option value="warning" <?= $sev==='warning'?'selected':'' ?>>warning</option>
          <option value="error"   <?= $sev==='error'?'selected':'' ?>>error</option>
        </select>
        <input type="text" name="q" placeholder="بحث في العنوان/النص/نوع الحدث" value="<?= htmlspecialchars($q,ENT_QUOTES,'UTF-8') ?>">
        <button class="btn" type="submit">تطبيق</button>
        <a href="?">إعادة الضبط</a>
      </div>
    </form>

    <form method="post" onsubmit="return confirm('تأكيد تعليم جميع الإشعارات كمقروء؟');" style="margin-bottom:10px">
      <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF,ENT_QUOTES,'UTF-8') ?>">
      <input type="hidden" name="action" value="read_all">
      <button class="btn">تعليم الكل كمقروء</button>
    </form>

    <?php if(!$list): ?>
      <div class="empty">لا توجد إشعارات لعرضها.</div>
    <?php else: foreach($list as $r): ?>
      <div class="item">
        <div>
          <div class="title">
            <?= htmlspecialchars($r['title'],ENT_QUOTES,'UTF-8') ?>
            <?php
              $sevName = strtolower($r['severity'] ?? 'info');
              $sevClass = ['success'=>'b-success','warning'=>'b-warning','error'=>'b-error'][$sevName] ?? 'b-info';
            ?>
            <span class="badge <?= $sevClass ?>"><?= htmlspecialchars($sevName,ENT_QUOTES,'UTF-8') ?></span>
            <?php if (is_null($r['read_at'])): ?>
              <span class="badge b-info">جديد</span>
            <?php endif; ?>
          </div>
          <div class="msg"><?= nl2br(htmlspecialchars($r['message'],ENT_QUOTES,'UTF-8')) ?></div>
          <div class="meta">
            <?= htmlspecialchars($r['event_type'],ENT_QUOTES,'UTF-8') ?> • <?= htmlspecialchars($r['created_at'],ENT_QUOTES,'UTF-8') ?>
            <?php if(!empty($r['target_table']) && !empty($r['target_id'])): ?>
              • المرجع: <?= htmlspecialchars($r['target_table'],ENT_QUOTES,'UTF-8') ?> #<?= (int)$r['target_id'] ?>
            <?php endif; ?>
            <?php if(!empty($r['actor_user_id'])): ?>
              • المستخدم: #<?= (int)$r['actor_user_id'] ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="actions">
          <?php if (is_null($r['read_at'])): ?>
            <form method="post">
              <input type="hidden" name="csrf" value="<?= htmlspecialchars($CSRF,ENT_QUOTES,'UTF-8') ?>">
              <input type="hidden" name="action" value="read_one">
              <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
              <button class="btn">تعليم كمقروء</button>
            </form>
          <?php else: ?>
            <span class="meta">مقروء: <?= htmlspecialchars($r['read_at'],ENT_QUOTES,'UTF-8') ?></span>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; endif; ?>

    <?php if ($totalPages > 1): ?>
      <div class="pager">
        <?php if ($page > 1): ?>
          <a href="?<?= build_query(['page'=>1]) ?>">&laquo; الأول</a>
          <a href="?<?= build_query(['page'=>$page-1]) ?>">&lsaquo; السابق</a>
        <?php endif; ?>
        <span class="current"><?= $page ?> / <?= $totalPages ?></span>
        <?php if ($page < $totalPages): ?>
          <a href="?<?= build_query(['page'=>$page+1]) ?>">التالي &rsaquo;</a>
          <a href="?<?= build_query(['page'=>$totalPages]) ?>">الأخير &raquo;</a>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
