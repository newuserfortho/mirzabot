<?php
// ═══════════════════════════════════════════════════════════════════
//  sub/content.php — سرو محتوای ساب از خود پنل 3x-ui (برای هر subId)
//  چرا: بعضی کلاینت‌های قدیمی‌تر در ساب‌سرورِ 2096 رجیستر نیستند و
//  /subs/{subId} برایشان 404 می‌دهد؛ ولی API پنل (clients/subLinks)
//  برای همه درست محتوا برمی‌گرداند.
//  ورودی: ?sub=SUBSCRIPTION_ID
//  خروجی: JSON {ok, lines[], upload, download, total, expire, enable}
//  CORS باز است.
// ═══════════════════════════════════════════════════════════════════
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/../config.php';

function pc($panel, $path)
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
    curl_close($ch);
    return json_decode((string)$body, true);
}

$sub = isset($_GET['sub']) ? trim((string)$_GET['sub']) : '';
if ($sub === '' || !preg_match('/^[A-Za-z0-9_-]{3,64}$/', $sub)) {
    echo json_encode(['ok' => false, 'msg' => 'bad sub']);
    exit;
}

$panel = null;
try {
    $st = $pdo->prepare("SELECT * FROM marzban_panel WHERE type='x-ui_single' ORDER BY id LIMIT 1");
    $st->execute();
    $panel = $st->fetch(PDO::FETCH_ASSOC);
    if (!$panel) {
        $st2 = $pdo->query("SELECT * FROM marzban_panel ORDER BY id LIMIT 1");
        $panel = $st2->fetch(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $panel = null;
}
if (!$panel) { echo json_encode(['ok' => false, 'msg' => 'no panel']); exit; }

// ۱) محتوای ساب (لیست کانفیگ‌ها)
$d = pc($panel, '/panel/api/clients/subLinks/' . rawurlencode($sub));
$lines = [];
if (!empty($d['success']) && is_array($d['obj'] ?? null)) {
    foreach ($d['obj'] as $l) {
        if (is_string($l) && trim($l) !== '') $lines[] = trim($l);
    }
}
if (!$lines) { echo json_encode(['ok' => false, 'msg' => 'no lines', 'success' => ($d['success'] ?? false)]); exit; }

// ۲) یافتن ایمیل و آمار (upload/download) از inbounds/list
$email = ''; $up = 0; $down = 0;
$lst = pc($panel, '/panel/api/inbounds/list');
if (!empty($lst['success']) && is_array($lst['obj'] ?? null)) {
    foreach ($lst['obj'] as $ib) {
        foreach (($ib['clientStats'] ?? []) as $c) {
            if (($c['subId'] ?? '') === $sub) {
                $email = (string)($c['email'] ?? '');
                $up = (int)($c['up'] ?? 0);
                $down = (int)($c['down'] ?? 0);
                break 2;
            }
        }
    }
}

// ۳) totalGB و expiryTime از get client (اگر ایمیل پیدا شد)
$total = 0; $expireMs = 0; $enable = true;
if ($email !== '') {
    $g = pc($panel, '/panel/api/clients/get/' . rawurlencode($email));
    if (!empty($g['success'])) {
        $cc = $g['obj']['client'] ?? [];
        $total = (int)($cc['totalGB'] ?? 0);
        $expireMs = (int)($cc['expiryTime'] ?? 0);
        $enable = (bool)($cc['enable'] ?? true);
    }
}

echo json_encode([
    'ok' => true,
    'sub' => $sub,
    'email' => $email,
    'lines' => $lines,
    'upload' => $up,
    'download' => $down,
    'total' => $total,
    'expire' => (int)floor($expireMs / 1000),
    'enable' => $enable,
]);
