-- ============================================================
-- 外贸业务工作台 MySQL 数据库初始化 SQL
-- 用途: 在小皮面板 phpMyAdmin 中执行，创建所有数据表
-- 执行方式: 打开 phpMyAdmin → 新建数据库 trade_workbench → 导入本文件
-- 说明: 所有表合并到一个 MySQL 数据库，替代原 Supabase 三库架构
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ========== 1. users 表：业务员账号库 ==========
-- username 唯一；密码由 PHP password_hash() 服务端哈希（bcrypt）
-- pass_salt 已废弃（保留列兼容旧表），pass_hash 使用 VARCHAR(255) 满足 PHP 官方建议
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `username` VARCHAR(50) UNIQUE NOT NULL,
  `pass_salt` VARCHAR(100) NOT NULL DEFAULT '',
  `pass_hash` VARCHAR(255) NOT NULL,
  `is_admin` TINYINT(1) DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== 2. customers 表：中央客户表 ==========
-- owner 记录客户归属的业务员
CREATE TABLE IF NOT EXISTS `customers` (
  `uid` VARCHAR(50) PRIMARY KEY,
  `owner` VARCHAR(50) DEFAULT '',
  `company_name` VARCHAR(255) DEFAULT '',
  `contact_person` VARCHAR(100) DEFAULT '',
  `phone` VARCHAR(50) DEFAULT '',
  `email` VARCHAR(100) DEFAULT '',
  `country` VARCHAR(50) DEFAULT '',
  `source` VARCHAR(50) DEFAULT '',
  `status` VARCHAR(20) DEFAULT '',
  `notes` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- 公司名唯一索引（大小写不敏感 + 忽略首尾空格）
CREATE UNIQUE INDEX IF NOT EXISTS `customers_company_unique`
  ON `customers` (LOWER(TRIM(IFNULL(`company_name`, ''))))
  WHERE TRIM(IFNULL(`company_name`, '')) != '';

-- ========== 3. samples 表：车手档案 ==========
CREATE TABLE IF NOT EXISTS `samples` (
  `uid` VARCHAR(50) PRIMARY KEY,
  `owner` VARCHAR(50) DEFAULT '',
  `name` VARCHAR(100) DEFAULT '',
  `type` VARCHAR(50) DEFAULT '',
  `address` TEXT,
  `phone` VARCHAR(50) DEFAULT '',
  `email` VARCHAR(100) DEFAULT '',
  `country` VARCHAR(50) DEFAULT '',
  `cooperation_status` VARCHAR(20) DEFAULT '合作中',
  `notes` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== 4. sample_shipments 表：寄样记录 ==========
CREATE TABLE IF NOT EXISTS `sample_shipments` (
  `uid` VARCHAR(50) PRIMARY KEY,
  `sample_id` VARCHAR(50) DEFAULT '',
  `owner` VARCHAR(50) DEFAULT '',
  `ship_date` DATE,
  `product_name` VARCHAR(255) DEFAULT '',
  `product_model` VARCHAR(100) DEFAULT '',
  `quantity` DECIMAL(10,2) DEFAULT 0,
  `reason` TEXT,
  `shipping_channel` VARCHAR(100) DEFAULT '',
  `tracking_no` VARCHAR(100) DEFAULT '',
  `ship_status` VARCHAR(20) DEFAULT '未寄出',
  `receipt_status` VARCHAR(20) DEFAULT '未签收',
  `test_status` VARCHAR(20) DEFAULT '未测试',
  `test_feedback` TEXT,
  `feedback_status` VARCHAR(20) DEFAULT '未反馈',
  `feedback_channel` VARCHAR(50) DEFAULT '',
  `notes` TEXT,
  `items` LONGTEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE INDEX IF NOT EXISTS `idx_shipments_sample_id` ON `sample_shipments` (`sample_id`);

-- ========== 5. product 表：产品库 ==========
CREATE TABLE IF NOT EXISTS `product` (
  `uid` VARCHAR(50) PRIMARY KEY,
  `owner` VARCHAR(50) DEFAULT '',
  `name` VARCHAR(255) NOT NULL,
  `model` VARCHAR(100) DEFAULT '',
  `category` VARCHAR(50) DEFAULT 'OTHER',
  `params` TEXT,
  `price` DECIMAL(10,2) DEFAULT 0,
  `image` LONGTEXT,
  `notes` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== 6. orders 表：订单管理 ==========
-- owner 记录订单归属的业务员（数据隔离用）
CREATE TABLE IF NOT EXISTS `orders` (
  `uid` VARCHAR(50) PRIMARY KEY,
  `owner` VARCHAR(50) DEFAULT '',
  `order_no` VARCHAR(50) DEFAULT '',
  `customer_name` VARCHAR(255) DEFAULT '',
  `product` TEXT,
  `amount` DECIMAL(12,2) DEFAULT 0,
  `currency` VARCHAR(10) DEFAULT 'USD',
  `status` VARCHAR(20) DEFAULT '待处理',
  `order_date` DATE,
  `shipping_channel` VARCHAR(100) DEFAULT '',
  `tracking_url` TEXT,
  `ship_date` DATE,
  `notes` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== 7. todos 表：待办任务 ==========
CREATE TABLE IF NOT EXISTS `todos` (
  `uid` VARCHAR(50) PRIMARY KEY,
  `title` VARCHAR(255) DEFAULT '',
  `related_customer` VARCHAR(255) DEFAULT '',
  `priority` VARCHAR(10) DEFAULT 'P1',
  `due_date` DATE,
  `status` VARCHAR(20) DEFAULT '待办',
  `notes` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== 8. letters 表：开发信 ==========
CREATE TABLE IF NOT EXISTS `letters` (
  `uid` VARCHAR(50) PRIMARY KEY,
  `title` VARCHAR(255) DEFAULT '',
  `subject` VARCHAR(255) DEFAULT '',
  `tags` VARCHAR(255) DEFAULT '',
  `content` LONGTEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== 9. socials 表：社媒文案 ==========
CREATE TABLE IF NOT EXISTS `socials` (
  `uid` VARCHAR(50) PRIMARY KEY,
  `title` VARCHAR(255) DEFAULT '',
  `platform` VARCHAR(50) DEFAULT '',
  `tags` VARCHAR(255) DEFAULT '',
  `content` LONGTEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== 10. quotes 表：报价单 ==========
CREATE TABLE IF NOT EXISTS `quotes` (
  `uid` VARCHAR(50) PRIMARY KEY,
  `owner` VARCHAR(50) DEFAULT '',
  `quote_no` VARCHAR(50) DEFAULT '',
  `customer_name` VARCHAR(255) DEFAULT '',
  `contact` VARCHAR(100) DEFAULT '',
  `date` DATE,
  `valid_until` DATE,
  `currency` VARCHAR(10) DEFAULT 'USD',
  `items` LONGTEXT,
  `subtotal` DECIMAL(12,2) DEFAULT 0,
  `total` DECIMAL(12,2) DEFAULT 0,
  `status` VARCHAR(20) DEFAULT '草稿',
  `notes` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== 11. performance 表：业绩汇总（按业务员隔离） ==========
CREATE TABLE IF NOT EXISTS `performance` (
  `uid` VARCHAR(50) PRIMARY KEY,
  `owner` VARCHAR(50) NOT NULL DEFAULT '',
  `month` VARCHAR(20) DEFAULT '',
  `customer_name` VARCHAR(255) DEFAULT '',
  `contact` VARCHAR(255) DEFAULT '',
  `order_no` VARCHAR(100) DEFAULT '',
  `product_model` VARCHAR(255) DEFAULT '',
  `product_name` VARCHAR(255) DEFAULT '',
  `estimated_ship_date` DATE DEFAULT NULL,
  `unit_price` DECIMAL(15,4) DEFAULT 0,
  `currency` VARCHAR(20) DEFAULT 'USD',
  `exchange_rate` DECIMAL(12,6) DEFAULT 0,
  `cny_equivalent` DECIMAL(15,4) DEFAULT 0,
  `tax_excluded_price` DECIMAL(15,4) DEFAULT 0,
  `shipping_other` DECIMAL(15,4) DEFAULT 0,
  `order_amount` DECIMAL(15,4) DEFAULT 0,
  `payment1` DECIMAL(15,4) DEFAULT 0,
  `payment2` DECIMAL(15,4) DEFAULT 0,
  `payment_total` DECIMAL(15,4) DEFAULT 0,
  `unpaid` DECIMAL(15,4) DEFAULT 0,
  `actual_ship_date` DATE DEFAULT NULL,
  `ship_method` VARCHAR(100) DEFAULT '',
  `ship_address` TEXT,
  `ship_fee` DECIMAL(15,4) DEFAULT 0,
  `quote_details` TEXT,
  `country` VARCHAR(100) DEFAULT '',
  `receiving_account` VARCHAR(255) DEFAULT '',
  `commission_rate` DECIMAL(8,4) DEFAULT 0,
  `commission_amount` DECIMAL(15,4) DEFAULT 0,
  `notes` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY `idx_owner` (`owner`),
  KEY `idx_month` (`month`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== 12. commission_rates 表：业务员提成率（个人隐私，按 owner 隔离） ==========
CREATE TABLE IF NOT EXISTS `commission_rates` (
  `uid` VARCHAR(50) PRIMARY KEY,
  `owner` VARCHAR(50) NOT NULL DEFAULT '',
  `rate` DECIMAL(8,4) DEFAULT 0,
  `notes` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_owner` (`owner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ========== 13. exchange_rates 表：汇率缓存 ==========
CREATE TABLE IF NOT EXISTS `exchange_rates` (
  `code` VARCHAR(10) PRIMARY KEY,
  `rate` DECIMAL(12,6) DEFAULT 0,
  `source` VARCHAR(50) DEFAULT '',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;
