<?php
/*
 * WhaleVPN extensions for Mirza Pro.
 *
 * Everything custom lives in this file so upstream updates merge cleanly.
 * Upstream files only carry one-line hooks that call the functions below
 * (search for "whale_" to find them).
 */
if (defined('WHALE_EXT_LOADED')) {
    return;
}
define('WHALE_EXT_LOADED', true);

// A test account that was never connected is removed after this many hours.
if (!defined('WHALE_TEST_UNUSED_HOURS')) {
    define('WHALE_TEST_UNUSED_HOURS', 24);
}

function whale_texts()
{
    return [
        'fa' => [
            'test_creating' => '⏳ در حال ساخت اکانت تست شما… چند لحظه صبر کنید.',
            'test_unused_removed' => "🗑 اکانت تست <code>{username}</code> چون در {hours} ساعت گذشته استفاده نشد، حذف شد.\n\n🛍 هر زمان آماده بودید، از بخش خرید سرویس اشتراک تهیه کنید.",
            'service_expired' => "⏳ مدت سرویس <code>{username}</code> به پایان رسید و اتصال آن قطع شد.\n\nبرای ادامه، سرویس را تمدید کنید.",
            'service_limited' => "📊 حجم سرویس <code>{username}</code> تمام شد و اتصال آن قطع شد.\n\nبرای ادامه، سرویس را تمدید کنید.",
            'service_removed_admin' => "🗑 سرویس <code>{username}</code> توسط پشتیبانی حذف شد.\n\nدر صورت داشتن سوال با پشتیبانی در ارتباط باشید.",
        ],
        'en' => [
            'test_creating' => '⏳ Creating your test account… please wait a moment.',
            'test_unused_removed' => "🗑 Test account <code>{username}</code> was removed because it was not used in the last {hours} hours.\n\n🛍 Whenever you are ready, buy a plan from the shop.",
            'service_expired' => "⏳ Service <code>{username}</code> has expired and is now disconnected.\n\nRenew it to keep using it.",
            'service_limited' => "📊 Service <code>{username}</code> has run out of data and is now disconnected.\n\nRenew it to keep using it.",
            'service_removed_admin' => "🗑 Service <code>{username}</code> was removed by support.\n\nContact support if you have any questions.",
        ],
    ];
}

function whale_user_lang($user_id)
{
    $user = $user_id ? select("user", "*", "id", $user_id, "select") : false;
    $lang = is_array($user) && !empty($user['lang']) ? $user['lang'] : 'fa';
    return $lang;
}

function whale_t($key, array $vars = [], $user_id = null)
{
    $texts = whale_texts();
    $lang = whale_user_lang($user_id);
    $set = $texts[$lang] ?? ($lang === 'fa' ? $texts['fa'] : $texts['en']);
    $text = $set[$key] ?? ($texts['fa'][$key] ?? $key);
    return strtr($text, $vars);
}

// Honors the per-user notification switch (user.status_cron) and reseller bot tokens.
function whale_send_user($user_id, $text, $keyboard = null, $bot_token = null)
{
    $user = select("user", "*", "id", $user_id, "select");
    if (is_array($user) && isset($user['status_cron']) && intval($user['status_cron']) === 0) {
        return false;
    }
    return sendmessage($user_id, $text, $keyboard, 'HTML', $bot_token ?: null);
}

function whale_renew_keyboard($invoice)
{
    $lang = whale_user_lang($invoice['id_user']);
    $label = $lang === 'fa' ? '💊 تمدید سرویس' : '💊 Renew service';
    return json_encode(['inline_keyboard' => [[['text' => $label, 'callback_data' => 'extend_' . $invoice['id_invoice']]]]]);
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
            // Deleted directly in the panel: close the invoice so it is not checked forever.
            update("invoice", "Status", "disabled", "id_invoice", $invoice['id_invoice']);
            return true;
        }
        return false;
    }
    if ($status !== 'on_hold') {
        return false;
    }
    $age = time() - intval($invoice['time_sell']);
    $used = intval($panel_data['used_traffic'] ?? 0);
    if ($age < WHALE_TEST_UNUSED_HOURS * 3600 || $used > 0) {
        return true;
    }
    $ManagePanel->RemoveUser($invoice['Service_location'], trim($invoice['username']));
    update("invoice", "Status", "disabled", "id_invoice", $invoice['id_invoice']);
    whale_send_user(
        $invoice['id_user'],
        whale_t('test_unused_removed', ['{username}' => trim($invoice['username']), '{hours}' => WHALE_TEST_UNUSED_HOURS], $invoice['id_user']),
        null,
        $invoice['bottype'] ?? null
    );
    return true;
}

/* ---------- paid services: tell the user the moment a service stops ---------- */

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
    if (in_array($status, ['expired', 'limited'], true)) {
        if (!empty($flags['whale_ended'])) {
            return;
        }
        $key = $status === 'expired' ? 'service_expired' : 'service_limited';
        whale_send_user(
            $invoice['id_user'],
            whale_t($key, ['{username}' => $invoice['username']], $invoice['id_user']),
            whale_renew_keyboard($invoice),
            $invoice['bottype'] ?? null
        );
        $flags['whale_ended'] = true;
    } elseif ($status === 'active' && !empty($flags['whale_ended'])) {
        $flags['whale_ended'] = false; // renewed: notify again on the next stop
    } else {
        return;
    }
    update("invoice", "notifctions", json_encode($flags), "id_invoice", $invoice['id_invoice']);
}

/* ---------- removals by admin (bot admin panel and web panel API) ---------- */

function whale_notify_removed($invoice)
{
    if (!is_array($invoice) || empty($invoice['id_user'])) {
        return;
    }
    whale_send_user(
        $invoice['id_user'],
        whale_t('service_removed_admin', ['{username}' => $invoice['username']], $invoice['id_user']),
        null,
        $invoice['bottype'] ?? null
    );
}
