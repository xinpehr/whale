<?php
/*
 * WhaleVPN core: schema, settings, Telegram output filter, small helpers.
 */

const WHALE_SCHEMA = 4;

function whale_pdo()
{
    global $pdo;
    return $pdo;
}

function whale_defaults()
{
    return [
        // test accounts
        'test_unused_hours' => 24,       // remove a test that was never used after N hours
        'test_nudge_hours' => 2,         // remind an unused test after N hours (0 = off)
        // paid services
        'nudge_days' => 1,               // remind a service never connected N days after purchase (0 = off)
        'rating_days' => 3,              // ask for a rating N days after purchase (0 = off)
        'rating_require_online' => 1,    // only ask users who connected at least once
        // devices (3x-ui limitIp = simultaneous devices/IPs)
        'device_limit_default' => 0,     // purchases without a product row (custom volume); 0 = unlimited
        'device_limit_test' => 0,        // test accounts; 0 = unlimited
        'device_price' => 0,             // price of one extra device (Toman); 0 = not sold
        'device_notify' => 1,            // tell the user when an extra device pushed an older one off
        // status card (PNG drawn by whale/lib/card.php)
        'card_enabled' => 1,             // show the "status card" button on the service screen
        // extra volume packs sold on the service screen: lines "gb:price" (empty = Mirza's per-GB flow)
        'volume_packs' => "10:128000\n25:298000",
        // renewal
        'renew_max_days_left' => 0,      // renewal allowed only when days left <= N (0 = no rule)
        'renew_max_percent_left' => 0,   // or remaining volume percent <= N (0 = no rule)
        // pricing
        'volume_tiers' => '',            // lines "min-max:price_per_gb" for normal users
        // referral
        'renew_commission_percent' => -1, // -1 = same as purchase commission percent
        'commission_min_amount' => 0,    // no commission for payments below this amount
        'start_gift_after_purchase' => 0, // start gift only after the referred user's first purchase
        'ref_alert_per_hour' => 5,       // alert when one referrer brings this many new users in an hour (0 = off)
        // gateways: JSON {"cart_to_offline":{"min_paid":1,"min_days":0}}
        'gateway_rules' => '{}',
        // UX
        'oneclick' => 1,
        'button_styles' => 1,
        'hide_location' => 1,
        'light_skin' => 1,               // every skin-tone emoji the bot sends uses the light tone
        // panel health
        'panel_health' => 1,
        'panel_fail_threshold' => 2,
    ];
}

function whale_setting_keys_numeric()
{
    return [
        'test_unused_hours', 'test_nudge_hours', 'nudge_days', 'rating_days', 'rating_require_online',
        'device_limit_default', 'device_limit_test', 'device_price', 'device_notify', 'card_enabled',
        'renew_max_days_left', 'renew_max_percent_left',
        'renew_commission_percent', 'commission_min_amount', 'start_gift_after_purchase', 'ref_alert_per_hour',
        'oneclick', 'button_styles', 'hide_location', 'light_skin', 'panel_health', 'panel_fail_threshold',
    ];
}

function whale_install()
{
    static $done = false;
    if ($done) {
        return true;
    }
    $pdo = whale_pdo();
    if (!$pdo) {
        return false;
    }
    try {
        $v = $pdo->query("SELECT v FROM whale_setting WHERE k = 'schema'")->fetchColumn();
        if (intval($v) >= WHALE_SCHEMA) {
            $done = true;
            return true;
        }
    } catch (Throwable $e) {
        // tables do not exist yet
    }
    $sql = [
        "CREATE TABLE IF NOT EXISTS whale_setting (k VARCHAR(64) NOT NULL PRIMARY KEY, v TEXT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS whale_product (code_product VARCHAR(200) NOT NULL PRIMARY KEY, device_limit INT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS whale_invoice (id_invoice VARCHAR(200) NOT NULL PRIMARY KEY, extra_devices INT NOT NULL DEFAULT 0, nudged TINYINT NOT NULL DEFAULT 0, rating_asked TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS whale_rating (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, id_invoice VARCHAR(200) NOT NULL, user_id VARCHAR(200) NOT NULL, rating TINYINT NOT NULL, comment TEXT NULL, created INT NOT NULL, UNIQUE KEY uq_invoice (id_invoice)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS whale_promo (code VARCHAR(40) NOT NULL PRIMARY KEY, title VARCHAR(200) NOT NULL, hits INT NOT NULL DEFAULT 0, created INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS whale_promo_user (user_id VARCHAR(200) NOT NULL PRIMARY KEY, code VARCHAR(40) NOT NULL, is_new TINYINT NOT NULL DEFAULT 0, created INT NOT NULL, KEY k_code (code)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS whale_ref_join (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, referrer VARCHAR(200) NOT NULL, user_id VARCHAR(200) NOT NULL, created INT NOT NULL, KEY k_ref (referrer, created)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS whale_user_gateway (user_id VARCHAR(200) NOT NULL, gateway VARCHAR(60) NOT NULL, PRIMARY KEY (user_id, gateway)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS whale_balance_log (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id VARCHAR(200) NOT NULL, old_balance BIGINT NULL, new_balance BIGINT NULL, delta BIGINT NULL, reason VARCHAR(255) NULL, created INT NOT NULL, KEY k_user (user_id, id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS whale_panel_health (code_panel VARCHAR(200) NOT NULL PRIMARY KEY, fails INT NOT NULL DEFAULT 0, auto_disabled TINYINT NOT NULL DEFAULT 0, down_since INT NULL, last_error TEXT NULL, last_ok INT NULL, last_check INT NULL, latency_ms INT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS whale_lock (k VARCHAR(190) NOT NULL PRIMARY KEY, expires INT NOT NULL, KEY k_expires (expires)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS whale_device_seen (username VARCHAR(200) NOT NULL PRIMARY KEY, ips TEXT NULL, checked INT NOT NULL DEFAULT 0, KEY k_checked (checked)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "DROP TRIGGER IF EXISTS whale_user_balance_au",
        "CREATE TRIGGER whale_user_balance_au AFTER UPDATE ON user FOR EACH ROW BEGIN IF NOT (OLD.Balance <=> NEW.Balance) THEN INSERT INTO whale_balance_log (user_id, old_balance, new_balance, delta, reason, created) VALUES (NEW.id, OLD.Balance, NEW.Balance, NEW.Balance - OLD.Balance, @whale_reason, UNIX_TIMESTAMP()); END IF; END",
    ];
    try {
        foreach ($sql as $q) {
            $pdo->exec($q);
        }
        $stmt = $pdo->prepare("INSERT INTO whale_setting (k, v) VALUES ('schema', ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
        $stmt->execute([WHALE_SCHEMA]);
        $done = true;
        return true;
    } catch (Throwable $e) {
        error_log('whale_install: ' . $e->getMessage());
        return false;
    }
}

function whale_get($key)
{
    global $whale_setting_cache;
    whale_install();
    if (!is_array($whale_setting_cache)) {
        $whale_setting_cache = [];
        try {
            foreach (whale_pdo()->query("SELECT k, v FROM whale_setting")->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $whale_setting_cache[$row['k']] = $row['v'];
            }
        } catch (Throwable $e) {
        }
    }
    if (array_key_exists($key, $whale_setting_cache) && $whale_setting_cache[$key] !== null) {
        return $whale_setting_cache[$key];
    }
    $defaults = whale_defaults();
    return $defaults[$key] ?? null;
}

function whale_int($key)
{
    return intval(whale_get($key));
}

function whale_set($key, $value)
{
    global $whale_setting_cache;
    whale_install();
    $stmt = whale_pdo()->prepare("INSERT INTO whale_setting (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    $stmt->execute([$key, (string) $value]);
    $whale_setting_cache = null;
}

function whale_q($sql, array $params = [])
{
    whale_install();
    $stmt = whale_pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

// Balance changes made by WhaleVPN carry a reason into the balance log trigger.
function whale_balance_add($user_id, $amount, $reason)
{
    whale_install();
    $pdo = whale_pdo();
    $pdo->prepare("SET @whale_reason = ?")->execute([mb_substr($reason, 0, 250)]);
    $stmt = $pdo->prepare("UPDATE user SET Balance = Balance + :a WHERE id = :id");
    $stmt->execute([':a' => intval($amount), ':id' => $user_id]);
    $pdo->exec("SET @whale_reason = NULL");
    if (function_exists('clearSelectCache')) {
        clearSelectCache('user');
    }
    return $stmt->rowCount() > 0;
}

function whale_money($amount)
{
    return number_format(intval($amount));
}

function whale_bot_username()
{
    global $usernamebot;
    return $usernamebot ?? '';
}

function whale_domain()
{
    global $domainhosts;
    return $domainhosts ?? '';
}

function whale_admin_ids()
{
    $ids = select("admin", "id_admin", null, null, "FETCH_COLUMN");
    return is_array($ids) ? $ids : [];
}

function whale_topic($report)
{
    $row = select("topicid", "idreport", "report", $report, "select");
    return is_array($row) ? ($row['idreport'] ?? null) : null;
}

// Report to the report channel (topic) when configured, otherwise to every admin.
function whale_report($text, $topic = 'otherreport', $keyboard = null)
{
    $setting = select("setting", "*");
    $channel = is_array($setting) ? ($setting['Channel_Report'] ?? '') : '';
    if (strlen((string) $channel) > 0) {
        $data = ['chat_id' => $channel, 'text' => $text, 'parse_mode' => 'HTML'];
        $thread = whale_topic($topic);
        if ($thread) {
            $data['message_thread_id'] = $thread;
        }
        if ($keyboard) {
            $data['reply_markup'] = $keyboard;
        }
        return telegram('sendmessage', $data);
    }
    foreach (whale_admin_ids() as $admin) {
        sendmessage($admin, $text, $keyboard, 'HTML');
    }
    return true;
}

function whale_is_admin($user_id)
{
    return in_array((string) $user_id, array_map('strval', whale_admin_ids()), true);
}

function whale_test_mode()
{
    return getenv('WHALE_TEST') === '1';
}

/* ---------- Telegram output filter (hooked into telegram() in botapi.php) ---------- */

function whale_tg_filter($method, &$datas)
{
    try {
        $m = strtolower((string) $method);
        if (in_array($m, ['sendmessage', 'editmessagetext', 'sendphoto', 'senddocument', 'editmessagecaption', 'editmessagereplymarkup'], true)) {
            if (isset($datas['reply_markup']) && whale_int('button_styles') === 1) {
                $datas['reply_markup'] = whale_style_markup($datas['reply_markup']);
            }
            if (whale_int('hide_location') === 1) {
                foreach (['text', 'caption'] as $field) {
                    if (isset($datas[$field]) && is_string($datas[$field])) {
                        $datas[$field] = whale_clean_location_text($datas[$field]);
                    }
                }
            }
        }
        if (whale_int('light_skin') === 1 && in_array($m, ['sendmessage', 'editmessagetext', 'sendphoto', 'senddocument', 'sendvideo', 'sendanimation', 'editmessagecaption', 'editmessagereplymarkup', 'answercallbackquery', 'copymessage'], true)) {
            whale_skin_tone_datas($m, $datas);
        }
    } catch (Throwable $e) {
        error_log('whale_tg_filter: ' . $e->getMessage());
    }
    if (whale_test_mode()) {
        return whale_capture($method, $datas);
    }
    return null;
}

function whale_capture($method, $datas)
{
    static $next = 1000;
    $file = getenv('WHALE_CAPTURE_FILE') ?: (sys_get_temp_dir() . '/whale_capture.jsonl');
    $row = ['method' => strtolower((string) $method), 'data' => $datas, 't' => microtime(true)];
    @file_put_contents($file, json_encode($row, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
    $next++;
    $chat = $datas['chat_id'] ?? 0;
    return ['ok' => true, 'result' => ['message_id' => $next + mt_rand(1, 999999), 'chat' => ['id' => $chat], 'date' => time()]];
}

function whale_style_rules()
{
    return [
        'success' => '/^(confirmandgetservice|confirmandgetserviceDiscount|confirmserivce|confirmserdiscount|confirmaextra|confirmaextratime|Add_Balance|buy$|extend_|exntedagei|serviceextendselect_|Confirm_pay_|get_gift_start|usertestbtn|cart_to_offline|aqayepardakht|zarinpal|plisio|nowpayment|iranpay\d|digitaltron|startelegrams|whale_devok_|whale_devbuy_|whale_volok_|whale_topup_|whale_renewpay_|payment$)/',
        'danger' => '/^(removeauto-|removeserviceuser_|confirmremoveservices-|rejectremoceserviceadmin-|remoceserviceadmin|reject_pay_|colselist)/',
        'primary' => '/^(product_|subscriptionurl_|config_|updateproduct_|whale_dev_|whale_vol_|whale_card_|whale_wallet_log|helpbtn)/',
    ];
}

function whale_style_markup($markup)
{
    $isString = is_string($markup);
    $data = $isString ? json_decode($markup, true) : $markup;
    if (!is_array($data)) {
        return $markup;
    }
    $rules = whale_style_rules();
    if (isset($data['inline_keyboard']) && is_array($data['inline_keyboard'])) {
        foreach ($data['inline_keyboard'] as $r => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $c => $btn) {
                if (!is_array($btn) || isset($btn['style'])) {
                    continue;
                }
                $style = null;
                if (isset($btn['callback_data'])) {
                    foreach ($rules as $name => $re) {
                        if (preg_match($re, (string) $btn['callback_data'])) {
                            $style = $name;
                            break;
                        }
                    }
                } elseif (isset($btn['url']) && strpos((string) $btn['url'], '/whale/add.php') !== false) {
                    $style = 'primary';
                } elseif (isset($btn['web_app'])) {
                    $style = 'primary';
                }
                if ($style) {
                    $data['inline_keyboard'][$r][$c]['style'] = $style;
                }
            }
        }
    }
    if (isset($data['keyboard']) && is_array($data['keyboard'])) {
        global $textbotlang;
        $sell = $textbotlang['textbot']['sell'] ?? null;
        $test = $textbotlang['textbot']['userTest'] ?? null;
        foreach ($data['keyboard'] as $r => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $c => $btn) {
                $text = is_array($btn) ? ($btn['text'] ?? '') : (string) $btn;
                if (is_array($btn) && isset($btn['style'])) {
                    continue;
                }
                $style = null;
                if ($sell && $text === $sell) {
                    $style = 'success';
                } elseif ($test && $text === $test) {
                    $style = 'primary';
                }
                if ($style) {
                    $data['keyboard'][$r][$c] = is_array($btn) ? array_merge($btn, ['style' => $style]) : ['text' => $text, 'style' => $style];
                }
            }
        }
    }
    return $isString ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data;
}

// Locations live inside the subscription, so the panel name is never a "location" for users.
function whale_clean_location_text($text)
{
    static $names = null;
    if ($names === null) {
        $names = [];
        try {
            foreach (whale_pdo()->query("SELECT name_panel FROM marzban_panel")->fetchAll(PDO::FETCH_COLUMN) as $n) {
                if (is_string($n) && $n !== '') {
                    $names[] = $n;
                }
            }
        } catch (Throwable $e) {
        }
    }
    if (!$names || $text === '') {
        return $text;
    }
    $label = whale_t('all_locations');
    $lines = explode("\n", $text);
    foreach ($lines as $i => $line) {
        if (!preg_match('/(موقعیت|لوکیشن|مکان|Location|location)/u', $line)) {
            continue;
        }
        foreach ($names as $name) {
            $q = preg_quote($name, '/');
            $new = preg_replace('/(:\s*(?:<[^>]+>)*\s*)' . $q . '((?:<\/[^>]+>)*\s*)$/u', '${1}' . $label . '${2}', $line);
            if ($new !== null && $new !== $line) {
                $lines[$i] = $new;
                break;
            }
        }
    }
    return implode("\n", $lines);
}

function whale_answer_callback($text = '', $alert = false)
{
    global $callback_query_id;
    if (empty($callback_query_id)) {
        return;
    }
    $data = ['callback_query_id' => $callback_query_id];
    if ($text !== '') {
        $data['text'] = $text;
        $data['show_alert'] = $alert;
    }
    telegram('answerCallbackQuery', $data);
}

function whale_kb(array $rows)
{
    return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
}

function whale_panel_by_name($name)
{
    return select("marzban_panel", "*", "name_panel", $name, "select");
}

function whale_invoice_meta($id_invoice)
{
    whale_q("INSERT IGNORE INTO whale_invoice (id_invoice) VALUES (?)", [$id_invoice]);
    $row = whale_q("SELECT * FROM whale_invoice WHERE id_invoice = ?", [$id_invoice])->fetch(PDO::FETCH_ASSOC);
    return $row ?: ['id_invoice' => $id_invoice, 'extra_devices' => 0, 'nudged' => 0, 'rating_asked' => 0];
}

function whale_invoice_meta_set($id_invoice, $field, $value)
{
    if (!in_array($field, ['extra_devices', 'nudged', 'rating_asked'], true)) {
        return;
    }
    whale_q("INSERT IGNORE INTO whale_invoice (id_invoice) VALUES (?)", [$id_invoice]);
    whale_q("UPDATE whale_invoice SET $field = ? WHERE id_invoice = ?", [intval($value), $id_invoice]);
}

function whale_time_value($value)
{
    if (is_numeric($value)) {
        return intval($value);
    }
    $t = strtotime((string) $value);
    return $t ?: 0;
}

function whale_test_label()
{
    global $textbotlang;
    return $textbotlang['common']['labels']['testServiceName'] ?? 'سرویس تست';
}

function whale_is_test_invoice($invoice)
{
    global $textbotlang;
    $labels = array_filter([
        $textbotlang['common']['labels']['testServiceName'] ?? null,
        $textbotlang['common']['labels']['testService1'] ?? null,
        $textbotlang['common']['labels']['testService5'] ?? null,
        'usertest',
    ]);
    return in_array($invoice['name_product'] ?? '', $labels, true);
}

/* ---------- light skin tone for every human / hand emoji ---------- */

function whale_skin_bases()
{
    static $re = null;
    if ($re === null) {
        // Unicode Emoji_Modifier_Base
        $cps = [0x261D, 0x26F9, 0x270A, 0x270B, 0x270C, 0x270D, 0x1F385, 0x1F3C2, 0x1F3C3, 0x1F3C4, 0x1F3C7, 0x1F3CA, 0x1F3CB, 0x1F3CC,
            0x1F442, 0x1F443, 0x1F446, 0x1F447, 0x1F448, 0x1F449, 0x1F44A, 0x1F44B, 0x1F44C, 0x1F44D, 0x1F44E, 0x1F44F, 0x1F450,
            0x1F466, 0x1F467, 0x1F468, 0x1F469, 0x1F46A, 0x1F46B, 0x1F46C, 0x1F46D, 0x1F46E, 0x1F470, 0x1F471, 0x1F472, 0x1F473, 0x1F474, 0x1F475, 0x1F476, 0x1F477, 0x1F478, 0x1F47C,
            0x1F481, 0x1F482, 0x1F483, 0x1F485, 0x1F486, 0x1F487, 0x1F48F, 0x1F491, 0x1F4AA, 0x1F574, 0x1F575, 0x1F57A, 0x1F590, 0x1F595, 0x1F596,
            0x1F645, 0x1F646, 0x1F647, 0x1F64B, 0x1F64C, 0x1F64D, 0x1F64E, 0x1F64F, 0x1F6A3, 0x1F6B4, 0x1F6B5, 0x1F6B6, 0x1F6C0, 0x1F6CC,
            0x1F90C, 0x1F90F, 0x1F918, 0x1F919, 0x1F91A, 0x1F91B, 0x1F91C, 0x1F91D, 0x1F91E, 0x1F91F, 0x1F926, 0x1F930, 0x1F931, 0x1F932, 0x1F933, 0x1F934, 0x1F935, 0x1F936, 0x1F937, 0x1F938, 0x1F939, 0x1F93D, 0x1F93E,
            0x1F977, 0x1F9B5, 0x1F9B6, 0x1F9B8, 0x1F9B9, 0x1F9BB, 0x1F9CD, 0x1F9CE, 0x1F9CF, 0x1F9D1, 0x1F9D2, 0x1F9D3, 0x1F9D4, 0x1F9D5, 0x1F9D6, 0x1F9D7, 0x1F9D8, 0x1F9D9, 0x1F9DA, 0x1F9DB, 0x1F9DC, 0x1F9DD,
            0x1FAC3, 0x1FAC4, 0x1FAC5, 0x1FAF0, 0x1FAF1, 0x1FAF2, 0x1FAF3, 0x1FAF4, 0x1FAF5, 0x1FAF6, 0x1FAF7, 0x1FAF8];
        $chars = array_map(function ($cp) {
            return preg_quote(mb_chr($cp, 'UTF-8'), '/');
        }, $cps);
        $re = '/(' . implode('|', $chars) . ')\x{FE0F}?(?![\x{1F3FB}-\x{1F3FF}])/u';
    }
    return $re;
}

function whale_skin_tone($text)
{
    if (!is_string($text) || $text === '') {
        return $text;
    }
    $out = preg_replace('/[\x{1F3FC}-\x{1F3FF}]/u', "\u{1F3FB}", $text);
    $out = preg_replace(whale_skin_bases(), '$1' . "\u{1F3FB}", (string) $out);
    return $out === null ? $text : $out;
}

// Incoming text: drop skin-tone modifiers so reply-keyboard labels still match the bot's own labels.
function whale_strip_skin_tone($text)
{
    if (!is_string($text) || $text === '') {
        return $text;
    }
    $out = preg_replace('/[\x{1F3FB}-\x{1F3FF}]/u', '', $text);
    return $out === null ? $text : $out;
}

function whale_skin_tone_markup($markup)
{
    $isString = is_string($markup);
    $data = $isString ? json_decode($markup, true) : $markup;
    if (!is_array($data)) {
        return $markup;
    }
    foreach (['inline_keyboard', 'keyboard'] as $kind) {
        if (!isset($data[$kind]) || !is_array($data[$kind])) {
            continue;
        }
        foreach ($data[$kind] as $r => $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach ($row as $c => $btn) {
                if (is_array($btn) && isset($btn['text'])) {
                    $data[$kind][$r][$c]['text'] = whale_skin_tone($btn['text']);
                } elseif (is_string($btn)) {
                    $data[$kind][$r][$c] = whale_skin_tone($btn);
                }
            }
        }
    }
    return $isString ? json_encode($data, JSON_UNESCAPED_UNICODE) : $data;
}

function whale_skin_tone_datas($method, array &$datas)
{
    $hasEntities = isset($datas['entities']) || isset($datas['caption_entities']);
    if (!$hasEntities) {
        foreach (['text', 'caption'] as $field) {
            if (isset($datas[$field]) && is_string($datas[$field])) {
                $datas[$field] = whale_skin_tone($datas[$field]);
            }
        }
    }
    if (isset($datas['reply_markup'])) {
        $datas['reply_markup'] = whale_skin_tone_markup($datas['reply_markup']);
    }
}

/* ---------- main menu layout ---------- */

// Reply keyboard of the main menu: one wide primary action, then a 3-column grid.
// Placeholders are the ones keyboard.php maps to labels; styles are Bot API button styles.
function whale_main_layout()
{
    return [
        'keyboard' => [
            [['text' => 'text_sell', 'style' => 'success']],
            [['text' => 'text_usertest', 'style' => 'primary'], ['text' => 'text_Purchased_services'], ['text' => 'text_extend', 'style' => 'primary']],
            [['text' => 'accountwallet'], ['text' => 'text_affiliates'], ['text' => 'text_Tariff_list']],
            [['text' => 'text_help'], ['text' => 'text_support']],
        ],
    ];
}

function whale_apply_main_layout()
{
    update("setting", "keyboardmain", json_encode(whale_main_layout(), JSON_UNESCAPED_UNICODE), null, null);
    if (function_exists('clearSelectCache')) {
        clearSelectCache('setting');
    }
    return true;
}
