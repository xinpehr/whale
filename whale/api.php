<?php
/*
 * WhaleVPN mini app API. Auth: the same Bearer token that /api/verify issues.
 * GET  ?action=me | service&id= | renew_plans&id= | buy_plans | quote&gb=&days=
 * POST ?action=renew {id, code | gb, days} | device {id} | topup {amount} | buy {code | gb, days}
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../botapi.php';
require_once __DIR__ . '/../jdf.php';
require_once __DIR__ . '/../function.php';
require_once __DIR__ . '/../panels.php';
date_default_timezone_set('Asia/Tehran');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function whale_api_out($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function whale_api_error($msg, $code = 200)
{
    whale_api_out(['ok' => false, 'error' => $msg], $code);
}

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if (!$auth && function_exists('getallheaders')) {
    foreach (getallheaders() as $k => $v) {
        if (strtolower($k) === 'authorization') {
            $auth = $v;
        }
    }
}
$token = preg_match('/^\s*Bearer\s+([a-f0-9]{20,100})\s*$/i', (string) $auth, $mm) ? $mm[1] : '';
if ($token === '') {
    whale_api_error('unauthorized', 403);
}
$user = select('user', '*', 'token', $token, 'select');
if (!is_array($user) || !is_string($user['token']) || !hash_equals($user['token'], $token)) {
    whale_api_error('unauthorized', 403);
}
if (($user['User_Status'] ?? '') === 'block') {
    whale_api_error('حساب شما مسدود است.', 402);
}
$from_id = $user['id'];
$textbotlang = languagechange();
$ManagePanel = new ManagePanel();
$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$body = [];
if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!is_array($body)) {
        $body = [];
    }
}

function whale_api_panel()
{
    global $user;
    $rows = whale_q("SELECT * FROM marzban_panel WHERE status = 'active' AND (agent = ? OR agent = 'all')", [$user['agent']])->fetchAll(PDO::FETCH_ASSOC);
    return $rows[0] ?? null;
}

function whale_api_status_label($status)
{
    global $textbotlang;
    return $textbotlang['users']['status'][$status] ?? ([
        'active' => 'فعال', 'on_hold' => 'در انتظار اتصال', 'disabled' => 'غیرفعال', 'expired' => 'منقضی', 'limited' => 'اتمام حجم',
    ][$status] ?? $status);
}

function whale_api_service_summary($invoice, $full = false)
{
    global $ManagePanel;
    $d = $ManagePanel->DataUser($invoice['Service_location'], $invoice['username']);
    $panel = whale_panel_by_name($invoice['Service_location']);
    $status = is_array($d) ? ($d['status'] ?? 'unknown') : 'unknown';
    if ($status === 'Unsuccessful') {
        $status = 'unknown';
    }
    $limit = intval($d['data_limit'] ?? 0);
    $used = intval($d['used_traffic'] ?? 0);
    $expire = intval($d['expire'] ?? 0);
    $sub = $d['subscription_url'] ?? null;
    $device = (is_array($panel) && ($panel['type'] ?? '') === 'x-ui_single') ? whale_device_limit_of($panel, $invoice['username']) : 0;
    $out = [
        'id' => $invoice['id_invoice'],
        'username' => $invoice['username'],
        'product_name' => $invoice['name_product'],
        'status' => $status,
        'status_label' => whale_api_status_label($status),
        'used_bytes' => $used,
        'limit_bytes' => $limit,
        'expire_ts' => $expire,
        'days_left' => $expire > 0 ? max(0, intval(floor(($expire - time()) / 86400))) : null,
        'sub_url' => $sub,
        'add_url' => $sub ? whale_add_url($sub) : null,
        'device_limit' => $device,
    ];
    if ($full) {
        $check = whale_renew_check(is_array($d) ? $d : []);
        $out += [
            'online_at' => $d['online_at'] ?? 'offline',
            'used_fmt' => formatBytes($used),
            'limit_fmt' => $limit > 0 ? formatBytes($limit) : 'نامحدود',
            'remaining_fmt' => $limit > 0 ? formatBytes(max(0, $limit - $used)) : 'نامحدود',
            'percent' => $limit > 0 ? min(100, intval(round($used * 100 / $limit))) : 0,
            'device_price' => $device > 0 ? whale_int('device_price') : 0,
            'device_price_fmt' => whale_money(whale_int('device_price')),
            'connected_devices' => $device > 0 ? whale_device_online($panel, $invoice['username']) : null,
            'can_renew' => $check['allowed'] && ($status !== 'on_hold') && (($panel['status_extend'] ?? 'on_extend') !== 'off_extend'),
            'renew_block_reason' => !$check['allowed'] ? whale_t('renew_blocked', ['days' => $check['days'] >= 9999 ? '∞' : $check['days'], 'percent' => $check['percent'], 'rule' => whale_renew_rule_text($check)]) : ($status === 'on_hold' ? 'برای تمدید ابتدا یک بار به سرویس وصل شوید.' : ''),
        ];
        $out['renew_block_reason'] = strip_tags($out['renew_block_reason']);
    }
    return $out;
}

function whale_api_invoice($id)
{
    global $user;
    $inv = whale_owned_invoice($user['id'], (string) $id);
    if (!$inv) {
        whale_api_error('سرویس پیدا نشد.', 404);
    }
    return $inv;
}

function whale_api_plans($panel)
{
    global $user;
    $products = [];
    foreach (whale_renew_products($panel, $user['agent']) as $p) {
        if (!empty($p['hide_panel'])) {
            $hidden = json_decode((string) $p['hide_panel'], true);
            if (is_array($hidden) && in_array($panel['name_panel'], $hidden, true)) {
                continue;
            }
        }
        $price = intval($p['price_product']);
        $products[] = ['code' => $p['code_product'], 'name' => $p['name_product'], 'volume_gb' => floatval($p['Volume_constraint']), 'days' => intval($p['Service_time']), 'price' => $price, 'price_fmt' => whale_money($price)];
    }
    return ['products' => $products, 'custom' => whale_custom_config($panel, $user['agent'])];
}

function whale_api_pay_in_bot($textKey, $vars, $button)
{
    global $user;
    sendmessage($user['id'], whale_t($textKey, $vars, $user['id']), whale_kb([[['text' => whale_t('btn_continue_pay', [], $user['id']), 'callback_data' => $button]]]), 'HTML');
    whale_api_out(['ok' => true, 'pay_in_bot' => true, 'message' => whale_t('api_pay_in_bot', [], $user['id'])]);
}

try {
    switch ($action) {
        case 'me':
            $rows = whale_q("SELECT * FROM invoice WHERE id_user = ? AND Status IN ('active','end_of_time','end_of_volume','sendedwarn','send_on_hold','disabled') ORDER BY time_sell DESC LIMIT 15", [(string) $user['id']])->fetchAll(PDO::FETCH_ASSOC);
            $services = [];
            foreach ($rows as $inv) {
                $services[] = whale_api_service_summary($inv);
            }
            $setting = select('setting', '*');
            $support = !empty($setting['id_support']) ? 'https://t.me/' . ltrim($setting['id_support'], '@') : null;
            whale_api_out(['ok' => true, 'user' => ['id' => (string) $user['id'], 'name' => $user['username'] ?? '', 'balance' => intval($user['Balance']), 'balance_fmt' => whale_money($user['Balance'])], 'brand' => 'WhaleVPN', 'bot_username' => whale_bot_username(), 'support_url' => $support, 'services' => $services, 'can_buy' => whale_api_panel() !== null]);

        case 'service':
            whale_api_out(['ok' => true, 'service' => whale_api_service_summary(whale_api_invoice($_GET['id'] ?? ''), true)]);

        case 'renew_plans':
            $inv = whale_api_invoice($_GET['id'] ?? '');
            $panel = whale_panel_by_name($inv['Service_location']);
            whale_api_out(['ok' => true] + whale_api_plans($panel));

        case 'buy_plans':
            $panel = whale_api_panel();
            if (!$panel) {
                whale_api_error('در حال حاضر امکان خرید وجود ندارد.');
            }
            whale_api_out(['ok' => true, 'panel_id' => $panel['code_panel']] + whale_api_plans($panel));

        case 'quote':
            $panel = isset($_GET['id']) ? whale_panel_by_name(whale_api_invoice($_GET['id'])['Service_location']) : whale_api_panel();
            if (!$panel) {
                whale_api_error('پنل در دسترس نیست.');
            }
            $price = whale_custom_price($panel, $user['agent'], intval($_GET['gb'] ?? 0), intval($_GET['days'] ?? 0));
            whale_api_out(['ok' => true, 'price' => $price, 'price_fmt' => whale_money($price)]);

        case 'renew':
            if ($method !== 'POST') {
                whale_api_error('method', 405);
            }
            $inv = whale_api_invoice($body['id'] ?? '');
            $panel = whale_panel_by_name($inv['Service_location']);
            $d = $ManagePanel->DataUser($inv['Service_location'], $inv['username']);
            if (($d['status'] ?? '') === 'on_hold') {
                whale_api_error('برای تمدید ابتدا یک بار به سرویس وصل شوید.');
            }
            $check = whale_renew_check($d);
            if (!$check['allowed']) {
                whale_api_error(strip_tags(whale_t('renew_blocked', ['days' => $check['days'], 'percent' => $check['percent'], 'rule' => whale_renew_rule_text($check)])));
            }
            $plan = whale_resolve_plan($panel, $user, $body['code'] ?? null, $body['gb'] ?? 0, $body['days'] ?? 0);
            if (!$plan) {
                whale_api_error('طرح انتخاب‌شده معتبر نیست.');
            }
            if (intval($user['Balance']) >= intval($plan['price_product'])) {
                if (!whale_renew_apply($user, $inv, $plan, $ManagePanel)) {
                    whale_api_error(whale_t('renew_failed'));
                }
                whale_api_out(['ok' => true, 'done' => true, 'message' => strip_tags(whale_t('renew_done', ['username' => $inv['username'], 'price' => whale_money($plan['price_product'])]))]);
            }
            $due = intval($plan['price_product']) - max(0, intval($user['Balance']));
            $ref = $plan['code_product'] === 'custom_volume' ? 'c' . intval($plan['Volume_constraint']) . 'x' . intval($plan['Service_time']) : 'p_' . $plan['code_product'];
            $cb = 'whale_renewpay_' . $inv['id_invoice'] . '_' . $ref;
            if (strlen($cb) > 64) {
                whale_api_error('کد طرح طولانی است؛ از ربات تمدید کنید.');
            }
            whale_api_pay_in_bot('miniapp_pay_renew', ['username' => $inv['username'], 'amount' => whale_money($due)], $cb);

        case 'device':
            if ($method !== 'POST') {
                whale_api_error('method', 405);
            }
            $inv = whale_api_invoice($body['id'] ?? '');
            $price = whale_int('device_price');
            if ($price <= 0) {
                whale_api_error(whale_t('device_not_sold'));
            }
            if (intval($user['Balance']) >= $price) {
                [$ok, $res] = whale_device_apply($inv, $price);
                if (!$ok) {
                    whale_api_error(strip_tags(whale_t($res)));
                }
                whale_api_out(['ok' => true, 'done' => true, 'device_limit' => $res, 'message' => strip_tags(whale_t('device_done', ['username' => $inv['username'], 'limit' => $res]))]);
            }
            whale_api_pay_in_bot('miniapp_pay_device', ['username' => $inv['username'], 'amount' => whale_money($price - max(0, intval($user['Balance'])))], 'whale_devok_' . $inv['id_invoice']);

        case 'topup':
            if ($method !== 'POST') {
                whale_api_error('method', 405);
            }
            $amount = intval($body['amount'] ?? 0);
            $min = intval(json_decode((string) (select('PaySetting', '*', 'NamePay', 'minbalance', 'select')['ValuePay'] ?? ''), true)[$user['agent']] ?? 0);
            $max = intval(json_decode((string) (select('PaySetting', '*', 'NamePay', 'maxbalance', 'select')['ValuePay'] ?? ''), true)[$user['agent']] ?? 0);
            if ($amount <= 0 || ($min > 0 && $amount < $min) || ($max > 0 && $amount > $max)) {
                whale_api_error(whale_t('invalid_amount', ['min' => whale_money($min), 'max' => whale_money($max)]));
            }
            whale_api_pay_in_bot('miniapp_pay_topup', ['amount' => whale_money($amount)], 'whale_topup_' . $amount);

        case 'buy':
            if ($method !== 'POST') {
                whale_api_error('method', 405);
            }
            $panel = whale_api_panel();
            if (!$panel) {
                whale_api_error('در حال حاضر امکان خرید وجود ندارد.');
            }
            $plan = whale_resolve_plan($panel, $user, $body['code'] ?? null, $body['gb'] ?? 0, $body['days'] ?? 0);
            if (!$plan) {
                whale_api_error('طرح انتخاب‌شده معتبر نیست.');
            }
            if (intval($user['Balance']) < intval($plan['price_product'])) {
                $due = intval($plan['price_product']) - max(0, intval($user['Balance']));
                whale_api_pay_in_bot('miniapp_pay_buy', ['amount' => whale_money($due)], 'whale_topup_' . $due);
            }
            $payload = ['actions' => 'purchase', 'country_id' => $panel['code_panel']];
            if ($plan['code_product'] === 'custom_volume') {
                $payload['custom_service'] = ['traffic_gb' => intval($plan['Volume_constraint']), 'time_days' => intval($plan['Service_time'])];
            } else {
                $payload['service_id'] = $plan['code_product'];
            }
            $ch = curl_init('https://' . whale_domain() . '/api/miniapp');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token],
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_RESOLVE => [whale_domain() . ':443:127.0.0.1'],
            ]);
            $res = json_decode((string) curl_exec($ch), true);
            curl_close($ch);
            if (is_array($res) && !empty($res['success'])) {
                whale_api_out(['ok' => true, 'done' => true, 'message' => 'سرویس ساخته شد و مشخصات در ربات برایتان ارسال شد.', 'service_id' => $res['order_id'] ?? null]);
            }
            whale_api_error(is_array($res) ? ($res['msg'] ?? $res['message'] ?? 'خرید ناموفق بود.') : 'خرید ناموفق بود.');

        default:
            whale_api_error('action', 400);
    }
} catch (Throwable $e) {
    error_log('whale api: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    whale_api_error('خطای داخلی، کمی بعد تلاش کنید.', 500);
}
