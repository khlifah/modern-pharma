<?php
// menu.php — قائمة جانبية
?>
<nav class="side">
  <!-- العمليات المخزنية -->
  <section class="group">
    <button class="group-head" type="button">العمليات المخزنية</button>
    <ul class="group-body">
      <li><a href="./purchases_invoice.php">فاتورة مشتريات</a></li>
      <li><a href="./purchase_samples.php">العينات المجانية الواردة</a></li>
      <li><a href="./purchase_returns.php">مردود مشتريات</a></li>
      <li><a href="./sales_invoice.php">فاتورة مبيعات</a></li>
      <li><a href="./sales_returns.php">مردود مبيعات</a></li>
      <li><a href="./stock_issue.php">أمر صرف مخزني</a></li>
      <li><a href="./cash_sales_reconcile.php">تحقيق فواتير المبيعات النقدية</a></li>
    </ul>
  </section>

  <!-- العمليات المالية -->
  <section class="group">
    <button class="group-head" type="button">العمليات المالية</button>
    <ul class="group-body">
      <li><a href="./receipt_voucher.php">سند قبض</a></li>
      <li><a href="./payment_voucher.php">سند صرف</a></li>
      <li><a href="./adjustment_entries.php">قيود التسوية</a></li>
      <li><a href="./post_to_journal.php">ترحيل العمليات إلى قيود اليومية</a></li>
    </ul>
  </section>

  <!-- الأرشيف -->
  <section class="group">
    <button class="group-head" type="button">الأرشيف</button>
    <ul class="group-body">
      <li><a href="./archive_inventory_docs.php">المستندات المخزنية</a></li>
      <li><a href="./archive_financial_docs.php">المستندات المالية</a></li>
    </ul>
  </section>

  <!-- تقارير -->
  <section class="group">
    <button class="group-head" type="button">تقارير</button>
    <ul class="group-body">
      <li><a href="./supplier_statement.php">كشف حساب عميل</a></li>
      <li><a href="./report_item_balances.php">أرصدة الأصناف</a></li>
      <li><a href="./report_cash_book.php">يومية الصندوق</a></li>
      <li><a href="./report_item_movements.php">حركة الأصناف</a></li>
      <li><a href="./report_general_activity.php">الحركة العامة للمنشأة</a></li>
    </ul>
  </section>
</nav>
