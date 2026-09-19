<?php
/*
 * WhaleVPN commerce: tiered pricing, gateway rules, balance log, referral rules,
 * promo links, extra devices and renewals paid from the wallet or a gateway.
 */

/* ---------- tiered custom-volume price ---------- */

function whale_volume_tiers()
{
    $tiers = [];
    foreach (preg_split('/\r?\n/', (string) whale_get('volume_tiers')) as $line) {
        $line = strtr(trim($line), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', ',' => '']);
        if (preg_match('/^(\d+)\s*-\s*(\d+)\s*:\s*(\d+)$/', $line, $m)) {
            $tiers[] = ['min' => intval($m[1]), 'max' => intval($m[2]), 'unit' => intval($m[3])];
        }
    }
    usort($tiers, function ($a, $b) {
        return $a['min'] <=> $b['min'];
    });
    return $tiers;
}

function whale_volume_unit_price($gb, $base, $agent = 'f')
{
    if ($agent !== 'f') {
        return $base;
    }
    foreach (whale_volume_tiers() as $t) {
        if ($gb >= $t['min'] && $gb <= $t['max']) {
            return $t['unit'];
        }
    }
    return $base;
}

function whale_volume_price($gb, $base, $agent = 'f')
{
    $gb = floatval($gb);
    return $gb * floatval(whale_volume_unit_price($gb, $base, $agent ?: 'f'));
}

function whale_send_price_tiers($user_id, $agent = 'f')
{
    $tiers = whale_volume_tiers();
    if ($agent !== 'f' || !$tiers) {
        return;
    }
    $lines = ["📉 <b>هرچه بیشتر، ارزان‌تر</b>"];
    foreach ($tiers as $t) {
        $lines[] = "• {$t['min']} تا {$t['max']} گیگ: هر گیگ " . whale_money($t['unit']) . " تومان";
    }
    sendmessage($user_id, implode("\n", $lines), null, 'HTML');
}

/* ---------- gateway rules ---------- */

function whale_gateway_keys()
{
    return [
        'cart_to_offline' => 'کارت به کارت',
        'aqayepardakht' => 'آقای پرداخت',
        'zarinpal' => 'زرین‌پال',
        'plisio' => 'Plisio',
        'nowpayment' => 'NowPayments',
        'iranpay1' => 'ارزی ریالی ۱',
        'iranpay2' => 'ارزی ریالی ۲',
        'iranpay3' => 'ارزی ریالی ۳',
        'iranpay4' => 'AbanGateway',
        'digitaltron' => 'ارز آفلاین',
        'startelegrams' => 'استارز تلگرام',
        'paymentnotverify' => 'پرداخت تأییدنشده',
    ];
}

function whale_gateway_rules()
{
    $rules = json_decode((string) whale_get('gateway_rules'), true);
    return is_array($rules) ? $rules : [];
}

function whale_gateway_allowed($user_id, $gateway)
{
    if (whale_q("SELECT 1 FROM whale_user_gateway WHERE user_id = ? AND gateway = ?", [(string) $user_id, $gateway])->fetchColumn()) {
        return false;
    }
    $rule = whale_gateway_rules()[$gateway] ?? null;
    if (!is_array($rule)) {
        return true;
    }
    $minPaid = intval($rule['min_paid'] ?? 0);
    $minDays = intval($rule['min_days'] ?? 0);
    if ($minPaid > 0) {
        $paid = intval(whale_q("SELECT COUNT(*) FROM Payment_report WHERE id_user = ? AND payment_Status = 'paid'", [(string) $user_id])->fetchColumn());
        if ($paid < $minPaid) {
            return false;
        }
    }
    if ($minDays > 0) {
        $user = select("user", "*", "id", $user_id, "select");
        $reg = is_array($user) ? whale_time_value($user['register'] ?? 0) : 0;
        if ($reg <= 0 || (time() - $reg) < $minDays * 86400) {
            return false;
        }
    }
    return true;
}

// Hook in keyboard.php after $step_payment is encoded.
function whale_filter_payment_keyboard($json, $user_id)
{
    if (!$user_id) {
        return $json;
    }
    $kb = json_decode((string) $json, true);
    if (!is_array($kb) || !isset($kb['inline_keyboard'])) {
        return $json;
    }
    $rows = [];
    $gateways = 0;
    foreach ($kb['inline_keyboard'] as $row) {
        $keep = [];
        foreach ($row as $btn) {
            $key = $btn['callback_data'] ?? (isset($btn['url']) ? 'cart_to_offline' : null);
            if ($key === 'colselist' || $key === null) {
                $keep[] = $btn;
                continue;
            }
            if (whale_gateway_allowed($user_id, $key)) {
                $keep[] = $btn;
                $gateways++;
            }
        }
        if ($keep) {
            $rows[] = $keep;
        }
    }
    if ($gateways === 0) {
        array_unshift($rows, [['text' => whale_t('no_gateway', [], $user_id), 'callback_data' => 'none']]);
    }
    $kb['inline_keyboard'] = $rows;
    return json_encode($kb, JSON_UNESCAPED_UNICODE);
}

/* ---------- balance log ---------- */

function whale_balance_reason_guess($row)
{
    if (!empty($row['reason'])) {
        return $row['reason'];
    }
    $uid = (string) $row['user_id'];
    $ts = intval($row['created']);
    $delta = intval($row['delta']);
    $win = 180;
    $from = date('Y/m/d H:i:s', $ts - $win);
    $to = date('Y/m/d H:i:s', $ts + $win);
    if ($delta > 0) {
        $p = whale_q("SELECT Payment_Method, id_invoice FROM Payment_report WHERE id_user = ? AND (time BETWEEN ? AND ? OR at_updated BETWEEN ? AND ?) ORDER BY id DESC LIMIT 1", [$uid, $from, $to, $from, $to])->fetch(PDO::FETCH_ASSOC);
        if ($p) {
            if ($p['Payment_Method'] === 'add balance by admin') {
                return whale_t('reason_admin_add', [], $uid);
            }
            return whale_t('reason_topup', [], $uid) . ' (' . $p['Payment_Method'] . ')';
        }
        $ref = whale_q("SELECT COUNT(*) FROM user WHERE affiliates = ?", [$uid])->fetchColumn();
        if (intval($ref) > 0) {
            return whale_t('reason_commission', [], $uid) . ' / ' . whale_t('reason_refund', [], $uid);
        }
        return whale_t('reason_other_in', [], $uid);
    }
    $inv = whale_q("SELECT username FROM invoice WHERE id_user = ? AND time_sell BETWEEN ? AND ? ORDER BY time_sell DESC LIMIT 1", [$uid, $ts - $win, $ts + $win])->fetchColumn();
    if ($inv) {
        return whale_t('reason_buy', ['username' => $inv], $uid);
    }
    $so = whale_q("SELECT username, type FROM service_other WHERE id_user = ? AND time BETWEEN ? AND ? ORDER BY id DESC LIMIT 1", [$uid, $from, $to])->fetch(PDO::FETCH_ASSOC);
    if ($so) {
        return in_array($so['type'], ['extend_user', 'extends_not_user'], true) ? whale_t('reason_renew', ['username' => $so['username']], $uid) : whale_t('reason_extra', [], $uid);
    }
    $p = whale_q("SELECT Payment_Method FROM Payment_report WHERE id_user = ? AND time BETWEEN ? AND ? AND Payment_Method = 'low balance by admin' LIMIT 1", [$uid, $from, $to])->fetchColumn();
    if ($p) {
        return whale_t('reason_admin_low', [], $uid);
    }
    return whale_t('reason_other_out', [], $uid);
}

function whale_balance_log_text($user_id, $limit = 10, $viewer = null)
{
    $rows = whale_q("SELECT * FROM whale_balance_log WHERE user_id = ? ORDER BY id DESC LIMIT " . intval($limit), [(string) $user_id])->fetchAll(PDO::FETCH_ASSOC);
    $viewer = $viewer ?? $user_id;
    if (!$rows) {
        return whale_t('wallet_log_empty', [], $viewer);
    }
    $out = [whale_t('wallet_log_title', [], $viewer)];
    foreach ($rows as $r) {
        $out[] = whale_t('wallet_log_line', [
            'sign' => intval($r['delta']) >= 0 ? '🟢 +' : '🔴 −',
            'amount' => whale_money(abs(intval($r['delta']))),
            'reason' => htmlspecialchars(whale_balance_reason_guess($r), ENT_QUOTES, 'UTF-8'),
            'date' => function_exists('jdate') ? jdate('Y/m/d H:i', intval($r['created'])) : date('Y-m-d H:i', intval($r['created'])),
            'balance' => whale_money($r['new_balance']),
        ], $viewer);
    }
    return implode("\n\n", $out);
}

// Hook in keyboard.php: account inline keyboard gets a wallet history button.
function whale_account_keyboard($json)
{
    $kb = json_decode((string) $json, true);
    if (!is_array($kb) || !isset($kb['inline_keyboard'])) {
        return $json;
    }
    $back = array_pop($kb['inline_keyboard']);
    $kb['inline_keyboard'][] = [['text' => whale_t('btn_wallet_log'), 'callback_data' => 'whale_wallet_log']];
    if ($back) {
        $kb['inline_keyboard'][] = $back;
    }
    return json_encode($kb, JSON_UNESCAPED_UNICODE);
}

/* ---------- referral rules ---------- */

function whale_commission_allowed($amount)
{
    return intval($amount) >= whale_int('commission_min_amount');
}

function whale_after_renew($user_id, $amount, $service_username = '')
{
    try {
        $user = select("user", "*", "id", $user_id, "select");
        if (!is_array($user) || empty($user['affiliates']) || intval($user['affiliates']) === 0) {
            return false;
        }
        $aff = select("affiliates", "*", null, null, "select");
        if (!is_array($aff) || ($aff['status_commission'] ?? '') !== 'oncommission') {
            return false;
        }
        $amount = intval($amount);
        if ($amount <= 0 || !whale_commission_allowed($amount)) {
            return false;
        }
        $pct = floatval(whale_get('renew_commission_percent'));
        if ($pct < 0) {
            $setting = select("setting", "*");
            $pct = floatval($setting['affiliatespercentage'] ?? 0);
        }
        $commission = intval(floor($amount * $pct / 100));
        if ($commission <= 0) {
            return false;
        }
        $referrer = $user['affiliates'];
        if (!rowExists("user", "id", $referrer)) {
            return false;
        }
        whale_balance_add($referrer, $commission, whale_t('reason_commission', [], $referrer) . ' (' . $user_id . ')');
        whale_send_user($referrer, whale_t('commission_renew', ['amount' => whale_money($commission)], $referrer));
        whale_report(whale_t('commission_renew_report', [
            'referrer' => $referrer, 'user_id' => $user_id, 'price' => whale_money($amount),
            'amount' => whale_money($commission), 'percent' => $pct,
        ]), 'porsantreport');
        return $commission;
    } catch (Throwable $e) {
        error_log('whale_after_renew: ' . $e->getMessage());
        return false;
    }
}

function whale_user_has_purchase($user_id)
{
    $n = whale_q("SELECT COUNT(*) FROM invoice WHERE id_user = ? AND name_product != ? AND Status NOT IN ('Unpaid', 'unpaid', 'Unsuccessful')", [(string) $user_id, whale_test_label()])->fetchColumn();
    return intval($n) > 0;
}

// Hook in index.php get_gift_start. Returns true when the gift is held back (message sent).
function whale_start_gift_blocked($user_id)
{
    if (whale_int('start_gift_after_purchase') !== 1 || whale_user_has_purchase($user_id)) {
        return false;
    }
    global $keyboard;
    sendmessage($user_id, whale_t('start_gift_after_purchase', [], $user_id), $keyboard, 'HTML');
    return true;
}

function whale_ref_join_track($referrer, $user_id)
{
    whale_q("INSERT INTO whale_ref_join (referrer, user_id, created) VALUES (?, ?, ?)", [(string) $referrer, (string) $user_id, time()]);
    $limit = whale_int('ref_alert_per_hour');
    if ($limit <= 0) {
        return;
    }
    $count = intval(whale_q("SELECT COUNT(*) FROM whale_ref_join WHERE referrer = ? AND created >= ?", [(string) $referrer, time() - 3600])->fetchColumn());
    if ($count < $limit) {
        return;
    }
    $key = 'ref_alert_' . $referrer;
    if (intval(whale_get($key)) > time() - 3600) {
        return;
    }
    whale_set($key, time());
    [$total, $buyers] = whale_referrer_stats($referrer);
    whale_report(whale_t('ref_alert', ['referrer' => $referrer, 'count' => $count, 'buyers' => $buyers, 'total' => $total]), 'otherreport', whale_kb([[['text' => '👤 مدیریت کاربر', 'callback_data' => 'manageuser_' . $referrer]]]));
}

function whale_referrer_stats($referrer)
{
    $total = intval(whale_q("SELECT COUNT(*) FROM user WHERE affiliates = ?", [(string) $referrer])->fetchColumn());
    $buyers = intval(whale_q("SELECT COUNT(DISTINCT i.id_user) FROM invoice i JOIN user u ON u.id = i.id_user WHERE u.affiliates = ? AND i.name_product != ? AND i.Status NOT IN ('Unpaid', 'unpaid', 'Unsuccessful')", [(string) $referrer, whale_test_label()])->fetchColumn());
    return [$total, $buyers];
}

/* ---------- promo links ---------- */

function whale_promo_link($code)
{
    return 'https://t.me/' . whale_bot_username() . '?start=ad_' . $code;
}

function whale_promo_create($title)
{
    $code = substr(preg_replace('/[^a-z0-9]/', '', strtolower(iconv('UTF-8', 'ASCII//TRANSLIT', $title) ?: '')), 0, 10);
    $code = ($code !== '' ? $code . '_' : '') . bin2hex(random_bytes(3));
    whale_q("INSERT INTO whale_promo (code, title, hits, created) VALUES (?, ?, 0, ?)", [$code, mb_substr($title, 0, 190), time()]);
    return $code;
}

function whale_promo_track($code, $user)
{
    $exists = whale_q("SELECT code FROM whale_promo WHERE code = ?", [$code])->fetchColumn();
    if (!$exists || !is_array($user)) {
        return false;
    }
    whale_q("UPDATE whale_promo SET hits = hits + 1 WHERE code = ?", [$code]);
    $isNew = (time() - whale_time_value($user['register'] ?? 0)) <= 120 ? 1 : 0;
    whale_q("INSERT IGNORE INTO whale_promo_user (user_id, code, is_new, created) VALUES (?, ?, ?, ?)", [(string) $user['id'], $code, $isNew, time()]);
    return true;
}

function whale_promo_stats()
{
    $rows = whale_q("SELECT * FROM whale_promo ORDER BY created DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
    $test = whale_test_label();
    foreach ($rows as &$r) {
        $r['members'] = intval(whale_q("SELECT COUNT(*) FROM whale_promo_user WHERE code = ? AND is_new = 1", [$r['code']])->fetchColumn());
        $r['buyers'] = intval(whale_q("SELECT COUNT(DISTINCT i.id_user) FROM invoice i JOIN whale_promo_user p ON p.user_id = i.id_user WHERE p.code = ? AND p.is_new = 1 AND i.name_product != ? AND i.Status NOT IN ('Unpaid','unpaid','Unsuccessful')", [$r['code'], $test])->fetchColumn());
        $sales = intval(whale_q("SELECT COALESCE(SUM(i.price_product),0) FROM invoice i JOIN whale_promo_user p ON p.user_id = i.id_user WHERE p.code = ? AND p.is_new = 1 AND i.name_product != ? AND i.Status NOT IN ('Unpaid','unpaid','Unsuccessful')", [$r['code'], $test])->fetchColumn());
        $renew = intval(whale_q("SELECT COALESCE(SUM(s.price),0) FROM service_other s JOIN whale_promo_user p ON p.user_id = s.id_user WHERE p.code = ? AND p.is_new = 1 AND s.status = 'paid'", [$r['code']])->fetchColumn());
        $r['revenue'] = $sales + $renew;
    }
    return $rows;
}

/* ---------- extra devices: purchase ---------- */

function whale_owned_invoice($user_id, $id_invoice)
{
    $invoice = select("invoice", "*", "id_invoice", $id_invoice, "select");
    if (!is_array($invoice) || (string) $invoice['id_user'] !== (string) $user_id) {
        return null;
    }
    return $invoice;
}

// Applies one extra device and charges $price from the wallet. Returns [ok, newLimit|errorKey].
function whale_device_apply($invoice, $price)
{
    $panel = whale_panel_by_name($invoice['Service_location']);
    if (!is_array($panel) || ($panel['type'] ?? '') !== 'x-ui_single') {
        return [false, 'device_failed'];
    }
    $current = whale_device_limit_of($panel, $invoice['username']);
    if ($current <= 0) {
        return [false, 'device_unlimited'];
    }
    $new = $current + 1;
    if (!whale_device_set_limit($panel, $invoice['username'], $new)) {
        return [false, 'device_failed'];
    }
    if ($price > 0) {
        whale_balance_add($invoice['id_user'], -intval($price), whale_t('reason_device', ['username' => $invoice['username']], $invoice['id_user']));
    }
    $meta = whale_invoice_meta($invoice['id_invoice']);
    whale_invoice_meta_set($invoice['id_invoice'], 'extra_devices', intval($meta['extra_devices']) + 1);
    whale_report("📱 <b>خرید دستگاه اضافه</b>\nکاربر: <code>{$invoice['id_user']}</code>\nسرویس: <code>{$invoice['username']}</code>\nسقف جدید: {$new}\nمبلغ: " . whale_money($price), 'otherservice');
    return [true, $new];
}

// Starts the gateway flow for an amount due. $type is stored in Processing_value_tow.
function whale_start_gateway_payment($user_id, $amount, $type, $ref, $text)
{
    global $step_payment;
    update("user", "Processing_value", intval($amount), "id", $user_id);
    update("user", "Processing_value_one", $ref, "id", $user_id);
    update("user", "Processing_value_tow", $type, "id", $user_id);
    step('get_step_payment', $user_id);
    $kb = $step_payment;
    if (!$kb) {
        $kb = whale_kb([[['text' => '💳', 'callback_data' => 'Add_Balance']]]);
    }
    sendmessage($user_id, $text, $kb, 'HTML');
}

function whale_device_confirm($user_id, $message_id, $id_invoice)
{
    $invoice = whale_owned_invoice($user_id, $id_invoice);
    $price = whale_int('device_price');
    if (!$invoice) {
        whale_answer_callback();
        return;
    }
    if ($price <= 0) {
        whale_answer_callback(whale_t('device_not_sold', [], $user_id), true);
        return;
    }
    clearSelectCache('user');
    $user = select("user", "*", "id", $user_id, "select");
    $balance = intval($user['Balance'] ?? 0);
    if ($balance >= $price) {
        [$ok, $res] = whale_device_apply($invoice, $price);
        whale_answer_callback();
        $text = $ok ? whale_t('device_done', ['username' => $invoice['username'], 'limit' => $res], $user_id) : whale_t($res, [], $user_id);
        Editmessagetext($user_id, $message_id, $text, whale_kb([[['text' => whale_t('btn_back', [], $user_id), 'callback_data' => 'product_' . $id_invoice]]]));
        return;
    }
    whale_answer_callback();
    $shop = select("shopSetting", "*", "Namevalue", "statusdirectpabuy", "select");
    if (is_array($shop) && ($shop['value'] ?? '') === 'offdirectbuy') {
        sendmessage($user_id, whale_t('no_credit_topup', [], $user_id), whale_kb([[['text' => '💳', 'callback_data' => 'Add_Balance']]]), 'HTML');
        return;
    }
    $due = $price - max(0, $balance);
    // gateways have a minimum deposit; the extra stays in the wallet (whale_direct_payment credits the full payment)
    $minCard = intval(select("PaySetting", "ValuePay", "NamePay", "minbalancecart", "select")['ValuePay'] ?? 0);
    $minAgent = intval(json_decode((string) (select("PaySetting", "ValuePay", "NamePay", "minbalance", "select")['ValuePay'] ?? ''), true)[$user['agent'] ?? 'f'] ?? 0);
    $due = max($due, $minCard, $minAgent);
    whale_start_gateway_payment($user_id, $due, 'whale_device', $id_invoice, whale_t('no_credit', ['price' => whale_money($due)], $user_id));
}

/* ---------- extra volume packs ---------- */

// [gb => price] from the "gb:price" lines in volume_packs, sorted by size.
function whale_volume_packs()
{
    $packs = [];
    foreach (preg_split('/\r?\n/', (string) whale_get('volume_packs')) as $line) {
        if (preg_match('/^\s*(\d+)\s*:\s*(\d+)\s*$/', $line, $m) && intval($m[1]) > 0 && intval($m[2]) > 0) {
            $packs[intval($m[1])] = intval($m[2]);
        }
    }
    ksort($packs);
    return $packs;
}

function whale_volume_show($user_id, $message_id, $id_invoice)
{
    whale_answer_callback();
    $invoice = whale_owned_invoice($user_id, $id_invoice);
    $packs = whale_volume_packs();
    if (!$invoice || !$packs) {
        whale_answer_callback(whale_t('volume_not_sold', [], $user_id), true);
        return;
    }
    global $ManagePanel;
    $manager = $ManagePanel instanceof ManagePanel ? $ManagePanel : new ManagePanel();
    $data = $manager->DataUser($invoice['Service_location'], trim($invoice['username']));
    $left = whale_t('card_unlimited', [], $user_id);
    if (is_array($data) && floatval($data['data_limit'] ?? 0) > 0) {
        $bytes = max(0, floatval($data['data_limit']) - floatval($data['used_traffic'] ?? 0));
        $left = whale_fa_digits(number_format($bytes / (1024 ** 3), 1)) . ' ' . whale_t('card_gb', [], $user_id);
    }
    $rows = [];
    foreach ($packs as $gb => $price) {
        $rows[] = [['text' => whale_t('btn_volume_pack', ['gb' => $gb, 'price' => whale_money($price)], $user_id), 'callback_data' => 'whale_volok_' . $id_invoice . '_' . $gb]];
    }
    $rows[] = [['text' => whale_t('btn_back', [], $user_id), 'callback_data' => 'product_' . $id_invoice]];
    Editmessagetext($user_id, $message_id, whale_t('volume_pick', ['username' => trim($invoice['username']), 'left' => $left], $user_id), whale_kb($rows));
}

// Adds $gb to the client on the panel through Mirza's own extra_volume() so reports,
// refund maths (service_other) and the invoice notifications stay consistent.
function whale_volume_apply($invoice, $gb, $price)
{
    global $ManagePanel;
    $panel = whale_panel_by_name($invoice['Service_location']);
    if (!is_array($panel) || ($panel['type'] ?? '') !== 'x-ui_single' || $gb <= 0) {
        return [false, 'volume_failed'];
    }
    $manager = $ManagePanel instanceof ManagePanel ? $ManagePanel : new ManagePanel();
    $username = trim($invoice['username']);
    $before = $manager->DataUser($invoice['Service_location'], $username);
    $res = $manager->extra_volume($username, $panel['code_panel'], intval($gb));
    if (!is_array($res) || empty($res['status'])) {
        return [false, 'volume_failed'];
    }
    if ($price > 0) {
        whale_balance_add($invoice['id_user'], -intval($price), whale_t('reason_volume', ['gb' => $gb, 'username' => $username], $invoice['id_user']));
    }
    whale_q(
        "INSERT IGNORE INTO service_other (id_user, username, value, type, time, price, output) VALUES (?, ?, ?, 'extra_user', ?, ?, ?)",
        [$invoice['id_user'], $username, json_encode(['volume_value' => intval($gb), 'priceـper_gig' => $gb > 0 ? intval($price / $gb) : 0, 'old_volume' => $before['data_limit'] ?? null, 'expire_old' => $before['expire'] ?? null]), date('Y/m/d H:i:s'), intval($price), json_encode($res)]
    );
    $after = whale_xui_client($panel, $username);
    $total = is_array($after) ? round(intval($after['totalGB'] ?? 0) / (1024 ** 3), 1) : '';
    whale_report("➕ <b>خرید حجم اضافه</b>\nکاربر: <code>{$invoice['id_user']}</code>\nسرویس: <code>{$username}</code>\nحجم: {$gb} گیگ\nمبلغ: " . whale_money($price), 'otherservice');
    return [true, $total];
}

function whale_volume_confirm($user_id, $message_id, $id_invoice, $gb)
{
    $invoice = whale_owned_invoice($user_id, $id_invoice);
    $packs = whale_volume_packs();
    $gb = intval($gb);
    if (!$invoice || !isset($packs[$gb])) {
        whale_answer_callback(whale_t('volume_not_sold', [], $user_id), true);
        return;
    }
    $price = $packs[$gb];
    clearSelectCache('user');
    $user = select("user", "*", "id", $user_id, "select");
    $balance = intval($user['Balance'] ?? 0);
    whale_answer_callback();
    if ($balance >= $price) {
        [$ok, $res] = whale_volume_apply($invoice, $gb, $price);
        $text = $ok
            ? whale_t('volume_done', ['gb' => $gb, 'username' => trim($invoice['username']), 'total' => whale_fa_digits($res)], $user_id)
            : whale_t($res, [], $user_id);
        Editmessagetext($user_id, $message_id, $text, whale_kb([[['text' => whale_t('btn_back', [], $user_id), 'callback_data' => 'product_' . $id_invoice]]]));
        return;
    }
    $shop = select("shopSetting", "*", "Namevalue", "statusdirectpabuy", "select");
    if (is_array($shop) && ($shop['value'] ?? '') === 'offdirectbuy') {
        sendmessage($user_id, whale_t('no_credit_topup', [], $user_id), whale_kb([[['text' => '💳', 'callback_data' => 'Add_Balance']]]), 'HTML');
        return;
    }
    $due = $price - max(0, $balance);
    $minCard = intval(select("PaySetting", "ValuePay", "NamePay", "minbalancecart", "select")['ValuePay'] ?? 0);
    $minAgent = intval(json_decode((string) (select("PaySetting", "ValuePay", "NamePay", "minbalance", "select")['ValuePay'] ?? ''), true)[$user['agent'] ?? 'f'] ?? 0);
    $due = max($due, $minCard, $minAgent);
    whale_start_gateway_payment($user_id, $due, 'whale_volume', $id_invoice . '|' . $gb, whale_t('no_credit', ['price' => whale_money($due)], $user_id));
}

/* ---------- renewal from wallet (mini app) ---------- */

function whale_renew_products($panel, $agent)
{
    $stmt = whale_q("SELECT * FROM product WHERE (Location = ? OR Location = '/all') AND agent = ? ORDER BY price_product + 0 ASC", [$panel['name_panel'], $agent]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function whale_custom_config($panel, $agent)
{
    $get = function ($col, $default = 0) use ($panel, $agent) {
        $v = json_decode((string) ($panel[$col] ?? ''), true);
        return is_array($v) ? ($v[$agent] ?? $default) : $default;
    };
    return [
        'enabled' => intval($get('customvolume')) === 1 && ($panel['type'] ?? '') !== 'Manualsale',
        'min_gb' => intval($get('mainvolume', 1)),
        'max_gb' => intval($get('maxvolume', 1000)),
        'min_days' => intval($get('maintime', 1)),
        'max_days' => intval($get('maxtime', 365)),
        'base_unit' => intval($get('pricecustomvolume')),
        'day_price' => intval($get('pricecustomtime')),
        'tiers' => $agent === 'f' ? whale_volume_tiers() : [],
    ];
}

function whale_custom_price($panel, $agent, $gb, $days)
{
    $c = whale_custom_config($panel, $agent);
    return intval(whale_volume_price($gb, $c['base_unit'], $agent) + $days * $c['day_price']);
}

function whale_resolve_plan($panel, $user, $code, $gb, $days)
{
    $agent = $user['agent'] ?? 'f';
    if ($code !== null && $code !== '') {
        foreach (whale_renew_products($panel, $agent) as $p) {
            if ((string) $p['code_product'] === (string) $code) {
                $price = intval($p['price_product']);
                if (intval($user['pricediscount'] ?? 0) > 0) {
                    $price = $price - intval($price * intval($user['pricediscount']) / 100);
                }
                return ['code_product' => $p['code_product'], 'name_product' => $p['name_product'], 'Volume_constraint' => $p['Volume_constraint'], 'Service_time' => $p['Service_time'], 'price_product' => $price];
            }
        }
        return null;
    }
    $c = whale_custom_config($panel, $agent);
    $gb = intval($gb);
    $days = intval($days);
    if (!$c['enabled'] || $gb < $c['min_gb'] || $gb > $c['max_gb'] || $days < $c['min_days'] || $days > $c['max_days']) {
        return null;
    }
    global $textbotlang;
    return ['code_product' => 'custom_volume', 'name_product' => $textbotlang['users']['customSellVolume']['title'] ?? 'Custom', 'Volume_constraint' => $gb, 'Service_time' => $days, 'price_product' => whale_custom_price($panel, $agent, $gb, $days)];
}

function whale_renew_apply($user, $invoice, $plan, ManagePanel $manager)
{
    $panel = whale_panel_by_name($invoice['Service_location']);
    $extend = $manager->extend($panel['Methodextend'], $plan['Volume_constraint'], $plan['Service_time'], $invoice['username'], $plan['code_product'], $panel['code_panel']);
    if (empty($extend['status'])) {
        whale_report("❌ <b>خطای تمدید از مینی‌اپ</b>\nسرویس: <code>{$invoice['username']}</code>\n<code>" . htmlspecialchars(json_encode($extend['msg'] ?? ''), ENT_QUOTES) . "</code>", 'errorreport');
        return false;
    }
    $price = intval($plan['price_product']);
    whale_balance_add($user['id'], -$price, whale_t('reason_renew', ['username' => $invoice['username']], $user['id']));
    $value = json_encode([
        'volumebuy' => $plan['Volume_constraint'], 'Service_time' => $plan['Service_time'],
        'code_product' => $plan['code_product'], 'id_order' => bin2hex(random_bytes(5)), 'source' => 'whale_miniapp',
    ]);
    whale_q("INSERT IGNORE INTO service_other (id_user, username, value, type, time, price, output, status) VALUES (?, ?, ?, 'extend_user', ?, ?, ?, 'paid')", [$user['id'], $invoice['username'], $value, date('Y/m/d H:i:s'), $price, json_encode($extend)]);
    update("invoice", "Status", "active", "id_invoice", $invoice['id_invoice']);
    sendmessage($user['id'], whale_t('renew_done', ['username' => $invoice['username'], 'price' => whale_money($price)], $user['id']), null, 'HTML');
    whale_report("💊 <b>تمدید از مینی‌اپ</b>\nکاربر: <code>{$user['id']}</code>\nسرویس: <code>{$invoice['username']}</code>\nطرح: " . htmlspecialchars($plan['name_product'], ENT_QUOTES) . " ({$plan['Volume_constraint']}GB / {$plan['Service_time']}d)\nمبلغ: " . whale_money($price), 'otherservice');
    whale_after_renew($user['id'], $price, $invoice['username']);
    return true;
}

// Renewal that needs a gateway: mirrors index.php confirmserivce shortfall branch.
function whale_renew_start_gateway($user, $invoice, $plan)
{
    $due = intval($plan['price_product']) - max(0, intval($user['Balance']));
    $order = bin2hex(random_bytes(5));
    $value = json_encode([
        'volumebuy' => $plan['Volume_constraint'], 'Service_time' => $plan['Service_time'],
        'oldvolume' => null, 'oldtime' => null, 'code_product' => $plan['code_product'], 'id_order' => $order,
    ]);
    whale_q("INSERT IGNORE INTO service_other (id_user, username, value, type, time, price, output, status) VALUES (?, ?, ?, 'extend_user', ?, ?, '', 'unpaid')", [$user['id'], $invoice['username'], $value, date('Y/m/d H:i:s'), intval($plan['price_product'])]);
    whale_start_gateway_payment($user['id'], $due, 'getextenduser', $invoice['username'] . '%' . $order, whale_t('no_credit', ['price' => whale_money($due)], $user['id']));
}

/* ---------- DirectPayment hook: payments for WhaleVPN order types ---------- */

function whale_direct_payment($steppay, $Payment_report, $user)
{
    if (!is_array($steppay) || !in_array($steppay[0] ?? '', ['whale_device', 'whale_volume'], true)) {
        return false;
    }
    $paid = intval($Payment_report['price']);
    if ($steppay[0] === 'whale_volume') {
        $invoice = select("invoice", "*", "id_invoice", $steppay[1] ?? '', "select");
        $gb = intval($steppay[2] ?? 0);
        $packs = whale_volume_packs();
        whale_balance_add($user['id'], $paid, whale_t('reason_topup', [], $user['id']));
        update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);
        if (!is_array($invoice) || !isset($packs[$gb])) {
            sendmessage($user['id'], whale_t('volume_failed', [], $user['id']), null, 'HTML');
            return true;
        }
        clearSelectCache('user');
        $fresh = select("user", "*", "id", $user['id'], "select");
        if (intval($fresh['Balance']) < $packs[$gb]) {
            sendmessage($user['id'], whale_t('no_credit_topup', [], $user['id']), null, 'HTML');
            return true;
        }
        [$ok, $res] = whale_volume_apply($invoice, $gb, $packs[$gb]);
        sendmessage($user['id'], $ok ? whale_t('volume_done', ['gb' => $gb, 'username' => trim($invoice['username']), 'total' => whale_fa_digits($res)], $user['id']) : whale_t($res, [], $user['id']), null, 'HTML');
        return true;
    }
    $invoice = select("invoice", "*", "id_invoice", $steppay[1] ?? '', "select");
    whale_balance_add($user['id'], $paid, whale_t('reason_topup', [], $user['id']));
    update("Payment_report", "payment_Status", "paid", "id_order", $Payment_report['id_order']);
    if (!is_array($invoice)) {
        sendmessage($user['id'], whale_t('device_failed', [], $user['id']), null, 'HTML');
        return true;
    }
    clearSelectCache('user');
    $fresh = select("user", "*", "id", $user['id'], "select");
    $price = whale_int('device_price');
    if (intval($fresh['Balance']) < $price) {
        sendmessage($user['id'], whale_t('no_credit_topup', [], $user['id']), null, 'HTML');
        return true;
    }
    [$ok, $res] = whale_device_apply($invoice, $price);
    $text = $ok ? whale_t('device_done', ['username' => $invoice['username'], 'limit' => $res], $user['id']) : whale_t($res, [], $user['id']);
    sendmessage($user['id'], $text, null, 'HTML');
    return true;
}
