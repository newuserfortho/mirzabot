<?php
// ═══════════════════════════════════════════════════════════════════
//  usage-snapshot.php — ثبت خودکار مصرف کلاینت‌های پنل (برای نمودار واقعی)
//  هر بار اجرا: از پنل x-ui_single لیست کلاینت‌ها را می‌گیرد و جمعِ up/down
//  هر کلاینت را به‌همراه timestamp در جدول usage_log ذخیره می‌کند.
//  کرون: هر ۱ ساعت یک‌بار (در cron/cron.sh)
// ═══════════════════════════════════════════════════════════════════
error_reporting(E_ERROR);
ini_set('display_errors', '0');
require_once 'config.php';

function snap_get($panel, $path)
{
    $url = rtrim((string)$panel['url_panel'], '/') . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $panel['password_panel']],
    ]);
    $body = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $err !== '') return null;
    return json_decode((string)$body, true);
}

// اطمینان از وجود جدول
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS usage_log (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        sub_id VARCHAR(96) NOT NULL,
        email VARCHAR(160),
        up BIGINT DEFAULT 0,
        down BIGINT DEFAULT 0,
        ts INT NOT NULL,
        KEY(sub_id, ts)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    echo 'table: ' . $e->getMessage() . "\n";
    exit;
}

$now = time();
$seen = [];
$stmt = $pdo->query("SELECT * FROM marzban_panel WHERE type='x-ui_single'");
$panels = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
if (!$panels) {
    // همه پنل‌ها را امتحان کن
    $stmt = $pdo->query("SELECT * FROM marzban_panel");
    $panels = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
}

$count = 0;
foreach ($panels as $panel) {
    if (empty($panel['password_panel'])) continue;
    $d = snap_get($panel, '/panel/api/inbounds/list');
    if (empty($d['success']) || !is_array($d['obj'] ?? null)) {
        echo 'panel ' . ($panel['name_panel'] ?? '?') . " fail\n";
        continue;
    }
    foreach ($d['obj'] as $ib) {
        foreach (($ib['clientStats'] ?? []) as $c) {
            $email = (string)($c['email'] ?? '');
            $sub = (string)($c['subId'] ?? '');
            if ($sub === '' || $email === '') continue;
            if (isset($seen[$sub])) continue; // اولین occurrence کافی است
            $seen[$sub] = 1;
            $up = (int)($c['up'] ?? 0);
            $down = (int)($c['down'] ?? 0);
            try {
                $ins = $pdo->prepare("INSERT INTO usage_log(sub_id,email,up,down,ts) VALUES(?,?,?,?,?)");
                $ins->execute([$sub, $email, $up, $down, $now]);
                $count++;
            } catch (Throwable $e) {
                echo 'ins: ' . $e->getMessage() . "\n";
            }
        }
    }
}

// حذف داده‌های خیلی قدیمی
try { $pdo->exec("DELETE FROM usage_log WHERE ts < " . ($now - 40 * 86400)); } catch (Throwable $e) {}
echo "snapshot done clients=" . $count . " ts=" . $now . "\n";
