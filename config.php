<?php
// config.php — نسخة مستقرة ومرتّبة

// إظهار/تسجيل الأخطاء أثناء التطوير
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
if (!is_dir(__DIR__ . '/logs')) { @mkdir(__DIR__ . '/logs', 0777, true); }
ini_set('error_log', __DIR__ . '/logs/php_errors.log');

// المنطقة الزمنية
date_default_timezone_set('Asia/Riyadh');

// تأكد أن $_ENV مصفوفة
if (!isset($_ENV) || !is_array($_ENV)) { $_ENV = []; }

/**
 * تحميل ملف .env (شكل KEY=VALUE) بشكل آمن
 */
(function () {
    $envFile = __DIR__ . '/.env';
    if (!is_file($envFile)) return;

    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;

        $name  = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));

        // إزالة علامات اقتباس إن وُجدت
        $value = trim($value, " \t\n\r\0\x0B\"'");

        if ($name === '') continue;

        // خزّن في البيئة وفي $_ENV (لا نستخدم $_env أبداً)
        // نعطي الأولوية لما هو موجود مسبقًا حتى لا نكسر بيئة الخادم
        if (getenv($name) === false) {
            putenv("$name=$value");
        }
        if (!array_key_exists($name, $_ENV)) {
            $_ENV[$name] = $value;
        }
    }
})();

/**
 * env()‎: اجلب قيمة من البيئة مع افتراضي
 */
if (!function_exists('env')) {
    function env(string $key, $default = null) {
        // تفضيل $_ENV ثم getenv
        if (array_key_exists($key, $_ENV)) return $_ENV[$key];
        $v = getenv($key);
        return ($v === false || $v === null) ? $default : $v;
    }
}

// إعدادات قاعدة البيانات
$DB_HOST = env('DB_HOST', '127.0.0.1');
$DB_PORT = (int)env('DB_PORT', 3306);
$DB_NAME = env('DB_NAME', 'modern_pharma');
$DB_USER = env('DB_USER', 'root');
$DB_PASS = env('DB_PASS', '');
$DB_CHAR = 'utf8mb4';

// بدء الجلسة
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * اتصال PDO موحّد (Singleton)
 */
if (!function_exists('db')) {
    function db(): PDO {
        static $pdo = null;
        if ($pdo instanceof PDO) return $pdo;

        $host = env('DB_HOST', '127.0.0.1');
        $port = (int) env('DB_PORT', 3306);
        $name = env('DB_NAME', 'modern_pharma');
        $user = env('DB_USER', 'root');
        $pass = env('DB_PASS', '');
        $charset = 'utf8mb4';

        $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false,
        ];

        // تعيين ترميز
        $pdo = new PDO($dsn, $user, $pass, $options);
        $pdo->exec("SET NAMES {$charset} COLLATE {$charset}_unicode_ci");
        return $pdo;
    }
}

/**
 * دالة مساعدة لفحص وجود عمود (محميّة ضد إعادة التعريف)
 */
if (!function_exists('col_exists')) {
    function col_exists(PDO $pdo, string $table, string $col): bool {
        $sql = "SELECT 1 
                FROM INFORMATION_SCHEMA.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND COLUMN_NAME = ?
                LIMIT 1";
        $st = $pdo->prepare($sql);
        $st->execute([$table, $col]);
        return (bool)$st->fetchColumn();
    }
}

// (اختياري) تأكد من وجود جدول users أساسي فقط عند الحاجة
if (!function_exists('ensure_min_schema')) {
    function ensure_min_schema(): void {
        $pdo = db();
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                username     VARCHAR(50)  NOT NULL UNIQUE,
                full_name    VARCHAR(100) NOT NULL,
                email        VARCHAR(100) DEFAULT NULL,
                password_hash VARCHAR(255) NOT NULL,
                role ENUM('admin','accountant','manager','user') NOT NULL DEFAULT 'user',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                last_login DATETIME DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ");

        // أنشئ مدير افتراضي إن ما فيه
        $c = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='admin'")->fetchColumn();
        if ($c === 0) {
            $st = $pdo->prepare("INSERT INTO users (username, full_name, email, password_hash, role, is_active)
                                 VALUES (?,?,?,?,?,1)");
            $st->execute([
                'admin',
                'System Administrator',
                env('ADMIN_EMAIL', 'admin@example.com'),
                password_hash('admin123', PASSWORD_DEFAULT),
                'admin'
            ]);
        }
    }
}

// إن رغبت بتفعيل إنشاء الحد الأدنى تلقائياً فاعل السطر التالي
// ensure_min_schema();
