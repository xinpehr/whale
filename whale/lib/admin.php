<?php
/*
 * WhaleVPN admin menu inside the bot: /whale
 */

function whale_setting_labels()
{
    return [
        'test_unused_hours' => 'حذف تست استفاده‌نشده بعد از (ساعت)',
        'test_nudge_hours' => 'یادآوری تست استفاده‌نشده بعد از (ساعت، ۰=خاموش)',
        'nudge_days' => 'یادآوری سرویس وصل‌نشده بعد از (روز، ۰=خاموش)',
        'rating_days' => 'درخواست امتیاز بعد از خرید (روز، ۰=خاموش)',
        'rating_require_online' => 'امتیاز فقط از کاربران متصل‌شده (۱/۰)',
        'device_limit_default' => 'سقف دستگاه پیش‌فرض خرید (۰=نامحدود)',
        'device_limit_test' => 'سقف دستگاه اکانت تست (۰=نامحدود)',
        'device_price' => 'قیمت هر دستگاه اضافه (تومان، ۰=خاموش)',
        'device_notify' => 'اطلاع‌رسانی قطع دستگاه قبلی به کاربر (۱/۰)',
        'card_enabled' => 'دکمه کارت وضعیت سرویس (۱/۰)',
        'renew_max_days_left' => 'تمدید فقط وقتی روز باقی ≤ (۰=بدون قاعده)',
        'renew_max_percent_left' => 'یا درصد حجم باقی ≤ (۰=بدون قاعده)',
        'renew_commission_percent' => 'درصد پورسانت تمدید (-۱=مثل خرید)',
        'commission_min_amount' => 'حداقل مبلغ برای پورسانت (تومان)',
        'start_gift_after_purchase' => 'هدیه عضویت فقط بعد از اولین خرید (۱/۰)',
        'ref_alert_per_hour' => 'هشدار زیرمجموعه مشکوک در ساعت (۰=خاموش)',
        'oneclick' => 'دکمه افزودن با یک لمس (۱/۰)',
        'button_styles' => 'رنگ دکمه‌ها (۱/۰)',
        'hide_location' => 'نمایش «همه لوکیشن‌ها داخل ساب» به‌جای نام پنل (۱/۰)',
        'light_skin' => 'ایموجی‌های انسانی با پوست روشن در کل ربات (۱/۰)',
        'panel_health' => 'پایش پنل و توقف/شروع خودکار فروش (۱/۰)',
        'panel_fail_threshold' => 'تعداد خطای پیاپی تا توقف فروش',
    ];
}

function whale_admin_menu_kb()
{
    return whale_kb([
        [['text' => '⚙️ تنظیمات', 'callback_data' => 'whale_admin_settings'], ['text' => '📱 دستگاه محصولات', 'callback_data' => 'whale_admin_products']],
        [['text' => '📉 قیمت پلکانی حجم', 'callback_data' => 'whale_admin_tiers'], ['text' => '🔁 روش تمدید', 'callback_data' => 'whale_admin_methods']],
        [['text' => '💳 قوانین درگاه', 'callback_data' => 'whale_admin_gateways'], ['text' => '🎯 لینک تبلیغاتی', 'callback_data' => 'whale_admin_promos']],
        [['text' => '⭐️ امتیازها', 'callback_data' => 'whale_admin_ratings'], ['text' => '👥 زیرمجموعه‌ها', 'callback_data' => 'whale_admin_refs']],
        [['text' => '🩺 وضعیت پنل', 'callback_data' => 'whale_admin_health'], ['text' => '📜 کیف پول کاربر', 'callback_data' => 'whale_admin_wallet']],
        [['text' => '📲 تنظیم مینی‌اپ', 'callback_data' => 'whale_admin_miniapp']],
    ]);
}

function whale_admin_back_kb($extra = [])
{
    $rows = $extra;
    $rows[] = [['text' => '🔙 منوی WhaleVPN', 'callback_data' => 'whale_admin_menu']];
    return whale_kb($rows);
}

function whale_admin_show($from_id, $message_id, $text, $kb, $edit)
{
    if ($edit && $message_id) {
        $r = Editmessagetext($from_id, $message_id, $text, $kb);
        if (is_array($r) && !empty($r['ok'])) {
            return;
        }
    }
    sendmessage($from_id, $text, $kb, 'HTML');
}

function whale_admin_handle()
{
    global $from_id, $text, $datain, $user, $message_id;
    $data = (string) $datain;
    $step = (string) ($user['step'] ?? '');
    if ($data !== '') {
        whale_answer_callback();
    }

    // text input for a pending prompt
    if ($data === '' && strpos($step, 'whale_admin_in_') === 0) {
        step('home', $from_id);
        if (trim((string) $text) === '/cancel') {
            sendmessage($from_id, '↩️ لغو شد.', whale_admin_menu_kb(), 'HTML');
            return true;
        }
        return whale_admin_input(substr($step, strlen('whale_admin_in_')), (string) $text);
    }
    if ($text === '/whale' || $data === 'whale_admin_menu') {
        step('home', $from_id);
        whale_admin_show($from_id, $message_id, "🐋 <b>مدیریت WhaleVPN</b>\n\nقابلیت‌های اختصاصی WhaleVPN را از اینجا تنظیم کنید.", whale_admin_menu_kb(), $data !== '');
        return true;
    }
    if ($data === 'whale_admin_settings') {
        $rows = [];
        foreach (whale_setting_labels() as $k => $label) {
            $rows[] = [['text' => $label . ' : ' . whale_get($k), 'callback_data' => 'whale_admin_set_' . $k]];
        }
        whale_admin_show($from_id, $message_id, "⚙️ <b>تنظیمات WhaleVPN</b>\nروی هر مورد بزنید تا مقدار جدید را بفرستید.", whale_admin_back_kb($rows), true);
        return true;
    }
    if (preg_match('/^whale_admin_set_([a-z_]+)$/', $data, $m) && array_key_exists($m[1], whale_setting_labels())) {
        step('whale_admin_in_set_' . $m[1], $from_id);
        sendmessage($from_id, "✏️ " . whale_setting_labels()[$m[1]] . "\nمقدار فعلی: <code>" . whale_get($m[1]) . "</code>\n\nعدد جدید را بفرستید (یا /cancel).", null, 'HTML');
        return true;
    }
    if ($data === 'whale_admin_products') {
        $products = whale_q("SELECT code_product, name_product FROM product ORDER BY id DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
        $rows = [];
        foreach ($products as $p) {
            $limit = whale_q("SELECT device_limit FROM whale_product WHERE code_product = ?", [$p['code_product']])->fetchColumn();
            $label = $limit === false ? 'پیش‌فرض' : ($limit == 0 ? 'نامحدود' : $limit);
            if (strlen('whale_admin_prod_' . $p['code_product']) <= 64) {
                $rows[] = [['text' => $p['name_product'] . ' — ' . $label, 'callback_data' => 'whale_admin_prod_' . $p['code_product']]];
            }
        }
        $txt = "📱 <b>سقف دستگاه همزمان هر محصول</b>\n\nپیش‌فرض خریدهای بدون محصول (حجم دلخواه): " . whale_get('device_limit_default') . "\nاکانت تست: " . whale_get('device_limit_test') . "\nقیمت هر دستگاه اضافه: " . whale_money(whale_get('device_price')) . " تومان";
        if (!$rows) {
            $txt .= "\n\nهنوز محصولی تعریف نشده است.";
        }
        whale_admin_show($from_id, $message_id, $txt, whale_admin_back_kb($rows), true);
        return true;
    }
    if (preg_match('/^whale_admin_prod_(.+)$/', $data, $m)) {
        step('whale_admin_in_prod_' . $m[1], $from_id);
        sendmessage($from_id, "📱 سقف دستگاه همزمان برای محصول <code>" . htmlspecialchars($m[1]) . "</code> را بفرستید.\n۰ = نامحدود، <code>default</code> = استفاده از پیش‌فرض.", null, 'HTML');
        return true;
    }
    if ($data === 'whale_admin_tiers') {
        step('whale_admin_in_tiers', $from_id);
        $cur = trim((string) whale_get('volume_tiers'));
        sendmessage($from_id, "📉 <b>قیمت پلکانی حجم دلخواه</b> (کاربران عادی)\n\nهر خط یک بازه: <code>از-تا:قیمت هر گیگ</code>\nمثال:\n<code>1-20:4000\n21-100:3500\n101-1000:3000</code>\n\nفعلی:\n<code>" . ($cur !== '' ? htmlspecialchars($cur) : 'خاموش') . "</code>\n\nمتن جدید را بفرستید، <code>off</code> برای خاموش، /cancel برای لغو.", null, 'HTML');
        return true;
    }
    if ($data === 'whale_admin_methods') {
        $rows = [];
        foreach (whale_q("SELECT code_panel, name_panel, Methodextend FROM marzban_panel")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $rows[] = [['text' => $p['name_panel'] . ' — ' . (whale_extend_methods()[$p['Methodextend']] ?? $p['Methodextend']), 'callback_data' => 'whale_admin_mp_' . $p['code_panel']]];
        }
        whale_admin_show($from_id, $message_id, "🔁 <b>روش تمدید</b>\nپنل را انتخاب کنید.", whale_admin_back_kb($rows), true);
        return true;
    }
    if (preg_match('/^whale_admin_mp_(\w+)$/', $data, $m)) {
        $rows = [];
        foreach (whale_extend_methods() as $key => $label) {
            $rows[] = [['text' => $label, 'callback_data' => 'whale_admin_ms_' . $m[1] . '_' . $key]];
        }
        whale_admin_show($from_id, $message_id, "🔁 روش تمدید را انتخاب کنید.\n\n<b>ریست زمان + انتقال حجم باقی</b>: زمان از امروز شروع می‌شود و حجم باقی‌مانده به بسته‌ی جدید اضافه می‌شود.", whale_admin_back_kb($rows), true);
        return true;
    }
    if (preg_match('/^whale_admin_ms_(\w+?)_([A-Za-z]+)$/', $data, $m) && array_key_exists($m[2], whale_extend_methods())) {
        update("marzban_panel", "Methodextend", $m[2], "code_panel", $m[1]);
        whale_admin_show($from_id, $message_id, "✅ روش تمدید ذخیره شد: " . whale_extend_methods()[$m[2]], whale_admin_back_kb(), true);
        return true;
    }
    if ($data === 'whale_admin_gateways') {
        $rules = whale_gateway_rules();
        $rows = [];
        foreach (whale_gateway_keys() as $key => $label) {
            $r = $rules[$key] ?? [];
            $desc = (intval($r['min_paid'] ?? 0) || intval($r['min_days'] ?? 0)) ? ('بعد از ' . intval($r['min_paid'] ?? 0) . ' خرید، ' . intval($r['min_days'] ?? 0) . ' روز عضویت') : 'بدون قاعده';
            $rows[] = [['text' => "$label — $desc", 'callback_data' => 'whale_admin_gw_' . $key]];
        }
        $rows[] = [['text' => '🙈 مخفی کردن درگاه برای یک کاربر', 'callback_data' => 'whale_admin_gwhide']];
        $hidden = whale_q("SELECT user_id, gateway FROM whale_user_gateway ORDER BY user_id LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($hidden as $h) {
            $rows[] = [['text' => "❌ حذف: {$h['user_id']} / {$h['gateway']}", 'callback_data' => 'whale_admin_gwun_' . $h['user_id'] . '_' . $h['gateway']]];
        }
        whale_admin_show($from_id, $message_id, "💳 <b>قوانین نمایش درگاه</b>\nبرای هر درگاه تعیین کنید بعد از چند خرید موفق و چند روز عضویت نمایش داده شود.", whale_admin_back_kb($rows), true);
        return true;
    }
    if (preg_match('/^whale_admin_gw_([a-z0-9_]+)$/', $data, $m) && array_key_exists($m[1], whale_gateway_keys())) {
        step('whale_admin_in_gw_' . $m[1], $from_id);
        sendmessage($from_id, "💳 " . whale_gateway_keys()[$m[1]] . "\nدو عدد با فاصله بفرستید: <code>حداقل‌خرید‌موفق حداقل‌روز‌عضویت</code>\nمثال: <code>1 3</code> — برای حذف قاعده: <code>0 0</code>", null, 'HTML');
        return true;
    }
    if ($data === 'whale_admin_gwhide') {
        step('whale_admin_in_gwhide', $from_id);
        $keys = implode(', ', array_keys(whale_gateway_keys()));
        sendmessage($from_id, "🙈 آیدی عددی کاربر و کلید درگاه را بفرستید:\n<code>123456789 cart_to_offline</code>\n\nکلیدها: <code>$keys</code>", null, 'HTML');
        return true;
    }
    if (preg_match('/^whale_admin_gwun_(\d+)_([a-z0-9_]+)$/', $data, $m)) {
        whale_q("DELETE FROM whale_user_gateway WHERE user_id = ? AND gateway = ?", [$m[1], $m[2]]);
        whale_admin_show($from_id, $message_id, "✅ درگاه {$m[2]} برای کاربر {$m[1]} دوباره نمایش داده می‌شود.", whale_admin_back_kb([[['text' => '💳 قوانین درگاه', 'callback_data' => 'whale_admin_gateways']]]), true);
        return true;
    }
    if ($data === 'whale_admin_promos') {
        $lines = ["🎯 <b>لینک‌های تبلیغاتی</b>"];
        foreach (whale_promo_stats() as $p) {
            $lines[] = "• <b>" . htmlspecialchars($p['title']) . "</b>\n<code>" . whale_promo_link($p['code']) . "</code>\nکلیک: {$p['hits']} · عضو جدید: {$p['members']} · خریدار: {$p['buyers']} · فروش: " . whale_money($p['revenue']) . " تومان";
        }
        if (count($lines) === 1) {
            $lines[] = "هنوز لینکی ساخته نشده است.";
        }
        whale_admin_show($from_id, $message_id, implode("\n\n", $lines), whale_admin_back_kb([[['text' => '➕ ساخت لینک جدید', 'callback_data' => 'whale_admin_promonew']]]), true);
        return true;
    }
    if ($data === 'whale_admin_promonew') {
        step('whale_admin_in_promo', $from_id);
        sendmessage($from_id, "🎯 یک عنوان برای لینک بفرستید (مثلاً «کانال X - مهر»).", null, 'HTML');
        return true;
    }
    if ($data === 'whale_admin_ratings') {
        $s = whale_q("SELECT COUNT(*) c, AVG(rating) a, SUM(rating<=3) low FROM whale_rating")->fetch(PDO::FETCH_ASSOC);
        $lines = ["⭐️ <b>امتیاز سرویس</b>", "تعداد: " . intval($s['c']) . " · میانگین: " . ($s['c'] ? round($s['a'], 2) : '—') . " · ≤۳: " . intval($s['low'])];
        foreach (whale_q("SELECT * FROM whale_rating ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $lines[] = str_repeat('⭐️', intval($r['rating'])) . " <code>{$r['user_id']}</code>" . ($r['comment'] ? "\n" . htmlspecialchars(mb_substr($r['comment'], 0, 200)) : '');
        }
        whale_admin_show($from_id, $message_id, implode("\n\n", $lines), whale_admin_back_kb(), true);
        return true;
    }
    if ($data === 'whale_admin_refs') {
        $lines = ["👥 <b>بیشترین زیرمجموعه</b>"];
        foreach (whale_q("SELECT id, username, affiliatescount FROM user WHERE affiliatescount + 0 > 0 ORDER BY affiliatescount + 0 DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC) as $u) {
            [$total, $buyers] = whale_referrer_stats($u['id']);
            $rate = $total ? round($buyers * 100 / $total) : 0;
            $flag = ($total >= 5 && $rate < 10) ? ' ⚠️' : '';
            $lines[] = "<code>{$u['id']}</code> @" . htmlspecialchars((string) $u['username']) . " — {$total} نفر، {$buyers} خریدار ({$rate}٪){$flag}";
        }
        if (count($lines) === 1) {
            $lines[] = 'هنوز زیرمجموعه‌ای ثبت نشده است.';
        }
        whale_admin_show($from_id, $message_id, implode("\n", $lines), whale_admin_back_kb(), true);
        return true;
    }
    if ($data === 'whale_admin_health' || $data === 'whale_admin_healthnow') {
        if ($data === 'whale_admin_healthnow') {
            whale_panel_health_tick(true);
        }
        whale_admin_show($from_id, $message_id, whale_panel_health_text(), whale_admin_back_kb([[['text' => '🔄 بررسی همین حالا', 'callback_data' => 'whale_admin_healthnow']]]), true);
        return true;
    }
    if ($data === 'whale_admin_wallet') {
        step('whale_admin_in_wallet', $from_id);
        sendmessage($from_id, "📜 آیدی عددی کاربر را بفرستید.", null, 'HTML');
        return true;
    }
    if ($data === 'whale_admin_miniapp') {
        $url = 'https://' . whale_domain() . '/whale/app/';
        $r = telegram('setChatMenuButton', ['menu_button' => json_encode(['type' => 'web_app', 'text' => 'WhaleVPN', 'web_app' => ['url' => $url]])]);
        whale_admin_show($from_id, $message_id, (!empty($r['ok']) ? "✅ دکمه‌ی منوی ربات روی مینی‌اپ WhaleVPN تنظیم شد:\n<code>$url</code>" : "❌ تنظیم ناموفق: " . htmlspecialchars(json_encode($r))), whale_admin_back_kb(), true);
        return true;
    }
    return false;
}

function whale_admin_input($action, $text)
{
    global $from_id;
    $t = trim(strtr($text, ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9']));
    if (strpos($action, 'set_') === 0) {
        $key = substr($action, 4);
        if (!array_key_exists($key, whale_setting_labels()) || !preg_match('/^-?\d+$/', $t)) {
            sendmessage($from_id, '❌ مقدار نامعتبر است. یک عدد صحیح بفرستید.', whale_admin_menu_kb(), 'HTML');
            return true;
        }
        whale_set($key, intval($t));
        sendmessage($from_id, "✅ ذخیره شد: " . whale_setting_labels()[$key] . " = <code>" . intval($t) . "</code>", whale_kb([[['text' => '⚙️ تنظیمات', 'callback_data' => 'whale_admin_settings']]]), 'HTML');
        return true;
    }
    if (strpos($action, 'prod_') === 0) {
        $code = substr($action, 5);
        if (strtolower($t) === 'default') {
            whale_q("DELETE FROM whale_product WHERE code_product = ?", [$code]);
        } elseif (preg_match('/^\d+$/', $t)) {
            whale_q("INSERT INTO whale_product (code_product, device_limit) VALUES (?, ?) ON DUPLICATE KEY UPDATE device_limit = VALUES(device_limit)", [$code, intval($t)]);
        } else {
            sendmessage($from_id, '❌ مقدار نامعتبر است.', whale_admin_menu_kb(), 'HTML');
            return true;
        }
        sendmessage($from_id, "✅ ذخیره شد.", whale_kb([[['text' => '📱 دستگاه محصولات', 'callback_data' => 'whale_admin_products']]]), 'HTML');
        return true;
    }
    if ($action === 'tiers') {
        if (strtolower($t) === 'off') {
            whale_set('volume_tiers', '');
        } else {
            whale_set('volume_tiers', $t);
            if (!whale_volume_tiers()) {
                whale_set('volume_tiers', '');
                sendmessage($from_id, '❌ هیچ خط معتبری پیدا نشد. قالب: <code>1-20:4000</code>', whale_admin_menu_kb(), 'HTML');
                return true;
            }
        }
        $lines = array_map(function ($x) {
            return "{$x['min']}-{$x['max']} گیگ: " . whale_money($x['unit']);
        }, whale_volume_tiers());
        sendmessage($from_id, "✅ قیمت پلکانی ذخیره شد.\n" . ($lines ? implode("\n", $lines) : 'خاموش'), whale_admin_menu_kb(), 'HTML');
        return true;
    }
    if (strpos($action, 'gw_') === 0) {
        $key = substr($action, 3);
        if (!preg_match('/^(\d+)\s+(\d+)$/', $t, $m) || !array_key_exists($key, whale_gateway_keys())) {
            sendmessage($from_id, '❌ قالب: <code>1 3</code>', whale_admin_menu_kb(), 'HTML');
            return true;
        }
        $rules = whale_gateway_rules();
        if (intval($m[1]) === 0 && intval($m[2]) === 0) {
            unset($rules[$key]);
        } else {
            $rules[$key] = ['min_paid' => intval($m[1]), 'min_days' => intval($m[2])];
        }
        whale_set('gateway_rules', json_encode($rules));
        sendmessage($from_id, '✅ قاعده‌ی درگاه ذخیره شد.', whale_kb([[['text' => '💳 قوانین درگاه', 'callback_data' => 'whale_admin_gateways']]]), 'HTML');
        return true;
    }
    if ($action === 'gwhide') {
        if (!preg_match('/^(\d+)\s+([a-z0-9_]+)$/', $t, $m) || !array_key_exists($m[2], whale_gateway_keys())) {
            sendmessage($from_id, '❌ قالب: <code>123456789 cart_to_offline</code>', whale_admin_menu_kb(), 'HTML');
            return true;
        }
        whale_q("INSERT IGNORE INTO whale_user_gateway (user_id, gateway) VALUES (?, ?)", [$m[1], $m[2]]);
        sendmessage($from_id, "✅ درگاه {$m[2]} برای کاربر {$m[1]} مخفی شد.", whale_kb([[['text' => '💳 قوانین درگاه', 'callback_data' => 'whale_admin_gateways']]]), 'HTML');
        return true;
    }
    if ($action === 'promo') {
        $title = mb_substr(trim($text), 0, 150);
        if ($title === '') {
            sendmessage($from_id, '❌ عنوان خالی است.', whale_admin_menu_kb(), 'HTML');
            return true;
        }
        $code = whale_promo_create($title);
        sendmessage($from_id, "✅ لینک ساخته شد:\n<code>" . whale_promo_link($code) . "</code>", whale_kb([[['text' => '🎯 لینک‌ها و آمار', 'callback_data' => 'whale_admin_promos']]]), 'HTML');
        return true;
    }
    if ($action === 'wallet') {
        if (!preg_match('/^\d+$/', $t)) {
            sendmessage($from_id, '❌ آیدی عددی بفرستید.', whale_admin_menu_kb(), 'HTML');
            return true;
        }
        sendmessage($from_id, "👤 <code>$t</code>\n\n" . whale_balance_log_text($t, 20, $from_id), whale_admin_menu_kb(), 'HTML');
        return true;
    }
    return false;
}
