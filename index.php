<?php
/**
 * UA 投放决策工作台 - 最终全量看板版
 */
require 'db.php'; 
date_default_timezone_set('PRC'); // 设置为中国时区
// 权限拦截
$user_perms = json_decode($_SESSION['user']['permissions'] ?? '[]', true);
if (!isset($_SESSION['user']) || ($_SESSION['user']['role'] !== 'admin' && !in_array('kf', $user_perms))) {
    die("无权访问。 <a href='http://192.168.10.124/auth.php'>前往登录</a>");
}

// 连接本地 kf 库
$db = new mysqli('localhost', 'kf', 'KF123456', 'kf');
if ($db->connect_error) die("DB Connection Failed");
$db->query("SET NAMES utf8mb4");

// 接口处理逻辑
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'get_tasks') {
        $rows = [];
        $res = $db->query("SELECT * FROM ua_tasks ORDER BY id DESC");
        while ($row = $res->fetch_assoc()) $rows[] = $row;
        echo json_encode(['success' => true, 'data' => $rows]); exit;
    }

    if ($action === 'add_task') {
        $game = $db->real_escape_string($_POST['game']);
        $platform = $db->real_escape_string($_POST['platform']);
        $threshold = (int)$_POST['threshold'];
        $interval = (int)$_POST['interval'];
        $start_date = $_POST['start_date'];
        $end_date = $_POST['end_date'];
        $db->query("INSERT INTO ua_tasks (game, platform, threshold, interval_min, start_date, end_date) VALUES ('$game', '$platform', $threshold, $interval, '$start_date', '$end_date')");
        echo json_encode(['success' => true, 'id' => $db->insert_id]); exit;
    }

    if ($action === 'update_task') {
        $id = (int)$_POST['id']; $type = $_POST['type'];
        if ($type === 'delete') $db->query("DELETE FROM ua_tasks WHERE id=$id");
        elseif ($type === 'stop') {
            $reason = $db->real_escape_string($_POST['reason']);
            $db->query("UPDATE ua_tasks SET status=0, stop_reason='$reason' WHERE id=$id");
        }
        echo json_encode(['success' => true]); exit;
    }

    if ($action === 'fetch_data') {
        $game = trim($_POST['game'] ?? '');
        $platform = trim($_POST['platform'] ?? '');
        
        $stmt_cookie = $pdo->prepare("SELECT key_value FROM settings WHERE key_name = 'ua_cookie'");
        $stmt_cookie->execute();
        $userCookie = $stmt_cookie->fetchColumn() ?: '';

        if (empty($game) || empty($platform)) {
            echo json_encode(['success' => false, 'error' => '参数不完整']); exit;
        }

        $url = 'https://ad.leniugame.com/ServerDataMonitor/serverData';
        $postData = ['leniuTjGlobalQueryTabHeadTag' => '0', 'date_hidden' => date('Ymd/Ymd'), 'is_roll' => '0'];
        
        $fetch = function($cookie) use ($url, $postData) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($postData),
                CURLOPT_RETURNTRANSFER => true, CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_HTTPHEADER => ['Cookie: ' . $cookie, 'User-Agent: Mozilla/5.0']
            ]);
            $res = curl_exec($ch); curl_close($ch);
            return json_decode($res, true);
        };

        $data = $fetch($userCookie);

        // 自动重登逻辑
        if (!$data || (isset($data['code']) && $data['code'] == 1001)) {
            $userCookie = autoLoginLeniu($pdo);
            if ($userCookie) $data = $fetch($userCookie);
        }

        $count = 0; $found = false; $serverArr = [];
        if (isset($data['data']['rows']) && is_array($data['data']['rows'])) {
            foreach ($data['data']['rows'] as $row) {
                if (trim($row['game_name']) == $game && trim($row['platform_name']) == $platform) {
                    $count += (int)($row['pay_role_new'] ?? 0);
                    $serverArr[] = $row['server_name'] ?? '';
                    $found = true;
                }
            }
        }
        echo json_encode(['success' => $found, 'count' => $count, 'servers' => implode('/', array_unique($serverArr)), 'time' => date('Y-m-d H:i:s')]); exit;
    }

    if ($action === 'send_lark') {
        // ... (原报警逻辑) ...
        echo json_encode(['success' => true]); exit;
    }
}
function autoLoginLeniu($pdo) {
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
        $pdo->prepare("REPLACE INTO settings (key_name, key_value) VALUES ('ua_cookie', ?)")->execute([$newCookie]);
        return $newCookie;
    }
    return false;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>UA 自动化监控看板</title>
    <style>
        body { font-family: sans-serif; background: #f0f2f5; padding: 20px; }
        .main-container { display: flex; gap: 20px; justify-content: center; }
        .app-card { background: #fff; width: 400px; padding: 25px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); }
        .task-card { width: 450px; }
        h3 { border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .field { margin-bottom: 15px; }
        label { display: block; font-size: 12px; color: #888; margin-bottom: 5px; }
        input { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 8px; box-sizing: border-box; }
        button { width: 100%; padding: 12px; border: none; border-radius: 8px; font-weight: bold; cursor: pointer; margin-top: 5px;}
        .btn-manual { background: #6c757d; color: #fff; }
        .btn-task { background: #1a73e8; color: #fff; }
        .task-item { background: #f8f9fa; padding: 15px; border-radius: 8px; margin-bottom: 10px; border: 1px solid #eee; }
        .res-display { margin-top: 20px; padding: 15px; border-radius: 12px; text-align: center; border: 2px solid #eee; }
        .modal-overlay { display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 100; justify-content: center; align-items: center; }
        .modal { background: #fff; padding: 25px; border-radius: 12px; width: 400px; }
    </style>
</head>
<body>

<div class="main-container">
    <div class="app-card">
        <h3>查询面板</h3>
        <div class="field"><label>游戏/平台</label>
            <div style="display:flex; gap:10px">
                <input type="text" id="game" placeholder="游戏">
                <input type="text" id="platform" placeholder="平台">
            </div>
        </div>
        <div class="field"><label>阈值</label><input type="number" id="threshold"></div>
        <button class="btn-manual" onclick="runManualFetch()">手动查询一次</button>
        <button class="btn-task" onclick="openTaskModal()">+ 添加离线自动任务</button>
        <div id="res-box" class="res-display" style="display:none">
            <div id="res-tips">首日付费人数</div>
            <div style="font-size:40px; font-weight:bold" id="res-val">0</div>
            <div id="res-time" style="font-size:11px; color:#999"></div>
        </div>
    </div>

    <div class="app-card task-card">
        <h3>
            24H 自动监控任务
            <span id="task-count" style="font-size:12px; color:#999; font-weight:normal"></span>
        </h3>
        <div id="task-list">正在加载任务状态...</div>
    </div>
</div>

<div class="modal-overlay" id="taskModal">
    <div class="modal">
        <h3>添加自动监控</h3>
        <div class="field"><label>游戏/平台</label><input type="text" id="t_game"><input type="text" id="t_platform" style="margin-top:5px"></div>
        <div class="field"><label>阈值/间隔(分)</label><div style="display:flex; gap:10px"><input type="number" id="t_threshold"><input type="number" id="t_interval" value="5"></div></div>
        <div class="field"><label>日期范围</label><div style="display:flex; gap:10px"><input type="date" id="t_start_date"><input type="date" id="t_end_date"></div></div>
        <button class="btn-task" onclick="confirmAddTask()">启动 24H 监控</button>
        <button class="btn-manual" onclick="document.getElementById('taskModal').style.display='none'">取消</button>
    </div>
</div>

<script>
window.onload = loadTasks;

async function loadTasks() {
    const fd = new URLSearchParams(); fd.append('action', 'get_tasks');
    const r = await fetch('', { method: 'POST', body: fd });
    const res = await r.json();
    renderTaskList(res.data);
}

function renderTaskList(tasks) {
    const listDiv = document.getElementById('task-list');
    const dObj = new Date();
    const today = dObj.getFullYear() + '-' + String(dObj.getMonth() + 1).padStart(2, '0') + '-' + String(dObj.getDate()).padStart(2, '0');
    
    // 任务分类容器
    const runningTasks = [];
    const waitingTasks = [];
    const historyTasks = [];

    tasks.forEach(t => {
        if (t.status == 0) {
            historyTasks.push(t);
        } else {
            const isNotStarted = t.start_date && today < t.start_date;
            const isFinishedToday = (parseInt(t.last_count) >= parseInt(t.threshold)) && (t.last_time && t.last_time.includes(today));
            
            if (isNotStarted || isFinishedToday) {
                waitingTasks.push(t);
            } else {
                runningTasks.push(t);
            }
        }
    });
    
    // 更新计数显示
    document.getElementById('task-count').innerText = `${runningTasks.length} 监控中 / ${waitingTasks.length} 等待中`;

    // 内部渲染函数：包含日期范围和下次时间预测
    const renderItem = (t, label = '') => {
        const isRun = t.status == 1;
        
        // 预计下次查询时间计算
        let nextTimeStr = '-';
        if (isRun && t.last_time && t.last_time !== '-' && t.last_time !== '') {
            const lastUpdate = new Date(t.last_time.replace(/-/g, '/'));
            const nextUpdate = new Date(lastUpdate.getTime() + (t.interval_min * 60 * 1000));
            nextTimeStr = nextUpdate.getHours().toString().padStart(2, '0') + ':' + nextUpdate.getMinutes().toString().padStart(2, '0');
        }

        return `
        <div class="task-item" style="${isRun ? '' : 'opacity:0.7; background:#f5f5f5'}">
            <div style="display:flex; justify-content:space-between; align-items:center">
                <b>${t.game} - ${t.platform} ${label ? `<small style="color:#f59e0b"> ${label}</small>` : ''}</b>
                ${isRun ? `<button onclick="stopTask(${t.id})" style="width:auto; padding:2px 8px; background:#ff4d4f; color:#fff; border:none; border-radius:4px; cursor:pointer">停止</button>` : `<span style="font-size:12px; color:#999">${t.stop_reason}</span>`}
            </div>
            <div style="font-size:10px; color:#1a73e8; margin:5px 0;">📅 运行日期: ${t.start_date} 至 ${t.end_date}</div>
            <div style="display:flex; justify-content:space-between; font-size:12px; color:#666">
                <span>当前: <b style="color:${parseInt(t.last_count)>=parseInt(t.threshold)?'red':'#333'}">${t.last_count}</b> / 阈值: ${t.threshold}</span>
                <span style="background:#e6f7ff; padding:0 5px; border-radius:4px; font-size:10px">${t.interval_min}分/次</span>
            </div>
            <div style="font-size:10px; color:#aaa; margin-top:8px; display:flex; justify-content:space-between; border-top:1px solid #eee; padding-top:5px">
                <span>🕒 上次: ${t.last_time}</span>
                <span style="color:#52c41a">预计下次: ${nextTimeStr}</span>
            </div>
        </div>`;
    };

    if (!tasks.length) { listDiv.innerHTML = '<div style="text-align:center; color:#ccc; padding-top:30px">暂无监控任务</div>'; return; }

    let html = '';
    // 1. 🟢 监控中分栏
    html += '<div style="margin-bottom:10px; font-size:12px; font-weight:bold; color:#52c41a;">🟢 正在监控任务</div>';
    html += runningTasks.length ? runningTasks.map(t => renderItem(t)).join('') : '<div style="font-size:10px; color:#ccc; padding:10px; text-align:center">暂无活跃任务</div>';

    // 2. ⏳ 等待运行分栏 (用户强调需求)
    if (waitingTasks.length > 0) {
        html += '<div style="margin:20px 0 10px; font-size:12px; color:#f59e0b; font-weight:bold; border-top:1px dashed #f59e0b; padding-top:10px">⏳ 等待运行 (未开始/今日已达标)</div>';
        html += waitingTasks.map(t => {
            const isNotStarted = t.start_date && today < t.start_date;
            return renderItem(t, isNotStarted ? '[未到期]' : '[今日已达标]');
        }).join('');
    }

    // 3. 🔴 历史记录分栏
    if (historyTasks.length > 0) {
        html += '<div style="margin:20px 0 10px; font-size:12px; color:#999; font-weight:bold; border-top:1px dashed #ddd; padding-top:10px">🔴 历史记录</div>';
        html += historyTasks.map(t => renderItem(t)).join('');
    }

    listDiv.innerHTML = html;
}

async function runManualFetch() {
    const btn = document.querySelector('.btn-manual');
    const game = document.getElementById('game').value;
    const platform = document.getElementById('platform').value;
    
    // 状态标识：开始查询
    btn.innerText = "正在同步接口数据...";
    btn.disabled = true;
    btn.style.background = "#ffa940";

    const fd = new URLSearchParams(); 
    fd.append('action', 'fetch_data'); 
    fd.append('game', game); 
    fd.append('platform', platform);
    
    try {
        const r = await fetch('', { method: 'POST', body: fd });
        const d = await r.json();
        const box = document.getElementById('res-box'); 
        box.style.display = 'block';
        document.getElementById('res-val').innerText = d.count;
        document.getElementById('res-time').innerText = d.time;
    } catch(e) {
        alert("查询失败，请检查网络或Cookie");
    } finally {
        // 状态标识：恢复原样
        btn.innerText = "手动查询一次";
        btn.disabled = false;
        btn.style.background = "#6c757d";
    }
}

function openTaskModal() {
    document.getElementById('t_game').value = document.getElementById('game').value;
    document.getElementById('t_platform').value = document.getElementById('platform').value;
    document.getElementById('taskModal').style.display = 'flex';
}

async function confirmAddTask() {
    const fd = new URLSearchParams();
    fd.append('action', 'add_task');
    fd.append('game', document.getElementById('t_game').value);
    fd.append('platform', document.getElementById('t_platform').value);
    fd.append('threshold', document.getElementById('t_threshold').value);
    fd.append('interval', document.getElementById('t_interval').value);
    fd.append('start_date', document.getElementById('t_start_date').value);
    fd.append('end_date', document.getElementById('t_end_date').value);
    await fetch('', { method: 'POST', body: fd });
    document.getElementById('taskModal').style.display = 'none';
    loadTasks();
}

async function stopTask(id) {
    const fd = new URLSearchParams(); fd.append('action', 'update_task'); fd.append('type', 'stop'); fd.append('id', id); fd.append('reason', '手动停止');
    await fetch('', { method: 'POST', body: fd });
    loadTasks();
}
</script>
</body>
</html>