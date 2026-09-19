<?php
/*
 * WhaleVPN end-to-end tests. Run on the bot server as www-data:
 *   sudo -u www-data php whale/tests/e2e.php
 *
 * Real Telegram updates are fed to index.php through php-cgi with WHALE_TEST=1,
 * so every Telegram API call is captured instead of sent. Panel (3x-ui) calls are real
 * and use throwaway users (ids 9000000001xx); everything is cleaned up at the end.
 */
if (PHP_SAPI !== 'cli') {
    exit("cli only\n");
}
const ROOT = __DIR__ . '/../..';
chdir(ROOT);
putenv('WHALE_TEST=1');
$CAP = sys_get_temp_dir() . '/whale_e2e_' . getmypid() . '.jsonl';
putenv('WHALE_CAPTURE_FILE=' . $CAP);
@unlink($CAP);

require_once ROOT . '/config.php';
require_once ROOT . '/botapi.php';
require_once ROOT . '/jdf.php';
require_once ROOT . '/function.php';
require_once ROOT . '/panels.php';
ini_set('display_errors', 'stderr');
ini_set('log_errors', '0');
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
set_exception_handler(function ($e) {
    echo "\n  FATAL " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n";
});
$textbotlang = languagechange();
$ManagePanel = new ManagePanel();
whale_install();

const U1 = '900000000101';
const U2 = '900000000102';
const U3 = '900000000103';
const ADMIN = '900000000199';
const PROD = 'whalee2e';

$RESULTS = [];
$UPDATE_ID = 880000000 + (time() % 100000000);
$SECRET = select('setting', '*')['webhook_secret'];
$PANEL = whale_q("SELECT * FROM marzban_panel WHERE status = 'active' AND type = 'x-ui_single' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!$PANEL) {
    exit("no active x-ui panel\n");
}

/* ---------- harness ---------- */

function ok($name, $cond, $detail = '')
{
    global $RESULTS;
    $RESULTS[] = [$name, (bool) $cond, $detail];
    echo ($cond ? "  PASS " : "  FAIL ") . $name . ($cond || $detail === '' ? '' : " — $detail") . "\n";
    return (bool) $cond;
}

function section($t)
{
    echo "\n== $t\n";
}

function cap_offset()
{
    global $CAP;
    clearstatcache();
    return file_exists($CAP) ? filesize($CAP) : 0;
}

function cap_since($offset)
{
    global $CAP;
    clearstatcache();
    if (!file_exists($CAP)) {
        return [];
    }
    $fh = fopen($CAP, 'r');
    fseek($fh, $offset);
    $rows = [];
    while (($line = fgets($fh)) !== false) {
        $r = json_decode($line, true);
        if (is_array($r)) {
            $rows[] = $r;
        }
    }
    fclose($fh);
    return $rows;
}

function flat($d)
{
    if (isset($d['reply_markup']) && is_string($d['reply_markup'])) {
        $decoded = json_decode($d['reply_markup'], true);
        if (is_array($decoded)) {
            $d['reply_markup'] = $decoded;
        }
    }
    return json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function cap_find(array $calls, $needle, $chat = null)
{
    foreach ($calls as $c) {
        $d = $c['data'];
        if ($chat !== null && (string) ($d['chat_id'] ?? '') !== (string) $chat) {
            continue;
        }
        $blob = flat($d);
        if (strpos($blob, $needle) !== false) {
            return $c;
        }
    }
    return null;
}

function cgi($script, $method, $query, $body, array $extraEnv = [])
{
    if (!is_file(ROOT . '/' . $script)) {
        return ['body' => '', 'err' => "missing $script"];
    }
    $env = [
        'GATEWAY_INTERFACE' => 'CGI/1.1', 'REQUEST_METHOD' => $method, 'SCRIPT_FILENAME' => realpath(ROOT . '/' . $script),
        'SCRIPT_NAME' => '/' . $script, 'REQUEST_URI' => '/' . $script . ($query !== '' ? '?' . $query : ''), 'QUERY_STRING' => $query,
        'CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => (string) strlen($body), 'REMOTE_ADDR' => '149.154.167.99',
        'REDIRECT_STATUS' => '200', 'SERVER_NAME' => whale_domain(), 'HTTP_HOST' => whale_domain(), 'HTTPS' => 'on',
        'DOCUMENT_ROOT' => realpath(ROOT), 'WHALE_TEST' => '1', 'WHALE_CAPTURE_FILE' => getenv('WHALE_CAPTURE_FILE'), 'PATH' => '/usr/bin:/bin',
    ] + $extraEnv;
    $p = proc_open(['/usr/bin/php-cgi'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(realpath(ROOT . '/' . $script)), $env);
    fwrite($pipes[0], $body);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    $parts = preg_split("/\r?\n\r?\n/", $out, 2);
    return ['body' => $parts[1] ?? $out, 'err' => $err];
}

function tg(array $update)
{
    global $UPDATE_ID, $SECRET;
    $update['update_id'] = ++$UPDATE_ID;
    $off = cap_offset();
    $r = cgi('index.php', 'POST', 'secret=' . $SECRET, json_encode($update, JSON_UNESCAPED_UNICODE));
    clearSelectCache();
    $calls = cap_since($off);
    if (trim($r['err']) !== '' && stripos($r['err'], 'warning') === false && stripos($r['err'], 'deprecated') === false) {
        echo "    [stderr] " . mb_substr(trim($r['err']), 0, 300) . "\n";
    }
    return $calls;
}

function msg($uid, $text)
{
    return tg(['message' => ['message_id' => mt_rand(1000, 999999), 'from' => ['id' => (int) $uid, 'is_bot' => false, 'first_name' => 'E2E', 'username' => 'e2e' . substr($uid, -3)], 'chat' => ['id' => (int) $uid, 'type' => 'private'], 'date' => time(), 'text' => $text]]);
}

function photo($uid)
{
    return tg(['message' => ['message_id' => mt_rand(1000, 999999), 'from' => ['id' => (int) $uid, 'is_bot' => false, 'first_name' => 'E2E', 'username' => 'e2e' . substr($uid, -3)], 'chat' => ['id' => (int) $uid, 'type' => 'private'], 'date' => time(), 'photo' => [['file_id' => 'AgACAgQAAxkBAAIE2E2E', 'file_unique_id' => 'e2e', 'width' => 90, 'height' => 90]]]]);
}

function cb($uid, $data, $mid = 777)
{
    return tg(['callback_query' => ['id' => (string) mt_rand(100000, 999999999), 'from' => ['id' => (int) $uid, 'is_bot' => false, 'first_name' => 'E2E', 'username' => 'e2e' . substr($uid, -3)], 'message' => ['message_id' => $mid, 'chat' => ['id' => (int) $uid, 'type' => 'private'], 'date' => time(), 'text' => 'x'], 'chat_instance' => '1', 'data' => $data]]);
}

function api($uid, $action, $method = 'GET', array $body = [], $query = '')
{
    $token = select('user', '*', 'id', $uid, 'select')['token'];
    $r = cgi('whale/api.php', $method, 'action=' . $action . ($query ? '&' . $query : ''), $method === 'POST' ? json_encode($body) : '', ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    clearSelectCache();
    return json_decode($r['body'], true) ?: ['raw' => mb_substr($r['body'], 0, 300), 'err' => mb_substr($r['err'], 0, 300)];
}

function mini_purchase($uid, $code)
{
    $token = select('user', '*', 'id', $uid, 'select')['token'];
    $off = cap_offset();
    $r = cgi('api/miniapp.php', 'POST', 'actions=purchase', json_encode(['actions' => 'purchase', 'country_id' => $GLOBALS['PANEL']['code_panel'], 'service_id' => $code]), ['HTTP_AUTHORIZATION' => 'Bearer ' . $token]);
    clearSelectCache();
    return [json_decode($r['body'], true) ?: ['raw' => mb_substr($r['body'], 0, 400), 'err' => mb_substr($r['err'], 0, 300)], cap_since($off)];
}

function user_row($uid)
{
    clearSelectCache('user');
    return whale_q("SELECT * FROM user WHERE id = ?", [$uid])->fetch(PDO::FETCH_ASSOC);
}

function client($username)
{
    return whale_xui_client($GLOBALS['PANEL'], $username);
}

function set_balance($uid, $amount)
{
    whale_q("UPDATE user SET Balance = ? WHERE id = ?", [$amount, $uid]);
    clearSelectCache('user');
}

function latest_invoice($uid, $test = null)
{
    $sql = "SELECT * FROM invoice WHERE id_user = ?" . ($test === true ? " AND name_product = ?" : ($test === false ? " AND name_product != ?" : "")) . " ORDER BY time_sell DESC LIMIT 1";
    $params = [$uid];
    if ($test !== null) {
        $params[] = whale_test_label();
    }
    clearSelectCache('invoice');
    return whale_q($sql, $params)->fetch(PDO::FETCH_ASSOC);
}

function kb_has(?array $call = null, $needle = '')
{
    if (!$call) {
        return false;
    }
    $m = $call['data']['reply_markup'] ?? '';
    $m = is_string($m) ? (json_decode($m, true) ?? $m) : $m;
    $m = is_string($m) ? $m : json_encode($m, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    return strpos($m, $needle) !== false;
}

/* ---------- snapshot for restore ---------- */

$SNAP = [
    'whale_setting' => whale_q("SELECT * FROM whale_setting")->fetchAll(PDO::FETCH_ASSOC),
    'volumewarn' => select('setting', '*')['volumewarn'],
    'affiliatespercentage' => select('setting', '*')['affiliatespercentage'],
    'Methodextend' => $PANEL['Methodextend'],
    'panel_status' => $PANEL['status'],
    'affiliates' => whale_q("SELECT * FROM affiliates")->fetch(PDO::FETCH_ASSOC),
];

function cleanup()
{
    global $SNAP, $PANEL, $ManagePanel;
    $ids = [U1, U2, U3, ADMIN, '900000000104', '900000000105', '900000000106'];
    $in = implode(',', array_fill(0, count($ids), '?'));
    foreach (whale_q("SELECT username, Service_location FROM invoice WHERE id_user IN ($in)", $ids)->fetchAll(PDO::FETCH_ASSOC) as $inv) {
        @$ManagePanel->RemoveUser($inv['Service_location'], $inv['username']);
    }
    whale_q("DELETE FROM cancel_service WHERE username IN (SELECT username FROM invoice WHERE id_user IN ($in))", $ids);
    foreach (["DELETE FROM invoice WHERE id_user IN ($in)", "DELETE FROM Payment_report WHERE id_user IN ($in)", "DELETE FROM service_other WHERE id_user IN ($in)",
        "DELETE FROM whale_balance_log WHERE user_id IN ($in)", "DELETE FROM whale_rating WHERE user_id IN ($in)", "DELETE FROM whale_promo_user WHERE user_id IN ($in)",
        "DELETE FROM whale_ref_join WHERE user_id IN ($in)", "DELETE FROM whale_ref_join WHERE referrer IN ($in)", "DELETE FROM whale_user_gateway WHERE user_id IN ($in)",
        "DELETE FROM reagent_report WHERE user_id IN ($in)", "DELETE FROM admin WHERE id_admin IN ($in)", "DELETE FROM user WHERE id IN ($in)"] as $q) {
        try {
            whale_q($q, $ids);
        } catch (Throwable $e) {
            echo "  cleanup: " . $e->getMessage() . "\n";
        }
    }
    whale_q("DELETE FROM product WHERE code_product = ?", [PROD]);
    whale_q("DELETE FROM card_number WHERE cardnumber = '6037000000009999'");
    whale_q("DELETE FROM whale_product WHERE code_product = ?", [PROD]);
    whale_q("DELETE FROM whale_promo WHERE title LIKE 'E2E%'");
    whale_q("DELETE FROM whale_invoice WHERE id_invoice NOT IN (SELECT id_invoice FROM invoice)");
    whale_q("DELETE FROM whale_device_seen WHERE username NOT IN (SELECT username FROM invoice)");
    whale_q("DELETE FROM whale_lock WHERE k LIKE 'e2e:%' OR k LIKE 'act:9000000001%'");
    whale_q("DELETE FROM whale_setting");
    foreach ($SNAP['whale_setting'] as $row) {
        whale_q("INSERT INTO whale_setting (k, v) VALUES (?, ?)", [$row['k'], $row['v']]);
    }
    update('setting', 'volumewarn', $SNAP['volumewarn']);
    update('setting', 'affiliatespercentage', $SNAP['affiliatespercentage']);
    update('marzban_panel', 'Methodextend', $SNAP['Methodextend'], 'code_panel', $PANEL['code_panel']);
    update('marzban_panel', 'status', $SNAP['panel_status'], 'code_panel', $PANEL['code_panel']);
    whale_q("UPDATE whale_panel_health SET auto_disabled = 0, fails = 0, down_since = NULL WHERE code_panel = ?", [$PANEL['code_panel']]);
    if (is_array($SNAP['affiliates'])) {
        whale_q("UPDATE affiliates SET status_commission = ?, Discount = ?, price_Discount = ?, porsant_one_buy = ?", [$SNAP['affiliates']['status_commission'], $SNAP['affiliates']['Discount'], $SNAP['affiliates']['price_Discount'], $SNAP['affiliates']['porsant_one_buy']]);
    }
    $GLOBALS['whale_setting_cache'] = null;
}

register_shutdown_function(function () {
    global $CAP;
    echo "\n== cleanup\n";
    cleanup();
    @unlink($CAP);
});

cleanup();

function new_user($uid)
{
    msg($uid, '/start');
    whale_q("UPDATE user SET roll_Status = 1, verify = '1', cardpayment = '1', step = 'home', token = ? WHERE id = ?", [bin2hex(random_bytes(20)), $uid]);
    clearSelectCache('user');
}

/* ---------- scenarios ---------- */

section('schema');
$tables = whale_q("SHOW TABLES LIKE 'whale_%'")->fetchAll(PDO::FETCH_COLUMN);
ok('whale tables exist', count($tables) >= 10, implode(',', $tables));
ok('balance trigger exists', (bool) whale_q("SHOW TRIGGERS LIKE 'user'")->fetch(PDO::FETCH_ASSOC));

section('button colors and location text');
$off = cap_offset();
telegram('sendmessage', ['chat_id' => U1, 'text' => "📍 موقعیت سرویس : " . $PANEL['name_panel'], 'reply_markup' => json_encode(['inline_keyboard' => [[['text' => 'buy', 'callback_data' => 'confirmandgetservice'], ['text' => 'del', 'callback_data' => 'removeauto-abc']]]])]);
$c = cap_since($off)[0] ?? null;
ok('confirm button is green', kb_has($c, '"style":"success"'));
ok('delete button is red', kb_has($c, '"style":"danger"'));
ok('panel name replaced by sub wording', $c && strpos($c['data']['text'], 'همه‌ی لوکیشن‌ها') !== false, $c['data']['text'] ?? '');

section('users');
new_user(U1);
new_user(U2);
whale_q("INSERT IGNORE INTO admin (id_admin, username, password, rule) VALUES (?, 'e2e', '', 'administrator')", [ADMIN]);
new_user(ADMIN);
ok('test users registered', user_row(U1) && user_row(U2) && user_row(ADMIN));

section('test account: wait message, one-tap button, device limit');
whale_set('device_limit_test', 2);
whale_q("UPDATE user SET limit_usertest = 1 WHERE id = ?", [U1]);
$calls = cb(U1, 'usertestbtn', 4242);
$test = latest_invoice(U1, true);
ok('test account created', (bool) $test, json_encode(array_slice(array_map(fn($c) => mb_substr(json_encode($c['data'], JSON_UNESCAPED_UNICODE), 0, 160), $calls), 0, 4), JSON_UNESCAPED_UNICODE));
ok('hourglass shown while creating', (bool) cap_find($calls, '"text":"⏳"'));
ok('one-tap add button in delivery message', (bool) cap_find($calls, '/whale/add.php?u='));
if ($test) {
    $cl = client($test['username']);
    ok('test client limitIp = 2 on panel', intval($cl['limitIp'] ?? -1) === 2, json_encode($cl['limitIp'] ?? null));
}

section('one-tap add page');
if (!is_file(ROOT . '/whale/add.php')) {
    ok('add page deployed', false, 'whale/add.php missing');
} else {
$sub = rtrim($PANEL['linksubx'], '/') . '/abcdef0123456789';
$u = rtrim(strtr(base64_encode($sub), '+/', '-_'), '=');
$page = cgi('whale/add.php', 'GET', 'u=' . $u, '');
ok('add page lists Happ deep link', strpos($page['body'], 'happ://add/') !== false);
ok('add page lists v2rayNG deep link', strpos($page['body'], 'v2rayng://install-config') !== false);
$bad = cgi('whale/add.php', 'GET', 'u=' . rtrim(strtr(base64_encode('https://evil.example/sub/x'), '+/', '-_'), '='), '');
ok('add page rejects foreign hosts', strpos($bad['body'], 'happ://add/') === false);
}

section('purchase from wallet (mini app) with product device limit');
whale_q("INSERT INTO product (code_product, name_product, price_product, Volume_constraint, Location, Service_time, agent, note, data_limit_reset, one_buy_status, inbounds, proxies, category, hide_panel) VALUES (?, 'E2E plan', '20000', '1', ?, '30', 'f', '', 'no_reset', '0', NULL, NULL, NULL, '[]')", [PROD, $PANEL['name_panel']]);
whale_q("INSERT INTO whale_product (code_product, device_limit) VALUES (?, 1)", [PROD]);
set_balance(U1, 100000);
[$res, $calls] = mini_purchase(U1, PROD);
$paid = latest_invoice(U1, false);
ok('purchase succeeded', is_array($res) && !empty($res['success']) && $paid, json_encode($res, JSON_UNESCAPED_UNICODE));
$PID = $paid['id_invoice'] ?? '';
$PUSER = $paid['username'] ?? '';
if ($paid) {
    ok('paid client limitIp = 1 (product rule)', intval(client($PUSER)['limitIp'] ?? -1) === 1);
    ok('balance charged 20000', intval(user_row(U1)['Balance']) === 80000, user_row(U1)['Balance']);
    $log = whale_q("SELECT * FROM whale_balance_log WHERE user_id = ? ORDER BY id DESC LIMIT 1", [U1])->fetch(PDO::FETCH_ASSOC);
    ok('balance log recorded purchase', $log && intval($log['delta']) === -20000, json_encode($log));
}

section('service screen');
$calls = cb(U1, 'product_' . $PID);
$screen = cap_find($calls, 'whale_dev_');
ok('service screen has devices button', (bool) $screen, mb_substr(json_encode(end($calls)['data'] ?? [], JSON_UNESCAPED_UNICODE), 0, 300));
ok('service screen has one-tap button', kb_has($screen, '/whale/add.php'));
ok('service screen shows device line', $screen && strpos($screen['data']['text'] ?? '', 'دستگاه') !== false);
ok('no change-location button', !kb_has($screen, 'changeloc_'));

section('update keeps device limit (status toggle)');
cb(U1, 'confirmaccountdisable_' . $PID);
$afterToggle = client($PUSER);
cb(U1, 'confirmaccountdisable_' . $PID);
$afterBack = client($PUSER);
ok('limitIp kept after disable', intval($afterToggle['limitIp'] ?? -1) === 1, json_encode(['enable' => $afterToggle['enable'] ?? null, 'limitIp' => $afterToggle['limitIp'] ?? null]));
ok('limitIp kept after enable', intval($afterBack['limitIp'] ?? -1) === 1 && !empty($afterBack['enable']));

section('extra device from wallet');
whale_set('device_price', 10000);
$calls = cb(U1, 'whale_devbuy_' . $PID);
ok('extra device prompt shown', (bool) cap_find($calls, 'whale_devok_' . $PID));
$calls = cb(U1, 'whale_devok_' . $PID);
ok('limitIp raised to 2', intval(client($PUSER)['limitIp'] ?? -1) === 2);
ok('wallet charged 10000', intval(user_row(U1)['Balance']) === 70000, user_row(U1)['Balance']);
$log = whale_q("SELECT reason FROM whale_balance_log WHERE user_id = ? ORDER BY id DESC LIMIT 1", [U1])->fetchColumn();
ok('balance log reason = extra device', strpos((string) $log, 'دستگاه') !== false, $log);

section('extra device through card-to-card');
whale_q("INSERT IGNORE INTO card_number (cardnumber, namecard) VALUES ('6037000000009999', 'E2E TEST')");
set_balance(U1, 0);
$calls = cb(U1, 'whale_devok_' . $PID);
$u1 = user_row(U1);
ok('gateway list shown', (bool) cap_find($calls, 'cart_to_offline'));
ok('order stored as whale_device', $u1['Processing_value_tow'] === 'whale_device' && $u1['step'] === 'get_step_payment', $u1['Processing_value_tow'] . '/' . $u1['step']);
ok('amount due raised to gateway minimum (20000)', intval($u1['Processing_value']) === 20000, $u1['Processing_value']);
$calls = cb(U1, 'cart_to_offline');
$order = whale_q("SELECT * FROM Payment_report WHERE id_user = ? ORDER BY id DESC LIMIT 1", [U1])->fetch(PDO::FETCH_ASSOC);
ok('payment report created', $order && strpos($order['id_invoice'], 'whale_device|') === 0, json_encode($order));
if ($order) {
    cb(U1, 'sendresidcart-' . $order['id_order']);
    photo(U1);
    $calls = cb(ADMIN, 'Confirm_pay_' . $order['id_order']);
    clearSelectCache();
    ok('card payment confirmed', whale_q("SELECT payment_Status FROM Payment_report WHERE id_order = ?", [$order['id_order']])->fetchColumn() === 'paid');
    ok('limitIp raised to 3 after payment', intval(client($PUSER)['limitIp'] ?? -1) === 3, json_encode(client($PUSER)['limitIp'] ?? null));
    ok('user told device added', (bool) cap_find($calls, 'سقف دستگاه', U1));
    ok('extra paid amount kept in wallet (20000 - 10000)', intval(user_row(U1)['Balance']) === 10000, user_row(U1)['Balance']);
}
whale_q("DELETE FROM card_number WHERE cardnumber = '6037000000009999'");

section('gateway rules');
set_balance(U1, 0);
whale_set('gateway_rules', json_encode(['cart_to_offline' => ['min_paid' => 50, 'min_days' => 0]]));
cb(U1, 'Add_Balance');
$calls = msg(U1, '50000');
$list = cap_find($calls, 'colselist', U1);
ok('card-to-card hidden by min purchases rule', $list && !kb_has($list, 'cart_to_offline'));
$calls = cb(U1, 'cart_to_offline');
ok('crafted callback blocked', (bool) cap_find($calls, 'فعال نیست'));
whale_set('gateway_rules', '{}');
whale_q("INSERT INTO whale_user_gateway (user_id, gateway) VALUES (?, 'cart_to_offline')", [U1]);
cb(U1, 'Add_Balance');
$calls = msg(U1, '50000');
$list = cap_find($calls, 'colselist', U1);
ok('card-to-card hidden for this user', $list && !kb_has($list, 'cart_to_offline'));
whale_q("DELETE FROM whale_user_gateway WHERE user_id = ?", [U1]);
cb(U1, 'Add_Balance');
$calls = msg(U1, '50000');
ok('card-to-card visible again', kb_has(cap_find($calls, 'colselist', U1), 'cart_to_offline'));
whale_q("UPDATE user SET step = 'home' WHERE id = ?", [U1]);

section('wallet history');
$calls = cb(U1, 'whale_wallet_log');
ok('wallet history message', (bool) cap_find($calls, 'گردش کیف پول', U1));

section('refund estimate before deletion');
cb(U1, 'removeserviceuser_' . $PID);
$calls = msg(U1, 'e2e reason');
ok('refund estimate shown', (bool) cap_find($calls, 'مبلغ تقریبی قابل برگشت', U1));

section('renewal threshold');
whale_set('renew_max_days_left', 5);
$calls = cb(U1, 'extend_' . $PID);
ok('early renewal blocked', (bool) cap_find($calls, 'هنوز زمان تمدید', U1));
whale_set('renew_max_days_left', 0);
$calls = cb(U1, 'extend_' . $PID);
ok('renewal allowed without rule', !cap_find($calls, 'هنوز زمان تمدید', U1));

section('mini app API');
$me = api(U1, 'me');
ok('api me lists the service', !empty($me['ok']) && in_array($PID, array_column($me['services'] ?? [], 'id'), true), json_encode($me, JSON_UNESCAPED_UNICODE));
$sv = api(U1, 'service', 'GET', [], 'id=' . $PID);
ok('api service has add_url and device limit', !empty($sv['service']['add_url']) && intval($sv['service']['device_limit']) === 3, json_encode($sv['service'] ?? $sv, JSON_UNESCAPED_UNICODE));
$pl = api(U1, 'renew_plans', 'GET', [], 'id=' . $PID);
ok('api renew plans include product', in_array(PROD, array_column($pl['products'] ?? [], 'code'), true));
$off = cap_offset();
$tp = api(U1, 'topup', 'POST', ['amount' => 50000]);
$calls = cap_since($off);
ok('api topup asks to pay in bot', !empty($tp['pay_in_bot']) && cap_find($calls, 'whale_topup_50000'), json_encode($tp, JSON_UNESCAPED_UNICODE));
$calls = cb(U1, 'whale_topup_50000');
ok('topup button opens gateway list', (bool) cap_find($calls, 'cart_to_offline', U1) && user_row(U1)['Processing_value'] == '50000');
whale_q("UPDATE user SET step = 'home' WHERE id = ?", [U1]);
$bad = api(U2, 'service', 'GET', [], 'id=' . $PID);
ok('api refuses other users service', empty($bad['ok']));

section('renewal from mini app: method, commission, limit kept');
whale_set('renew_commission_percent', 10);
update('marzban_panel', 'Methodextend', 'resetTimeConvertVolume', 'code_panel', $PANEL['code_panel']);
whale_q("UPDATE affiliates SET status_commission = 'oncommission'");
whale_q("UPDATE user SET affiliates = ? WHERE id = ?", [U2, U1]);
set_balance(U2, 0);
set_balance(U1, 50000);
$before = client($PUSER);
$rn = api(U1, 'renew', 'POST', ['id' => $PID, 'code' => PROD]);
$after = client($PUSER);
ok('renew done', !empty($rn['done']), json_encode($rn, JSON_UNESCAPED_UNICODE));
$gb = 1073741824;
ok('volume = new 1GB + unused leftover', intval($after['totalGB'] ?? 0) === intval($gb + max(0, intval($before['totalGB']) - 0)), ($before['totalGB'] ?? '?') . ' -> ' . ($after['totalGB'] ?? '?'));
$exp = intval(($after['expiryTime'] ?? 0) / 1000);
ok('time restarts from now (+30d)', abs($exp - (time() + 30 * 86400)) < 600, date('c', $exp));
ok('limitIp kept after renewal', intval($after['limitIp'] ?? -1) === 3);
ok('referrer got 10% renewal commission', intval(user_row(U2)['Balance']) === 2000, user_row(U2)['Balance']);
ok('renewal recorded in service_other', (bool) whale_q("SELECT 1 FROM service_other WHERE id_user = ? AND type = 'extend_user' AND status = 'paid'", [U1])->fetchColumn());

section('renewal needing a gateway');
set_balance(U1, 5000);
$rn = api(U1, 'renew', 'POST', ['id' => $PID, 'code' => PROD]);
ok('mini app asks to pay in bot', !empty($rn['pay_in_bot']), json_encode($rn, JSON_UNESCAPED_UNICODE));
$calls = cb(U1, 'whale_renewpay_' . $PID . '_p_' . PROD);
$u1 = user_row(U1);
ok('gateway flow started for renewal', $u1['Processing_value_tow'] === 'getextenduser' && intval($u1['Processing_value']) === 15000 && cap_find($calls, 'cart_to_offline', U1), json_encode([$u1['Processing_value_tow'], $u1['Processing_value']]));
whale_q("UPDATE user SET step = 'home' WHERE id = ?", [U1]);

section('commission minimum on purchase');
update('setting', 'affiliatespercentage', '10');
// the mini app pays purchase commission only inside the "on_buy_porsant" branch (upstream behaviour);
// the user already has several invoices, so its "not first purchase" branch pays on every purchase
whale_q("UPDATE affiliates SET porsant_one_buy = 'on_buy_porsant'");
whale_set('commission_min_amount', 0);
set_balance(U2, 0);
set_balance(U1, 100000);
[$pr, $prCalls] = mini_purchase(U1, PROD);
ok('purchase for commission test succeeded', !empty($pr['success']), json_encode($pr, JSON_UNESCAPED_UNICODE));
$diag = [
    'pct' => whale_q("SELECT affiliatespercentage FROM setting")->fetchColumn(),
    'u1_affiliates' => user_row(U1)['affiliates'],
    'aff' => whale_q("SELECT status_commission, porsant_one_buy FROM affiliates")->fetch(PDO::FETCH_ASSOC),
    'min' => whale_get('commission_min_amount'),
    'u2_log' => whale_q("SELECT delta, reason FROM whale_balance_log WHERE user_id = ? ORDER BY id DESC LIMIT 3", [U2])->fetchAll(PDO::FETCH_ASSOC),
    'calls_to_u2' => array_map(fn($c) => mb_substr($c['data']['text'] ?? '', 0, 80), array_values(array_filter($prCalls, fn($c) => (string) ($c['data']['chat_id'] ?? '') === U2))),
    'report' => array_map(fn($c) => mb_substr($c['data']['text'] ?? '', 0, 80), array_values(array_filter($prCalls, fn($c) => strpos($c['data']['text'] ?? '', 'پورسانت') !== false))),
];
ok('commission paid when above minimum', intval(user_row(U2)['Balance']) === 2000, user_row(U2)['Balance'] . ' ' . json_encode($diag, JSON_UNESCAPED_UNICODE));
whale_set('commission_min_amount', 50000);
set_balance(U2, 0);
mini_purchase(U1, PROD);
ok('no commission below minimum', intval(user_row(U2)['Balance']) === 0, user_row(U2)['Balance']);
update('setting', 'affiliatespercentage', $SNAP['affiliatespercentage']);

section('tiered volume price');
whale_set('volume_tiers', "1-10:4000\n11-100:3000");
ok('5GB uses 4000/GB', intval(whale_volume_price(5, 4000, 'f')) === 20000);
ok('20GB uses 3000/GB', intval(whale_volume_price(20, 4000, 'f')) === 60000);
ok('agents keep their base price', intval(whale_volume_price(20, 4000, 'n')) === 80000);
$q = api(U1, 'quote', 'GET', [], 'gb=20&days=30');
ok('api quote uses tiers', intval($q['price'] ?? 0) === 60000 + 30 * intval(json_decode($PANEL['pricecustomtime'], true)['f'] ?? 0), json_encode($q));

section('volume warning in MB');
cb(ADMIN, 'settimecornvolume');
msg(ADMIN, '500MB');
clearSelectCache('setting');
ok('500MB stored as GB decimal', select('setting', '*')['volumewarn'] === '0.4883', select('setting', '*')['volumewarn']);

section('not-connected reminder and rating');
whale_set('nudge_days', 1);
whale_set('rating_days', 1);
whale_set('rating_require_online', 0);
whale_q("UPDATE invoice SET time_sell = ?, time_cron = NULL, Status = 'active' WHERE id_invoice = ?", [time() - 3 * 86400, $PID]);
whale_q("DELETE FROM whale_invoice WHERE id_invoice = ?", [$PID]);
$off = cap_offset();
$r = cgi('cronbot/NoticationsService.php', 'GET', '', '');
exec('cd ' . escapeshellarg(ROOT . '/cronbot') . ' && WHALE_TEST=1 WHALE_CAPTURE_FILE=' . escapeshellarg(getenv('WHALE_CAPTURE_FILE')) . ' php NoticationsService.php 2>&1', $o);
$calls = cap_since($off);
ok('reminder sent to never-connected user', (bool) cap_find($calls, 'هنوز به آن وصل نشده‌اید', U1));
ok('rating request sent', (bool) cap_find($calls, 'whale_rate_' . $PID . '_5', U1));
$calls = cb(U1, 'whale_rate_' . $PID . '_2');
ok('low rating asks for a comment', (bool) cap_find($calls, 'چه مشکلی بود', U1));
$calls = msg(U1, 'e2e: slow at night');
$rating = whale_q("SELECT * FROM whale_rating WHERE id_invoice = ?", [$PID])->fetch(PDO::FETCH_ASSOC);
ok('rating and comment saved', $rating && intval($rating['rating']) === 2 && strpos($rating['comment'], 'slow') !== false);
ok('rating reported to admins', (bool) cap_find($calls, 'توضیح امتیاز'));

section('unused test reminder');
if ($test) {
    whale_set('test_nudge_hours', 1);
    whale_q("UPDATE invoice SET time_sell = ?, Status = 'active' WHERE id_invoice = ?", [time() - 2 * 3600, $test['id_invoice']]);
    whale_q("DELETE FROM whale_invoice WHERE id_invoice = ?", [$test['id_invoice']]);
    $off = cap_offset();
    for ($i = 0; $i < 4 && !cap_find(cap_since($off), 'اکانت تست', U1); $i++) {
        exec('cd ' . escapeshellarg(ROOT . '/cronbot') . ' && WHALE_TEST=1 WHALE_CAPTURE_FILE=' . escapeshellarg(getenv('WHALE_CAPTURE_FILE')) . ' php configtest.php 2>&1');
    }
    ok('unused test reminder sent', (bool) cap_find(cap_since($off), 'اکانت تست', U1));
}

section('promo link');
$calls = cb(ADMIN, 'whale_admin_promonew');
$calls = msg(ADMIN, 'E2E channel');
$code = whale_q("SELECT code FROM whale_promo WHERE title = 'E2E channel'")->fetchColumn();
ok('promo link created by admin', $code && cap_find($calls, '?start=ad_' . $code));
msg(U3, '/start ad_' . $code);
$pu = whale_q("SELECT * FROM whale_promo_user WHERE user_id = ?", [U3])->fetch(PDO::FETCH_ASSOC);
ok('new user attributed to promo', $pu && $pu['code'] === $code && intval($pu['is_new']) === 1);
$st = array_values(array_filter(whale_promo_stats(), fn($p) => $p['code'] === $code))[0] ?? null;
ok('promo stats count member and click', $st && intval($st['members']) === 1 && intval($st['hits']) === 1, json_encode($st));

section('suspicious referral alert and start gift');
whale_set('ref_alert_per_hour', 3);
$off = cap_offset();
foreach (['900000000104', '900000000105', '900000000106'] as $ref) {
    msg($ref, '/start ' . U2);
}
ok('alert after 3 new referrals in an hour', (bool) cap_find(cap_since($off), 'زیرمجموعه‌گیری مشکوک'));
whale_set('start_gift_after_purchase', 1);
whale_q("UPDATE affiliates SET Discount = 'onDiscountaffiliates', price_Discount = '10000'");
whale_q("UPDATE user SET affiliates = ? WHERE id = '900000000104'", [U2]);
whale_q("INSERT IGNORE INTO reagent_report (user_id, get_gift, time, reagent) VALUES ('900000000104', 0, ?, ?)", [time(), U2]);
whale_q("UPDATE user SET roll_Status = 1, verify = '1', step = 'home' WHERE id = '900000000104'");
$b = intval(user_row('900000000104')['Balance']);
$calls = cb('900000000104', 'get_gift_start');
ok('start gift held until first purchase', cap_find($calls, 'بعد از اولین خرید') && intval(user_row('900000000104')['Balance']) === $b);

section('admin menu');
$calls = msg(ADMIN, '/whale');
ok('admin menu opens', (bool) cap_find($calls, 'whale_admin_settings', ADMIN));
cb(ADMIN, 'whale_admin_set_nudge_days');
msg(ADMIN, '4');
$GLOBALS['whale_setting_cache'] = null;
ok('admin can change a setting', whale_int('nudge_days') === 4);
$calls = msg(U1, '/whale');
ok('non-admin cannot open menu', !cap_find($calls, 'whale_admin_settings'));

section('panel health: pause and resume sales');
whale_set('panel_fail_threshold', 2);
$GLOBALS['whale_probe_override'] = fn($p) => ['ok' => false, 'error' => 'curl 7: Failed to connect (e2e)', 'ms' => 5];
try {
    $off = cap_offset();
    whale_panel_health_tick(true);
    whale_panel_health_tick(true);
    clearSelectCache('marzban_panel');
    $status = whale_q("SELECT status FROM marzban_panel WHERE code_panel = ?", [$PANEL['code_panel']])->fetchColumn();
    ok('sales paused after 2 failures', $status === 'disable', $status);
    ok('admins told the exact error', (bool) cap_find(cap_since($off), 'Failed to connect (e2e)'));
    $GLOBALS['whale_probe_override'] = fn($p) => ['ok' => true, 'error' => null, 'ms' => 42];
    whale_panel_health_tick(true);
    $status = whale_q("SELECT status FROM marzban_panel WHERE code_panel = ?", [$PANEL['code_panel']])->fetchColumn();
    ok('sales resumed when panel is back', $status === 'active', $status);
} finally {
    unset($GLOBALS['whale_probe_override']);
    update('marzban_panel', 'status', $SNAP['panel_status'], 'code_panel', $PANEL['code_panel']);
}
$real = whale_panel_probe($PANEL);
ok('real probe of live panel succeeds', $real['ok'], json_encode($real));

section('duplicate action and cron locks');
putenv('WHALE_TEST=0'); // both guards stand aside in test mode, so exercise them for real
try {
    whale_action_release('e2e:guard');
    ok('first tap of an action is allowed', whale_action_guard('e2e:guard', 5));
    ok('second tap within the window is blocked', !whale_action_guard('e2e:guard', 5));
    whale_action_release('e2e:guard');
    ok('action is free again once released', whale_action_guard('e2e:guard', 5));
    ok('first cron run takes the lock', whale_cron_guard('e2e_lock'));
    ok('second cron run steps aside', !whale_cron_guard('e2e_lock'));
} finally {
    putenv('WHALE_TEST=1');
    whale_action_release('e2e:guard');
    @unlink(ROOT . '/storage/cache/whale-cron-e2e_lock.lock');
}

section('device kicked notification');
$inv = whale_q("SELECT * FROM invoice WHERE id_invoice = ?", [$PID])->fetch(PDO::FETCH_ASSOC);
if ($inv) {
    whale_set('device_notify', 1);
    $cardUser = trim($inv['username']);
    // sized to the limit the tick itself will read, so the "at the limit" condition holds
    $limit = max(1, whale_device_limit_of($PANEL, $cardUser));
    $before = [];
    for ($i = 1; $i <= $limit; $i++) {
        $before[] = '10.0.0.' . $i;
    }
    $after = $before;
    array_shift($after);
    $after[] = '10.9.9.9';
    sort($after);
    whale_q("INSERT INTO whale_device_seen (username, ips, checked) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE ips = VALUES(ips), checked = VALUES(checked)", [$cardUser, json_encode($before), time() - 120]);
    $GLOBALS['whale_device_ips_override'] = fn($p, $u) => trim($u) === $cardUser ? $after : null;
    try {
        $off = cap_offset();
        whale_device_watch_tick(200);
        $calls = cap_since($off);
        ok('user told the older device was disconnected', (bool) cap_find($calls, 'دستگاه جدیدی', $inv['id_user']));
        ok('notice offers the devices button', (bool) cap_find($calls, 'whale_dev_' . $PID, $inv['id_user']));
        $stored = json_decode((string) whale_q("SELECT ips FROM whale_device_seen WHERE username = ?", [$cardUser])->fetchColumn(), true);
        ok('last seen IPs stored for the next run', $stored === $after, json_encode($stored));
        // a device joining without pushing anyone off must stay silent
        whale_q("UPDATE whale_device_seen SET ips = ? WHERE username = ?", [json_encode(array_slice($after, 0, max(1, count($after) - 1))), $cardUser]);
        $off = cap_offset();
        whale_device_watch_tick(200);
        ok('a new device alone is not reported', !cap_find(cap_since($off), 'دستگاه جدیدی', $inv['id_user']));
        // and nothing at all happens while the feature is off
        whale_set('device_notify', 0);
        whale_q("UPDATE whale_device_seen SET ips = ? WHERE username = ?", [json_encode($before), $cardUser]);
        $off = cap_offset();
        whale_device_watch_tick(200);
        ok('switch off stops the notices', !cap_find(cap_since($off), 'دستگاه جدیدی', $inv['id_user']));
        whale_set('device_notify', 1);
    } finally {
        unset($GLOBALS['whale_device_ips_override']);
    }
}

section('service status card');
whale_set('card_enabled', 1);
ok('GD and the Persian font are available', whale_card_available());
$png = whale_card_render([
    'username' => 'e2e-card', 'product' => 'WhaleVPN e2e', 'status' => 'active',
    'used' => 3.5 * (1024 ** 3), 'total' => 10 * (1024 ** 3),
    'expire' => time() + 12 * 86400, 'devices' => 2, 'user_id' => U1,
]);
ok('card renders a PNG', is_string($png) && strncmp($png, "\x89PNG", 4) === 0 && strlen($png) > 3000, is_string($png) ? strlen($png) . ' bytes' : 'null');
$unlimited = whale_card_render(['username' => 'e2e-card', 'status' => 'active', 'used' => 0, 'total' => 0, 'expire' => null, 'devices' => 0, 'user_id' => U1]);
ok('card renders for an unlimited service', is_string($unlimited) && strncmp($unlimited, "\x89PNG", 4) === 0);
$calls = cb(U1, 'product_' . $PID);
ok('service screen offers the status card', (bool) cap_find($calls, 'whale_card_' . $PID));
$calls = cb(U1, 'whale_card_' . $PID);
$photo = null;
foreach ($calls as $c) {
    if (($c['method'] ?? '') === 'sendphoto') {
        $photo = $c;
        break;
    }
}
ok('card sent as a photo with a caption', $photo && strpos(json_encode($photo['data'] ?? [], JSON_UNESCAPED_UNICODE), 'کارت وضعیت') !== false, mb_substr(json_encode(end($calls)['data'] ?? [], JSON_UNESCAPED_UNICODE), 0, 300));
whale_set('card_enabled', 0);
$calls = cb(U1, 'product_' . $PID);
ok('card button hidden when switched off', !cap_find($calls, 'whale_card_' . $PID));
whale_set('card_enabled', 1);

section('live plans: volume, duration and device limit reach the panel');
whale_q("UPDATE user SET roll_Status = 1, verify = '1', step = 'home', token = ? WHERE id = ?", [bin2hex(random_bytes(20)), U3]);
clearSelectCache('user');
foreach (['orca1' => [50, 30, 2], 'beluga3' => [60, 90, 1]] as $code => [$gb, $days, $dev]) {
    $plan = whale_q("SELECT * FROM product WHERE code_product = ?", [$code])->fetch(PDO::FETCH_ASSOC);
    if (!$plan) {
        ok("plan $code exists", false);
        continue;
    }
    set_balance(U3, intval($plan['price_product']) + 1000);
    [$res] = mini_purchase(U3, $code);
    $inv = latest_invoice(U3, false);
    ok("plan $code purchased", !empty($res['success']) && $inv, json_encode($res, JSON_UNESCAPED_UNICODE));
    $c = $inv ? client(trim($inv['username'])) : null;
    ok("plan $code: {$gb} GB on the panel", $c && intval($c['totalGB']) === $gb * 1024 ** 3, json_encode($c));
    $left = $c ? (intval($c['expiryTime']) / 1000 - time()) / 86400 : 0;
    ok("plan $code: {$days} days on the panel", abs($left - $days) < 1.5 || intval($c['expiryTime'] ?? 0) < 0, (string) round($left, 2));
    ok("plan $code: {$dev} device(s) on the panel", $c && intval($c['limitIp']) === $dev, json_encode($c['limitIp'] ?? null));
    ok("plan $code: wallet charged the plan price", intval(user_row(U3)['Balance']) === 1000, user_row(U3)['Balance']);
}

/* ---------- summary ---------- */
$pass = count(array_filter($RESULTS, fn($r) => $r[1]));
$fail = count($RESULTS) - $pass;
echo "\n== RESULT: $pass passed, $fail failed\n";
foreach ($RESULTS as $r) {
    if (!$r[1]) {
        echo "  ✗ {$r[0]}" . ($r[2] !== '' ? " — " . mb_substr($r[2], 0, 400) : '') . "\n";
    }
}
