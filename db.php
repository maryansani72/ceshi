<?php

// 让 www 和 jk 两个子域共享登录态（同主域）
ini_set('session.cookie_domain', '.zzyceshi.work');
ini_set('session.cookie_path', '/');
// 如果你全站都是 https，建议加上这两行（更稳）
ini_set('session.cookie_secure', '1');
ini_set('session.cookie_httponly', '1');
// 兼容大多数场景（避免跨站点请求被拦）
ini_set('session.cookie_samesite', 'Lax');

session_start(); // 开启会话

// db.php
session_start(); // 开启会话

$host = '127.0.0.1';
$db   = 'zm';
$user = 'zm';
$pass = 'zm123456';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // 生产环境不要直接输出错误，这里为了调试方便
    die("数据库连接失败: " . $e->getMessage());
}

/**
 * ===== UA Monitor Helpers =====
 * 说明：
 * - 监控所需的 Cookie 是 leniugame 的登录态（通常包含 ln_auth + ln_auth_id），不是本网站的 PHPSESSID。
 * - 当 Cookie 失效/缺失时，会自动尝试重登获取新 Cookie，并通过飞书(ua_webhook)通知成功/失败。
 */

function ua_get_setting(PDO $pdo, string $key, string $default = ''): string {
    $stmt = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = ?");
    $stmt->execute([$key]);
    $val = $stmt->fetchColumn();
    return ($val === false || $val === null) ? $default : (string)$val;
}

function ua_set_setting(PDO $pdo, string $key, string $value): void {
    $stmt = $pdo->prepare("REPLACE INTO settings (key_name, key_value) VALUES (?, ?)");
    $stmt->execute([$key, $value]);
}

function ua_cookie_is_valid(string $cookie): bool {
    if (trim($cookie) === '') return false;
    return (strpos($cookie, 'ln_auth=') !== false) && (strpos($cookie, 'ln_auth_id=') !== false);
}

/** 发送飞书通知（ua_webhook） */
function ua_lark_notify(PDO $pdo, string $title, string $content, string $template = 'blue'): void {
    $webhook = ua_get_setting($pdo, 'ua_webhook', '');
    if ($webhook === '') return;

    $message = [
        "msg_type" => "interactive",
        "card" => [
            "header" => [
                "title" => ["tag" => "plain_text", "content" => $title],
                "template" => $template
            ],
            "elements" => [
                ["tag" => "div", "text" => ["tag" => "lark_md", "content" => $content]]
            ]
        ]
    ];

    $ch = curl_init($webhook);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POST => 1,
        CURLOPT_POSTFIELDS => json_encode($message, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 10,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

/** 从响应头/跳转链路中提取并合并 Set-Cookie */
function ua_collect_cookies_from_headers(string $rawHeaders): string {
    // 允许多段 header（跟随跳转时会重复出现）
    preg_match_all('/^Set-Cookie:\s*([^;\r\n]*)/mi', $rawHeaders, $m);
    if (empty($m[1])) return '';
    $pairs = [];
    foreach ($m[1] as $pair) {
        $pair = trim($pair);
        if ($pair === '') continue;
        // 只保留 name=value
        $pairs[$pair] = true;
    }
    return implode('; ', array_keys($pairs));
}

/**
 * 自动登录 leniugame 获取 Cookie
 * 成功条件：返回的 Cookie 必须包含 ln_auth + ln_auth_id
 * 失败常见原因：登录参数失效 / 需要验证码 / 风控限制
 */
function ua_auto_login_leniu(PDO $pdo) {
    $loginUrl = 'https://bloc.leniugame.com/Login/account';
    // 保持你原有的登录 payload（如果对方改了登录机制，这里也需要更新）
    $postData = 'ln_aaaaa=zhuizyan&ln_ddddd=5f05862b03f97c65b123b56f92d8a284d3e4971a82d4ee76baff98be6f0b1b83785cd91b6b0d9e92dcf7b8c9e71cbf9b2a6bf303fc82ab634473c6bc6c766f7f3eca9ccf5a759fb7971486299fc45cd6c2dd6ff35b45e1100c2c7a18cdd75b89a70163bbd037f48a8ab4c7e15afe591908e401a27910912292f91a5b3fffe808&user_name=&code=&codeType=1&__hash__=67cd93aa40c81b1e2eb4059182c172af_2c239120b8a73d3edbc74d6a20460dd6';

    $rawHeaders = '';
    $ch = curl_init($loginUrl);

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $postData,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_ENCODING => '',
        CURLOPT_HTTPHEADER => [
            'Accept: */*',
            'Accept-Language: zh-CN,zh;q=0.9',
            'Connection: keep-alive',
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/121.0.0.0 Safari/537.36'
        ],
    ]);

    $resp = curl_exec($ch);
    if ($resp === false) {
        curl_close($ch);
        return false;
    }
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headers = substr($resp, 0, $headerSize);
    curl_close($ch);

    $newCookie = ua_collect_cookies_from_headers($headers);

    // 只有拿到真正登录态才更新 DB
    if (ua_cookie_is_valid($newCookie)) {
        ua_set_setting($pdo, 'ua_cookie', $newCookie);
        return $newCookie;
    }
    return false;
}

/**
 * 使用当前 Cookie 请求一次接口，判断是否仍然有效
 * 返回：true=有效；false=无效/未登录
 */
function ua_check_cookie_valid(string $cookie): bool {
    if (!ua_cookie_is_valid($cookie)) return false;

    $url = 'https://ad.leniugame.com/ServerDataMonitor/serverData';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'leniuTjGlobalQueryTabHeadTag' => '0',
            'date_hidden' => date('Ymd/Ymd'),
            'is_roll' => '0'
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Cookie: ' . $cookie,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    if (!$data) return false;
    // code=1001 通常代表未登录/无权限
    if (isset($data['code']) && (int)$data['code'] === 1001) return false;
    // 有 rows 才算真的成功
    return isset($data['data']['rows']) && is_array($data['data']['rows']);
}

/**
 * 确保有可用 Cookie：缺失/失效时自动获取，并飞书通知
 * $scene: '定时任务' / '手动查询' 等
 */
function ua_ensure_cookie(PDO $pdo, string $scene = '查询') {
    $cookie = ua_get_setting($pdo, 'ua_cookie', '');

    if ($cookie === '' || !ua_cookie_is_valid($cookie)) {
        ua_lark_notify($pdo, "⚠️ UA Cookie 缺失（{$scene}）", "检测到未配置或不完整的 Cookie（需要包含 `ln_auth` 与 `ln_auth_id`）。\n将尝试自动获取…", "orange");
        $new = ua_auto_login_leniu($pdo);
        if ($new) {
            ua_lark_notify($pdo, "✅ UA Cookie 获取成功（{$scene}）", "已自动获取到新的登录态 Cookie，并已写入系统。", "green");
            return $new;
        }
        ua_lark_notify($pdo, "❌ UA Cookie 获取失败（{$scene}）", "自动获取失败：可能需要验证码/登录参数失效/风控限制。\n请到 `www.zzyceshi.work/admin.php` 手动粘贴最新 Cookie。", "red");
        return false;
    }

    // 有 cookie，但可能失效
    if (!ua_check_cookie_valid($cookie)) {
        ua_lark_notify($pdo, "⚠️ UA Cookie 已失效（{$scene}）", "检测到 Cookie 已过期/未登录，将尝试自动获取…", "orange");
        $new = ua_auto_login_leniu($pdo);
        if ($new && ua_check_cookie_valid($new)) {
            ua_lark_notify($pdo, "✅ UA Cookie 刷新成功（{$scene}）", "已自动刷新 Cookie，监控将继续正常运行。", "green");
            return $new;
        }
        ua_lark_notify($pdo, "❌ UA Cookie 刷新失败（{$scene}）", "自动刷新失败：可能需要验证码/登录参数失效/风控限制。\n请到 `www.zzyceshi.work/admin.php` 手动粘贴最新 Cookie。", "red");
        return false;
    }

    return $cookie;
}

?>
