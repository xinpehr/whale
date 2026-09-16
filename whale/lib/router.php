<?php
/*
 * WhaleVPN update router. Hooks in index.php:
 *   $text = whale_early_update($text);   before the "/start <param>" block
 *   if (whale_handle_update()) return;   before the first user dispatch
 */

function whale_early_update($text)
{
    global $from_id, $user;
    try {
        if (is_string($text) && whale_int('light_skin') === 1) {
            $text = whale_strip_skin_tone($text);
        }
        if (!is_string($text) || strpos($text, '/start ') !== 0) {
            return $text;
        }
        $param = trim(substr($text, 7));
        if (preg_match('/^ad_([a-z0-9_]{1,40})$/i', $param, $m)) {
            whale_promo_track(strtolower($m[1]), is_array($user) ? $user : select("user", "*", "id", $from_id, "select"));
            return '/start';
        }
        if (ctype_digit($param) && $param !== (string) $from_id && is_array($user)) {
            $isNew = (time() - whale_time_value($user['register'] ?? 0)) <= 120;
            if ($isNew && (empty($user['affiliates']) || intval($user['affiliates']) === 0)) {
                whale_ref_join_track($param, $from_id);
            }
        }
    } catch (Throwable $e) {
        error_log('whale_early_update: ' . $e->getMessage());
    }
    return $text;
}

function whale_handle_update()
{
    global $from_id, $text, $datain, $user, $message_id;
    try {
        if (!$from_id) {
            return false;
        }
        $step = is_array($user) ? (string) ($user['step'] ?? '') : '';
        $data = (string) $datain;

        // a second tap on "buy" or "test account" arrives as a different update: guard the action itself
        if (whale_duplicate_action($from_id, $data, $text)) {
            return true;
        }

        if (whale_is_admin($from_id) && ($text === '/whale' || strpos($data, 'whale_admin') === 0 || strpos($step, 'whale_admin') === 0)) {
            return whale_admin_handle();
        }
        if (strpos($step, 'whale_ratecomment_') === 0 && $data === '' && is_string($text) && $text !== '') {
            whale_rating_handle_comment($from_id, substr($step, strlen('whale_ratecomment_')), $text);
            return true;
        }
        if ($data === '') {
            return false;
        }
        if (preg_match('/^whale_rate_([a-f0-9]+)_([1-5])$/', $data, $m)) {
            whale_rating_handle_callback($from_id, $message_id, $m[1], $m[2]);
            return true;
        }
        if (preg_match('/^whale_card_(\w+)$/', $data, $m)) {
            whale_card_callback($from_id, $m[1]);
            return true;
        }
        if (preg_match('/^whale_dev_(\w+)$/', $data, $m)) {
            whale_devices_show($from_id, $message_id, $m[1]);
            return true;
        }
        if (preg_match('/^whale_devbuy_(\w+)$/', $data, $m)) {
            whale_device_buy_prompt($from_id, $message_id, $m[1]);
            return true;
        }
        if (preg_match('/^whale_devok_(\w+)$/', $data, $m)) {
            whale_device_confirm($from_id, $message_id, $m[1]);
            return true;
        }
        if ($data === 'whale_wallet_log') {
            whale_answer_callback();
            sendmessage($from_id, whale_balance_log_text($from_id, 10), null, 'HTML');
            return true;
        }
        if (preg_match('/^whale_topup_(\d+)$/', $data, $m)) {
            whale_answer_callback();
            whale_start_gateway_payment($from_id, intval($m[1]), '0', '0', whale_t('topup_prompt', ['amount' => whale_money($m[1])], $from_id));
            return true;
        }
        if (preg_match('/^whale_renewpay_(\w+)_(p_[\w-]+|c(\d+)x(\d+))$/', $data, $m)) {
            whale_answer_callback();
            whale_renew_pay_callback($from_id, $m);
            return true;
        }
        if ($step === 'get_step_payment' && array_key_exists($data, whale_gateway_keys()) && !whale_gateway_allowed($from_id, $data)) {
            whale_answer_callback(whale_t('gateway_hidden', [], $from_id), true);
            return true;
        }
    } catch (Throwable $e) {
        error_log('whale_handle_update: ' . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine());
    }
    return false;
}

function whale_devices_show($from_id, $message_id, $id_invoice)
{
    $invoice = whale_owned_invoice($from_id, $id_invoice);
    if (!$invoice) {
        whale_answer_callback();
        return;
    }
    $panel = whale_panel_by_name($invoice['Service_location']);
    $limit = is_array($panel) ? whale_device_limit_of($panel, $invoice['username']) : 0;
    whale_answer_callback();
    if ($limit <= 0) {
        whale_answer_callback(whale_t('device_unlimited', [], $from_id), true);
        return;
    }
    $online = whale_device_online($panel, $invoice['username']);
    $rows = [];
    if (whale_int('device_price') > 0) {
        $rows[] = [['text' => whale_t('btn_device_buy', [], $from_id), 'callback_data' => 'whale_devbuy_' . $id_invoice]];
    }
    $rows[] = [['text' => whale_t('btn_back', [], $from_id), 'callback_data' => 'product_' . $id_invoice]];
    Editmessagetext($from_id, $message_id, whale_t('devices_info', ['username' => $invoice['username'], 'limit' => $limit, 'online' => $online], $from_id), whale_kb($rows));
}

function whale_device_buy_prompt($from_id, $message_id, $id_invoice)
{
    $invoice = whale_owned_invoice($from_id, $id_invoice);
    $price = whale_int('device_price');
    if (!$invoice) {
        whale_answer_callback();
        return;
    }
    if ($price <= 0) {
        whale_answer_callback(whale_t('device_not_sold', [], $from_id), true);
        return;
    }
    $panel = whale_panel_by_name($invoice['Service_location']);
    $limit = is_array($panel) ? whale_device_limit_of($panel, $invoice['username']) : 0;
    if ($limit <= 0) {
        whale_answer_callback(whale_t('device_unlimited', [], $from_id), true);
        return;
    }
    clearSelectCache('user');
    $user = select("user", "*", "id", $from_id, "select");
    whale_answer_callback();
    Editmessagetext($from_id, $message_id, whale_t('device_buy_prompt', [
        'username' => $invoice['username'], 'limit' => $limit, 'new_limit' => $limit + 1,
        'price' => whale_money($price), 'balance' => whale_money($user['Balance'] ?? 0),
    ], $from_id), whale_kb([
        [['text' => whale_t('btn_confirm_pay', ['price' => whale_money($price)], $from_id), 'callback_data' => 'whale_devok_' . $id_invoice]],
        [['text' => whale_t('btn_back', [], $from_id), 'callback_data' => 'product_' . $id_invoice]],
    ]));
}

function whale_renew_pay_callback($from_id, array $m)
{
    $invoice = whale_owned_invoice($from_id, $m[1]);
    if (!$invoice) {
        return;
    }
    $panel = whale_panel_by_name($invoice['Service_location']);
    clearSelectCache('user');
    $user = select("user", "*", "id", $from_id, "select");
    if (!is_array($panel) || !is_array($user)) {
        return;
    }
    $code = strpos($m[2], 'p_') === 0 ? substr($m[2], 2) : null;
    $plan = whale_resolve_plan($panel, $user, $code, $m[3] ?? 0, $m[4] ?? 0);
    if (!$plan) {
        sendmessage($from_id, whale_t('renew_failed', [], $from_id), null, 'HTML');
        return;
    }
    global $ManagePanel;
    $manager = $ManagePanel instanceof ManagePanel ? $ManagePanel : new ManagePanel();
    if (intval($user['Balance']) >= intval($plan['price_product'])) {
        if (!whale_renew_apply($user, $invoice, $plan, $manager)) {
            sendmessage($from_id, whale_t('renew_failed', [], $from_id), null, 'HTML');
        }
        return;
    }
    whale_renew_start_gateway($user, $invoice, $plan);
}
