<?php
ini_set('error_log', 'error_log');
// نمایش خطاها به خروجی خام صدمه نزند:
@ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../Marzban.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
$ManagePanel = new ManagePanel();

/**
 * پیدا کردن subId کلاینتِ یک پنل 3x-ui از روی email (با API پنل).
 * فقط برای ساخت لینکِ صفحه‌ی زیبا استفاده می‌شود.
 */
function xui_find_subid($panel, $email)
{
    if (!$panel || empty($email)) return null;
    $url = rtrim((string)$panel['url_panel'], '/') . '/panel/api/inbounds/list';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $panel['password_panel']],
    ]);
    $body = curl_exec($ch);
    curl_close($ch);
    $j = json_decode((string)$body, true);
    if (empty($j['success'])) return null;
    foreach ($j['obj'] as $ib) {
        foreach (($ib['clientStats'] ?? []) as $c) {
            if (isset($c['email']) && $c['email'] === $email && !empty($c['subId'])) {
                return $c['subId'];
            }
        }
    }
    return null;
}

$ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
$isBrowser = (strpos($ua, 'Mozilla') !== false);

$url = $_SERVER['REQUEST_URI'];
$parts = explode('/sub/', $url);
$rawId = isset($parts[1]) ? trim($parts[1], '/') : '';
// فقط شناسه؛ کوئری و / اضافه حذف شود
$id = strtok($rawId, '?');

if ($id === false || $id === '') {
    header('Content-Type: text/plain; charset=utf-8');
    echo "ERROR!";
    exit;
}

$invoice = select("invoice", "*", "id_invoice", $id, "select");
if (!$invoice) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "Error!";
    exit;
}

// اگر در مرورگر باز شد → برو به صفحه‌ی زیبای ساب (همان که قبلاً درست شد)
if ($isBrowser) {
    $panel = select("marzban_panel", "*", "name_panel", $invoice['Service_location'], "select");
    $subId = xui_find_subid($panel, $invoice['username']);
    if ($subId) {
        header('Location: https://sub.aminishere.shop/s.html?sub=' . rawurlencode($subId), true, 302);
        exit;
    }
    // اگر subId پیدا نشد، طبق روال قبلی ادامه می‌دهیم تا خراب نشود
}

// حالت اپ (یا fallback) → همان خروجی خام قبلی
header('Content-Type: text/plain; charset=utf-8');
try {
    $DataUserOut = $ManagePanel->DataUser($invoice['Service_location'], $invoice['username']);
    $config = "";
    foreach ($DataUserOut['links'] as $Links) {
        $config .= $Links . "\r\r";
    }
    echo $config;
} catch (Exception $e) {
    echo "Error!";
}
