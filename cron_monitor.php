<?php
/**
 * UA 自动监控 - 后台定时执行版
 * 严格保持原逻辑，实现 24 小时离线监控
 */

// 设置脚本永不超时
set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 1. 引入数据库配置文件 (获取 $pdo)
require __DIR__ . '/db.php'; 
date_default_timezone_set('PRC'); // 设置为中国时区
// 2. 初始化 kf 库连接 (获取 $db，严格同步 index.php 逻辑)
$db = new mysqli('localhost', 'kf', 'KF123456', 'kf');
if ($db->connect_error) die("DB Connection Failed: " . $db->connect_error);
$db->query("SET NAMES utf8mb4");

echo "--- 监控任务开始 " . date('Y-m-d H:i:s') . " ---\n";

// 3. 获取所有正在运行的任务 (status=1)
$res = $db->query("SELECT * FROM ua_tasks WHERE status = 1");
$today = date('Y-m-d');

if ($res->num_rows === 0) {
    echo "当前无运行中的任务。\n";
}

while ($task = $res->fetch_assoc()) {
    $id = $task['id'];
    $game = $task['game'];
    $platform = $task['platform'];
    $threshold = (int)$task['threshold'];
    $startDate = $task['start_date'];
    $endDate = $task['end_date'];

    echo "正在检查任务 [{$id}] {$game}-{$platform}... ";

    // --- 日期范围校验 ---
    if ($startDate && $today < $startDate) { echo "未到期跳过\n"; continue; }
    if ($endDate && $today > $endDate) {
        $db->query("UPDATE ua_tasks SET status=0, stop_reason='监控周期已结束' WHERE id=$id");
        echo "已到期停止\n";
        continue;
    }

    // --- 每日达标跳过逻辑：如果今日已达标报警过，不再重复请求 ---
    $lastVal = (int)($task['last_count'] ?? 0);
    $lastTime = $task['last_time'] ?? '';
    if ($lastVal >= $threshold && strpos($lastTime, $today) !== false) {
        echo "今日已达标，跳过查询\n";
        continue;
    }

    // --- 抓取逻辑：严格复用原 payload ---
    $stmt_cookie = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = 'ua_cookie'");
    $stmt_cookie->execute();
    $userCookie = $stmt_cookie->fetchColumn() ?: '';

    if (empty($userCookie)) { echo "错误：Cookie缺失\n"; continue; }

    $url = 'https://ad.leniugame.com/ServerDataMonitor/serverData';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['leniuTjGlobalQueryTabHeadTag' => '0', 'date_hidden' => date('Ymd/Ymd'), 'is_roll' => '0']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => [
            'Cookie: ' . $userCookie,
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
        ]
    ]);
    
    $resp = curl_exec($ch);
    $data = json_decode($resp, true);

    // 自动重登逻辑
    if (!$data || (isset($data['code']) && $data['code'] == 1001)) {
        echo "Cookie失效，尝试重登... ";
        $newCookie = autoLoginLeniu_Cron($pdo);
        if ($newCookie) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Cookie: ' . $newCookie, 'User-Agent: Mozilla/5.0']);
            $resp = curl_exec($ch);
            $data = json_decode($resp, true);
            echo "重登成功 ";
        } else {
            echo "重登失败 ";
        }
    }
    curl_close($ch);

    // 数据解析
    $count = 0;
    $found = false;
    $serverArr = [];
    if (isset($data['data']['rows']) && is_array($data['data']['rows'])) {
        foreach ($data['data']['rows'] as $row) {
            if (trim($row['game_name']) == $game && trim($row['platform_name']) == $platform) {
                $count += (int)($row['pay_role_new'] ?? 0);
                $serverArr[] = $row['server_name'] ?? '';
                $found = true;
            }
        }
    }

    // 更新 DB
    $time = date('Y-m-d H:i:s');
    $db->query("UPDATE ua_tasks SET last_count='$count', last_time='$time' WHERE id=$id");
    echo "结果: {$count} ";

    // 飞书报警
    if ($found && $count >= $threshold) {
        echo "触发报警！";
        sendLark_Cron($pdo, $game, $platform, $count, $threshold, implode(' / ', array_unique($serverArr)));
    }
    echo "\n";
}
echo "--- 监控任务完成 ---\n";

/** 辅助函数：飞书通知 **/
function sendLark_Cron($pdo, $game, $platform, $count, $threshold, $servers) {
    $stmt = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = 'ua_webhook'");
    $stmt->execute();
    $webhook = $stmt->fetchColumn();
    if (empty($webhook)) return;

    $message = [
        "msg_type" => "interactive",
        "card" => [
            "header" => ["title" => ["tag" => "plain_text", "content" => "🚨 离线监控预警"], "template" => "red"],
            "elements" => [
                ["tag" => "div", "text" => ["tag" => "lark_md", "content" => "**游戏：** $game\n**平台：** $platform\n**区服：** $servers\n**当前首日付费：** **$count**\n**预警阈值：** $threshold\n\n后台自动检出达标。"]]
            ]
        ]
    ];
    $ch = curl_init($webhook);
    curl_setopt_array($ch, [CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_POST => 1, CURLOPT_POSTFIELDS => json_encode($message), CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false]);
    curl_exec($ch); curl_close($ch);
}

/** 辅助函数：重登逻辑 **/
function autoLoginLeniu_Cron($pdo) {
    $loginUrl = 'https://bloc.leniugame.com/Login/account';
    $postData = 'ln_aaaaa=zhuizyan&ln_ddddd=5f05862b03f97c65b123b56f92d8a284d3e4971a82d4ee76baff98be6f0b1b83785cd91b6b0d9e92dcf7b8c9e71cbf9b2a6bf303fc82ab634473c6bc6c766f7f3eca9ccf5a759fb7971486299fc45cd6c2dd6ff35b45e1100c2c7a18cdd75b89a70163bbd037f48a8ab4c7e15afe591908e401a27910912292f91a5b3fffe808&user_name=&code=&codeType=1&__hash__=67cd93aa40c81b1e2eb4059182c172af_2c239120b8a73d3edbc74d6a20460dd6';
    $ch = curl_init($loginUrl);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $postData, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_SSL_VERIFYPEER => false]);
    $response = curl_exec($ch);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $header = substr($response, 0, $headerSize);
    curl_close($ch);
    preg_match_all('/Set-Cookie: (.*?);/i', $header, $matches);
    if (!empty($matches[1])) {
        $newCookie = implode('; ', array_unique($matches[1]));
        $stmt = $pdo->prepare("REPLACE INTO settings (key_name, key_value) VALUES ('ua_cookie', ?)");
        $stmt->execute([$newCookie]);
        return $newCookie;
    }
    return false;
}