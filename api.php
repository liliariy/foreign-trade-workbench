<?php
/**
 * 外贸业务工作台 - PHP API 层
 * 替代 Supabase REST API，处理所有前端 CRUD 操作
 *
 * 接口列表:
 *   GET    api.php?action=list&table={table}&owner={owner}           列出记录
 *   POST   api.php?action=upsert&table={table}                       批量 upsert (body: JSON array)
 *   DELETE api.php?action=delete&table={table}&uid={uid}              删除单条
 *   GET    api.php?action=user-get&username={username}                查询用户(不含密码)
 *   POST   api.php?action=user-login                                  用户登录 (body: {username,password})
 *   POST   api.php?action=user-create                                  创建用户 (body: {username,password})
 *   PATCH  api.php?action=user-update&username={username}              更新用户 (body: {password,...})
 *   GET    api.php?action=dup-check&name={name}&email={email}          客户查重
 *   GET    api.php?action=probe                                        健康检查
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PATCH, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, apikey, Authorization, Prefer');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/* ========== 数据库连接 ========== */
function db() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            json_error(500, '数据库连接失败: ' . $e->getMessage());
        }
    }
    return $pdo;
}

/* ========== 工具函数 ========== */
function json_success($data) {
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error($code, $msg) {
    http_response_code($code);
    echo json_encode(['error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_param($key, $default = '') {
    return isset($_GET[$key]) ? $_GET[$key] : $default;
}

function get_body() {
    $raw = file_get_contents('php://input');
    if (!$raw) return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/* 表名映射: 前端逻辑表名 → MySQL 物理表名 */
function resolve_table($name) {
    $map = [
        'customers' => 'customers',
        'samples' => 'samples',
        'shipments' => 'sample_shipments',
        'products' => 'product',
        'orders' => 'orders',
        'todos' => 'todos',
        'letters' => 'letters',
        'socials' => 'socials',
        'quotes' => 'quotes',
        'performance' => 'performance',
        'commission_rates' => 'commission_rates',
        'exchange_rates' => 'exchange_rates',
    ];
    return isset($map[$name]) ? $map[$name] : $name;
}

/* 列定义: 每张表的所有业务字段（不含 uid/created_at/updated_at） */
function get_columns($table) {
    $cols = [
        'customers' => ['owner','company_name','contact_person','phone','email','country','source','status','notes'],
        'samples' => ['owner','name','type','address','phone','email','country','cooperation_status','notes'],
        'sample_shipments' => ['sample_id','owner','ship_date','product_name','product_model','quantity','reason','shipping_channel','tracking_no','ship_status','receipt_status','test_status','test_feedback','feedback_status','feedback_channel','notes','items'],
        'product' => ['owner','name','model','category','params','price','image','notes'],
        'orders' => ['owner','order_no','customer_name','product','amount','shipping_other','total_amount','currency','status','order_date','shipping_channel','tracking_url','ship_date','notes'],
        'todos' => ['title','related_customer','priority','due_date','status','notes'],
        'letters' => ['title','subject','tags','content'],
        'socials' => ['title','platform','tags','content'],
        'quotes' => ['owner','quote_no','customer_name','contact','date','valid_until','currency','items','subtotal','total','status','notes'],
        'performance' => ['owner','order_uid','month','customer_name','contact','order_no','product_model','product_name','estimated_ship_date','unit_price','currency','exchange_rate','cny_equivalent','tax_excluded_price','shipping_other','order_amount','payment1','payment2','payment_total','unpaid','actual_ship_date','ship_method','ship_address','ship_fee','quote_details','country','receiving_account','commission_rate','commission_amount','notes'],
        'commission_rates' => ['owner','rate','notes'],
        'exchange_rates' => ['code','rate','source'],
        'users' => ['username','pass_salt','pass_hash','is_admin'],
    ];
    return isset($cols[$table]) ? $cols[$table] : null;
}

/* JSON 列: 需要序列化/反序列化处理的列 */
function get_json_columns($table) {
    $json_cols = [
        'sample_shipments' => ['items'],
        'quotes' => ['items'],
    ];
    return isset($json_cols[$table]) ? $json_cols[$table] : [];
}

/* ========== 数据隔离迁移（幂等，可重复执行） ========== */
/* 自动为 orders 表补 owner 列（首次访问时执行一次，之后用标记文件跳过） */
function ensureOwnerColumn() {
    static $done = false;
    if ($done) return;
    $flag = __DIR__ . '/.owner_migrated';
    if (is_file($flag)) { $done = true; return; }
    $ok = false;
    try {
        db()->exec("ALTER TABLE `orders` ADD COLUMN `owner` VARCHAR(50) NOT NULL DEFAULT '' AFTER `uid`");
        $ok = true;
    } catch (PDOException $e) {
        // 1060 = 字段已存在，视为成功
        if ($e->getCode() === '1060' || stripos($e->getMessage(), 'Duplicate column') !== false) {
            $ok = true;
        }
    }
    if ($ok) @file_put_contents($flag, '1');
    $done = true;
}

/* 自动为 orders 表补 shipping_other / total_amount 列（幂等，可重复执行） */
function ensureOrdersColumns() {
    static $done = false;
    if ($done) return;
    $flag = __DIR__ . '/.orders_cols_migrated';
    if (is_file($flag)) { $done = true; return; }
    $cols = [
        'shipping_other' => "ALTER TABLE `orders` ADD COLUMN `shipping_other` DECIMAL(15,4) DEFAULT 0 AFTER `amount`",
        'total_amount'   => "ALTER TABLE `orders` ADD COLUMN `total_amount` DECIMAL(15,4) DEFAULT 0 AFTER `shipping_other`",
    ];
    foreach ($cols as $sql) {
        try {
            db()->exec($sql);
        } catch (PDOException $e) {
            // 1060 = 字段已存在，视为成功；其它错误忽略，不阻塞请求
        }
    }
    @file_put_contents($flag, '1');
    $done = true;
}

/* 自动建表：业绩汇总 / 提成率 / 汇率缓存（幂等） */
function ensureTables() {
    static $done = false;
    if ($done) return;
    $flag = __DIR__ . '/.tables_initialized';
    if (is_file($flag)) { $done = true; return; }

    $sql = "
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

CREATE TABLE IF NOT EXISTS `commission_rates` (
  `uid` VARCHAR(50) PRIMARY KEY,
  `owner` VARCHAR(50) NOT NULL DEFAULT '',
  `rate` DECIMAL(8,4) DEFAULT 0,
  `notes` TEXT,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uk_owner` (`owner`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS `exchange_rates` (
  `code` VARCHAR(10) PRIMARY KEY,
  `rate` DECIMAL(12,6) DEFAULT 0,
  `source` VARCHAR(50) DEFAULT '',
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ";

    try {
        db()->exec($sql);
        @file_put_contents($flag, '1');
    } catch (PDOException $e) {
        // 忽略已存在错误，不阻塞请求
    }
    $done = true;
}

/* 迁移接口：补列 + 将历史空 owner 记录回填到指定主账号
   用法: api.php?action=migrate&owner=user1  （owner 缺省 user1）
   仅客户/订单/报价单三个隔离表回填 */
function action_migrate() {
    ensureOwnerColumn();
    $owner = preg_replace('/[^A-Za-z0-9_\-]/', '', get_param('owner', 'user1'));
    $affected = [];
    foreach (['customers', 'orders', 'quotes'] as $t) {
        $phys = resolve_table($t);
        $n = db()->exec("UPDATE `$phys` SET `owner` = '$owner' WHERE `owner` IS NULL OR `owner` = ''");
        $affected[$t] = $n;
    }
    json_success(['status' => 'ok', 'owner' => $owner, 'affected' => $affected]);
}

/* ========== 接口实现 ========== */

/* 1. 列出记录 */
function action_list() {
    $table = resolve_table(get_param('table'));
    $owner = get_param('owner', '');
    $cols = get_columns($table);
    if (!$cols) json_error(400, '未知表: ' . $table);

    $allCols = array_merge(['uid'], $cols, ['created_at', 'updated_at']);
    $select = implode(', ', array_map(function($c) { return "`$c`"; }, $allCols));

    $sql = "SELECT $select FROM `$table`";
    $params = [];

    // owner 过滤（普通业务员只看自己归属的记录；管理员 is_admin 不过滤，看全部）
    // 隔离白名单：客户 / 订单 / 报价单 / 业绩汇总 / 提成配置 按业务员独立
    if ($owner !== '' && in_array($table, ['customers','orders','quotes','performance','commission_rates'], true)) {
        $sql .= " WHERE `owner` = ?";
        $params[] = $owner;
    }

    $sql .= " ORDER BY `created_at` DESC LIMIT 5000";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    // JSON 列反序列化
    $jsonCols = get_json_columns($table);
    if ($jsonCols) {
        foreach ($rows as &$r) {
            foreach ($jsonCols as $jc) {
                if (isset($r[$jc]) && $r[$jc] !== '' && $r[$jc] !== null) {
                    $decoded = json_decode($r[$jc], true);
                    $r[$jc] = $decoded !== null ? $decoded : [];
                } else {
                    $r[$jc] = [];
                }
            }
        }
    }

    json_success($rows);
}

/* 判断列是否为日期/时间列 */
function is_date_column($col) {
    return in_array($col, ['created_at','updated_at','order_date','ship_date','due_date','date','valid_until'], true);
}

/* 将 ISO 8601 / JS Date.toISOString() / 空字符串 转成 MySQL 可接受的 DATE/DATETIME 格式
 * 空字符串统一转 NULL（MySQL DATE/DATETIME 严格模式不接受 ''）
 * DATE 列返回 Y-m-d；DATETIME 列返回 Y-m-d H:i:s
 */
function normalize_date_value($val, $col) {
    if ($val === null || $val === '') return null;
    // 已经是 MySQL 格式
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) return $val;
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $val)) return $val;
    // 尝试解析
    $ts = strtotime($val);
    if ($ts === false) return $val;
    // DATE 列
    if (preg_match('/_date$/', $col) || $col === 'date' || $col === 'valid_until') {
        return date('Y-m-d', $ts);
    }
    // DATETIME 列 (created_at / updated_at)
    return date('Y-m-d H:i:s', $ts);
}

/* 2. 批量 upsert (INSERT ON DUPLICATE KEY UPDATE) */
function action_upsert() {
    $table = resolve_table(get_param('table'));
    $cols = get_columns($table);
    if (!$cols) json_error(400, '未知表: ' . $table);

    $records = get_body();
    if (empty($records)) json_success([]);

    $allCols = array_merge(['uid'], $cols, ['created_at', 'updated_at']);
    $jsonCols = get_json_columns($table);

    $results = [];
    foreach ($records as $rec) {
    if (empty($rec['uid'])) continue;

        $placeholders = [];
        $values = [];
        foreach ($allCols as $col) {
            $placeholders[] = '?';
            $val = isset($rec[$col]) ? $rec[$col] : null;

            // JSON 列序列化
            if (in_array($col, $jsonCols) && $val !== null && !is_string($val)) {
                $val = json_encode($val, JSON_UNESCAPED_UNICODE);
            }
            if (in_array($col, $jsonCols) && is_string($val) && $val !== '' && $val[0] === '[' ) {
                // 已经是 JSON 字符串，保持
            }

            // 日期时间列统一处理：空字符串转 NULL，ISO 8601 转 MySQL DATE/DATETIME 格式
            if (is_date_column($col)) {
                $val = normalize_date_value($val, $col);
            }

            $values[] = $val;
        }

        // ON DUPLICATE KEY UPDATE
        $updateParts = [];
        foreach ($cols as $col) {
            $updateParts[] = "`$col` = VALUES(`$col`)";
        }
        $updateParts[] = "`updated_at` = NOW()";

        $colList = implode(', ', array_map(function($c) { return "`$c`"; }, $allCols));
        $phList = implode(', ', $placeholders);
        $updateSql = implode(', ', $updateParts);

        $sql = "INSERT INTO `$table` ($colList) VALUES ($phList) ON DUPLICATE KEY UPDATE $updateSql";

        $stmt = db()->prepare($sql);
        try {
            $stmt->execute($values);
        } catch (PDOException $e) {
            // 唯一约束冲突（客户查重兜底）→ 跳过
            if ($e->getCode() == 23000) {
                continue;
            }
            throw $e;
        }
        $results[] = $rec['uid'];
    }

    // 返回 upsert 后的完整记录（前端需要 representation）
    $uidList = array_map(function($u) { return db()->quote($u); }, $results);
    if (!empty($uidList)) {
        $select = implode(', ', array_map(function($c) { return "`$c`"; }, $allCols));
        $sql = "SELECT $select FROM `$table` WHERE `uid` IN (" . implode(',', $uidList) . ") ORDER BY `created_at` DESC";
        $rows = db()->query($sql)->fetchAll();

        $jsonCols = get_json_columns($table);
        if ($jsonCols) {
            foreach ($rows as &$r) {
                foreach ($jsonCols as $jc) {
                    if (isset($r[$jc]) && $r[$jc] !== '' && $r[$jc] !== null) {
                        $decoded = json_decode($r[$jc], true);
                        $r[$jc] = $decoded !== null ? $decoded : [];
                    } else {
                        $r[$jc] = [];
                    }
                }
            }
        }
        json_success($rows);
    }

    json_success([]);
}

/* 3. 删除单条记录 */
function action_delete() {
    $table = resolve_table(get_param('table'));
    $uid = get_param('uid', '');
    if (!$uid) json_error(400, '缺少 uid');

    $stmt = db()->prepare("DELETE FROM `$table` WHERE `uid` = ?");
    $stmt->execute([$uid]);
    json_success(null);
}

/* 4. 查询用户（不返回密码哈希） */
function action_user_get() {
    $username = get_param('username', '');
    if (!$username) json_error(400, '缺少 username');

    $stmt = db()->prepare("SELECT `id`, `username`, `is_admin`, `created_at`, `updated_at` FROM `users` WHERE `username` = ? LIMIT 1");
    $stmt->execute([$username]);
    $row = $stmt->fetch();
    if (!$row) json_success(null);

    json_success($row);
}

/* 5. 用户登录（服务端验证密码） */
function action_user_login() {
    $body = get_body();
    if (empty($body['username']) || empty($body['password'])) {
        json_error(400, '缺少账号或密码');
    }

    $stmt = db()->prepare("SELECT * FROM `users` WHERE `username` = ? LIMIT 1");
    $stmt->execute([$body['username']]);
    $row = $stmt->fetch();
    if (!$row) json_error(404, '账号不存在');

    if (!password_verify($body['password'], $row['pass_hash'])) {
        json_error(401, '密码错误');
    }

    unset($row['pass_hash'], $row['pass_salt']);
    json_success($row);
}

/* 6. 创建用户（服务端哈希密码） */
function action_user_create() {
    $body = get_body();
    if (empty($body['username']) || empty($body['password'])) {
        json_error(400, '缺少必填字段');
    }

    $hash = password_hash($body['password'], PASSWORD_DEFAULT);
    try {
        $stmt = db()->prepare("INSERT INTO `users` (username, pass_salt, pass_hash, is_admin) VALUES (?, ?, ?, ?)");
        $isAdmin = !empty($body['is_admin']) ? 1 : 0;
        $stmt->execute([$body['username'], '', $hash, $isAdmin]);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            json_error(409, '账号已存在');
        }
        throw $e;
    }

    // 返回新创建的用户（不含密码哈希）
    $stmt = db()->prepare("SELECT `id`, `username`, `is_admin`, `created_at`, `updated_at` FROM `users` WHERE `username` = ? LIMIT 1");
    $stmt->execute([$body['username']]);
    json_success($stmt->fetch());
}

/* 7. 更新用户（支持明文密码改密） */
function action_user_update() {
    $username = get_param('username', '');
    if (!$username) json_error(400, '缺少 username');
    $body = get_body();

    $sets = [];
    $vals = [];
    if (isset($body['password']) && $body['password'] !== '') {
        $sets[] = "`pass_hash` = ?";
        $vals[] = password_hash($body['password'], PASSWORD_DEFAULT);
    }
    if (isset($body['is_admin'])) {
        $sets[] = "`is_admin` = ?";
        $vals[] = $body['is_admin'] ? 1 : 0;
    }
    if (empty($sets)) json_error(400, '无更新字段');

    $vals[] = $username;
    $sql = "UPDATE `users` SET " . implode(', ', $sets) . " WHERE `username` = ?";
    $stmt = db()->prepare($sql);
    $stmt->execute($vals);

    // 返回更新后的用户（不含密码哈希）
    $stmt = db()->prepare("SELECT `id`, `username`, `is_admin`, `created_at`, `updated_at` FROM `users` WHERE `username` = ? LIMIT 1");
    $stmt->execute([$username]);
    json_success($stmt->fetch());
}

/* 7. 客户查重 */
function action_dup_check() {
    $name = trim(get_param('name', ''));
    $email = strtolower(trim(get_param('email', '')));
    $excludeUid = get_param('exclude', '');

    if (!$name && !$email) json_success(null);

    $conditions = [];
    $params = [];
    if ($name) {
        $conditions[] = "LOWER(TRIM(IFNULL(`company_name`, ''))) = ?";
        $params[] = strtolower($name);
    }
    if ($email) {
        $conditions[] = "LOWER(TRIM(IFNULL(`email`, ''))) = ?";
        $params[] = $email;
    }

    $sql = "SELECT `uid`, `owner`, `company_name`, `email` FROM `customers` WHERE (" . implode(' OR ', $conditions) . ")";
    if ($excludeUid) {
        $sql .= " AND `uid` != ?";
        $params[] = $excludeUid;
    }
    $sql .= " LIMIT 25";

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    if (empty($rows)) json_success(null);

    // 公司名优先匹配
    foreach ($rows as $r) {
        if ($name && strtolower(trim($r['company_name'])) === strtolower($name)) {
            json_success($r);
        }
    }
    // 邮箱兜底
    foreach ($rows as $r) {
        if ($email && strtolower(trim($r['email'])) === $email) {
            json_success($r);
        }
    }

    json_success(null);
}

/* 8. 健康检查 */
function action_probe() {
    try {
        db()->query("SELECT 1");
        json_success(['status' => 'ok']);
    } catch (Exception $e) {
        json_error(500, '数据库不可用: ' . $e->getMessage());
    }
}

/* 9. 实时汇率查询（USD/CNY 为主，支持任意货币对） */
function action_exchange_rate() {
    $from = strtoupper(get_param('from', 'USD'));
    $to = strtoupper(get_param('to', 'CNY'));
    $allowed = '/^[A-Z]{3}$/';
    if (!preg_match($allowed, $from) || !preg_match($allowed, $to)) {
        json_error(400, '货币代码必须是 3 位字母');
    }

    // 先尝试免费实时接口
    $url = "https://api.exchangerate-api.com/v4/latest/{$from}";
    $rate = null;
    $source = 'live';
    try {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'ignore_errors' => true]]);
        $res = @file_get_contents($url, false, $ctx);
        if ($res) {
            $data = json_decode($res, true);
            if (!empty($data['rates'][$to])) {
                $rate = floatval($data['rates'][$to]);
            }
        }
    } catch (Exception $e) {
        $rate = null;
    }

    // 实时接口失败则回退到本地缓存
    if ($rate === null || $rate <= 0) {
        $stmt = db()->prepare("SELECT `rate` FROM `exchange_rates` WHERE `code` = ? LIMIT 1");
        $stmt->execute(["{$from}/{$to}"]);
        $row = $stmt->fetch();
        if ($row) {
            $rate = floatval($row['rate']);
            $source = 'cache';
        }
    }

    if ($rate === null || $rate <= 0) {
        // 完全没有缓存时给一个常见默认值，避免前端卡死
        $fallbacks = ['USD/CNY' => 7.2, 'CNY/USD' => 0.1389];
        $rate = isset($fallbacks["{$from}/{$to}"]) ? $fallbacks["{$from}/{$to}"] : 1.0;
        $source = 'fallback';
    }

    json_success(['from' => $from, 'to' => $to, 'rate' => $rate, 'source' => $source]);
}

/* 10. 获取当前业务员的提成率 */
function action_commission_get() {
    $owner = preg_replace('/[^A-Za-z0-9_\-]/', '', get_param('owner', ''));
    if (!$owner) json_error(400, '缺少 owner');
    $stmt = db()->prepare("SELECT `uid`, `owner`, `rate`, `notes`, `created_at`, `updated_at` FROM `commission_rates` WHERE `owner` = ? LIMIT 1");
    $stmt->execute([$owner]);
    $row = $stmt->fetch();
    json_success($row ? $row : ['rate' => 0, 'owner' => $owner]);
}

/* 11. 保存当前业务员的提成率（个人隐私，仅本人可见） */
function action_commission_set() {
    $owner = preg_replace('/[^A-Za-z0-9_\-]/', '', get_param('owner', ''));
    if (!$owner) json_error(400, '缺少 owner');
    $body = get_body();
    $rate = isset($body['rate']) ? floatval($body['rate']) : 0;
    $notes = isset($body['notes']) ? $body['notes'] : '';
    $uid = 'comm_' . $owner;

    $stmt = db()->prepare("INSERT INTO `commission_rates` (`uid`, `owner`, `rate`, `notes`, `created_at`, `updated_at`) VALUES (?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE `rate` = VALUES(`rate`), `notes` = VALUES(`notes`), `updated_at` = NOW()");
    $stmt->execute([$uid, $owner, $rate, $notes]);

    $stmt = db()->prepare("SELECT `uid`, `owner`, `rate`, `notes`, `created_at`, `updated_at` FROM `commission_rates` WHERE `owner` = ? LIMIT 1");
    $stmt->execute([$owner]);
    json_success($stmt->fetch());
}

/* ========== 路由 ========== */
$action = get_param('action', '');
$method = $_SERVER['REQUEST_METHOD'];

try {
    // 确保隔离所需的 owner 列存在（幂等，仅首次执行 ALTER）
    ensureOwnerColumn();
    // 确保订单表补列（运费及其他 / 总计金额）
    ensureOrdersColumns();
    // 确保业绩汇总/提成率/汇率缓存表存在（幂等）
    ensureTables();
    switch ($action) {
        case 'list':
            if ($method === 'GET') action_list();
            break;
        case 'upsert':
            if ($method === 'POST') action_upsert();
            break;
        case 'delete':
            if ($method === 'DELETE') action_delete();
            break;
        case 'user-get':
            if ($method === 'GET') action_user_get();
            break;
        case 'user-login':
            if ($method === 'POST') action_user_login();
            break;
        case 'user-create':
            if ($method === 'POST') action_user_create();
            break;
        case 'user-update':
            if ($method === 'PATCH' || $method === 'POST') action_user_update();
            break;
        case 'dup-check':
            if ($method === 'GET') action_dup_check();
            break;
        case 'probe':
            action_probe();
            break;
        case 'migrate':
            if ($method === 'GET' || $method === 'POST') action_migrate();
            break;
        case 'exchange-rate':
            if ($method === 'GET') action_exchange_rate();
            break;
        case 'commission-get':
            if ($method === 'GET') action_commission_get();
            break;
        case 'commission-set':
            if ($method === 'POST' || $method === 'PATCH') action_commission_set();
            break;
        default:
            json_error(400, '未知操作: ' . $action);
    }
} catch (Exception $e) {
    json_error(500, $e->getMessage());
}
