<?php
// ═══════════════════════════════════════════════════════════════════
//  link-manual.php — اتصال کلاینتی که در پنل سنایی دستی ساخته‌ای به یک
//  کاربرِ تلگرام (بات) تا در «سرویس‌های من» او نمایش داده شود.
//  استفاده:
//    1) توی ریلوی متغیر TUNNEL_SECRET را بگذار (اگر هست خودش می‌خواند).
//    2) باز کن:  https://دامنه-بات/link-manual.php?key=رمز
//    3) «آیدی یا @یوزرنیم تلگرام» شخص را بزن + هر خط یک «email کلاینت» پنل.
//  ⚠️ بعد از استفاده، این فایل را از ریپو حذف کن.
// ═══════════════════════════════════════════════════════════════════
error_reporting(E_ALL & ~E_DEPRECATED);
require_once 'config.php';

$secret = getenv('TUNNEL_SECRET') ?: (getenv('LINKER_SECRET') ?: 'sovra-link-999');
if (!isset($_GET['key']) || !hash_equals($secret, (string) $_GET['key'])) {
    http_response_code(403);
    echo '<meta charset="utf-8"><div dir="rtl" style="font-family:Tahoma;max-width:520px;margin:40px auto">';
    echo '<h3>🚫 دسترسی غیرمجاز</h3><p>برای استفاده، رمز را با ?key= بفرست.</p></div>';
    exit;
}
function e($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// انتخاب پنل x-ui_single
$panel = null;
try {
    $st = $pdo->prepare("SELECT * FROM marzban_panel WHERE type='x-ui_single' ORDER BY id LIMIT 1");
    $st->execute();
    $panel = $st->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $x) {}
if (!$panel) { echo 'پنل x-ui_single پیدا نشد'; exit; }

// کاربرهای تلگرام (برای نمایش)
$users = [];
try { $users = $pdo->query("SELECT id, username FROM user ORDER BY id")->fetchAll(PDO::FETCH_ASSOC); } catch (Throwable $x) {}

// فراخوانی ساده به پنل
function pcall($panel, $path){
    $url = rtrim((string)$panel['url_panel'], '/') . $path;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Authorization: Bearer ' . $panel['password_panel']],
    ]);
    $body = curl_exec($ch); curl_close($ch);
    return json_decode((string)$body, true);
}

$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $target = trim((string)($_POST['target'] ?? ''));
    $emailsRaw = trim((string)($_POST['emails'] ?? ''));
    $tgId = null;
    // تشخیص آیدی عددی یا @username
    if ($target !== '') {
        if (preg_match('/^-?\d+$/', $target)) { $tgId = $target; }
        else {
            $un = ltrim(trim($target), '@');
            try {
                $q = $pdo->prepare("SELECT id FROM user WHERE username=? LIMIT 1");
                $q->execute([$un]);
                $r = $q->fetch(PDO::FETCH_ASSOC);
                if ($r) $tgId = $r['id']; else $results[] = ['e'=>'','ok'=>false,'msg'=>'یوزرنیم تلگرام «'.$un.'» در بات پیدا نشد (اول باید /start بزند)'];
            } catch (Throwable $x) { $results[] = ['e'=>'','ok'=>false,'msg'=>'خطا: '.$x->getMessage()]; }
        }
    }
    if ($tgId !== null) {
        $emails = array_values(array_unique(array_filter(array_map('trim', preg_split('/[\r\n,;]+/', $emailsRaw)))));
        foreach ($emails as $em) {
            $g = pcall($panel, '/panel/api/clients/get/' . rawurlencode($em));
            $found = !empty($g['success']) && !empty($g['obj']['client']['email']);
            if (!$found) { $results[] = ['e'=>$em,'ok'=>false,'msg'=>'کلاینتی به این نام در پنل نیست']; continue; }
            $cc = $g['obj']['client'];
            $expMs = (int)($cc['expiryTime'] ?? 0);
            $totalB = (int)($cc['totalGB'] ?? 0);
            $volumeGB = $totalB > 0 ? round($totalB / 1000000000, 2) : 0;
            $days = $expMs > 0 ? (int)ceil(($expMs/1000 - time())/86400) : 0;
            if ($days < 0) $days = 0;
            $id_inv = bin2hex(random_bytes(6));
            try {
                $ins = $pdo->prepare("INSERT IGNORE INTO invoice
                    (id_user, id_invoice, username, time_sell, Service_location, name_product,
                     price_product, Volume, Service_time, Status, notifctions)
                    VALUES (?,?,?,?,?,?,0,?,?,'active',0)");
                $ins->execute([$tgId, $id_inv, $em, time(), $panel['name_panel'], 'سرویس دستی', $volumeGB, $days]);
                if ($ins->rowCount() > 0) {
                    $results[] = ['e'=>$em,'ok'=>true,'msg'=>"وصل شد به آیدی $tgId (حجم: {$volumeGB}GB، مدت: {$days}روز)"];
                } else {
                    $results[] = ['e'=>$em,'ok'=>false,'msg'=>'از قبل برای این کلاینت/کاربر رکورد هست (INSERT IGNORE)'];
                }
            } catch (Throwable $x) {
                $results[] = ['e'=>$em,'ok'=>false,'msg'=>'خطای دیتابیس: '.$x->getMessage()];
            }
        }
    } elseif (!$results) {
        $results[] = ['e'=>'','ok'=>false,'msg'=>'آیدی/یوزرنیم تلگرام را وارد کن'];
    }
}
?>
<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>اتصال کلاینت دستی</title>
<style>
 body{font-family:Tahoma;background:#0e1430;color:#eef;display:flex;justify-content:center;padding:24px}
 .wrap{max-width:760px;width:100%}
 .card{background:#141b3f;border:1px solid #2a3466;border-radius:16px;padding:20px;margin-bottom:16px}
 h2{margin-top:0;color:#8fb1ff} h4{color:#8fb1ff;margin:10px 0 6px}
 label{font-size:13px;color:#b9c4ee} input,textarea{width:100%;box-sizing:border-box;padding:9px;border-radius:9px;
  border:1px solid #2a3466;background:#0d1330;color:#eef;font-size:13px;direction:ltr;text-align:left;font-family:monospace}
 button{background:linear-gradient(90deg,#3b6cff,#7c4dff);color:#fff;border:none;padding:11px 22px;border-radius:10px;font-weight:bold;cursor:pointer;margin-top:8px}
 table{width:100%;border-collapse:collapse;font-size:11px;margin-top:6px}
 td,th{border:1px solid #2a3466;padding:5px 8px;text-align:right}
 .ok{color:#3ddc97} .err{color:#ff7070}
 ul.ul{font-size:12px;line-height:2;color:#cdd6f5}
</style></head><body><div class="wrap">
<div class="card"><h2>🔗 اتصال کلاینت دستی به کاربر بات</h2>
<p style="font-size:12px;color:#b9c4ee">برای شخصی که کلاینتش را در پنل سنایی <b>دستی</b> ساخته‌ای، این‌جا مشخصش کن تا سرویسش در «سرویس‌های من» بات بیاید.</p>
<form method="post">
 <label>آیدی عددی یا @یوزرنیم تلگرام (از لیست پایین یا دستی):</label>
 <input name="target" placeholder="مثلاً 123456789 یا @username" style="margin-bottom:8px">
 <label>email کلاینت (هر خط یکی — دقیقاً همان که در پنل ساختی):</label>
 <textarea name="emails" rows="3" placeholder="sara_ml&#10;ali_2026"></textarea><br>
 <button type="submit">🚀 اتصال</button>
</form>
<?php if($results){ echo '<ul class="ul">'; foreach($results as $r){ echo '<li class="'.($r['ok']?'ok':'err').'">'.e($r['e']?$r['e'].' — ':'').e($r['msg']).'</li>'; } echo '</ul>'; } ?>
</div>
<div class="card"><h4>👥 کاربرهای بات (برای انتخاب)</h4>
<table><tr><th>آیدی</th><th>@یوزرنیم</th></tr>
<?php foreach($users as $u){ echo '<tr><td>'.e($u['id']).'</td><td>'.e($u['username']).'</td></tr>'; } ?>
</table></div>
</div></body></html>
