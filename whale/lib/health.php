<?php
/*
 * WhaleVPN panel health: precise connection errors, auto pause/resume of sales.
 */

function whale_panel_probe($panel)
{
    if (isset($GLOBALS['whale_probe_override']) && is_callable($GLOBALS['whale_probe_override'])) {
        return call_user_func($GLOBALS['whale_probe_override'], $panel);
    }
    $start = microtime(true);
    $type = $panel['type'] ?? '';
    $url = rtrim((string) ($panel['url_panel'] ?? ''), '/');
    if ($url === '') {
        return ['ok' => false, 'error' => 'panel url is empty', 'ms' => 0];
    }
    $ch = curl_init();
    $headers = ['Accept: application/json'];
    if ($type === 'x-ui_single') {
        curl_setopt($ch, CURLOPT_URL, $url . '/panel/api/server/status');
        $headers[] = 'Authorization: Bearer ' . $panel['password_panel'];
    } else {
        curl_setopt($ch, CURLOPT_URL, $url);
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 6,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $code = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
    curl_close($ch);
    $ms = intval((microtime(true) - $start) * 1000);
    if ($errno) {
        return ['ok' => false, 'error' => "curl $errno: $err", 'ms' => $ms];
    }
    if ($type === 'x-ui_single') {
        $json = json_decode((string) $body, true);
        if ($code === 200 && is_array($json) && !empty($json['success'])) {
            return ['ok' => true, 'error' => null, 'ms' => $ms];
        }
        $snippet = mb_substr(trim(strip_tags((string) $body)), 0, 120);
        if ($code === 401 || $code === 403) {
            return ['ok' => false, 'error' => "HTTP $code: API token rejected", 'ms' => $ms];
        }
        return ['ok' => false, 'error' => "HTTP $code" . ($snippet !== '' ? ": $snippet" : ''), 'ms' => $ms];
    }
    return $code > 0 && $code < 500 ? ['ok' => true, 'error' => null, 'ms' => $ms] : ['ok' => false, 'error' => "HTTP $code", 'ms' => $ms];
}

function whale_duration_text($seconds)
{
    $seconds = max(0, intval($seconds));
    $h = intdiv($seconds, 3600);
    $m = intdiv($seconds % 3600, 60);
    return $h > 0 ? "{$h} ساعت و {$m} دقیقه" : "{$m} دقیقه";
}

// Runs from cronbot/configtest.php (every 2 minutes). $force skips the rate limit.
function whale_panel_health_tick($force = false)
{
    if (whale_int('panel_health') !== 1) {
        return [];
    }
    if (!$force && intval(whale_get('health_last')) > time() - 100) {
        return [];
    }
    whale_set('health_last', time());
    $threshold = max(1, whale_int('panel_fail_threshold'));
    $results = [];
    $panels = whale_q("SELECT * FROM marzban_panel")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($panels as $panel) {
        if (($panel['type'] ?? '') === 'Manualsale') {
            continue;
        }
        whale_q("INSERT IGNORE INTO whale_panel_health (code_panel) VALUES (?)", [$panel['code_panel']]);
        $h = whale_q("SELECT * FROM whale_panel_health WHERE code_panel = ?", [$panel['code_panel']])->fetch(PDO::FETCH_ASSOC);
        $active = ($panel['status'] ?? '') === 'active';
        if (!$active && intval($h['auto_disabled']) !== 1) {
            continue; // disabled by an admin: leave it alone
        }
        $probe = whale_panel_probe($panel);
        $now = time();
        if ($probe['ok']) {
            whale_q("UPDATE whale_panel_health SET fails = 0, last_ok = ?, last_check = ?, latency_ms = ?, last_error = NULL WHERE code_panel = ?", [$now, $now, $probe['ms'], $panel['code_panel']]);
            if (intval($h['auto_disabled']) === 1) {
                update("marzban_panel", "status", "active", "code_panel", $panel['code_panel']);
                $since = intval($h['down_since'] ?: $now);
                whale_q("UPDATE whale_panel_health SET auto_disabled = 0, down_since = NULL WHERE code_panel = ?", [$panel['code_panel']]);
                whale_report(whale_t('panel_up', ['panel' => htmlspecialchars($panel['name_panel']), 'duration' => whale_duration_text($now - $since)]), 'errorreport');
                $results[$panel['code_panel']] = 'recovered';
            } else {
                $results[$panel['code_panel']] = 'ok';
            }
            continue;
        }
        $fails = intval($h['fails']) + 1;
        $downSince = $h['down_since'] ?: $now;
        whale_q("UPDATE whale_panel_health SET fails = ?, last_check = ?, latency_ms = ?, last_error = ?, down_since = ? WHERE code_panel = ?", [$fails, $now, $probe['ms'], $probe['error'], $downSince, $panel['code_panel']]);
        if ($fails >= $threshold && $active) {
            update("marzban_panel", "status", "disable", "code_panel", $panel['code_panel']);
            whale_q("UPDATE whale_panel_health SET auto_disabled = 1 WHERE code_panel = ?", [$panel['code_panel']]);
            whale_report(whale_t('panel_down', ['panel' => htmlspecialchars($panel['name_panel']), 'error' => htmlspecialchars((string) $probe['error'])]), 'errorreport');
            $results[$panel['code_panel']] = 'disabled';
        } else {
            $results[$panel['code_panel']] = 'fail';
        }
    }
    return $results;
}

// cronbot/uptime_panel.php: our health check replaces the old alert that repeated every run.
function whale_uptime_panel_takeover()
{
    return whale_int('panel_health') === 1;
}

function whale_panel_health_text()
{
    $rows = whale_q("SELECT p.name_panel, p.status, h.* FROM marzban_panel p LEFT JOIN whale_panel_health h ON h.code_panel = p.code_panel")->fetchAll(PDO::FETCH_ASSOC);
    $out = ["🩺 <b>وضعیت پنل‌ها</b>"];
    foreach ($rows as $r) {
        $icon = ($r['status'] === 'active') ? '🟢' : (intval($r['auto_disabled']) === 1 ? '🔴' : '⚪️');
        $line = "$icon <b>" . htmlspecialchars($r['name_panel']) . "</b> — " . ($r['latency_ms'] !== null ? intval($r['latency_ms']) . 'ms' : '—');
        if (!empty($r['last_error'])) {
            $line .= "\n   خطای آخر: <code>" . htmlspecialchars($r['last_error']) . "</code>";
        }
        if (!empty($r['last_check'])) {
            $line .= "\n   آخرین بررسی: " . (function_exists('jdate') ? jdate('H:i:s', intval($r['last_check'])) : date('H:i:s', intval($r['last_check'])));
        }
        $out[] = $line;
    }
    return implode("\n\n", $out);
}
