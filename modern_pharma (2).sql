-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: 01 سبتمبر 2025 الساعة 07:38
-- إصدار الخادم: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `modern_pharma`
--

DELIMITER $$
--
-- الإجراءات
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_allocate_fefo_for_sale` (IN `p_product_id` INT, IN `p_qty` INT)   alloc: BEGIN
  DECLARE v_left INT DEFAULT p_qty;
  DECLARE b_id INT; DECLARE b_qty INT;

  CREATE TEMPORARY TABLE IF NOT EXISTS tmp_fefo_alloc (
    batch_id INT, take_qty INT
  );
  TRUNCATE tmp_fefo_alloc;

  WHILE v_left > 0 DO
    SELECT id, qty INTO b_id, b_qty
    FROM product_batches
    WHERE product_id = p_product_id AND qty > 0
    ORDER BY (expiry IS NULL), expiry ASC, id ASC
    LIMIT 1;

    IF b_id IS NULL THEN LEAVE alloc; END IF;

    INSERT INTO tmp_fefo_alloc(batch_id, take_qty)
    VALUES (b_id, IF(b_qty >= v_left, v_left, b_qty));

    SET v_left = v_left - IF(b_qty >= v_left, v_left, b_qty);
  END WHILE;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_create_journal_with_lines` (IN `p_entry_date` DATE, IN `p_memo` VARCHAR(255), IN `p_created_by` INT, IN `p_acc_debit` INT, IN `p_acc_credit` INT, IN `p_amount` DECIMAL(14,2))   BEGIN
  DECLARE v_je_id INT;
  START TRANSACTION;
  INSERT INTO journal_entries(entry_date, memo, created_by)
  VALUES(p_entry_date, p_memo, p_created_by);
  SET v_je_id = LAST_INSERT_ID();

  INSERT INTO journal_details(journal_id, account_id, debit, credit)
  VALUES (v_je_id, p_acc_debit, p_amount, 0.00),
         (v_je_id, p_acc_credit, 0.00, p_amount);
  COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_post_purchase` (IN `p_invoice_id` INT, IN `p_user_id` INT)   proc: BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_product_id INT; 
  DECLARE v_qty INT; 
  DECLARE v_cost DECIMAL(14,2);
  DECLARE v_batch_id INT;
  DECLARE v_posted TINYINT;

  DECLARE cur CURSOR FOR
    SELECT product_id, quantity, cost
    FROM purchase_invoice_items
    WHERE invoice_id = p_invoice_id;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  START TRANSACTION;

  -- قفل الفاتورة وقراءة حالة posted
  SELECT posted INTO v_posted
  FROM purchase_invoices
  WHERE id = p_invoice_id
  FOR UPDATE;

  IF v_posted IS NULL THEN 
    ROLLBACK;
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'فاتورة الشراء غير موجودة';
  END IF;

  IF v_posted = 1 THEN
    COMMIT;
    LEAVE proc;
  END IF;

  OPEN cur;
  readloop: LOOP
    FETCH cur INTO v_product_id, v_qty, v_cost;
    IF done = 1 THEN LEAVE readloop; END IF;

    INSERT INTO product_batches(product_id, batch_code, expiry, qty, cost)
    VALUES (v_product_id, CONCAT('B', p_invoice_id, '-', UNIX_TIMESTAMP()), NULL, v_qty, v_cost);
    SET v_batch_id = LAST_INSERT_ID();

    INSERT INTO inventory_movements(product_id, batch_id, move_type, ref_table, ref_id, qty, cost, note)
    VALUES (v_product_id, v_batch_id, 'IN', 'purchase_invoices', p_invoice_id, v_qty, v_cost, 'Post Purchase');

    UPDATE products
      SET quantity = quantity + v_qty
      WHERE id = v_product_id;
  END LOOP;
  CLOSE cur;

  UPDATE purchase_invoices SET posted = 1 WHERE id = p_invoice_id;

  COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_post_sale` (IN `p_invoice_id` INT, IN `p_user_id` INT)   proc2: BEGIN
  DECLARE done INT DEFAULT 0;
  DECLARE v_product_id INT; 
  DECLARE v_qty_needed INT; 
  DECLARE v_price DECIMAL(14,2);
  DECLARE v_posted TINYINT;

  DECLARE b_id INT; 
  DECLARE b_qty INT; 
  DECLARE b_cost DECIMAL(14,2);
  DECLARE take_qty INT;

  DECLARE cur CURSOR FOR
    SELECT product_id, quantity, price
    FROM sales_invoice_items
    WHERE invoice_id = p_invoice_id;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = 1;

  START TRANSACTION;

  SELECT posted INTO v_posted
  FROM sales_invoices
  WHERE id = p_invoice_id
  FOR UPDATE;

  IF v_posted IS NULL THEN
    ROLLBACK;
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'فاتورة البيع غير موجودة';
  END IF;

  IF v_posted = 1 THEN
    COMMIT;
    LEAVE proc2;
  END IF;

  OPEN cur;
  readloop: LOOP
    FETCH cur INTO v_product_id, v_qty_needed, v_price;
    IF done = 1 THEN LEAVE readloop; END IF;

    fefo: WHILE v_qty_needed > 0 DO
      SELECT id, qty, cost
        INTO b_id, b_qty, b_cost
      FROM product_batches
      WHERE product_id = v_product_id AND qty > 0
      ORDER BY (expiry IS NULL), expiry ASC, id ASC
      LIMIT 1;

      IF b_id IS NULL THEN
        ROLLBACK;
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'لا توجد كمية كافية في الدفعات (FEFO)';
      END IF;

      SET take_qty = IF(b_qty >= v_qty_needed, v_qty_needed, b_qty);

      UPDATE product_batches SET qty = qty - take_qty WHERE id = b_id;

      INSERT INTO inventory_movements(product_id, batch_id, move_type, ref_table, ref_id, qty, cost, note)
      VALUES (v_product_id, b_id, 'OUT', 'sales_invoices', p_invoice_id, take_qty, b_cost, 'Post Sale FEFO');

      UPDATE products
        SET quantity = GREATEST(quantity - take_qty, 0)
        WHERE id = v_product_id;

      SET v_qty_needed = v_qty_needed - take_qty;
    END WHILE;
  END LOOP;
  CLOSE cur;

  UPDATE sales_invoices SET posted = 1 WHERE id = p_invoice_id;

  COMMIT;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `sp_test_purchase_sale` ()   BEGIN
  DECLARE v_uid INT DEFAULT NULL;
  DECLARE v_cid INT DEFAULT NULL;
  DECLARE v_sid INT DEFAULT NULL;
  DECLARE v_pid INT DEFAULT NULL;
  DECLARE qty_on_hand INT DEFAULT 0;
  DECLARE purch_id INT DEFAULT 0;
  DECLARE sale_id INT DEFAULT 0;
  DECLARE v_price DECIMAL(14,2) DEFAULT 0.00;

  -- جلب المعرفات (قد ترجع NULL إن لم توجد)
  SET v_uid = (SELECT id FROM users WHERE username='admin' LIMIT 1);
  SET v_cid = (SELECT id FROM customers WHERE name='عميل تجريبي' LIMIT 1);
  SET v_sid = (SELECT id FROM suppliers WHERE name='مورد تجريبي' LIMIT 1);
  SET v_pid = (SELECT id FROM products WHERE barcode='P500' LIMIT 1);

  -- تحقق من وجود العناصر الأساسية
  IF v_pid IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'خطأ: المنتج (barcode P500) غير موجود';
  END IF;
  IF v_uid IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'خطأ: المستخدم admin غير موجود';
  END IF;
  IF v_cid IS NULL OR v_sid IS NULL THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'خطأ: العميل أو المورد الافتراضي مفقود';
  END IF;

  -- احسب الكمية بالمخزون من دفعات المنتج
  SELECT COALESCE(SUM(qty),0) INTO qty_on_hand FROM product_batches WHERE product_id = v_pid;

  -- لو المخزون صفر → أنشئ فاتورة شراء ثم رُحّلها
  IF qty_on_hand = 0 THEN
    INSERT INTO purchase_invoices(supplier_id, invoice_date, total, created_by)
    VALUES (v_sid, CURDATE(), 0, v_uid);
    SET purch_id = LAST_INSERT_ID();

    INSERT INTO purchase_invoice_items(invoice_id, product_id, quantity, cost, total)
    VALUES (purch_id, v_pid, 10, 200.00, 10*200.00);

    UPDATE purchase_invoices
      SET total = (SELECT COALESCE(SUM(total),0) FROM purchase_invoice_items WHERE invoice_id = purch_id)
      WHERE id = purch_id;

    -- استدعاء إجراء ترحيل المشتريات — تأكد أن sp_post_purchase موجود
    CALL sp_post_purchase(purch_id, v_uid);
  END IF;

  -- إنشاء فاتورة بيع ثم ترحيلها
  INSERT INTO sales_invoices(customer_id, invoice_date, total, created_by)
  VALUES (v_cid, CURDATE(), 0, v_uid);
  SET sale_id = LAST_INSERT_ID();

  SET v_price = (SELECT price FROM products WHERE id = v_pid);
  IF v_price IS NULL OR v_price <= 0 THEN SET v_price = 300.00; END IF;

  INSERT INTO sales_invoice_items(invoice_id, product_id, quantity, price, total)
  VALUES (sale_id, v_pid, 7, v_price, 7 * v_price);

  UPDATE sales_invoices
    SET total = (SELECT COALESCE(SUM(total),0) FROM sales_invoice_items WHERE invoice_id = sale_id)
    WHERE id = sale_id;

  CALL sp_post_sale(sale_id, v_uid);

END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- بنية الجدول `accounts`
--

CREATE TABLE `accounts` (
  `id` int(11) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `name` varchar(120) NOT NULL,
  `type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `accounts`
--

INSERT INTO `accounts` (`id`, `code`, `name`, `type`, `parent_id`, `created_at`) VALUES
(1, '100', 'الصندوق', 'asset', NULL, '2025-08-28 21:46:41'),
(2, '101', 'البنك', 'asset', NULL, '2025-08-28 21:46:41'),
(3, '200', 'الدائنون', 'liability', NULL, '2025-08-28 21:46:41'),
(4, '201', 'العملاء', 'asset', NULL, '2025-08-28 21:46:41'),
(5, '300', 'رأس المال', 'equity', NULL, '2025-08-28 21:46:41'),
(6, '400', 'المبيعات', 'revenue', NULL, '2025-08-28 21:46:41'),
(7, '401', 'خصم المبيعات', 'revenue', NULL, '2025-08-28 21:46:41'),
(8, '500', 'المشتريات', 'expense', NULL, '2025-08-28 21:46:41'),
(9, '501', 'المصاريف الإدارية', 'expense', NULL, '2025-08-28 21:46:41'),
(10, '', 'اختبار دائن', 'asset', NULL, '2025-08-29 03:53:37'),
(15, 'ACC-000001', 'دائنون - مورد #2', 'asset', NULL, '2025-08-29 04:08:41'),
(16, 'ACC-000002', 'مدينون - عميل/مورد #3', 'asset', NULL, '2025-08-29 19:21:56'),
(17, 'ACC-000003', 'دائنون - مورد #3', 'asset', NULL, '2025-08-30 00:38:12'),
(18, 'ACC-000004', 'مشتريات تحت التسوية', 'asset', NULL, '2025-08-30 01:19:52'),
(19, 'ACC-000005', 'دائنون - مورد #4', 'asset', NULL, '2025-08-30 01:23:31'),
(20, 'ACC-000006', 'مصروف صرف مخزني', 'asset', NULL, '2025-08-30 01:41:30'),
(21, 'ACC-000007', 'قبض عام - خليفه', 'asset', NULL, '2025-08-30 17:48:36'),
(22, 'ACC-000008', 'دائنون - مورد #5', 'asset', NULL, '2025-08-30 18:28:54'),
(23, 'ACC-000009', 'دائنون - مورد #6', 'asset', NULL, '2025-08-31 20:46:55'),
(29, 'ACC-20250901-0001', 'المخزون', 'asset', NULL, '2025-09-01 02:54:12'),
(31, 'ACC_TEST1', 'اختبار مدين', 'asset', NULL, '2025-09-01 02:59:14');

-- --------------------------------------------------------

--
-- بنية الجدول `adjustment_entries`
--

CREATE TABLE `adjustment_entries` (
  `id` int(11) NOT NULL,
  `doc_date` date NOT NULL,
  `description` varchar(255) NOT NULL,
  `debit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `posted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `audit_trail`
--

CREATE TABLE `audit_trail` (
  `id` bigint(20) NOT NULL,
  `user_id` int(11) NOT NULL,
  `action` varchar(50) NOT NULL,
  `table_name` varchar(50) NOT NULL,
  `record_id` int(11) DEFAULT NULL,
  `old_value` text DEFAULT NULL,
  `new_value` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `categories`
--

CREATE TABLE `categories` (
  `id` int(11) NOT NULL,
  `name` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `chart_of_accounts`
--

CREATE TABLE `chart_of_accounts` (
  `id` int(11) NOT NULL,
  `code` varchar(20) NOT NULL,
  `name` varchar(100) NOT NULL,
  `type` enum('asset','liability','equity','revenue','expense') NOT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `customers`
--

CREATE TABLE `customers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `opening_balance` decimal(12,2) NOT NULL DEFAULT 0.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `customers`
--

INSERT INTO `customers` (`id`, `name`, `phone`, `email`, `address`, `created_at`, `opening_balance`) VALUES
(1, 'عميل تجريبي', '777000000', NULL, NULL, '2025-08-28 21:46:41', 0.00),
(2, 'مورد تجريبي', '777777777', NULL, NULL, '2025-09-01 03:20:55', 0.00),
(3, 'بةل', '589834758964', NULL, NULL, '2025-09-01 03:20:55', 0.00),
(4, 'انا خليفه', '589834758964', NULL, NULL, '2025-09-01 03:20:55', 0.00),
(5, 'رامز', '7566666666', NULL, NULL, '2025-09-01 03:20:55', 0.00),
(6, 'خليفه طاهر المروني', '0739435396', NULL, 'taiv\r\nggyg', '2025-09-01 03:20:55', 0.00),
(7, 'خليفه طاهر', '0739435396', NULL, 'taiv\r\nggyg', '2025-09-01 03:20:55', 0.00);

-- --------------------------------------------------------

--
-- بنية الجدول `inventory_movements`
--

CREATE TABLE `inventory_movements` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_id` int(11) DEFAULT NULL,
  `move_type` enum('IN','OUT','ADJ') NOT NULL,
  `ref_table` enum('purchase_invoices','sales_invoices','manual') NOT NULL DEFAULT 'manual',
  `ref_id` int(11) DEFAULT NULL,
  `qty` int(11) NOT NULL,
  `cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `note` varchar(255) DEFAULT '',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `inventory_movements`
--

INSERT INTO `inventory_movements` (`id`, `product_id`, `batch_id`, `move_type`, `ref_table`, `ref_id`, `qty`, `cost`, `note`, `created_at`) VALUES
(1, 1, 1, 'IN', 'purchase_invoices', 1, 10, 200.00, 'Post Purchase', '2025-08-28 21:57:02'),
(2, 1, 1, 'OUT', 'sales_invoices', 1, 7, 200.00, 'Post Sale FEFO', '2025-08-28 21:57:02');

-- --------------------------------------------------------

--
-- بنية الجدول `journal_details`
--

CREATE TABLE `journal_details` (
  `id` int(11) NOT NULL,
  `journal_id` int(10) UNSIGNED DEFAULT NULL,
  `account_id` int(11) DEFAULT NULL,
  `debit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `credit` decimal(14,2) NOT NULL DEFAULT 0.00,
  `entry_id` int(11) NOT NULL,
  `account` varchar(120) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `journal_details`
--

INSERT INTO `journal_details` (`id`, `journal_id`, `account_id`, `debit`, `credit`, `entry_id`, `account`) VALUES
(9, 13, 15, 500000.00, 0.00, 13, 'دائنون - مورد #2'),
(10, 13, 10, 0.00, 500000.00, 13, 'المخزون'),
(11, 14, 15, 500000.00, 0.00, 14, 'دائنون - مورد #2'),
(12, 14, 10, 0.00, 500000.00, 14, 'المخزون'),
(13, 15, 15, 165000.00, 0.00, 15, 'دائنون - مورد #2'),
(14, 15, 10, 0.00, 165000.00, 15, 'المخزون'),
(15, 16, 15, 165000.00, 0.00, 16, 'دائنون - مورد #2'),
(16, 16, 10, 0.00, 165000.00, 16, 'المخزون'),
(17, 17, 1, 1200000.00, 0.00, 17, 'الصندوق'),
(18, 17, 16, 0.00, 1200000.00, 17, 'مدينون - عميل/مورد #3'),
(19, 18, 10, 500000.00, 0.00, 18, 'المخزون'),
(20, 18, 17, 0.00, 500000.00, 18, 'دائنون - مورد #3'),
(21, 19, 10, 750000.00, 0.00, 19, 'المخزون'),
(22, 19, 18, 0.00, 750000.00, 19, 'مشتريات تحت التسوية'),
(23, 20, 10, 750000.00, 0.00, 20, 'المخزون'),
(24, 20, 18, 0.00, 750000.00, 20, 'مشتريات تحت التسوية'),
(25, 21, 10, 500000.00, 0.00, 21, 'المخزون'),
(26, 21, 19, 0.00, 500000.00, 21, 'دائنون - مورد #4'),
(27, 22, 17, 1250000.00, 0.00, 22, 'دائنون - مورد #3'),
(28, 22, 10, 0.00, 1250000.00, 22, 'المخزون'),
(29, 23, 10, 750000.00, 0.00, 23, 'المخزون'),
(30, 23, 17, 0.00, 750000.00, 23, 'دائنون - مورد #3'),
(31, 24, 20, 5000000.00, 0.00, 24, 'مصروف صرف مخزني'),
(32, 24, 10, 0.00, 5000000.00, 24, 'المخزون'),
(33, 25, 17, 5000.00, 0.00, 25, 'دائنون - مورد #3'),
(34, 25, 10, 0.00, 5000.00, 25, 'المخزون'),
(35, 26, 1, 20000.00, 0.00, 26, 'الصندوق'),
(36, 26, 21, 0.00, 20000.00, 26, 'قبض عام - خليفه'),
(37, 27, 22, 555000.00, 0.00, 27, 'دائنون - مورد #5'),
(38, 27, 10, 0.00, 555000.00, 27, 'المخزون'),
(39, 28, 22, 555000.00, 0.00, 28, 'دائنون - مورد #5'),
(40, 28, 10, 0.00, 555000.00, 28, 'المخزون'),
(41, 29, 10, 5000000.00, 0.00, 29, 'المخزون'),
(42, 29, 23, 0.00, 5000000.00, 29, 'دائنون - مورد #6'),
(43, 30, 10, 5000000.00, 0.00, 30, 'المخزون'),
(44, 30, 23, 0.00, 5000000.00, 30, 'دائنون - مورد #6'),
(45, 31, 10, 10000000.00, 0.00, 31, 'المخزون'),
(46, 31, 23, 0.00, 10000000.00, 31, 'دائنون - مورد #6'),
(47, 32, 10, 10000000.00, 0.00, 32, 'المخزون'),
(48, 32, 23, 0.00, 10000000.00, 32, 'دائنون - مورد #6'),
(49, 33, 10, 250000.00, 0.00, 33, 'المخزون'),
(50, 33, 23, 0.00, 250000.00, 33, 'دائنون - مورد #6'),
(51, 34, 10, 250000.00, 0.00, 34, 'المخزون'),
(52, 34, 23, 0.00, 250000.00, 34, 'دائنون - مورد #6'),
(53, 35, 10, 15000.00, 0.00, 35, 'المخزون'),
(54, 35, 23, 0.00, 15000.00, 35, 'دائنون - مورد #6'),
(55, 36, 10, 15000.00, 0.00, 36, 'المخزون'),
(56, 36, 23, 0.00, 15000.00, 36, 'دائنون - مورد #6'),
(57, 37, 10, 15000.00, 0.00, 37, 'المخزون'),
(58, 37, 23, 0.00, 15000.00, 37, 'دائنون - مورد #6'),
(59, 38, 10, 7500000.00, 0.00, 38, 'المخزون'),
(60, 38, 19, 0.00, 7500000.00, 38, 'دائنون - مورد #4'),
(61, 39, 10, 7500000.00, 0.00, 39, 'المخزون'),
(62, 39, 19, 0.00, 7500000.00, 39, 'دائنون - مورد #4'),
(63, 40, 10, 5000000.00, 0.00, 40, 'المخزون'),
(64, 40, 23, 0.00, 5000000.00, 40, 'دائنون - مورد #6'),
(65, 41, 10, 5000000.00, 0.00, 41, 'المخزون'),
(66, 41, 23, 0.00, 5000000.00, 41, 'دائنون - مورد #6'),
(67, 42, 10, 5000000.00, 0.00, 42, 'المخزون'),
(68, 42, 23, 0.00, 5000000.00, 42, 'دائنون - مورد #6'),
(76, 46, 29, 1387500.00, 0.00, 46, 'المخزون'),
(77, 46, 15, 0.00, 1387500.00, 46, 'دائنون - مورد #2'),
(78, 47, 29, 1387500.00, 0.00, 47, 'المخزون'),
(79, 47, 15, 0.00, 1387500.00, 47, 'دائنون - مورد #2'),
(80, 48, 29, 1387500.00, 0.00, 48, 'المخزون'),
(81, 48, 15, 0.00, 1387500.00, 48, 'دائنون - مورد #2'),
(82, 45, 31, 100.00, 0.00, 0, 'اختبار مدين'),
(83, 45, 10, 0.00, 100.00, 0, 'اختبار دائن'),
(84, 49, 29, 1387500.00, 0.00, 49, 'المخزون'),
(85, 49, 15, 0.00, 1387500.00, 49, 'دائنون - مورد #2'),
(86, 50, 29, 1387500.00, 0.00, 50, 'المخزون'),
(87, 50, 15, 0.00, 1387500.00, 50, 'دائنون - مورد #2');

-- --------------------------------------------------------

--
-- بنية الجدول `journal_entries`
--

CREATE TABLE `journal_entries` (
  `id` int(10) UNSIGNED NOT NULL,
  `doc_type` varchar(50) NOT NULL,
  `doc_id` int(11) DEFAULT NULL,
  `entry_date` date NOT NULL,
  `memo` varchar(255) NOT NULL DEFAULT '',
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `doc_date` date DEFAULT NULL,
  `description` varchar(255) DEFAULT NULL,
  `code` varchar(50) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `journal_entries`
--

INSERT INTO `journal_entries` (`id`, `doc_type`, `doc_id`, `entry_date`, `memo`, `created_by`, `created_at`, `doc_date`, `description`, `code`) VALUES
(1, 'purchase', 10, '0000-00-00', '', NULL, '2025-08-29 03:52:07', '2025-08-29', 'فاتورة مشتريات', NULL),
(2, 'purchase', 11, '0000-00-00', '', NULL, '2025-08-29 03:53:37', '2025-08-29', 'فاتورة مشتريات', NULL),
(3, 'purchase', 12, '0000-00-00', '', NULL, '2025-08-29 03:54:11', '2025-08-29', 'فاتورة مشتريات', NULL),
(4, 'purchase', 13, '0000-00-00', '', NULL, '2025-08-29 03:55:41', '2025-08-29', 'فاتورة مشتريات', NULL),
(5, 'purchase', 14, '0000-00-00', '', NULL, '2025-08-29 03:56:14', '2025-08-29', 'فاتورة مشتريات', NULL),
(6, 'purchase_return', 4, '0000-00-00', '', NULL, '2025-08-29 03:57:11', '2025-08-29', 'مردود مشتريات', NULL),
(7, 'purchase', 15, '0000-00-00', '', NULL, '2025-08-29 03:58:04', '2025-08-29', 'فاتورة مشتريات', NULL),
(8, 'purchase_return', 5, '0000-00-00', '', NULL, '2025-08-29 04:00:02', '2025-08-29', 'مردود مشتريات', NULL),
(9, 'purchase_return', 6, '0000-00-00', '', NULL, '2025-08-29 04:04:48', '2025-08-29', 'مردود مشتريات', NULL),
(10, 'purchase_return', 7, '0000-00-00', '', NULL, '2025-08-29 04:05:13', '2025-08-29', 'مردود مشتريات', NULL),
(11, 'purchase_return', 8, '0000-00-00', '', NULL, '2025-08-29 04:08:41', '2025-08-29', 'مردود مشتريات', 'JE-20250829-0001'),
(12, 'purchase_return', 9, '0000-00-00', '', NULL, '2025-08-29 04:08:55', '2025-08-29', 'مردود مشتريات', 'JE-20250829-0002'),
(13, 'purchase_return', 10, '0000-00-00', '', NULL, '2025-08-29 04:13:03', '2025-08-29', 'مردود مشتريات', 'JE-20250829-0003'),
(14, 'purchase_return', 11, '0000-00-00', '', NULL, '2025-08-29 04:13:11', '2025-08-29', 'مردود مشتريات', 'JE-20250829-0004'),
(15, 'purchase_return', 12, '0000-00-00', '', NULL, '2025-08-29 04:13:21', '2025-08-29', 'مردود مشتريات', 'JE-20250829-0005'),
(16, 'purchase_return', 13, '0000-00-00', '', NULL, '2025-08-29 04:15:16', '2025-08-29', 'مردود مشتريات', 'JE-20250829-0006'),
(17, 'sales_cash_reconcile', 3, '0000-00-00', '', NULL, '2025-08-29 19:21:56', '2025-08-29', 'تحقيق فاتورة مبيعات #3', 'JE-20250829-0007'),
(18, 'purchase', 16, '0000-00-00', '', NULL, '2025-08-30 00:38:12', '2025-08-30', 'فاتورة مشتريات', 'JE-20250830-0001'),
(19, 'purchase', 17, '0000-00-00', '', NULL, '2025-08-30 01:19:52', '2025-08-30', 'فاتورة مشتريات', 'JE-20250830-0002'),
(20, 'purchase', 18, '0000-00-00', '', NULL, '2025-08-30 01:22:44', '2025-08-30', 'فاتورة مشتريات', 'JE-20250830-0003'),
(21, 'purchase', 19, '0000-00-00', '', NULL, '2025-08-30 01:23:31', '2025-08-30', 'فاتورة مشتريات', 'JE-20250830-0004'),
(22, 'purchase_return', 14, '0000-00-00', '', NULL, '2025-08-30 01:24:33', '2025-08-30', 'مردود مشتريات', 'JE-20250830-0005'),
(23, 'purchase', 20, '0000-00-00', '', NULL, '2025-08-30 01:33:19', '2025-08-30', 'فاتورة مشتريات', 'JE-20250830-0006'),
(24, 'stock_issue', 1, '0000-00-00', '', NULL, '2025-08-30 01:41:30', '2025-08-30', 'أمر صرف مخزني', 'JE-20250830-0007'),
(25, 'purchase_return', 15, '0000-00-00', '', NULL, '2025-08-30 02:03:19', '2025-08-30', 'مردود مشتريات', 'JE-20250830-0008'),
(26, 'receipt', 1, '0000-00-00', '', NULL, '2025-08-30 17:48:36', '2025-08-30', 'سند قبض من خليفه', 'JE-20250830-0009'),
(27, 'purchase_return', 16, '0000-00-00', '', NULL, '2025-08-30 18:28:54', '2025-08-30', 'مردود مشتريات', 'JE-20250830-0010'),
(28, 'purchase_return', 17, '0000-00-00', '', NULL, '2025-08-30 18:34:42', '2025-08-30', 'مردود مشتريات', 'JE-20250830-0011'),
(29, 'purchase', 21, '0000-00-00', '', NULL, '2025-08-31 20:46:55', '2025-08-31', 'فاتورة مشتريات', 'JE-20250831-0001'),
(30, 'purchase', 22, '0000-00-00', '', NULL, '2025-08-31 20:49:40', '2025-08-31', 'فاتورة مشتريات', 'JE-20250831-0002'),
(31, 'purchase', 23, '0000-00-00', '', NULL, '2025-08-31 20:49:51', '2025-08-31', 'فاتورة مشتريات', 'JE-20250831-0003'),
(32, 'purchase', 24, '0000-00-00', '', NULL, '2025-08-31 20:52:00', '2025-08-31', 'فاتورة مشتريات', 'JE-20250831-0004'),
(33, 'purchase', 25, '0000-00-00', '', NULL, '2025-08-31 20:52:11', '2025-08-31', 'فاتورة مشتريات', 'JE-20250831-0005'),
(34, 'purchase', 26, '0000-00-00', '', NULL, '2025-08-31 20:58:53', '2025-08-31', 'فاتورة مشتريات', 'JE-20250831-0006'),
(35, 'purchase', 27, '0000-00-00', '', NULL, '2025-08-31 20:59:02', '2025-08-31', 'فاتورة مشتريات', 'JE-20250831-0007'),
(36, 'purchase', 28, '0000-00-00', '', NULL, '2025-08-31 21:08:19', '2025-08-31', 'فاتورة مشتريات', 'JE-20250831-0008'),
(37, 'purchase', 29, '0000-00-00', '', NULL, '2025-08-31 21:50:00', '2025-08-31', 'فاتورة مشتريات', 'JE-20250831-0009'),
(38, 'purchase', 30, '0000-00-00', '', NULL, '2025-08-31 21:50:45', '2025-08-31', 'فاتورة مشتريات', 'JE-20250831-0010'),
(39, 'purchase', 31, '0000-00-00', '', NULL, '2025-08-31 22:34:49', '2025-08-31', 'فاتورة مشتريات', 'JE-20250831-0011'),
(40, 'purchase', 32, '0000-00-00', '', NULL, '2025-08-31 22:35:07', '2025-09-01', 'فاتورة مشتريات', 'JE-20250901-0001'),
(41, 'purchase', 33, '0000-00-00', '', NULL, '2025-08-31 22:48:39', '2025-09-01', 'فاتورة مشتريات', 'JE-20250901-0002'),
(42, 'purchase', 34, '0000-00-00', '', NULL, '2025-08-31 22:49:05', '2025-09-01', 'فاتورة مشتريات', 'JE-20250901-0003'),
(43, 'purchase', 36, '0000-00-00', '', NULL, '2025-09-01 01:56:26', '2025-09-01', 'فاتورة مشتريات', 'JE-20250901-0004'),
(44, 'test', NULL, '0000-00-00', '', NULL, '2025-09-01 02:30:43', '2025-09-01', 'manual test', NULL),
(45, 'test', NULL, '0000-00-00', '', NULL, '2025-09-01 02:32:31', '2025-09-01', 'manual test', NULL),
(46, 'purchase', 37, '0000-00-00', '', NULL, '2025-09-01 02:54:12', '2025-09-01', 'فاتورة مشتريات', 'JE-20250901-0005'),
(47, 'purchase', 38, '0000-00-00', '', NULL, '2025-09-01 03:01:21', '2025-09-01', 'فاتورة مشتريات', 'JE-20250901-0006'),
(48, 'purchase', 39, '0000-00-00', '', NULL, '2025-09-01 03:01:28', '2025-09-01', 'فاتورة مشتريات', 'JE-20250901-0007'),
(49, 'purchase', 40, '0000-00-00', '', NULL, '2025-09-01 03:02:42', '2025-09-01', 'فاتورة مشتريات', 'JE-20250901-0008'),
(50, 'purchase', 41, '0000-00-00', '', NULL, '2025-09-01 03:13:57', '2025-09-01', 'فاتورة مشتريات', NULL);

-- --------------------------------------------------------

--
-- بنية الجدول `monthly_sales`
--

CREATE TABLE `monthly_sales` (
  `month` varchar(7) DEFAULT NULL,
  `total_sales` decimal(36,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `notifications`
--

CREATE TABLE `notifications` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `message` text NOT NULL,
  `severity` enum('info','success','warning','error') NOT NULL DEFAULT 'info',
  `actor_user_id` int(11) DEFAULT NULL,
  `target_table` varchar(100) DEFAULT NULL,
  `target_id` int(11) DEFAULT NULL,
  `status` enum('new','read') DEFAULT 'new',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  `event_type` varchar(50) DEFAULT NULL,
  `title` varchar(200) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `notifications`
--

INSERT INTO `notifications` (`id`, `user_id`, `message`, `severity`, `actor_user_id`, `target_table`, `target_id`, `status`, `created_at`, `read_at`, `event_type`, `title`) VALUES
(1, NULL, 'تم حفظ فاتورة مشتريات بقيمة: 15,000.00 ريال.', 'success', 5, 'purchase_invoices', 28, 'new', '2025-08-31 21:08:20', NULL, 'purchase_created', 'فاتورة مشتريات #28'),
(2, NULL, 'تم حفظ فاتورة مشتريات بقيمة: 15,000.00 ريال.', 'success', 5, 'purchase_invoices', 29, 'new', '2025-08-31 21:50:00', NULL, 'purchase_created', 'فاتورة مشتريات #29'),
(3, NULL, 'تم حفظ فاتورة مشتريات بقيمة: 7,500,000.00 ريال.', 'success', 5, 'purchase_invoices', 30, 'new', '2025-08-31 21:50:45', NULL, 'purchase_created', 'فاتورة مشتريات #30'),
(4, NULL, 'تم حفظ فاتورة مشتريات بقيمة: 7,500,000.00 ريال.', 'success', 9, 'purchase_invoices', 31, 'new', '2025-08-31 22:34:49', NULL, 'purchase_created', 'فاتورة مشتريات #31'),
(5, NULL, 'تم حفظ فاتورة مشتريات بقيمة: 5,000,000.00 ريال.', 'success', 9, 'purchase_invoices', 32, 'new', '2025-08-31 22:35:07', NULL, 'purchase_created', 'فاتورة مشتريات #32'),
(6, NULL, 'تم حفظ فاتورة مشتريات بقيمة: 5,000,000.00 ريال.', 'success', 9, 'purchase_invoices', 33, 'new', '2025-08-31 22:48:39', NULL, 'purchase_created', 'فاتورة مشتريات #33'),
(7, NULL, 'تم حفظ فاتورة مشتريات بقيمة: 5,000,000.00 ريال.', 'success', 9, 'purchase_invoices', 34, 'new', '2025-08-31 22:49:05', NULL, 'purchase_created', 'فاتورة مشتريات #34'),
(8, NULL, 'تم حفظ فاتورة مشتريات بقيمة: 1,387,500.00 ريال.', 'success', 4, 'purchase_invoices', 41, 'new', '2025-09-01 03:13:57', NULL, 'purchase_created', 'فاتورة مشتريات #41');

-- --------------------------------------------------------

--
-- بنية الجدول `payment_methods`
--

CREATE TABLE `payment_methods` (
  `id` int(11) NOT NULL,
  `method_name` varchar(50) NOT NULL,
  `description` varchar(200) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `payment_methods`
--

INSERT INTO `payment_methods` (`id`, `method_name`, `description`) VALUES
(1, 'Cash', 'الدفع نقداً'),
(2, 'Bank Transfer', 'تحويل بنكي'),
(3, 'Cheque', 'شيك بنكي');

-- --------------------------------------------------------

--
-- بنية الجدول `payment_vouchers`
--

CREATE TABLE `payment_vouchers` (
  `id` int(11) NOT NULL,
  `doc_date` date NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `amount` decimal(14,2) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `posted` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `products`
--

CREATE TABLE `products` (
  `id` int(11) NOT NULL,
  `sku` varchar(100) DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `barcode` varchar(100) DEFAULT NULL,
  `cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `quantity` int(11) NOT NULL DEFAULT 0,
  `min_quantity` int(11) NOT NULL DEFAULT 0,
  `cost_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `sale_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `unit` varchar(30) DEFAULT 'قطعة',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `products`
--

INSERT INTO `products` (`id`, `sku`, `name`, `barcode`, `cost`, `price`, `quantity`, `min_quantity`, `cost_price`, `sale_price`, `unit`, `created_at`) VALUES
(1, NULL, 'باراسيتامول 500mg', 'P500', 200.00, 300.00, 3, 0, 2500.00, 2700.00, 'قطعة', '2025-08-28 21:46:41'),
(2, NULL, 'برمول', NULL, 0.00, 0.00, 51065, 0, 2500.00, 3000.00, 'قطعة', '2025-08-29 01:56:37');

-- --------------------------------------------------------

--
-- بنية الجدول `product_batches`
--

CREATE TABLE `product_batches` (
  `id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `batch_code` varchar(64) DEFAULT NULL,
  `expiry` date DEFAULT NULL,
  `qty` int(11) NOT NULL DEFAULT 0,
  `cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `product_batches`
--

INSERT INTO `product_batches` (`id`, `product_id`, `batch_code`, `expiry`, `qty`, `cost`, `created_at`) VALUES
(1, 1, 'B1-1756418222', NULL, 3, 200.00, '2025-08-28 21:57:02');

-- --------------------------------------------------------

--
-- بنية الجدول `purchase_invoices`
--

CREATE TABLE `purchase_invoices` (
  `id` int(11) NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `invoice_date` date NOT NULL,
  `total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `posted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `reconciled` tinyint(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `purchase_invoices`
--

INSERT INTO `purchase_invoices` (`id`, `supplier_id`, `note`, `total_amount`, `invoice_date`, `total`, `posted`, `created_by`, `created_at`, `reconciled`) VALUES
(1, 1, NULL, 0.00, '2025-08-29', 2000.00, 1, 1, '2025-08-28 21:57:02', 0),
(2, 3, NULL, 400000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:18:49', 0),
(3, 2, NULL, 200000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:39:37', 0),
(4, 3, NULL, 250000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:44:31', 0),
(5, 3, NULL, 250000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:45:06', 0),
(6, 2, NULL, 325000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:47:55', 0),
(7, 2, NULL, 325000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:50:10', 0),
(8, 2, NULL, 325000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:50:14', 0),
(9, 2, NULL, 300000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:50:34', 0),
(10, 2, NULL, 300000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:52:06', 0),
(11, 2, NULL, 300000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:53:36', 0),
(12, 2, NULL, 3750000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:54:11', 0),
(13, 2, NULL, 3750000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:55:41', 0),
(14, 2, NULL, 32500000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:56:14', 0),
(15, 2, NULL, 5000000.00, '2025-08-29', 0.00, 0, NULL, '2025-08-29 03:58:04', 0),
(16, 3, NULL, 500000.00, '2025-08-30', 0.00, 0, NULL, '2025-08-30 00:38:12', 0),
(17, NULL, NULL, 750000.00, '2025-08-30', 0.00, 0, NULL, '2025-08-30 01:19:52', 0),
(18, NULL, NULL, 750000.00, '2025-08-30', 0.00, 0, NULL, '2025-08-30 01:22:44', 0),
(19, 4, NULL, 500000.00, '2025-08-30', 0.00, 0, NULL, '2025-08-30 01:23:31', 0),
(20, 3, NULL, 750000.00, '2025-08-30', 0.00, 0, NULL, '2025-08-30 01:33:19', 0),
(21, 6, NULL, 5000000.00, '2025-08-31', 0.00, 0, NULL, '2025-08-31 20:46:55', 0),
(22, 6, NULL, 5000000.00, '2025-08-31', 0.00, 0, NULL, '2025-08-31 20:49:40', 0),
(23, 6, NULL, 10000000.00, '2025-08-31', 0.00, 0, NULL, '2025-08-31 20:49:51', 0),
(24, 6, NULL, 10000000.00, '2025-08-31', 0.00, 0, NULL, '2025-08-31 20:52:00', 0),
(25, 6, NULL, 250000.00, '2025-08-31', 0.00, 0, NULL, '2025-08-31 20:52:11', 0),
(26, 6, NULL, 250000.00, '2025-08-31', 0.00, 0, NULL, '2025-08-31 20:58:53', 0),
(27, 6, NULL, 15000.00, '2025-08-31', 0.00, 0, NULL, '2025-08-31 20:59:02', 0),
(28, 6, NULL, 15000.00, '2025-08-31', 0.00, 0, NULL, '2025-08-31 21:08:19', 0),
(29, 6, NULL, 15000.00, '2025-08-31', 0.00, 0, NULL, '2025-08-31 21:50:00', 0),
(30, 4, NULL, 7500000.00, '2025-08-31', 0.00, 0, NULL, '2025-08-31 21:50:45', 0),
(31, 4, NULL, 7500000.00, '2025-08-31', 0.00, 0, NULL, '2025-08-31 22:34:49', 0),
(32, 6, NULL, 5000000.00, '2025-09-01', 0.00, 0, NULL, '2025-08-31 22:35:07', 0),
(33, 6, NULL, 5000000.00, '2025-09-01', 0.00, 0, NULL, '2025-08-31 22:48:39', 0),
(34, 6, NULL, 5000000.00, '2025-09-01', 0.00, 0, NULL, '2025-08-31 22:49:05', 0),
(35, 5, NULL, 5000000.00, '2025-09-01', 0.00, 0, NULL, '2025-09-01 01:34:33', 0),
(36, 5, NULL, 50000000.00, '2025-09-01', 0.00, 0, NULL, '2025-09-01 01:56:26', 0),
(37, 2, NULL, 1387500.00, '2025-09-01', 0.00, 0, NULL, '2025-09-01 02:54:12', 0),
(38, 2, NULL, 1387500.00, '2025-09-01', 0.00, 0, NULL, '2025-09-01 03:01:21', 0),
(39, 2, NULL, 1387500.00, '2025-09-01', 0.00, 0, NULL, '2025-09-01 03:01:28', 0),
(40, 2, NULL, 1387500.00, '2025-09-01', 0.00, 0, NULL, '2025-09-01 03:02:42', 0),
(41, 2, NULL, 1387500.00, '2025-09-01', 0.00, 0, NULL, '2025-09-01 03:13:57', 0);

-- --------------------------------------------------------

--
-- بنية الجدول `purchase_invoice_items`
--

CREATE TABLE `purchase_invoice_items` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `cost` decimal(14,2) NOT NULL,
  `total` decimal(14,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `purchase_invoice_items`
--

INSERT INTO `purchase_invoice_items` (`id`, `invoice_id`, `product_id`, `quantity`, `cost`, `total`) VALUES
(1, 1, 1, 10, 200.00, 2000.00);

-- --------------------------------------------------------

--
-- بنية الجدول `purchase_items`
--

CREATE TABLE `purchase_items` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `cost_price` decimal(14,2) NOT NULL,
  `line_total` decimal(14,2) NOT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- إرجاع أو استيراد بيانات الجدول `purchase_items`
--

INSERT INTO `purchase_items` (`id`, `invoice_id`, `product_id`, `quantity`, `cost_price`, `line_total`, `issue_date`, `expiry_date`) VALUES
(1, 2, 2, 200, 2000.00, 400000.00, NULL, NULL),
(2, 3, 2, 100, 2000.00, 200000.00, NULL, NULL),
(3, 4, 2, 100, 2500.00, 250000.00, NULL, NULL),
(4, 5, 2, 100, 2500.00, 250000.00, NULL, NULL),
(5, 6, 2, 130, 2500.00, 325000.00, NULL, NULL),
(6, 7, 2, 130, 2500.00, 325000.00, NULL, NULL),
(7, 8, 2, 130, 2500.00, 325000.00, NULL, NULL),
(8, 9, 2, 120, 2500.00, 300000.00, NULL, NULL),
(9, 10, 2, 120, 2500.00, 300000.00, NULL, NULL),
(10, 11, 2, 120, 2500.00, 300000.00, NULL, NULL),
(11, 12, 2, 1500, 2500.00, 3750000.00, NULL, NULL),
(12, 13, 2, 1500, 2500.00, 3750000.00, NULL, NULL),
(13, 14, 2, 13000, 2500.00, 32500000.00, NULL, NULL),
(14, 15, 1, 2000, 2500.00, 5000000.00, NULL, NULL),
(15, 16, 2, 200, 2500.00, 500000.00, NULL, NULL),
(16, 17, 2, 300, 2500.00, 750000.00, '2025-08-03', '2025-08-31'),
(17, 18, 2, 300, 2500.00, 750000.00, '2025-08-03', '2025-08-31'),
(18, 19, 2, 200, 2500.00, 500000.00, '2025-08-01', '2025-09-30'),
(19, 20, 2, 300, 2500.00, 750000.00, NULL, '2025-08-28'),
(20, 21, 2, 2000, 2500.00, 5000000.00, NULL, NULL),
(21, 22, 2, 2000, 2500.00, 5000000.00, NULL, NULL),
(22, 23, 2, 4000, 2500.00, 10000000.00, NULL, NULL),
(23, 24, 2, 4000, 2500.00, 10000000.00, NULL, NULL),
(24, 25, 2, 100, 2500.00, 250000.00, NULL, NULL),
(25, 26, 2, 100, 2500.00, 250000.00, NULL, NULL),
(26, 27, 2, 6, 2500.00, 15000.00, NULL, NULL),
(27, 28, 2, 6, 2500.00, 15000.00, NULL, NULL),
(28, 29, 2, 6, 2500.00, 15000.00, NULL, NULL),
(29, 30, 2, 3000, 2500.00, 7500000.00, NULL, '2025-09-17'),
(30, 31, 2, 3000, 2500.00, 7500000.00, NULL, '2025-09-17'),
(31, 32, 2, 2000, 2500.00, 5000000.00, NULL, NULL),
(32, 33, 2, 2000, 2500.00, 5000000.00, NULL, NULL),
(33, 34, 2, 2000, 2500.00, 5000000.00, NULL, NULL),
(34, 35, 2, 2000, 2500.00, 5000000.00, NULL, NULL),
(35, 36, 2, 20000, 2500.00, 50000000.00, NULL, NULL),
(36, 37, 2, 555, 2500.00, 1387500.00, NULL, NULL),
(37, 38, 2, 555, 2500.00, 1387500.00, NULL, NULL),
(38, 39, 2, 555, 2500.00, 1387500.00, NULL, NULL),
(39, 40, 2, 555, 2500.00, 1387500.00, NULL, NULL),
(40, 41, 2, 555, 2500.00, 1387500.00, NULL, NULL);

-- --------------------------------------------------------

--
-- بنية الجدول `purchase_return_headers`
--

CREATE TABLE `purchase_return_headers` (
  `id` int(11) NOT NULL,
  `doc_date` date NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- إرجاع أو استيراد بيانات الجدول `purchase_return_headers`
--

INSERT INTO `purchase_return_headers` (`id`, `doc_date`, `supplier_id`, `note`, `total_amount`, `created_at`) VALUES
(1, '2025-08-29', 3, NULL, 600000.00, '2025-08-29 03:16:45'),
(2, '2025-08-29', 3, NULL, 200000.00, '2025-08-29 03:23:52'),
(3, '2025-08-29', 3, NULL, 200000.00, '2025-08-29 03:27:09'),
(4, '2025-08-29', 2, NULL, 30000000.00, '2025-08-29 03:57:11'),
(5, '2025-08-29', 2, NULL, 2500000.00, '2025-08-29 04:00:01'),
(6, '2025-08-29', 2, NULL, 2500000.00, '2025-08-29 04:04:48'),
(7, '2025-08-29', 2, NULL, 3000000.00, '2025-08-29 04:05:13'),
(8, '2025-08-29', 2, NULL, 3000000.00, '2025-08-29 04:08:41'),
(9, '2025-08-29', 2, NULL, 500000.00, '2025-08-29 04:08:55'),
(10, '2025-08-29', 2, NULL, 500000.00, '2025-08-29 04:13:03'),
(11, '2025-08-29', 2, NULL, 500000.00, '2025-08-29 04:13:11'),
(12, '2025-08-29', 2, NULL, 165000.00, '2025-08-29 04:13:21'),
(13, '2025-08-29', 2, NULL, 165000.00, '2025-08-29 04:15:16'),
(14, '2025-08-30', 3, NULL, 1250000.00, '2025-08-30 01:24:33'),
(15, '2025-08-30', 3, NULL, 5000.00, '2025-08-30 02:03:19'),
(16, '2025-08-30', 5, NULL, 555000.00, '2025-08-30 18:28:54'),
(17, '2025-08-30', 5, NULL, 555000.00, '2025-08-30 18:34:42');

-- --------------------------------------------------------

--
-- بنية الجدول `purchase_return_items`
--

CREATE TABLE `purchase_return_items` (
  `id` int(11) NOT NULL,
  `header_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `cost_price` decimal(14,2) NOT NULL,
  `line_total` decimal(14,2) NOT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- إرجاع أو استيراد بيانات الجدول `purchase_return_items`
--

INSERT INTO `purchase_return_items` (`id`, `header_id`, `product_id`, `quantity`, `cost_price`, `line_total`, `issue_date`, `expiry_date`) VALUES
(1, 1, 2, 300, 2000.00, 600000.00, NULL, NULL),
(2, 2, 2, 100, 2000.00, 200000.00, NULL, NULL),
(3, 3, 2, 100, 2000.00, 200000.00, NULL, NULL),
(4, 4, 2, 12000, 2500.00, 30000000.00, NULL, NULL),
(5, 5, 1, 1000, 2500.00, 2500000.00, NULL, NULL),
(6, 6, 1, 1000, 2500.00, 2500000.00, NULL, NULL),
(7, 7, 2, 1200, 2500.00, 3000000.00, NULL, NULL),
(8, 8, 2, 1200, 2500.00, 3000000.00, NULL, NULL),
(9, 9, 2, 200, 2500.00, 500000.00, NULL, NULL),
(10, 10, 2, 200, 2500.00, 500000.00, NULL, NULL),
(11, 11, 2, 200, 2500.00, 500000.00, NULL, NULL),
(12, 12, 2, 66, 2500.00, 165000.00, NULL, NULL),
(13, 13, 2, 66, 2500.00, 165000.00, NULL, NULL),
(14, 14, 2, 500, 2500.00, 1250000.00, '2025-08-01', '2025-09-30'),
(15, 15, 2, 2, 2500.00, 5000.00, NULL, '2025-08-30'),
(16, 16, 2, 222, 2500.00, 555000.00, NULL, NULL),
(17, 17, 2, 222, 2500.00, 555000.00, NULL, '2025-08-19');

-- --------------------------------------------------------

--
-- بنية الجدول `purchase_samples_headers`
--

CREATE TABLE `purchase_samples_headers` (
  `id` int(11) NOT NULL,
  `doc_date` date NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `purchase_samples_items`
--

CREATE TABLE `purchase_samples_items` (
  `id` int(11) NOT NULL,
  `header_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `cost_price` decimal(14,2) NOT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `receipts`
--

CREATE TABLE `receipts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `receipt_no` varchar(30) NOT NULL,
  `receipt_date` date NOT NULL,
  `partner_type` enum('customer','supplier') NOT NULL DEFAULT 'customer',
  `partner_id` bigint(20) UNSIGNED NOT NULL,
  `currency` char(3) NOT NULL DEFAULT 'YER',
  `fx_rate` decimal(18,6) NOT NULL DEFAULT 1.000000,
  `amount` decimal(18,2) NOT NULL,
  `discount` decimal(18,2) NOT NULL DEFAULT 0.00,
  `payment_method` varchar(20) NOT NULL,
  `bank_account_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reference_no` varchar(100) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `status` enum('draft','posted','void') NOT NULL DEFAULT 'draft',
  `posted` tinyint(1) NOT NULL DEFAULT 0,
  `journal_entry_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_by` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- إرجاع أو استيراد بيانات الجدول `receipts`
--

INSERT INTO `receipts` (`id`, `receipt_no`, `receipt_date`, `partner_type`, `partner_id`, `currency`, `fx_rate`, `amount`, `discount`, `payment_method`, `bank_account_id`, `reference_no`, `notes`, `status`, `posted`, `journal_entry_id`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'RC-0001', '2025-08-30', 'customer', 1, 'YER', 1.000000, 10000.00, 0.00, 'cash', NULL, NULL, NULL, 'draft', 1, NULL, NULL, '2025-08-30 05:44:09', '2025-08-30 21:37:57');

-- --------------------------------------------------------

--
-- بنية الجدول `receipt_vouchers`
--

CREATE TABLE `receipt_vouchers` (
  `id` int(11) NOT NULL,
  `doc_date` date NOT NULL,
  `payer` varchar(150) NOT NULL,
  `amount` decimal(14,2) NOT NULL,
  `method` enum('cash','bank') NOT NULL DEFAULT 'cash',
  `note` varchar(255) DEFAULT NULL,
  `posted` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- إرجاع أو استيراد بيانات الجدول `receipt_vouchers`
--

INSERT INTO `receipt_vouchers` (`id`, `doc_date`, `payer`, `amount`, `method`, `note`, `posted`, `created_at`) VALUES
(1, '2025-08-30', 'خليفه', 20000.00, 'cash', NULL, 0, '2025-08-30 17:48:36');

-- --------------------------------------------------------

--
-- بنية الجدول `sales_invoices`
--

CREATE TABLE `sales_invoices` (
  `id` int(11) NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `invoice_date` date NOT NULL,
  `supplier_id` int(11) DEFAULT NULL,
  `customer_name` varchar(150) DEFAULT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `reconciled` tinyint(1) NOT NULL DEFAULT 0,
  `total` decimal(14,2) NOT NULL DEFAULT 0.00,
  `posted` tinyint(1) NOT NULL DEFAULT 0,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `sales_invoices`
--

INSERT INTO `sales_invoices` (`id`, `customer_id`, `note`, `invoice_date`, `supplier_id`, `customer_name`, `total_amount`, `reconciled`, `total`, `posted`, `created_by`, `created_at`) VALUES
(1, 1, NULL, '2025-08-29', NULL, NULL, 0.00, 0, 2100.00, 1, 1, '2025-08-28 21:57:02'),
(2, NULL, NULL, '2025-08-29', NULL, NULL, 4500000.00, 0, 0.00, 0, NULL, '2025-08-29 02:52:38'),
(3, NULL, NULL, '2025-08-29', 3, NULL, 1200000.00, 1, 0.00, 0, NULL, '2025-08-29 02:58:29');

-- --------------------------------------------------------

--
-- بنية الجدول `sales_invoice_items`
--

CREATE TABLE `sales_invoice_items` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(14,2) NOT NULL,
  `total` decimal(14,2) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `sales_invoice_items`
--

INSERT INTO `sales_invoice_items` (`id`, `invoice_id`, `product_id`, `quantity`, `price`, `total`) VALUES
(1, 1, 1, 7, 300.00, 2100.00);

-- --------------------------------------------------------

--
-- بنية الجدول `sales_items`
--

CREATE TABLE `sales_items` (
  `id` int(11) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `sale_price` decimal(14,2) NOT NULL DEFAULT 0.00,
  `price` decimal(14,2) NOT NULL,
  `line_total` decimal(14,2) NOT NULL,
  `issue_date` date DEFAULT NULL,
  `expiry_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- إرجاع أو استيراد بيانات الجدول `sales_items`
--

INSERT INTO `sales_items` (`id`, `invoice_id`, `product_id`, `quantity`, `sale_price`, `price`, `line_total`, `issue_date`, `expiry_date`) VALUES
(1, 2, 2, 1500, 0.00, 3000.00, 4500000.00, NULL, NULL),
(2, 3, 2, 400, 0.00, 3000.00, 1200000.00, NULL, NULL);

-- --------------------------------------------------------

--
-- بنية الجدول `sales_return_headers`
--

CREATE TABLE `sales_return_headers` (
  `id` int(11) NOT NULL,
  `doc_date` date NOT NULL,
  `supplier_id` int(11) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `total_amount` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `sales_return_items`
--

CREATE TABLE `sales_return_items` (
  `id` int(11) NOT NULL,
  `header_id` int(11) NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `price` decimal(14,2) NOT NULL,
  `cost` decimal(14,2) NOT NULL,
  `line_total` decimal(14,2) NOT NULL,
  `expiry_date` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `stock_issues`
--

CREATE TABLE `stock_issues` (
  `id` int(11) NOT NULL,
  `doc_date` date NOT NULL,
  `product_id` int(11) NOT NULL,
  `quantity` int(11) NOT NULL,
  `reason` varchar(150) DEFAULT NULL,
  `note` varchar(255) DEFAULT NULL,
  `total_cost` decimal(14,2) NOT NULL DEFAULT 0.00,
  `expiry_date` date DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- إرجاع أو استيراد بيانات الجدول `stock_issues`
--

INSERT INTO `stock_issues` (`id`, `doc_date`, `product_id`, `quantity`, `reason`, `note`, `total_cost`, `expiry_date`, `created_at`) VALUES
(1, '2025-08-30', 2, 2000, NULL, NULL, 5000000.00, '2026-05-28', '2025-08-30 01:41:30');

-- --------------------------------------------------------

--
-- بنية الجدول `suppliers`
--

CREATE TABLE `suppliers` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `opening_balance` decimal(14,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `suppliers`
--

INSERT INTO `suppliers` (`id`, `name`, `phone`, `email`, `address`, `opening_balance`, `created_at`) VALUES
(1, 'مورد تجريبي', '777777777', NULL, NULL, 0.00, '2025-08-28 21:46:41'),
(2, 'بةل', '589834758964', NULL, NULL, 222222222222.00, '2025-08-29 01:55:29'),
(3, 'انا خليفه', '589834758964', NULL, NULL, 70000000000.00, '2025-08-29 01:55:49'),
(4, 'رامز', '7566666666', NULL, NULL, 200000000.00, '2025-08-29 19:23:26'),
(5, 'خليفه طاهر المروني', '0739435396', NULL, 'taiv\r\nggyg', 50000000.00, '2025-08-30 18:01:27'),
(6, 'خليفه طاهر', '0739435396', NULL, 'taiv\r\nggyg', 999999999999.99, '2025-08-30 18:01:45');

-- --------------------------------------------------------

--
-- بنية الجدول `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('admin','accountant','manager','cashier','user') NOT NULL DEFAULT 'user',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- إرجاع أو استيراد بيانات الجدول `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password_hash`, `role`, `is_active`, `created_at`) VALUES
(1, 'admin', 'admin@example.com', '$2y$10$ZgROr2mHcz5CqV.CK1xyLeGQy8zMryBPXVv0F0Eft/2pFqLxKNR7K', 'admin', 1, '2025-08-28 21:46:41'),
(4, 'ww', NULL, '$2y$10$jxSlYeBfTmAfhKbo22F2YelCdudF.dhDpm9lBfOL8su9Duyh7YTd6', 'user', 1, '2025-08-29 01:34:50'),
(5, 'www', NULL, '$2y$10$8FQhRBHmmvJP8E8mVXN04ORkdEg.6wAZMGUszI/Sz4w2XAuzMRW1S', 'user', 1, '2025-08-30 09:19:34');

-- --------------------------------------------------------

--
-- بنية الجدول `v_stock_batches`
--

CREATE TABLE `v_stock_batches` (
  `batch_id` int(11) DEFAULT NULL,
  `product_id` int(11) DEFAULT NULL,
  `product_name` varchar(150) DEFAULT NULL,
  `batch_code` varchar(64) DEFAULT NULL,
  `expiry` date DEFAULT NULL,
  `qty` int(11) DEFAULT NULL,
  `cost` decimal(14,2) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- بنية الجدول `v_stock_products`
--

CREATE TABLE `v_stock_products` (
  `product_id` int(11) DEFAULT NULL,
  `name` varchar(150) DEFAULT NULL,
  `qty_on_hand` decimal(32,0) DEFAULT NULL,
  `nearest_expiry` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `accounts`
--
ALTER TABLE `accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD UNIQUE KEY `code_2` (`code`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `adjustment_entries`
--
ALTER TABLE `adjustment_entries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `audit_trail`
--
ALTER TABLE `audit_trail`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_prod` (`product_id`),
  ADD KEY `ix_ref` (`ref_table`,`ref_id`),
  ADD KEY `batch_id` (`batch_id`);

--
-- Indexes for table `journal_details`
--
ALTER TABLE `journal_details`
  ADD PRIMARY KEY (`id`),
  ADD KEY `ix_journal_lines` (`journal_id`,`account_id`),
  ADD KEY `idx_journal_details_entry` (`entry_id`),
  ADD KEY `idx_journal_details_account` (`account`),
  ADD KEY `idx_journal_details_account_id` (`account_id`),
  ADD KEY `idx_journal_id` (`journal_id`),
  ADD KEY `idx_account_id` (`account_id`);

--
-- Indexes for table `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `ix_journal_date` (`entry_date`),
  ADD KEY `idx_journal_entries_doc` (`doc_type`,`doc_id`),
  ADD KEY `idx_journal_entries_date` (`doc_date`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `idx_event_type` (`event_type`),
  ADD KEY `idx_created_at` (`created_at`),
  ADD KEY `idx_read` (`read_at`);

--
-- Indexes for table `payment_methods`
--
ALTER TABLE `payment_methods`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `method_name` (`method_name`);

--
-- Indexes for table `payment_vouchers`
--
ALTER TABLE `payment_vouchers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `products`
--
ALTER TABLE `products`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `barcode` (`barcode`),
  ADD UNIQUE KEY `sku` (`sku`),
  ADD KEY `ix_products_name` (`name`),
  ADD KEY `idx_products_barcode` (`barcode`);

--
-- Indexes for table `product_batches`
--
ALTER TABLE `product_batches`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_prod_batch` (`product_id`,`batch_code`,`expiry`),
  ADD KEY `ix_expiry` (`expiry`),
  ADD KEY `idx_product_batches_product_id` (`product_id`);

--
-- Indexes for table `purchase_invoices`
--
ALTER TABLE `purchase_invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `ix_purchase_date` (`invoice_date`),
  ADD KEY `idx_purchase_supplier_id` (`supplier_id`);

--
-- Indexes for table `purchase_invoice_items`
--
ALTER TABLE `purchase_invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `ix_purchase_item_invoice` (`invoice_id`);

--
-- Indexes for table `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_id` (`invoice_id`);

--
-- Indexes for table `purchase_return_headers`
--
ALTER TABLE `purchase_return_headers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `purchase_return_items`
--
ALTER TABLE `purchase_return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `header_id` (`header_id`);

--
-- Indexes for table `purchase_samples_headers`
--
ALTER TABLE `purchase_samples_headers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `purchase_samples_items`
--
ALTER TABLE `purchase_samples_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `header_id` (`header_id`);

--
-- Indexes for table `receipts`
--
ALTER TABLE `receipts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `receipt_no` (`receipt_no`),
  ADD KEY `idx_date` (`receipt_date`),
  ADD KEY `idx_partner` (`partner_type`,`partner_id`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_posted` (`posted`);

--
-- Indexes for table `receipt_vouchers`
--
ALTER TABLE `receipt_vouchers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sales_invoices`
--
ALTER TABLE `sales_invoices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `created_by` (`created_by`),
  ADD KEY `ix_sales_date` (`invoice_date`),
  ADD KEY `idx_sales_customer_id` (`customer_id`);

--
-- Indexes for table `sales_invoice_items`
--
ALTER TABLE `sales_invoice_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `product_id` (`product_id`),
  ADD KEY `ix_sales_item_invoice` (`invoice_id`);

--
-- Indexes for table `sales_items`
--
ALTER TABLE `sales_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `invoice_id` (`invoice_id`),
  ADD KEY `product_id` (`product_id`);

--
-- Indexes for table `sales_return_headers`
--
ALTER TABLE `sales_return_headers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `sales_return_items`
--
ALTER TABLE `sales_return_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `header_id` (`header_id`);

--
-- Indexes for table `stock_issues`
--
ALTER TABLE `stock_issues`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `suppliers`
--
ALTER TABLE `suppliers`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `accounts`
--
ALTER TABLE `accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=32;

--
-- AUTO_INCREMENT for table `adjustment_entries`
--
ALTER TABLE `adjustment_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_trail`
--
ALTER TABLE `audit_trail`
  MODIFY `id` bigint(20) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `inventory_movements`
--
ALTER TABLE `inventory_movements`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `journal_details`
--
ALTER TABLE `journal_details`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=88;

--
-- AUTO_INCREMENT for table `journal_entries`
--
ALTER TABLE `journal_entries`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=51;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `payment_methods`
--
ALTER TABLE `payment_methods`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `payment_vouchers`
--
ALTER TABLE `payment_vouchers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `products`
--
ALTER TABLE `products`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `product_batches`
--
ALTER TABLE `product_batches`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `purchase_invoices`
--
ALTER TABLE `purchase_invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=42;

--
-- AUTO_INCREMENT for table `purchase_invoice_items`
--
ALTER TABLE `purchase_invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `purchase_items`
--
ALTER TABLE `purchase_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=41;

--
-- AUTO_INCREMENT for table `purchase_return_headers`
--
ALTER TABLE `purchase_return_headers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `purchase_return_items`
--
ALTER TABLE `purchase_return_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `purchase_samples_headers`
--
ALTER TABLE `purchase_samples_headers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `purchase_samples_items`
--
ALTER TABLE `purchase_samples_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `receipts`
--
ALTER TABLE `receipts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `receipt_vouchers`
--
ALTER TABLE `receipt_vouchers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sales_invoices`
--
ALTER TABLE `sales_invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `sales_invoice_items`
--
ALTER TABLE `sales_invoice_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `sales_items`
--
ALTER TABLE `sales_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `sales_return_headers`
--
ALTER TABLE `sales_return_headers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `sales_return_items`
--
ALTER TABLE `sales_return_items`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `stock_issues`
--
ALTER TABLE `stock_issues`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `suppliers`
--
ALTER TABLE `suppliers`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- قيود الجداول المُلقاة.
--

--
-- قيود الجداول `accounts`
--
ALTER TABLE `accounts`
  ADD CONSTRAINT `accounts_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- قيود الجداول `audit_trail`
--
ALTER TABLE `audit_trail`
  ADD CONSTRAINT `audit_trail_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`);

--
-- قيود الجداول `chart_of_accounts`
--
ALTER TABLE `chart_of_accounts`
  ADD CONSTRAINT `chart_of_accounts_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `chart_of_accounts` (`id`);

--
-- قيود الجداول `inventory_movements`
--
ALTER TABLE `inventory_movements`
  ADD CONSTRAINT `inventory_movements_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `inventory_movements_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `product_batches` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- قيود الجداول `journal_details`
--
ALTER TABLE `journal_details`
  ADD CONSTRAINT `fk_jd_account_ok1` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_jd_journal` FOREIGN KEY (`journal_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_jd_journal_1` FOREIGN KEY (`journal_id`) REFERENCES `journal_entries` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `journal_details_ibfk_2` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON UPDATE CASCADE;

--
-- قيود الجداول `journal_entries`
--
ALTER TABLE `journal_entries`
  ADD CONSTRAINT `journal_entries_ibfk_1` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- قيود الجداول `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `notifications_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- قيود الجداول `product_batches`
--
ALTER TABLE `product_batches`
  ADD CONSTRAINT `product_batches_ibfk_1` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- قيود الجداول `purchase_invoices`
--
ALTER TABLE `purchase_invoices`
  ADD CONSTRAINT `purchase_invoices_ibfk_1` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `purchase_invoices_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- قيود الجداول `purchase_invoice_items`
--
ALTER TABLE `purchase_invoice_items`
  ADD CONSTRAINT `purchase_invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `purchase_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `purchase_invoice_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE;

--
-- قيود الجداول `purchase_items`
--
ALTER TABLE `purchase_items`
  ADD CONSTRAINT `purchase_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `purchase_invoices` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `purchase_return_items`
--
ALTER TABLE `purchase_return_items`
  ADD CONSTRAINT `purchase_return_items_ibfk_1` FOREIGN KEY (`header_id`) REFERENCES `purchase_return_headers` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `purchase_samples_items`
--
ALTER TABLE `purchase_samples_items`
  ADD CONSTRAINT `purchase_samples_items_ibfk_1` FOREIGN KEY (`header_id`) REFERENCES `purchase_samples_headers` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `sales_invoices`
--
ALTER TABLE `sales_invoices`
  ADD CONSTRAINT `sales_invoices_ibfk_1` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `sales_invoices_ibfk_2` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- قيود الجداول `sales_invoice_items`
--
ALTER TABLE `sales_invoice_items`
  ADD CONSTRAINT `sales_invoice_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `sales_invoice_items_ibfk_2` FOREIGN KEY (`product_id`) REFERENCES `products` (`id`) ON UPDATE CASCADE;

--
-- قيود الجداول `sales_items`
--
ALTER TABLE `sales_items`
  ADD CONSTRAINT `sales_items_ibfk_1` FOREIGN KEY (`invoice_id`) REFERENCES `sales_invoices` (`id`) ON DELETE CASCADE;

--
-- قيود الجداول `sales_return_items`
--
ALTER TABLE `sales_return_items`
  ADD CONSTRAINT `sales_return_items_ibfk_1` FOREIGN KEY (`header_id`) REFERENCES `sales_return_headers` (`id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
