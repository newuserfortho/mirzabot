<?php
// ═══════════════════════════════════════════════════════════════════
//  sub/usage.php — سرو کردن آمار واقعیِ مصرف برای صفحه‌ی زیبای ساب
//  ورودی:  ?sub=SUBSCRIPTION_ID
//  خروجی: JSON شامل نقاط (timestamp, up, down) از جدول usage_log
//  CORS باز است چون از دامنه‌ی دیگری (sub.aminishere.shop) صدا زده می‌شود.
// ═══════════════════════════════════════════════════════════════════
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

error_reporting(0);
ini_set('display_errors', '0');
require_once __DIR__ . '/../config.php';

$sub = isset($_GET['sub']) ? trim((string)$_GET['sub']) : '';
if ($sub === '' || !preg_match('/^[A-Za-z0-9_-]{3,64}$/', $sub)) {
    echo json_encode(['ok' => false, 'msg' => 'bad sub']);
    exit;
}
try {
    $cut = time() - 45 * 86400;
    $st = $pdo->prepare("SELECT up, down, ts FROM usage_log WHERE sub_id=? AND ts>=? ORDER BY ts ASC");
    $st->execute([$sub, $cut]);
    $pts = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pts[] = ['t' => (int)$r['ts'], 'u' => (int)$r['up'], 'd' => (int)$r['down']];
    }
    echo json_encode(['ok' => true, 'sub' => $sub, 'points' => $pts]);
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'msg' => $e->getMessage()]);
}
