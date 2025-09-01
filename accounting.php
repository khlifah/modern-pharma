<?php
// accounting.php — دوال المحاسبة (مرنة مع اختلافات المخطط)
// ملاحظة: يعتمد على config.php للحصول على db() وقراءة file.env
if (!function_exists('db')) require_once __DIR__ . '/config.php';

/**
 * فحص وجود عمود في جدول (آمن وصحيح)
 */
if (!function_exists('col_exists')) {
  function col_exists(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("
      SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME   = ?
        AND COLUMN_NAME  = ?
      LIMIT 1
    ");
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  }
}

/**
 * إنشاء جدول الحسابات إن لم يوجد + ترقية عمود code إن لزم
 */
function ensure_accounts(PDO $pdo): void {
  $pdo->exec("
    CREATE TABLE IF NOT EXISTS accounts(
      id   INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL UNIQUE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
  if (!col_exists($pdo, 'accounts', 'code')) {
    try { $pdo->exec("ALTER TABLE accounts ADD COLUMN code VARCHAR(50) NULL UNIQUE"); } catch (Throwable $e) {}
  }
}

/**
 * توليد كود فريد (اختياري إذا كان عندك عمود code)
 */
function gen_unique_code(PDO $pdo, string $table, string $prefix, ?string $date = null): string {
  if ($date) {
    $ymd = preg_replace('/[^0-9]/', '', $date);
    if (strlen($ymd) !== 8) $ymd = date('Ymd');
    for ($i=1; $i<=9999; $i++) {
      $code = sprintf("%s-%s-%04d", $prefix, $ymd, $i);
      $st = $pdo->prepare("SELECT 1 FROM `$table` WHERE code=? LIMIT 1");
      try { $st->execute([$code]); if (!$st->fetchColumn()) return $code; }
      catch (Throwable $e) { return $code; }
    }
    return sprintf("%s-%s-%d", $prefix, $ymd, random_int(10000,99999));
  } else {
    for ($i=1; $i<=999999; $i++) {
      $code = sprintf("%s-%06d", $prefix, $i);
      $st = $pdo->prepare("SELECT 1 FROM `$table` WHERE code=? LIMIT 1");
      try { $st->execute([$code]); if (!$st->fetchColumn()) return $code; }
      catch (Throwable $e) { return $code; }
    }
    return sprintf("%s-%d", $prefix, random_int(100000,999999));
  }
}

/**
 * الحصول/الإنشاء لحساب محاسبي بحسب الاسم — يُرجع account_id
 */
function get_account_id(PDO $pdo, string $name): int {
  ensure_accounts($pdo);
  $sel = $pdo->prepare("SELECT id FROM accounts WHERE name=? LIMIT 1");
  $sel->execute([$name]);
  $id = $sel->fetchColumn();
  if ($id) return (int)$id;

  $code = col_exists($pdo,'accounts','code') ? gen_unique_code($pdo,'accounts','ACC',date('Y-m-d')) : null;
  if ($code) {
    $ins = $pdo->prepare("INSERT INTO accounts(name,code) VALUES(?,?)");
    $ins->execute([$name,$code]);
  } else {
    $ins = $pdo->prepare("INSERT INTO accounts(name) VALUES(?)");
    $ins->execute([$name]);
  }
  return (int)$pdo->lastInsertId();
}

/**
 * ترقية/إنشاء جداول اليومية حسب الحاجة
 */
function ensure_journal(PDO $pdo): void {
  // إنشاء جداول أساسية إن لم توجد
  $pdo->exec("CREATE TABLE IF NOT EXISTS journal_entries(
    id INT AUTO_INCREMENT PRIMARY KEY
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  $pdo->exec("CREATE TABLE IF NOT EXISTS journal_details(
    id INT AUTO_INCREMENT PRIMARY KEY
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

  // أعمدة journal_entries
  if (!col_exists($pdo,'journal_entries','doc_type'))    $pdo->exec("ALTER TABLE journal_entries ADD COLUMN doc_type VARCHAR(50) NULL");
  if (!col_exists($pdo,'journal_entries','doc_id'))      $pdo->exec("ALTER TABLE journal_entries ADD COLUMN doc_id INT NULL");
  if (!col_exists($pdo,'journal_entries','doc_date'))    $pdo->exec("ALTER TABLE journal_entries ADD COLUMN doc_date DATE NULL");
  if (!col_exists($pdo,'journal_entries','description')) $pdo->exec("ALTER TABLE journal_entries ADD COLUMN description VARCHAR(255) NULL");
  if (!col_exists($pdo,'journal_entries','created_at'))  $pdo->exec("ALTER TABLE journal_entries ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
  if (!col_exists($pdo,'journal_entries','code')) {
    try { $pdo->exec("ALTER TABLE journal_entries ADD COLUMN code VARCHAR(50) NULL UNIQUE"); } catch (Throwable $e) {}
  }

  // أعمدة journal_details
  if (!col_exists($pdo,'journal_details','entry_id') && !col_exists($pdo,'journal_details','journal_id')) {
    $pdo->exec("ALTER TABLE journal_details ADD COLUMN entry_id INT NULL");
  }
  if (!col_exists($pdo,'journal_details','account'))    $pdo->exec("ALTER TABLE journal_details ADD COLUMN account VARCHAR(120) NULL");
  if (!col_exists($pdo,'journal_details','account_id')) $pdo->exec("ALTER TABLE journal_details ADD COLUMN account_id INT NULL");
  if (!col_exists($pdo,'journal_details','debit'))      $pdo->exec("ALTER TABLE journal_details ADD COLUMN debit  DECIMAL(14,2) NOT NULL DEFAULT 0.00");
  if (!col_exists($pdo,'journal_details','credit'))     $pdo->exec("ALTER TABLE journal_details ADD COLUMN credit DECIMAL(14,2) NOT NULL DEFAULT 0.00");
}

/**
 * معرفة عمود الربط الفعلي في journal_details (entry_id أو journal_id)
 */
function details_fk_col(PDO $pdo): ?string {
  $sql = "SELECT COLUMN_NAME
          FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = DATABASE()
            AND TABLE_NAME = 'journal_details'
            AND REFERENCED_TABLE_NAME = 'journal_entries'
            AND REFERENCED_COLUMN_NAME = 'id'
          LIMIT 1";
  $st = $pdo->query($sql);
  $col = $st ? $st->fetchColumn() : false;
  if ($col) return (string)$col;
  if (col_exists($pdo, 'journal_details', 'entry_id'))   return 'entry_id';
  if (col_exists($pdo, 'journal_details', 'journal_id')) return 'journal_id';
  return null;
}

/**
 * ترحيل قيد يومية متوازن
 * $lines: مصفوفة من أسطر القيد [ ['account'=>'اسم', 'debit'=>..., 'credit'=>...], ... ]
 * تُرجع رقم القيد (journal_entries.id)
 */
function post_journal(PDO $pdo,string $doc_type,int $doc_id,string $doc_date,string $desc,array $lines): int {
  // توازن القيد
  $dr=0.0; $cr=0.0;
  foreach($lines as $l){ $dr+=round((float)($l['debit']??0),2); $cr+=round((float)($l['credit']??0),2); }
  if (abs($dr-$cr)>0.005) throw new Exception("القيد غير متوازن: مدين=$dr ، دائن=$cr");

  // ابدأ TX فقط إذا ما فيه TX
  $started = false;
  if (!$pdo->inTransaction()) { $pdo->beginTransaction(); $started = true; }

  try {
    // .. إدراج رأس اليومية + التفاصيل (كما عندك) ..
    // مثال مختصر:
    $st = $pdo->prepare("INSERT INTO journal_entries(doc_type,doc_id,doc_date,description) VALUES (?,?,?,?)");
    $st->execute([$doc_type, $doc_id?:null, $doc_date, $desc?:null]);
    $eid = (int)$pdo->lastInsertId();

    // عبّي journal_id (وأيضًا entry_id إن موجود) في journal_details
    $hasEntryId   = col_exists($pdo,'journal_details','entry_id');
    $hasJournalId = col_exists($pdo,'journal_details','journal_id');
    $hasAccId     = col_exists($pdo,'journal_details','account_id');
    $hasAcc       = col_exists($pdo,'journal_details','account');
    if (!$hasAccId && !$hasAcc) { $pdo->exec("ALTER TABLE journal_details ADD COLUMN account VARCHAR(120) NULL"); $hasAcc = true; }

    $cols = [];
    if ($hasEntryId)   $cols[]='entry_id';
    if ($hasJournalId) $cols[]='journal_id';
    if ($hasAccId && $hasAcc)      $cols = array_merge($cols,['account_id','account','debit','credit']);
    elseif ($hasAccId)             $cols = array_merge($cols,['account_id','debit','credit']);
    else                           $cols = array_merge($cols,['account','debit','credit']);

    $ph  = '('.implode(',', array_fill(0,count($cols),'?')).')';
    $sql = "INSERT INTO journal_details(".implode(',',$cols).") VALUES $ph";
    $ins = $pdo->prepare($sql);

    foreach($lines as $l){
      $row=[];
      if ($hasEntryId)   $row[]=$eid;
      if ($hasJournalId) $row[]=$eid;

      if ($hasAccId){
        $aid = get_account_id($pdo, (string)$l['account']);
        if ($hasAcc) { $row[]=$aid; $row[]=(string)$l['account']; }
        else         { $row[]=$aid; }
      } else {
        $row[] = (string)$l['account'];
      }
      $row[] = round((float)($l['debit'] ?? 0),2);
      $row[] = round((float)($l['credit']?? 0),2);
      $ins->execute($row);
    }

    if ($started && $pdo->inTransaction()) $pdo->commit();
    return $eid;

  } catch(Throwable $e){
    if ($started && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
