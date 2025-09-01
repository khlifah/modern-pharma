<?php
require_once __DIR__ . '/auth.php';
if(!function_exists('db')) require_once __DIR__ . '/config.php';
$pdo = db();

$title = 'أرصدة الأصناف';

// فلترة البيانات
$search = $_GET['search'] ?? '';
$category = $_GET['category'] ?? '';
$low_stock = isset($_GET['low_stock']);

// التحقق من وجود جدول الفئات وإنشاؤه إذا لم يكن موجوداً
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        description TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {
    // تجاهل الخطأ إذا كان الجدول موجوداً بالفعل
}

// جلب البيانات الأساسية
$sql = "SELECT p.id, p.name, p.quantity, p.min_quantity, p.cost_price, p.sale_price,
        'غير محدد' as category_name,
        (p.quantity * p.cost_price) as total_value
        FROM products p 
        WHERE 1=1";

$params = [];

// تطبيق الفلاتر
if ($search) {
    $sql .= " AND p.name LIKE ?";
    $params[] = "%$search%";
}

if ($low_stock) {
    $sql .= " AND p.quantity <= p.min_quantity";
}

$sql .= " ORDER BY p.name";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

// جلب قائمة الفئات (فارغة حالياً)
$categories = [];

// حساب الإحصائيات
$total_items = count($products);
$total_value = array_sum(array_column($products, 'total_value'));
$low_stock_count = 0;
$out_of_stock_count = 0;

foreach ($products as $product) {
    if ($product['quantity'] <= 0) {
        $out_of_stock_count++;
    } elseif ($product['quantity'] <= $product['min_quantity']) {
        $low_stock_count++;
    }
}
?>
<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= $title ?> - موردن</title>
    <style>
        body{margin:0;font-family:Tahoma;background:#f5f8fc}
        .header{display:flex;justify-content:space-between;align-items:center;background:#0c2140;color:#fff;padding:12px}
        a{color:#64ffda;text-decoration:none}
        .wrap{padding:18px;display:grid;gap:16px}
        .card{background:#fff;border:1px solid #dfe7f5;border-radius:12px;padding:16px}
        .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:16px;margin-bottom:20px}
        .stat{background:#f8fafc;padding:16px;border-radius:8px;text-align:center}
        .stat-value{font-size:20px;font-weight:700;color:#0c2140}
        .stat-label{color:#6b7280;font-size:13px;margin-top:4px}
        .filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px}
        label{display:block;margin:8px 0 4px}
        input,select{width:100%;padding:10px;border:1px solid #cfd9ec;border-radius:10px}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th,td{border-bottom:1px solid #e7eaf3;padding:8px;text-align:right;font-size:13px}
        th{background:#f8fafc;font-weight:600}
        .btn{border:0;border-radius:10px;padding:9px 12px;background:#13c2b3;color:#062427;font-weight:700;cursor:pointer}
        .low-stock{background:#fef3c7;color:#92400e}
        .out-of-stock{background:#fee2e2;color:#991b1b}
        .normal-stock{background:#d1fae5;color:#065f46}
        .stock-badge{padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600}
        .value{color:#059669;font-weight:600}
        .checkbox-container{display:flex;align-items:center;gap:8px}
        .checkbox-container input[type="checkbox"]{width:auto}
    </style>
</head>
<body>
<div class="header">
    <div><?= $title ?></div>
    <nav><a href="./dashboard.php">الرجوع</a></nav>
</div>
<div class="wrap">
    <div class="stats">
        <div class="stat">
            <div class="stat-value"><?= number_format($total_items, 0) ?></div>
            <div class="stat-label">إجمالي الأصناف</div>
        </div>
        <div class="stat">
            <div class="stat-value value"><?= number_format($total_value, 2) ?></div>
            <div class="stat-label">إجمالي قيمة المخزون</div>
        </div>
        <div class="stat">
            <div class="stat-value" style="color:#dc2626"><?= $low_stock_count ?></div>
            <div class="stat-label">أصناف منخفضة المخزون</div>
        </div>
        <div class="stat">
            <div class="stat-value" style="color:#991b1b"><?= $out_of_stock_count ?></div>
            <div class="stat-label">أصناف نفدت من المخزون</div>
        </div>
    </div>

    <div class="card">
        <h3>فلترة أرصدة الأصناف</h3>
        <form method="get" class="filters">
            <div>
                <label>البحث في الأصناف</label>
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="اسم الصنف...">
            </div>
            <div style="display:none">
                <label>الفئة</label>
                <select name="category">
                    <option value="">جميع الفئات</option>
                </select>
            </div>
            <div class="checkbox-container">
                <input type="checkbox" id="low_stock" name="low_stock" <?= $low_stock ? 'checked' : '' ?>>
                <label for="low_stock">المخزون المنخفض فقط</label>
            </div>
            <div style="display:flex;align-items:end">
                <button type="submit" class="btn">بحث</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>تفاصيل أرصدة الأصناف</h3>
        <table>
            <thead>
                <tr>
                    <th>اسم الصنف</th>
                    <th>الفئة</th>
                    <th>الكمية الحالية</th>
                    <th>الحد الأدنى</th>
                    <th>حالة المخزون</th>
                    <th>تكلفة الوحدة</th>
                    <th>سعر البيع</th>
                    <th>إجمالي القيمة</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#6b7280">لا توجد أصناف تطابق معايير البحث</td></tr>
                <?php else: ?>
                    <?php foreach ($products as $product): ?>
                        <?php
                        $stock_status = 'normal-stock';
                        $stock_text = 'مخزون طبيعي';
                        
                        if ($product['quantity'] <= 0) {
                            $stock_status = 'out-of-stock';
                            $stock_text = 'نفد المخزون';
                        } elseif ($product['quantity'] <= $product['min_quantity']) {
                            $stock_status = 'low-stock';
                            $stock_text = 'مخزون منخفض';
                        }
                        ?>
                        <tr>
                            <td><?= htmlspecialchars($product['name']) ?></td>
                            <td><?= htmlspecialchars($product['category_name']) ?></td>
                            <td><?= number_format($product['quantity'], 0) ?></td>
                            <td><?= number_format($product['min_quantity'], 0) ?></td>
                            <td>
                                <span class="stock-badge <?= $stock_status ?>"><?= $stock_text ?></span>
                            </td>
                            <td><?= number_format($product['cost_price'], 2) ?></td>
                            <td><?= number_format($product['sale_price'], 2) ?></td>
                            <td class="value"><?= number_format($product['total_value'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
