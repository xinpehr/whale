<?php
/*
 * WhaleVPN notifications: test cleanup, service stop, not-connected reminders, rating requests.
 */

if (!defined('WHALE_TEST_UNUSED_HOURS')) {
    define('WHALE_TEST_UNUSED_HOURS', 24);
}

function whale_renew_keyboard($invoice)
{
    return whale_kb([[['text' => whale_t('btn_renew', [], $invoice['id_user']), 'callback_data' => 'extend_' . $invoice['id_invoice']]]]);
}

function whale_nudge_keyboard($invoice, $sub_url)
{
    $rows = [];
    $one = whale_oneclick_row($sub_url, $invoice['id_user']);
    if ($one) {
        $rows[] = $one;
    }
    $rows[] = [['text' => '📚 ' . (whale_user_lang($invoice['id_user']) === 'fa' ? 'آموزش اتصال' : 'How to connect'), 'callback_data' => 'helpbtn']];
    return whale_kb($rows);
}

/* ---------- test account: "please wait" feedback ---------- */

function whale_wait_start($chat_id, $message_id, $is_callback)
{
    global $callback_query_id;
    $text = whale_t('test_creating', [], $chat_id);
    if ($is_callback && $message_id) {
        if (!empty($callback_query_id)) {
            telegram('answerCallbackQuery', ['callback_query_id' => $callback_query_id]);
        }
        $edited = Editmessagetext($chat_id, $message_id, $text, json_encode(['inline_keyboard' => []]));
        if (is_array($edited) && !empty($edited['ok'])) {
            return $message_id;
        }
    }
    $sent = sendmessage($chat_id, $text, null, 'HTML');
    return is_array($sent) ? ($sent['result']['message_id'] ?? null) : null;
}

function whale_wait_end($chat_id, $wait_message_id)
{
    if ($wait_message_id) {
        deletemessage($chat_id, $wait_message_id);
    }
}

/* ---------- test account cleanup (cronbot/configtest.php) ---------- */

// Returns true when the invoice was handled here and the caller should skip it.
function whale_test_cleanup($invoice, $panel_data, $ManagePanel)
{
    $status = is_array($panel_data) ? ($panel_data['status'] ?? '') : '';
    if ($status === 'Unsuccessful') {
        $msg = is_string($panel_data['msg'] ?? null) ? $panel_data['msg'] : json_encode($panel_data['msg'] ?? '');
        if (stripos($msg, 'not found') !== false) {
            update("invoice", "Status", "disabled", "id_invoice", $invoice['id_invoice']);
            return true;
        }
        return false;
    }
    if ($status !== 'on_hold') {
        return false;
    }
    $age = time() - whale_time_value($invoice['time_sell']);
    $used = intval($panel_data['used_traffic'] ?? 0);
    if ($used > 0) {
        return true;
    }
    $hours = max(1, whale_int('test_unused_hours'));
    if ($age < $hours * 3600) {
        $nudge = whale_int('test_nudge_hours');
        if ($nudge > 0 && $age >= $nudge * 3600) {
            $meta = whale_invoice_meta($invoice['id_invoice']);
            if (intval($meta['nudged']) === 0) {
                whale_invoice_meta_set($invoice['id_invoice'], 'nudged', 1);
                whale_send_user(
                    $invoice['id_user'],
                    whale_t('nudge_test', ['username' => trim($invoice['username'])], $invoice['id_user']),
                    whale_nudge_keyboard($invoice, $panel_data['subscription_url'] ?? null),
                    $invoice['bottype'] ?? null
                );
            }
        }
        return true;
    }
    $ManagePanel->RemoveUser($invoice['Service_location'], trim($invoice['username']));
    update("invoice", "Status", "disabled", "id_invoice", $invoice['id_invoice']);
    whale_send_user(
        $invoice['id_user'],
        whale_t('test_unused_removed', ['username' => trim($invoice['username']), 'hours' => $hours], $invoice['id_user']),
        null,
        $invoice['bottype'] ?? null
    );
    return true;
}

/* ---------- paid services: per-invoice tick from cronbot/NoticationsService.php ---------- */

function whale_notify_service_ended($invoice_id, $panel_data)
{
    if (function_exists('clearSelectCache')) {
        clearSelectCache('invoice');
    }
    $invoice = select("invoice", "*", "id_invoice", $invoice_id, "select");
    if (!is_array($invoice) || !is_array($panel_data)) {
        return;
    }
    $status = $panel_data['status'] ?? '';
    $flags = json_decode($invoice['notifctions'] ?? '', true);
    if (!is_array($flags)) {
        $flags = [];
    }
    $changed = false;
    if (in_array($status, ['expired', 'limited'], true)) {
        if (empty($flags['whale_ended'])) {
            $key = $status === 'expired' ? 'service_expired' : 'service_limited';
            whale_send_user(
                $invoice['id_user'],
                whale_t($key, ['username' => $invoice['username']], $invoice['id_user']),
                whale_renew_keyboard($invoice),
                $invoice['bottype'] ?? null
            );
            $flags['whale_ended'] = true;
            $changed = true;
        }
    } elseif ($status === 'active' && !empty($flags['whale_ended'])) {
        $flags['whale_ended'] = false;
        $changed = true;
    }
    if ($changed) {
        update("invoice", "notifctions", json_encode($flags), "id_invoice", $invoice['id_invoice']);
    }
    try {
        whale_nudge_unconnected($invoice, $panel_data);
        whale_rating_maybe_ask($invoice, $panel_data);
    } catch (Throwable $e) {
        error_log('whale invoice tick: ' . $e->getMessage());
    }
}

function whale_never_connected($panel_data)
{
    $online = $panel_data['online_at'] ?? null;
    return ($panel_data['status'] ?? '') === 'on_hold' || $online === null || $online === '' || $online === 'offline';
}

function whale_nudge_unconnected($invoice, $panel_data)
{
    $days = whale_int('nudge_days');
    if ($days <= 0 || whale_is_test_invoice($invoice)) {
        return false;
    }
    if (!in_array($panel_data['status'] ?? '', ['active', 'on_hold'], true) || !whale_never_connected($panel_data)) {
        return false;
    }
    if (time() - whale_time_value($invoice['time_sell']) < $days * 86400) {
        return false;
    }
    $meta = whale_invoice_meta($invoice['id_invoice']);
    if (intval($meta['nudged']) !== 0) {
        return false;
    }
    whale_invoice_meta_set($invoice['id_invoice'], 'nudged', 1);
    whale_send_user(
        $invoice['id_user'],
        whale_t('nudge_paid', ['username' => $invoice['username']], $invoice['id_user']),
        whale_nudge_keyboard($invoice, $panel_data['subscription_url'] ?? null),
        $invoice['bottype'] ?? null
    );
    return true;
}

/* ---------- ratings ---------- */

function whale_rating_maybe_ask($invoice, $panel_data)
{
    $days = whale_int('rating_days');
    if ($days <= 0 || whale_is_test_invoice($invoice)) {
        return false;
    }
    if (($panel_data['status'] ?? '') !== 'active') {
        return false;
    }
    if (whale_int('rating_require_online') === 1 && whale_never_connected($panel_data)) {
        return false;
    }
    if (time() - whale_time_value($invoice['time_sell']) < $days * 86400) {
        return false;
    }
    $meta = whale_invoice_meta($invoice['id_invoice']);
    if (intval($meta['rating_asked']) !== 0) {
        return false;
    }
    whale_invoice_meta_set($invoice['id_invoice'], 'rating_asked', 1);
    $row = [];
    for ($i = 1; $i <= 5; $i++) {
        $row[] = ['text' => $i . '⭐️', 'callback_data' => 'whale_rate_' . $invoice['id_invoice'] . '_' . $i];
    }
    whale_send_user(
        $invoice['id_user'],
        whale_t('rating_ask', ['username' => $invoice['username']], $invoice['id_user']),
        whale_kb([$row]),
        $invoice['bottype'] ?? null
    );
    return true;
}

function whale_rating_handle_callback($from_id, $message_id, $invoice_id, $rating)
{
    $invoice = select("invoice", "*", "id_invoice", $invoice_id, "select");
    $rating = max(1, min(5, intval($rating)));
    if (!is_array($invoice) || (string) $invoice['id_user'] !== (string) $from_id) {
        whale_answer_callback();
        return;
    }
    whale_q("INSERT INTO whale_rating (id_invoice, user_id, rating, created) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE rating = VALUES(rating), created = VALUES(created)", [$invoice_id, $from_id, $rating, time()]);
    whale_answer_callback(whale_t('rating_thanks', [], $from_id));
    whale_report(whale_t('rating_report', [
        'user_id' => $from_id,
        'username' => $invoice['username'],
        'stars' => str_repeat('⭐️', $rating),
        'rating' => $rating,
    ]), 'otherreport');
    if ($rating <= 3) {
        Editmessagetext($from_id, $message_id, whale_t('rating_ask_comment', [], $from_id), json_encode(['inline_keyboard' => []]));
        step('whale_ratecomment_' . $invoice_id, $from_id);
    } else {
        Editmessagetext($from_id, $message_id, whale_t('rating_thanks', [], $from_id) . ' ' . str_repeat('⭐️', $rating), json_encode(['inline_keyboard' => []]));
    }
}

function whale_rating_handle_comment($from_id, $invoice_id, $text)
{
    global $keyboard;
    step('home', $from_id);
    if (trim($text) === '/skip' || trim($text) === '') {
        sendmessage($from_id, whale_t('rating_thanks', [], $from_id), $keyboard, 'HTML');
        return;
    }
    $comment = mb_substr(trim($text), 0, 1500);
    whale_q("UPDATE whale_rating SET comment = ? WHERE id_invoice = ? AND user_id = ?", [$comment, $invoice_id, $from_id]);
    $row = whale_q("SELECT rating FROM whale_rating WHERE id_invoice = ?", [$invoice_id])->fetchColumn();
    $invoice = select("invoice", "*", "id_invoice", $invoice_id, "select");
    whale_report(whale_t('rating_comment_report', [
        'user_id' => $from_id,
        'username' => is_array($invoice) ? $invoice['username'] : $invoice_id,
        'rating' => intval($row),
        'comment' => htmlspecialchars($comment, ENT_QUOTES, 'UTF-8'),
    ]), 'otherreport');
    sendmessage($from_id, whale_t('rating_comment_thanks', [], $from_id), $keyboard, 'HTML');
}

/* ---------- removals by admin ---------- */

function whale_notify_removed($invoice)
{
    if (!is_array($invoice) || empty($invoice['id_user'])) {
        return;
    }
    whale_send_user(
        $invoice['id_user'],
        whale_t('service_removed_admin', ['username' => $invoice['username']], $invoice['id_user']),
        null,
        $invoice['bottype'] ?? null
    );
}

/* ---------- volume warning in MB or GB ---------- */

function whale_volumewarn_valid($text)
{
    return is_string($text) && preg_match('/^\s*\d+(?:\.\d+)?\s*(?:gb|g|mb|m|گیگ|مگ|گیگابایت|مگابایت)?\s*$/iu', $text) === 1;
}

// Returns GB (decimal string) as stored in setting.volumewarn.
function whale_volumewarn_value($text)
{
    if (!preg_match('/(\d+(?:\.\d+)?)\s*(gb|g|mb|m|گیگ|مگ|گیگابایت|مگابایت)?/iu', (string) $text, $m)) {
        return '0';
    }
    $n = floatval($m[1]);
    $unit = mb_strtolower($m[2] ?? '');
    if (in_array($unit, ['mb', 'm', 'مگ', 'مگابایت'], true)) {
        $n = $n / 1024;
    }
    return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.') ?: '0';
}
