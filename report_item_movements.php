<?php
require_once __DIR__ . '/auth.php';
if(!function_exists('db')) require_once __DIR__ . '/config.php';
$pdo = db();

$title = 'حركة الأصناف';

// فلترة البيانات
$product_id = $_GET['product_id'] ?? '';
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');

// جلب قائمة المنتجات
$products = $pdo->query("SELECT id, name FROM products ORDER BY name")->fetchAll();

// جمع حركات المخزون من جداول مختلفة
$movements = [];

// حركات المشتريات (دخول)
$sql = "SELECT 'مشتريات' as type, pi.invoice_date as doc_date, CONCAT('فاتورة مشتريات #', pi.id) as doc_number,
        p.name as product_name, pit.quantity, pit.cost_price as unit_cost, 'in' as movement_type,
        s.name as supplier_name
        FROM purchase_invoices pi 
        JOIN purchase_items pit ON pit.invoice_id = pi.id
        JOIN products p ON p.id = pit.product_id
        LEFT JOIN suppliers s ON s.id = pi.supplier_id
        WHERE pi.invoice_date BETWEEN ? AND ?";

$params = [$from_date, $to_date];
if ($product_id) {
    $sql .= " AND pit.product_id = ?";
    $params[] = $product_id;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movements = array_merge($movements, $stmt->fetchAll());

// حركات المبيعات (خروج)
$sql = "SELECT 'مبيعات' as type, si.invoice_date as doc_date, CONCAT('فاتورة مبيعات #', si.id) as doc_number,
        p.name as product_name, sit.quantity, sit.sale_price as unit_cost, 'out' as movement_type,
        '' as supplier_name
        FROM sales_invoices si 
        JOIN sales_items sit ON sit.invoice_id = si.id
        JOIN products p ON p.id = sit.product_id
        WHERE si.invoice_date BETWEEN ? AND ?";

$params = [$from_date, $to_date];
if ($product_id) {
    $sql .= " AND sit.product_id = ?";
    $params[] = $product_id;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movements = array_merge($movements, $stmt->fetchAll());

// أوامر الصرف (خروج)
$sql = "SELECT 'صرف مخزون' as type, si.doc_date, CONCAT('أمر صرف #', si.id) as doc_number,
        p.name as product_name, si.quantity, 0 as unit_cost, 'out' as movement_type,
        si.reason as supplier_name
        FROM stock_issues si 
        JOIN products p ON p.id = si.product_id
        WHERE si.doc_date BETWEEN ? AND ?";

$params = [$from_date, $to_date];
if ($product_id) {
    $sql .= " AND si.product_id = ?";
    $params[] = $product_id;
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$movements = array_merge($movements, $stmt->fetchAll());

// ترتيب النتائج حسب التاريخ
usort($movements, function($a, $b) {
    return strtotime($b['doc_date']) - strtotime($a['doc_date']);
});

// حساب الإحصائيات
$total_in = 0;
$total_out = 0;
foreach ($movements as $mov) {
    if ($mov['movement_type'] === 'in') {
        $total_in += $mov['quantity'];
    } else {
        $total_out += $mov['quantity'];
    }
}

// الرصيد الحالي للمنتج المحدد
$current_balance = null;
if ($product_id) {
    $stmt = $pdo->prepare("SELECT quantity FROM products WHERE id = ?");
    $stmt->execute([$product_id]);
    $current_balance = $stmt->fetchColumn();
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
        .in{color:#059669;font-weight:600} 
        .out{color:#dc2626;font-weight:600}
        .badge{padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600}
        .badge-مشتريات{background:#dbeafe;color:#1e40af}
        .badge-مبيعات{background:#dcfce7;color:#166534}
        .badge-صرف{background:#fee2e2;color:#991b1b}
    </style>
</head>
<body>
<div class="header">
    <div><?= $title ?></div>
    <nav><a href="./dashboard.php">الرجوع</a></nav>
</div>
<div class="wrap">
    <?php if (!empty($movements)): ?>
    <div class="stats">
        <div class="stat">
            <div class="stat-value in">+<?= number_format($total_in, 0) ?></div>
            <div class="stat-label">إجمالي الداخل</div>
        </div>
        <div class="stat">
            <div class="stat-value out">-<?= number_format($total_out, 0) ?></div>
            <div class="stat-label">إجمالي الخارج</div>
        </div>
        <div class="stat">
            <div class="stat-value"><?= number_format($total_in - $total_out, 0) ?></div>
            <div class="stat-label">صافي الحركة</div>
        </div>
        <?php if ($current_balance !== null): ?>
        <div class="stat">
            <div class="stat-value" style="color:#0c2140"><?= number_format($current_balance, 0) ?></div>
            <div class="stat-label">الرصيد الحالي</div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card">
        <h3>فلترة حركة الأصناف</h3>
        <form method="get" class="filters">
            <div>
                <label>الصنف</label>
                <select name="product_id">
                    <option value="">جميع الأصناف</option>
                    <?php foreach ($products as $product): ?>
                        <option value="<?= $product['id'] ?>" <?= $product_id == $product['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($product['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label>من تاريخ</label>
                <input type="date" name="from_date" value="<?= htmlspecialchars($from_date) ?>">
            </div>
            <div>
                <label>إلى تاريخ</label>
                <input type="date" name="to_date" value="<?= htmlspecialchars($to_date) ?>">
            </div>
            <div style="display:flex;align-items:end">
                <button type="submit" class="btn">بحث</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>تفاصيل حركة الأصناف</h3>
        <table>
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>نوع المستند</th>
                    <th>رقم المستند</th>
                    <th>الصنف</th>
                    <th>نوع الحركة</th>
                    <th>الكمية</th>
                    <th>السعر/التكلفة</th>
                    <th>ملاحظات</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movements)): ?>
                    <tr><td colspan="8" style="text-align:center;color:#6b7280">لا توجد حركات في الفترة المحددة</td></tr>
                <?php else: ?>
                    <?php foreach ($movements as $mov): ?>
                        <tr>
                            <td><?= $mov['doc_date'] ?></td>
                            <td>
                                <span class="badge badge-<?= str_replace(' ', '', $mov['type']) ?>"><?= $mov['type'] ?></span>
                            </td>
                            <td><?= htmlspecialchars($mov['doc_number']) ?></td>
                            <td><?= htmlspecialchars($mov['product_name']) ?></td>
                            <td class="<?= $mov['movement_type'] ?>">
                                <?= $mov['movement_type'] === 'in' ? '⬆️ دخول' : '⬇️ خروج' ?>
                            </td>
                            <td class="<?= $mov['movement_type'] ?>"><?= number_format($mov['quantity'], 0) ?></td>
                            <td><?= $mov['unit_cost'] > 0 ? number_format($mov['unit_cost'], 2) : '-' ?></td>
                            <td><?= htmlspecialchars($mov['supplier_name'] ?: '-') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>

