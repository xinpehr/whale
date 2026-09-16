<?php
/*
 * WhaleVPN service status card: one PNG that shows what the service screen says in text.
 *
 * Verified on the bot server (GD 2.3.3 + FreeType, PHP 8.5): Vazirmatn renders Persian
 * joined and right to left as it is, so nothing is reshaped here. An earlier attempt to
 * pre-shape the text reversed digit groups ("12.5" became "5.21"), which is why the text
 * is drawn exactly as written.
 */

function whale_card_font($weight = 'regular')
{
    $files = [
        'regular' => 'Vazirmatn-Regular.ttf',
        'medium' => 'Vazirmatn-Medium.ttf',
        'bold' => 'Vazirmatn-Bold.ttf',
    ];
    $path = __DIR__ . '/../assets/fonts/' . ($files[$weight] ?? $files['regular']);
    return is_file($path) ? $path : null;
}

function whale_card_available()
{
    return function_exists('imagettftext') && function_exists('imagecreatetruecolor')
        && whale_card_font('bold') !== null && whale_card_font('regular') !== null;
}

// Persian digits read better on the card than ASCII ones.
function whale_fa_digits($text)
{
    return str_replace(
        ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '.'],
        ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٫'],
        (string) $text
    );
}

function whale_card_palette()
{
    return [
        'bg' => [10, 16, 30],
        'panel' => [17, 26, 46],
        'panel_soft' => [24, 36, 60],
        'accent' => [78, 168, 255],
        'text' => [234, 240, 255],
        'muted' => [124, 138, 165],
        'good' => [52, 211, 153],
        'warn' => [251, 191, 36],
        'bad' => [248, 113, 113],
    ];
}

function whale_card_color($img, array $rgb)
{
    return imagecolorallocate($img, $rgb[0], $rgb[1], $rgb[2]);
}

function whale_card_rounded_rect($img, $x1, $y1, $x2, $y2, $r, $color)
{
    imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
    imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
    imagefilledellipse($img, $x1 + $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($img, $x2 - $r, $y1 + $r, $r * 2, $r * 2, $color);
    imagefilledellipse($img, $x1 + $r, $y2 - $r, $r * 2, $r * 2, $color);
    imagefilledellipse($img, $x2 - $r, $y2 - $r, $r * 2, $r * 2, $color);
}

function whale_card_text($img, $size, $x, $y, $color, $font, $text, $align = 'left')
{
    if ($text === '' || $font === null) {
        return 0;
    }
    $box = imagettfbbox($size, 0, $font, $text);
    $w = abs($box[2] - $box[0]);
    if ($align === 'right') {
        $x = $x - $w;
    } elseif ($align === 'center') {
        $x = $x - intval($w / 2);
    }
    imagettftext($img, $size, 0, intval($x), intval($y), $color, $font, $text);
    return $w;
}

function whale_card_bytes_gb($bytes)
{
    return $bytes > 0 ? $bytes / (1024 * 1024 * 1024) : 0;
}

function whale_card_gb_text($gb, $user_id = null)
{
    $value = $gb >= 10 ? number_format($gb, 0, '.', '') : number_format($gb, 1, '.', '');
    return whale_fa_digits($value) . ' ' . whale_t('card_gb', [], $user_id);
}

/*
 * $d: username, product, status, used, total (bytes), expire (unix ts or null),
 *     devices (int, 0 = unlimited), user_id (for language).
 * Returns PNG bytes, or null when GD/fonts are missing.
 */
function whale_card_render(array $d)
{
    if (!whale_card_available()) {
        return null;
    }
    $uid = $d['user_id'] ?? null;
    $W = 1000;
    $H = 500;
    $img = imagecreatetruecolor($W, $H);
    imageantialias($img, true);
    $p = whale_card_palette();
    $bg = whale_card_color($img, $p['bg']);
    $panel = whale_card_color($img, $p['panel']);
    $panelSoft = whale_card_color($img, $p['panel_soft']);
    $accent = whale_card_color($img, $p['accent']);
    $text = whale_card_color($img, $p['text']);
    $muted = whale_card_color($img, $p['muted']);
    $good = whale_card_color($img, $p['good']);
    $warn = whale_card_color($img, $p['warn']);
    $bad = whale_card_color($img, $p['bad']);

    $fontR = whale_card_font('regular');
    $fontM = whale_card_font('medium');
    $fontB = whale_card_font('bold');

    imagefilledrectangle($img, 0, 0, $W, $H, $bg);
    whale_card_rounded_rect($img, 30, 30, $W - 30, $H - 30, 28, $panel);
    // brand stripe
    imagefilledrectangle($img, 30, 30, 38, $H - 30, $accent);

    $status = (string) ($d['status'] ?? '');
    if (in_array($status, ['expired', 'limited'], true)) {
        $statusText = whale_t($status === 'expired' ? 'card_s_expired' : 'card_s_limited', [], $uid);
        $statusColor = $bad;
    } elseif ($status === 'disabled') {
        $statusText = whale_t('card_s_disabled', [], $uid);
        $statusColor = $warn;
    } else {
        $statusText = whale_t('card_s_active', [], $uid);
        $statusColor = $good;
    }

    // header: service name on the right, status pill on the left (Persian layout)
    whale_card_text($img, 26, $W - 70, 100, $text, $fontB, (string) ($d['username'] ?? ''), 'right');
    $product = (string) ($d['product'] ?? '');
    if ($product !== '') {
        whale_card_text($img, 16, $W - 70, 138, $muted, $fontR, $product, 'right');
    }
    whale_card_rounded_rect($img, 70, 74, 250, 118, 22, $panelSoft);
    whale_card_text($img, 17, 160, 104, $statusColor, $fontM, $statusText, 'center');

    // data row
    $total = floatval($d['total'] ?? 0);
    $used = floatval($d['used'] ?? 0);
    $unlimited = $total <= 0;
    $remain = $unlimited ? 0 : max(0, $total - $used);
    $ratio = $unlimited ? 0 : min(1, $used / max(1, $total));

    whale_card_text($img, 17, $W - 70, 210, $muted, $fontR, whale_t('card_l_data', [], $uid), 'right');
    $dataValue = $unlimited
        ? whale_t('card_unlimited', [], $uid)
        : whale_card_gb_text(whale_card_bytes_gb($remain), $uid);
    whale_card_text($img, 30, $W - 70, 258, $text, $fontB, $dataValue, 'right');

    // progress bar
    $barX1 = 70;
    $barX2 = $W - 70;
    $barY = 290;
    whale_card_rounded_rect($img, $barX1, $barY, $barX2, $barY + 18, 9, $panelSoft);
    if (!$unlimited && $ratio > 0) {
        $fill = intval(($barX2 - $barX1) * $ratio);
        if ($fill > 18) {
            $barColor = $ratio >= 0.9 ? $bad : ($ratio >= 0.7 ? $warn : $accent);
            // the bar grows from the right, like the Persian text
            whale_card_rounded_rect($img, $barX2 - $fill, $barY, $barX2, $barY + 18, 9, $barColor);
        }
    }
    if (!$unlimited) {
        $usedText = whale_t('card_used_of', [
            'used' => whale_card_gb_text(whale_card_bytes_gb($used), $uid),
            'total' => whale_card_gb_text(whale_card_bytes_gb($total), $uid),
        ], $uid);
        whale_card_text($img, 15, $W - 70, 338, $muted, $fontR, $usedText, 'right');
    }

    // days + devices
    $expire = $d['expire'] ?? null;
    if (empty($expire)) {
        $daysValue = whale_t('card_unlimited', [], $uid);
    } else {
        $days = intval(floor((intval($expire) - time()) / 86400));
        $daysValue = $days > 0
            ? whale_fa_digits($days) . ' ' . whale_t('card_days', [], $uid)
            : whale_t('card_s_expired', [], $uid);
    }
    whale_card_rounded_rect($img, 70, 370, 500, 440, 18, $panelSoft);
    whale_card_text($img, 15, 480, 398, $muted, $fontR, whale_t('card_l_days', [], $uid), 'right');
    whale_card_text($img, 22, 480, 428, $text, $fontB, $daysValue, 'right');

    $devices = intval($d['devices'] ?? 0);
    whale_card_rounded_rect($img, 530, 370, $W - 70, 440, 18, $panelSoft);
    whale_card_text($img, 15, $W - 90, 398, $muted, $fontR, whale_t('card_l_devices', [], $uid), 'right');
    whale_card_text($img, 22, $W - 90, 428, $text, $fontB, $devices > 0 ? whale_fa_digits($devices) : whale_t('card_unlimited', [], $uid), 'right');

    // footer
    $bot = whale_bot_username();
    if ($bot) {
        whale_card_text($img, 14, 70, 466, $muted, $fontR, '@' . $bot, 'left');
    }

    ob_start();
    imagepng($img);
    $png = ob_get_clean();
    imagedestroy($img);
    return $png;
}

/* ---------- sending ---------- */

/*
 * Goes through telegram() like every other message, so the WhaleVPN output filter still
 * applies (light skin tone on the caption, button colours) and e2e captures it instead
 * of sending. telegram() posts a CURLFile as multipart, the same way sendDocument does.
 */
function whale_card_photo_send($chat_id, $png, $caption, $keyboard = null, $bot_token = null)
{
    $tmp = null;
    $payload = [
        'chat_id' => $chat_id,
        'caption' => $caption,
        'parse_mode' => 'HTML',
    ];
    if ($keyboard) {
        $payload['reply_markup'] = is_string($keyboard) ? $keyboard : json_encode($keyboard);
    }
    if (whale_test_mode()) {
        $payload['photo'] = 'e2e:png:' . strlen((string) $png);
    } else {
        $tmp = tempnam(sys_get_temp_dir(), 'whalecard') . '.png';
        if (file_put_contents($tmp, $png) === false) {
            return false;
        }
        $payload['photo'] = new CURLFile($tmp, 'image/png', 'service.png');
    }
    $res = telegram('sendphoto', $payload, $bot_token ?: null);
    if ($tmp !== null) {
        @unlink($tmp);
    }
    return is_array($res) && !empty($res['ok']);
}

// Callback whale_card_<id_invoice>
function whale_card_callback($from_id, $id_invoice)
{
    whale_answer_callback();
    $invoice = whale_owned_invoice($from_id, $id_invoice);
    if (!$invoice) {
        return;
    }
    global $ManagePanel;
    $manager = $ManagePanel instanceof ManagePanel ? $ManagePanel : new ManagePanel();
    $data = $manager->DataUser($invoice['Service_location'], trim($invoice['username']));
    if (!is_array($data) || ($data['status'] ?? '') === 'Unsuccessful') {
        sendmessage($from_id, whale_t('card_failed', [], $from_id), null, 'HTML');
        return;
    }
    $panel = whale_panel_by_name($invoice['Service_location']);
    $devices = is_array($panel) && ($panel['type'] ?? '') === 'x-ui_single'
        ? whale_device_limit_of($panel, trim($invoice['username']))
        : 0;
    $png = whale_card_render([
        'username' => trim($invoice['username']),
        'product' => $invoice['name_product'] ?? '',
        'status' => $data['status'] ?? '',
        'used' => $data['used_traffic'] ?? 0,
        'total' => $data['data_limit'] ?? 0,
        'expire' => $data['expire'] ?? null,
        'devices' => $devices,
        'user_id' => $from_id,
    ]);
    if ($png === null) {
        sendmessage($from_id, whale_t('card_failed', [], $from_id), null, 'HTML');
        return;
    }
    $keyboard = whale_kb([[['text' => whale_t('btn_renew', [], $from_id), 'callback_data' => 'extend_' . $invoice['id_invoice']]]]);
    if (!whale_card_photo_send($from_id, $png, whale_t('card_caption', ['username' => trim($invoice['username'])], $from_id), $keyboard, $invoice['bottype'] ?? null)) {
        sendmessage($from_id, whale_t('card_failed', [], $from_id), null, 'HTML');
    }
}
