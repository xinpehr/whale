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

/* ---------- Persian shaping ---------- */

/*
 * GD draws glyphs in logical order from left to right, so Persian comes out mirrored.
 * Control render on the bot server, 2026-09-16: the string "روز" was drawn as "زور" and
 * "زور" as "روز". Text is therefore turned into presentation forms (which carry the
 * joining shape themselves) and reordered here before drawing. Digits and Latin keep
 * their own left-to-right order: Persian digits live in the Arabic block, and treating
 * them as Arabic letters is what turned "۱۲٫۵" into "۵٫۲۱" in the first attempt.
 */

// [isolated, final, initial, medial]; null means the letter does not join to its left.
function whale_fa_forms()
{
    static $f = null;
    if ($f !== null) {
        return $f;
    }
    $f = [
        'ا' => ["\u{FE8D}", "\u{FE8E}", null, null],
        'آ' => ["\u{FE81}", "\u{FE82}", null, null],
        'أ' => ["\u{FE83}", "\u{FE84}", null, null],
        'إ' => ["\u{FE87}", "\u{FE88}", null, null],
        'ب' => ["\u{FE8F}", "\u{FE90}", "\u{FE91}", "\u{FE92}"],
        'پ' => ["\u{FB56}", "\u{FB57}", "\u{FB58}", "\u{FB59}"],
        'ت' => ["\u{FE95}", "\u{FE96}", "\u{FE97}", "\u{FE98}"],
        'ث' => ["\u{FE99}", "\u{FE9A}", "\u{FE9B}", "\u{FE9C}"],
        'ج' => ["\u{FE9D}", "\u{FE9E}", "\u{FE9F}", "\u{FEA0}"],
        'چ' => ["\u{FB7A}", "\u{FB7B}", "\u{FB7C}", "\u{FB7D}"],
        'ح' => ["\u{FEA1}", "\u{FEA2}", "\u{FEA3}", "\u{FEA4}"],
        'خ' => ["\u{FEA5}", "\u{FEA6}", "\u{FEA7}", "\u{FEA8}"],
        'د' => ["\u{FEA9}", "\u{FEAA}", null, null],
        'ذ' => ["\u{FEAB}", "\u{FEAC}", null, null],
        'ر' => ["\u{FEAD}", "\u{FEAE}", null, null],
        'ز' => ["\u{FEAF}", "\u{FEB0}", null, null],
        'ژ' => ["\u{FB8A}", "\u{FB8B}", null, null],
        'س' => ["\u{FEB1}", "\u{FEB2}", "\u{FEB3}", "\u{FEB4}"],
        'ش' => ["\u{FEB5}", "\u{FEB6}", "\u{FEB7}", "\u{FEB8}"],
        'ص' => ["\u{FEB9}", "\u{FEBA}", "\u{FEBB}", "\u{FEBC}"],
        'ض' => ["\u{FEBD}", "\u{FEBE}", "\u{FEBF}", "\u{FEC0}"],
        'ط' => ["\u{FEC1}", "\u{FEC2}", "\u{FEC3}", "\u{FEC4}"],
        'ظ' => ["\u{FEC5}", "\u{FEC6}", "\u{FEC7}", "\u{FEC8}"],
        'ع' => ["\u{FEC9}", "\u{FECA}", "\u{FECB}", "\u{FECC}"],
        'غ' => ["\u{FECD}", "\u{FECE}", "\u{FECF}", "\u{FED0}"],
        'ف' => ["\u{FED1}", "\u{FED2}", "\u{FED3}", "\u{FED4}"],
        'ق' => ["\u{FED5}", "\u{FED6}", "\u{FED7}", "\u{FED8}"],
        'ک' => ["\u{FB8E}", "\u{FB8F}", "\u{FB90}", "\u{FB91}"],
        'ك' => ["\u{FED9}", "\u{FEDA}", "\u{FEDB}", "\u{FEDC}"],
        'گ' => ["\u{FB92}", "\u{FB93}", "\u{FB94}", "\u{FB95}"],
        'ل' => ["\u{FEDD}", "\u{FEDE}", "\u{FEDF}", "\u{FEE0}"],
        'م' => ["\u{FEE1}", "\u{FEE2}", "\u{FEE3}", "\u{FEE4}"],
        'ن' => ["\u{FEE5}", "\u{FEE6}", "\u{FEE7}", "\u{FEE8}"],
        'ه' => ["\u{FEE9}", "\u{FEEA}", "\u{FEEB}", "\u{FEEC}"],
        'ة' => ["\u{FE93}", "\u{FE94}", null, null],
        'و' => ["\u{FEED}", "\u{FEEE}", null, null],
        'ؤ' => ["\u{FE85}", "\u{FE86}", null, null],
        'ی' => ["\u{FBFC}", "\u{FBFD}", "\u{FBFE}", "\u{FBFF}"],
        'ي' => ["\u{FEF1}", "\u{FEF2}", "\u{FEF3}", "\u{FEF4}"],
        'ئ' => ["\u{FE89}", "\u{FE8A}", "\u{FE8B}", "\u{FE8C}"],
        'ء' => ["\u{FE80}", "\u{FE80}", null, null],
    ];
    return $f;
}

// lam + alef become a single glyph; [isolated, final]
function whale_fa_ligatures()
{
    return [
        'لا' => ["\u{FEFB}", "\u{FEFC}"],
        'لآ' => ["\u{FEF5}", "\u{FEF6}"],
        'لأ' => ["\u{FEF7}", "\u{FEF8}"],
        'لإ' => ["\u{FEF9}", "\u{FEFA}"],
    ];
}

// digits, separators and percent: always written left to right, in any script
function whale_fa_is_number($ch)
{
    $cp = mb_ord($ch, 'UTF-8');
    if ($cp === false) {
        return false;
    }
    return ($cp >= 0x0030 && $cp <= 0x0039)
        || ($cp >= 0x0660 && $cp <= 0x0669)
        || ($cp >= 0x06F0 && $cp <= 0x06F9)
        || in_array($cp, [0x002C, 0x002E, 0x003A, 0x0025, 0x066A, 0x066B, 0x066C], true);
}

function whale_fa_is_latin($ch)
{
    $cp = mb_ord($ch, 'UTF-8');
    return $cp !== false && (($cp >= 0x0041 && $cp <= 0x005A) || ($cp >= 0x0061 && $cp <= 0x007A) || $cp === 0x0040 || $cp === 0x002D || $cp === 0x005F);
}

// marks sit on the previous letter and must not break a join
function whale_fa_is_mark($ch)
{
    $cp = mb_ord($ch, 'UTF-8');
    return $cp !== false && (($cp >= 0x064B && $cp <= 0x065F) || $cp === 0x0670 || $cp === 0x0640);
}

function whale_fa_shape($text)
{
    if (!is_string($text) || $text === '' || !function_exists('mb_str_split') || !function_exists('mb_ord')) {
        return (string) $text;
    }
    $chars = mb_str_split($text, 1, 'UTF-8');
    $forms = whale_fa_forms();
    $ligs = whale_fa_ligatures();

    // 1. ligatures first, so lam-alef counts as one letter when picking joining forms
    $merged = [];
    for ($i = 0, $n = count($chars); $i < $n; $i++) {
        $pair = $chars[$i] . ($chars[$i + 1] ?? '');
        if (isset($ligs[$pair])) {
            $merged[] = ['lig' => $ligs[$pair]];
            $i++;
            continue;
        }
        $merged[] = $chars[$i];
    }

    // 2. pick a presentation form per letter from its logical neighbours
    $out = [];
    $count = count($merged);
    for ($i = 0; $i < $count; $i++) {
        $cur = $merged[$i];
        $prev = null;
        for ($j = $i - 1; $j >= 0; $j--) {
            if (is_string($merged[$j]) && whale_fa_is_mark($merged[$j])) {
                continue;
            }
            $prev = $merged[$j];
            break;
        }
        $next = null;
        for ($j = $i + 1; $j < $count; $j++) {
            if (is_string($merged[$j]) && whale_fa_is_mark($merged[$j])) {
                continue;
            }
            $next = $merged[$j];
            break;
        }
        $joinPrev = is_string($prev) && isset($forms[$prev]) && $forms[$prev][2] !== null;
        $joinNext = is_string($next) && isset($forms[$next]);

        if (is_array($cur)) {
            $out[] = $joinPrev ? $cur['lig'][1] : $cur['lig'][0];
            continue;
        }
        if (!isset($forms[$cur])) {
            $out[] = $cur;
            continue;
        }
        $f = $forms[$cur];
        if ($joinPrev && $joinNext && $f[3] !== null) {
            $out[] = $f[3];
        } elseif ($joinNext && $f[2] !== null) {
            $out[] = $f[2];
        } elseif ($joinPrev) {
            $out[] = $f[1];
        } else {
            $out[] = $f[0];
        }
    }

    // 3. split into runs, reverse the run order, reverse the characters inside RTL runs
    $runs = [];
    $current = null;
    foreach ($out as $ch) {
        $kind = (whale_fa_is_number($ch) || whale_fa_is_latin($ch)) ? 'ltr' : 'rtl';
        if ($current === null || $current['kind'] !== $kind) {
            if ($current !== null) {
                $runs[] = $current;
            }
            $current = ['kind' => $kind, 'chars' => []];
        }
        $current['chars'][] = $ch;
    }
    if ($current !== null) {
        $runs[] = $current;
    }
    $result = [];
    foreach (array_reverse($runs) as $run) {
        $chars = $run['kind'] === 'rtl' ? array_reverse($run['chars']) : $run['chars'];
        foreach ($chars as $ch) {
            $result[] = $ch;
        }
    }

    // brackets read backwards once the line is reversed
    $mirror = ['(' => ')', ')' => '(', '[' => ']', ']' => '[', '{' => '}', '}' => '{', '«' => '»', '»' => '«'];
    foreach ($result as $k => $ch) {
        if (isset($mirror[$ch])) {
            $result[$k] = $mirror[$ch];
        }
    }
    return implode('', $result);
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
    $text = whale_fa_shape($text);
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
    return ob_get_clean();
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
