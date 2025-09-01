<?php
require_once __DIR__ . '/auth.php';
if(!function_exists('db')) require_once __DIR__ . '/config.php';
$pdo = db();

// إنشاء جدول حركات المخزون
$pdo->exec("CREATE TABLE IF NOT EXISTS inventory_movements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  doc_date DATE NOT NULL,
  doc_type ENUM('purchase','sale','purchase_return','sales_return','stock_issue','free_sample','adjustment') NOT NULL,
  doc_id INT NOT NULL,
  doc_number VARCHAR(50) NOT NULL,
  product_id INT NOT NULL,
  product_name VARCHAR(255) NOT NULL,
  movement_type ENUM('in','out') NOT NULL,
  quantity DECIMAL(10,3) NOT NULL,
  unit_cost DECIMAL(10,2) NULL,
  unit_price DECIMAL(10,2) NULL,
  expiry_date DATE NULL,
  supplier_customer VARCHAR(255) NULL,
  department VARCHAR(100) NULL,
  notes TEXT NULL,
  approved_by VARCHAR(100) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_product_date (product_id, doc_date),
  INDEX idx_doc_type (doc_type, doc_id),
  INDEX idx_date (doc_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// دالة لتسجيل حركة مخزون
function record_inventory_movement($pdo, $data) {
    $stmt = $pdo->prepare("INSERT INTO inventory_movements 
        (doc_date, doc_type, doc_id, doc_number, product_id, product_name, movement_type, 
         quantity, unit_cost, unit_price, expiry_date, supplier_customer, department, notes, approved_by) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    
    return $stmt->execute([
        $data['doc_date'],
        $data['doc_type'], 
        $data['doc_id'],
        $data['doc_number'],
        $data['product_id'],
        $data['product_name'],
        $data['movement_type'],
        $data['quantity'],
        $data['unit_cost'] ?? null,
        $data['unit_price'] ?? null,
        $data['expiry_date'] ?? null,
        $data['supplier_customer'] ?? null,
        $data['department'] ?? null,
        $data['notes'] ?? null,
        $data['approved_by'] ?? $_SESSION['username'] ?? 'النظام'
    ]);
}

$title = 'حركات المخزون';
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
        .filters{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:12px;margin-bottom:16px}
        label{display:block;margin:8px 0 4px}
        input,select{width:100%;padding:10px;border:1px solid #cfd9ec;border-radius:10px}
        table{width:100%;border-collapse:collapse;margin-top:10px}
        th,td{border-bottom:1px solid #e7eaf3;padding:8px;text-align:right;font-size:13px}
        th{background:#f8fafc;font-weight:600}
        .btn{border:0;border-radius:10px;padding:9px 12px;background:#13c2b3;color:#062427;font-weight:700;cursor:pointer}
        .in{color:#059669} .out{color:#dc2626}
        .badge{padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600}
        .badge-purchase{background:#dbeafe;color:#1e40af}
        .badge-sale{background:#dcfce7;color:#166534}
        .badge-return{background:#fef3c7;color:#92400e}
        .badge-issue{background:#fee2e2;color:#991b1b}
        .badge-sample{background:#f3e8ff;color:#7c2d12}
        .badge-adjustment{background:#f1f5f9;color:#475569}
    </style>
</head>
<body>
<div class="header">
    <div><?= $title ?></div>
    <nav><a href="./dashboard.php">الرجوع</a></nav>
</div>
<div class="wrap">
    <div class="card">
        <h3>فلترة الحركات</h3>
        <form method="get" class="filters">
            <div>
                <label>من تاريخ</label>
                <input type="date" name="from_date" value="<?= htmlspecialchars($_GET['from_date'] ?? date('Y-m-01')) ?>">
            </div>
            <div>
                <label>إلى تاريخ</label>
                <input type="date" name="to_date" value="<?= htmlspecialchars($_GET['to_date'] ?? date('Y-m-d')) ?>">
            </div>
            <div>
                <label>نوع الحركة</label>
                <select name="doc_type">
                    <option value="">جميع الأنواع</option>
                    <option value="purchase" <?= ($_GET['doc_type'] ?? '') === 'purchase' ? 'selected' : '' ?>>مشتريات</option>
                    <option value="sale" <?= ($_GET['doc_type'] ?? '') === 'sale' ? 'selected' : '' ?>>مبيعات</option>
                    <option value="purchase_return" <?= ($_GET['doc_type'] ?? '') === 'purchase_return' ? 'selected' : '' ?>>مردود مشتريات</option>
                    <option value="sales_return" <?= ($_GET['doc_type'] ?? '') === 'sales_return' ? 'selected' : '' ?>>مردود مبيعات</option>
                    <option value="stock_issue" <?= ($_GET['doc_type'] ?? '') === 'stock_issue' ? 'selected' : '' ?>>صرف مخزون</option>
                    <option value="free_sample" <?= ($_GET['doc_type'] ?? '') === 'free_sample' ? 'selected' : '' ?>>عينات مجانية</option>
                    <option value="adjustment" <?= ($_GET['doc_type'] ?? '') === 'adjustment' ? 'selected' : '' ?>>تسوية مخزون</option>
                </select>
            </div>
            <div>
                <label>اسم الصنف</label>
                <input name="product_name" value="<?= htmlspecialchars($_GET['product_name'] ?? '') ?>" placeholder="البحث في أسماء الأصناف">
            </div>
            <div style="display:flex;align-items:end">
                <button type="submit" class="btn">بحث</button>
            </div>
        </form>
    </div>

    <div class="card">
        <h3>سجل حركات المخزون</h3>
        <?php
        $where = ["1=1"];
        $params = [];
        
        if (!empty($_GET['from_date'])) {
            $where[] = "doc_date >= ?";
            $params[] = $_GET['from_date'];
        }
        if (!empty($_GET['to_date'])) {
            $where[] = "doc_date <= ?";
            $params[] = $_GET['to_date'];
        }
        if (!empty($_GET['doc_type'])) {
            $where[] = "doc_type = ?";
            $params[] = $_GET['doc_type'];
        }
        if (!empty($_GET['product_name'])) {
            $where[] = "product_name LIKE ?";
            $params[] = '%' . $_GET['product_name'] . '%';
        }
        
        $sql = "SELECT * FROM inventory_movements WHERE " . implode(' AND ', $where) . " ORDER BY doc_date DESC, id DESC LIMIT 200";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $movements = $stmt->fetchAll();
        ?>
        
        <table>
            <thead>
                <tr>
                    <th>التاريخ</th>
                    <th>نوع المستند</th>
                    <th>رقم المستند</th>
                    <th>الصنف</th>
                    <th>نوع الحركة</th>
                    <th>الكمية</th>
                    <th>التكلفة</th>
                    <th>السعر</th>
                    <th>تاريخ الانتهاء</th>
                    <th>المورد/العميل</th>
                    <th>المعتمد</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($movements)): ?>
                    <tr><td colspan="11" style="text-align:center;color:#6b7280">لا توجد حركات في الفترة المحددة</td></tr>
                <?php else: ?>
                    <?php foreach ($movements as $mov): ?>
                        <tr>
                            <td><?= $mov['doc_date'] ?></td>
                            <td>
                                <?php
                                $badges = [
                                    'purchase' => ['مشتريات', 'badge-purchase'],
                                    'sale' => ['مبيعات', 'badge-sale'],
                                    'purchase_return' => ['مردود مشتريات', 'badge-return'],
                                    'sales_return' => ['مردود مبيعات', 'badge-return'],
                                    'stock_issue' => ['صرف مخزون', 'badge-issue'],
                                    'free_sample' => ['عينات مجانية', 'badge-sample'],
                                    'adjustment' => ['تسوية مخزون', 'badge-adjustment']
                                ];
                                $badge = $badges[$mov['doc_type']] ?? ['غير محدد', 'badge-adjustment'];
                                ?>
                                <span class="badge <?= $badge[1] ?>"><?= $badge[0] ?></span>
                            </td>
                            <td><?= htmlspecialchars($mov['doc_number']) ?></td>
                            <td><?= htmlspecialchars($mov['product_name']) ?></td>
                            <td class="<?= $mov['movement_type'] ?>">
                                <?= $mov['movement_type'] === 'in' ? '⬆️ دخول' : '⬇️ خروج' ?>
                            </td>
                            <td><?= number_format($mov['quantity'], 3) ?></td>
                            <td><?= $mov['unit_cost'] ? number_format($mov['unit_cost'], 2) : '-' ?></td>
                            <td><?= $mov['unit_price'] ? number_format($mov['unit_price'], 2) : '-' ?></td>
                            <td><?= $mov['expiry_date'] ?: '-' ?></td>
                            <td><?= htmlspecialchars($mov['supplier_customer'] ?: '-') ?></td>
                            <td><?= htmlspecialchars($mov['approved_by']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
</body>
</html>
