<?php
/*
 * WhaleVPN service features: one-tap add, device limit, refund estimate,
 * renewal threshold, custom renewal method.
 */

/* ---------- one-tap add ---------- */

function whale_add_url($sub_url, $app = null)
{
    if (!is_string($sub_url) || !preg_match('~^https?://~i', $sub_url)) {
        return null;
    }
    $u = rtrim(strtr(base64_encode($sub_url), '+/', '-_'), '=');
    $url = 'https://' . whale_domain() . '/whale/add.php?u=' . $u;
    if ($app) {
        $url .= '&a=' . rawurlencode($app);
    }
    return $url;
}

function whale_oneclick_row($sub_url, $user_id = null)
{
    if (whale_int('oneclick') !== 1) {
        return null;
    }
    $url = whale_add_url($sub_url);
    return $url ? [['text' => whale_t('btn_oneclick', [], $user_id), 'url' => $url]] : null;
}

// Service detail screen: add one-tap / devices rows, drop change-location.
function whale_service_keyboard($keyboardJson, $nameloc, $DataUserOut)
{
    $kb = is_string($keyboardJson) ? json_decode($keyboardJson, true) : $keyboardJson;
    if (!is_array($kb) || !isset($kb['inline_keyboard'])) {
        return $keyboardJson;
    }
    $rows = [];
    foreach ($kb['inline_keyboard'] as $row) {
        $row = array_values(array_filter($row, function ($b) {
            return !(isset($b['callback_data']) && strpos((string) $b['callback_data'], 'changeloc_') === 0);
        }));
        if ($row) {
            $rows[] = $row;
        }
    }
    $extra = [];
    $status = $DataUserOut['status'] ?? '';
    $uid = $nameloc['id_user'] ?? null;
    if (in_array($status, ['active', 'on_hold', 'disabled', 'Unknown'], true)) {
        $one = whale_oneclick_row($DataUserOut['subscription_url'] ?? null, $uid);
        if ($one) {
            $extra[] = $one;
        }
    }
    if (whale_int('card_enabled') === 1 && whale_card_available() && in_array($status, ['active', 'on_hold', 'disabled', 'expired', 'limited'], true)) {
        $extra[] = [['text' => whale_t('btn_card', [], $uid), 'callback_data' => 'whale_card_' . $nameloc['id_invoice']]];
    }
    $panel = whale_panel_by_name($nameloc['Service_location'] ?? '');
    if (is_array($panel) && ($panel['type'] ?? '') === 'x-ui_single' && in_array($status, ['active', 'on_hold', 'disabled'], true)) {
        $limit = whale_device_limit_of($panel, $nameloc['username']);
        $devRow = [['text' => $limit > 0 ? whale_t('btn_devices', ['limit' => $limit], $uid) : whale_t('btn_devices_unlimited', [], $uid), 'callback_data' => 'whale_dev_' . $nameloc['id_invoice']]];
        if ($limit > 0 && whale_int('device_price') > 0) {
            $devRow[] = ['text' => whale_t('btn_device_buy', [], $uid), 'callback_data' => 'whale_devbuy_' . $nameloc['id_invoice']];
        }
        $extra[] = $devRow;
    }
    if ($extra) {
        $back = array_pop($rows);
        $rows = array_merge($extra, $rows);
        if ($back) {
            $rows[] = $back;
        }
    }
    $kb['inline_keyboard'] = $rows;
    return json_encode($kb, JSON_UNESCAPED_UNICODE);
}

function whale_service_text($text, $nameloc, $DataUserOut)
{
    $panel = whale_panel_by_name($nameloc['Service_location'] ?? '');
    if (is_array($panel) && ($panel['type'] ?? '') === 'x-ui_single') {
        $limit = whale_device_limit_of($panel, $nameloc['username']);
        if ($limit > 0) {
            $text .= "\n" . whale_t('service_devices_line', ['limit' => $limit], $nameloc['id_user'] ?? null);
        }
    }
    return $text;
}

// Purchase / test success message: add the one-tap row.
function whale_service_message_markup($reply_markup, $sub_link, $panel_info = null)
{
    $row = whale_oneclick_row($sub_link);
    if (!$row) {
        return $reply_markup;
    }
    $kb = is_string($reply_markup) ? json_decode($reply_markup, true) : $reply_markup;
    if (!is_array($kb) || !isset($kb['inline_keyboard'])) {
        $kb = ['inline_keyboard' => []];
    }
    array_unshift($kb['inline_keyboard'], $row);
    return json_encode($kb, JSON_UNESCAPED_UNICODE);
}

/* ---------- 3x-ui client helpers ---------- */

function whale_xui_client($panel, $email)
{
    $res = get_clinets($email, $panel);
    if (!empty($res['error']) || empty($res['body'])) {
        return null;
    }
    $body = json_decode($res['body'], true);
    if (!is_array($body) || empty($body['obj'])) {
        return null;
    }
    $obj = $body['obj'];
    return isset($obj['client']) && is_array($obj['client']) ? $obj['client'] : $obj;
}

function whale_xui_post($panel, $path, $payload = null)
{
    $req = new CurlRequest(rtrim($panel['url_panel'], '/') . $path);
    $req->setHeaders(['Accept: application/json', 'Content-Type: application/json']);
    $req->setBearerToken($panel['password_panel']);
    $res = $req->post($payload === null ? '' : json_encode($payload));
    $body = isset($res['body']) ? json_decode($res['body'], true) : null;
    return ['ok' => empty($res['error']) && is_array($body) && !empty($body['success']), 'body' => $body, 'error' => $res['error'] ?? null, 'status' => $res['status'] ?? null];
}

// Full client payload for clients/update: 3x-ui 3.8 resets every field that is left out.
function whale_xui_full_payload(array $client, array $changes = [])
{
    $payload = [
        'email' => $client['email'] ?? '',
        'totalGB' => intval($client['totalGB'] ?? 0),
        'expiryTime' => intval($client['expiryTime'] ?? 0),
        'tgId' => intval($client['tgId'] ?? 0),
        'enable' => (bool) ($client['enable'] ?? true),
        'subId' => $client['subId'] ?? '',
        'comment' => $client['comment'] ?? '',
        'limitIp' => intval($client['limitIp'] ?? 0),
    ];
    // never send the numeric record id: 3x-ui reads "id" as the protocol UUID and rejects the update
    return array_merge($payload, $changes);
}

/* ---------- device limit (limitIp) ---------- */

function whale_device_limit_for_product($code_product)
{
    if ($code_product === 'usertest') {
        return whale_int('device_limit_test');
    }
    $row = whale_q("SELECT device_limit FROM whale_product WHERE code_product = ?", [(string) $code_product])->fetchColumn();
    if ($row !== false) {
        return intval($row);
    }
    return whale_int('device_limit_default');
}

// Called in panels.php createUser (x-ui branch) right before addClient.
function whale_pending_device_limit($code_product)
{
    $GLOBALS['whale_pending_limit'] = whale_device_limit_for_product($code_product);
}

// Called in x-ui_single.php addClient on the client payload.
function whale_client_add_payload(array $data)
{
    $limit = intval($GLOBALS['whale_pending_limit'] ?? 0);
    unset($GLOBALS['whale_pending_limit']);
    if ($limit > 0 && !isset($data['limitIp'])) {
        $data['limitIp'] = $limit;
    }
    return $data;
}

// Called in panels.php Modifyuser (x-ui branch): keep limitIp and comment, which the update would wipe.
function whale_modify_payload(array $data, $username, $panel)
{
    $client = whale_xui_client($panel, $username);
    if (!is_array($client)) {
        return $data;
    }
    if (!isset($data['limitIp'])) {
        $data['limitIp'] = intval($client['limitIp'] ?? 0);
    }
    if (!isset($data['comment']) && isset($client['comment'])) {
        $data['comment'] = $client['comment'];
    }
    if (!isset($data['subId']) && !empty($client['subId'])) {
        $data['subId'] = $client['subId'];
    }
    return $data;
}

function whale_device_limit_of($panel, $username)
{
    static $cache = [];
    $key = ($panel['name_panel'] ?? '') . '|' . $username;
    if (!array_key_exists($key, $cache)) {
        $client = whale_xui_client($panel, $username);
        $cache[$key] = is_array($client) ? intval($client['limitIp'] ?? 0) : 0;
    }
    return $cache[$key];
}

function whale_device_set_limit($panel, $username, $limit)
{
    $client = whale_xui_client($panel, $username);
    if (!is_array($client)) {
        return false;
    }
    $res = whale_xui_post($panel, '/panel/api/clients/update/' . rawurlencode($username), whale_xui_full_payload($client, ['limitIp' => intval($limit)]));
    if (!$res['ok']) {
        return false;
    }
    $check = whale_xui_client($panel, $username);
    return is_array($check) && intval($check['limitIp'] ?? -1) === intval($limit);
}

/*
 * IPs 3x-ui currently has on record for this client, sorted. Null when the panel call fails,
 * which is not the same as "no device connected". 3.8 answers with objects (ip/time/node);
 * older builds answered with a plain string.
 */
function whale_device_ips($panel, $username)
{
    if (isset($GLOBALS['whale_device_ips_override'])) {
        return $GLOBALS['whale_device_ips_override']($panel, $username);
    }
    $res = whale_xui_post($panel, '/panel/api/clients/ips/' . rawurlencode($username));
    if (!$res['ok']) {
        return null;
    }
    $obj = $res['body']['obj'] ?? [];
    if (is_string($obj)) {
        $obj = preg_split('/[\s,]+/', $obj);
    }
    $ips = [];
    foreach ((array) $obj as $entry) {
        $ip = is_array($entry) ? (string) ($entry['ip'] ?? '') : (string) $entry;
        $ip = trim(preg_replace('/\s*\(.*\)$/', '', $ip));
        if ($ip !== '') {
            $ips[$ip] = true;
        }
    }
    $ips = array_keys($ips);
    sort($ips);
    return $ips;
}

function whale_device_online($panel, $username)
{
    $ips = whale_device_ips($panel, $username);
    return is_array($ips) ? count($ips) : 0;
}

/* ---------- refund estimate (mirrors admin.php cancel approval) ---------- */

function whale_refund_estimate($nameloc, $DataUserOut)
{
    $stmt = whale_q("SELECT SUM(price) FROM service_other WHERE username = ? AND type != 'change_location' AND type != 'extend_user'", [$nameloc['username']]);
    $sum = intval($stmt->fetchColumn());
    $price = floatval($nameloc['price_product'] ?? 0);
    $serviceTime = floatval($nameloc['Service_time'] ?? 0);
    $dataLimit = floatval($DataUserOut['data_limit'] ?? 0);
    $used = floatval($DataUserOut['used_traffic'] ?? 0);
    $expire = intval($DataUserOut['expire'] ?? 0);
    if (($DataUserOut['status'] ?? '') === 'on_hold') {
        return intval($price);
    }
    if (empty($DataUserOut['data_limit']) && empty($DataUserOut['expire'])) {
        return 0;
    }
    if (empty($DataUserOut['data_limit'])) {
        if ($serviceTime <= 0) {
            return 0;
        }
        $pricetime = ($price / $serviceTime) + $sum;
        return max(0, intval((($expire - time()) / 86400) * $pricetime));
    }
    if (empty($DataUserOut['expire'])) {
        $left = ($dataLimit - $used) / $dataLimit;
        return max(0, intval(round($left * ($price + $sum), 2)));
    }
    if ($serviceTime <= 0 || $dataLimit <= 0) {
        return 0;
    }
    $timeleft = (round(($expire - time()) / 86400, 0)) / $serviceTime;
    $volumeleft = ($dataLimit - $used) / $dataLimit;
    return max(0, intval(round($timeleft * $volumeleft * ($price + $sum), 2)));
}

function whale_paid_total($nameloc)
{
    $stmt = whale_q("SELECT SUM(price) FROM service_other WHERE username = ? AND (status = 'paid' OR status IS NULL OR status = '')", [$nameloc['username']]);
    return intval($nameloc['price_product'] ?? 0) + intval($stmt->fetchColumn());
}

function whale_refund_text($base, $nameloc, $DataUserOut)
{
    if (!is_array($nameloc) || !is_array($DataUserOut)) {
        return $base;
    }
    try {
        $line = whale_t('refund_estimate', [
            'paid' => whale_money(whale_paid_total($nameloc)),
            'refund' => whale_money(whale_refund_estimate($nameloc, $DataUserOut)),
        ], $nameloc['id_user'] ?? null);
        return $base . "\n\n" . $line;
    } catch (Throwable $e) {
        error_log('whale_refund_text: ' . $e->getMessage());
        return $base;
    }
}

/* ---------- renewal threshold ---------- */

function whale_renew_check($DataUserOut)
{
    $maxDays = whale_int('renew_max_days_left');
    $maxPct = whale_int('renew_max_percent_left');
    $status = $DataUserOut['status'] ?? '';
    $expire = intval($DataUserOut['expire'] ?? 0);
    $limit = floatval($DataUserOut['data_limit'] ?? 0);
    $used = floatval($DataUserOut['used_traffic'] ?? 0);
    $days = $expire > 0 ? max(0, intval(floor(($expire - time()) / 86400))) : 9999;
    $pct = $limit > 0 ? max(0, intval(floor((($limit - $used) / $limit) * 100))) : 100;
    $result = ['allowed' => true, 'days' => $days, 'percent' => $pct, 'rule' => ''];
    if (($maxDays <= 0 && $maxPct <= 0) || in_array($status, ['expired', 'limited'], true)) {
        return $result;
    }
    $ok = false;
    $rules = [];
    if ($maxDays > 0) {
        $rules[] = ['renew_rule_days', ['days' => $maxDays]];
        $ok = $ok || $days <= $maxDays;
    }
    if ($maxPct > 0) {
        $rules[] = ['renew_rule_percent', ['percent' => $maxPct]];
        $ok = $ok || $pct <= $maxPct;
    }
    $result['allowed'] = $ok;
    $result['rules'] = $rules;
    return $result;
}

function whale_renew_rule_text(array $check, $user_id = null)
{
    $parts = [];
    foreach ($check['rules'] ?? [] as $r) {
        $parts[] = whale_t($r[0], $r[1], $user_id);
    }
    return implode(whale_t('renew_rule_or', [], $user_id), $parts);
}

// Hook in index.php extend_ handler. Returns true when renewal is blocked (message sent).
function whale_renew_blocked($user_id, $nameloc, $DataUserOut)
{
    $check = whale_renew_check($DataUserOut);
    if ($check['allowed']) {
        return false;
    }
    sendmessage($user_id, whale_t('renew_blocked', [
        'days' => $check['days'] >= 9999 ? '∞' : $check['days'],
        'percent' => $check['percent'],
        'rule' => whale_renew_rule_text($check, $user_id),
    ], $user_id), null, 'HTML');
    return true;
}

/* ---------- renewal method: restart time, carry remaining volume ---------- */

function whale_extend_method_key($value)
{
    if (is_string($value) && trim($value) === 'resetTimeConvertVolume') {
        return 'resetTimeConvertVolume';
    }
    return extendMethodKey($value);
}

function whale_extend_reset_time_convert($manager, $panel, $username, $data_user, &$data_limit_new, &$time_new)
{
    $reset = $manager->ResetUserDataUsage($username, $panel['name_panel']);
    if (empty($reset['status'])) {
        return false;
    }
    if ($data_limit_new > 0) {
        $left = floatval($data_user['data_limit'] ?? 0) - floatval($data_user['used_traffic'] ?? 0);
        $data_limit_new = $data_limit_new + max(0, $left);
    }
    // $time_new already is now + days (restart); nothing to add.
    return true;
}

function whale_extend_methods()
{
    return [
        'resetVolumeTime' => 'ریست حجم و زمان',
        'addTimeVolumeNextMonth' => 'افزودن حجم و زمان به قبلی',
        'resetTimeAddVolume' => 'ریست زمان + افزودن حجم',
        'resetVolumeAddTime' => 'ریست حجم + افزودن زمان',
        'addTimeConvertVolume' => 'افزودن زمان + انتقال حجم باقی',
        'resetTimeConvertVolume' => 'ریست زمان + انتقال حجم باقی (WhaleVPN)',
    ];
}
