<?php
/*
 * WhaleVPN guards.
 *
 * whale_action_guard(): one expensive action per user at a time. Upstream already drops
 *   repeated Telegram update_ids (isDuplicateUpdate in botapi.php), but a user tapping
 *   "buy" or "test account" twice sends two *different* updates, and both would run.
 * whale_cron_guard(): one copy of a cron at a time. Mirza's crons have no lock, so a slow
 *   run (dead panel, many invoices) is joined by the next minute's run on top of it.
 */

function whale_lock_table_ready()
{
    static $ok = null;
    if ($ok === null) {
        $ok = whale_install();
    }
    return $ok;
}

/*
 * Returns true when this call owns the key for the next $ttl seconds, false when
 * somebody already holds it. Fails open: on any DB problem the action proceeds.
 */
function whale_action_guard($key, $ttl = 10)
{
    if (whale_test_mode()) {
        return true; // e2e replays the same action many times on purpose
    }
    if (!whale_lock_table_ready()) {
        return true;
    }
    $key = mb_substr((string) $key, 0, 190);
    $now = time();
    try {
        whale_q("DELETE FROM whale_lock WHERE expires < ?", [$now]);
        $stmt = whale_q("INSERT IGNORE INTO whale_lock (k, expires) VALUES (?, ?)", [$key, $now + max(1, intval($ttl))]);
        return $stmt->rowCount() === 1;
    } catch (Throwable $e) {
        error_log('whale_action_guard: ' . $e->getMessage());
        return true;
    }
}

function whale_action_release($key)
{
    try {
        whale_q("DELETE FROM whale_lock WHERE k = ?", [mb_substr((string) $key, 0, 190)]);
    } catch (Throwable $e) {
        // nothing to do: the row expires on its own
    }
}

// Callbacks that create or pay for something: running them twice costs the user money.
function whale_guarded_actions()
{
    return [
        'usertestbtn' => 25,
        'confirmandgetservice' => 30,
        'confirmandgetserviceDiscount' => 30,
        'confirmserivce' => 30,
        'confirmserdiscount' => 30,
        'confirmaextra' => 30,
        'confirmaextratime' => 30,
        'get_gift_start' => 15,
    ];
}

/*
 * Called from whale_handle_update(). Returns true when the update must be swallowed
 * because the same user fired the same action moments ago.
 */
function whale_duplicate_action($user_id, $data, $text)
{
    if (!$user_id) {
        return false;
    }
    $action = null;
    $ttl = 15;
    $data = (string) $data;
    foreach (whale_guarded_actions() as $prefix => $seconds) {
        if ($data === $prefix || strpos($data, $prefix . '_') === 0) {
            $action = $prefix;
            $ttl = $seconds;
            break;
        }
    }
    if ($action === null && is_string($text) && $text !== '') {
        // the test button also arrives as plain keyboard text
        $label = whale_strip_skin_tone($text);
        if ($label !== '' && whale_is_test_label($label)) {
            $action = 'usertest';
            $ttl = 25;
        }
    }
    if ($action === null) {
        return false;
    }
    if (whale_action_guard('act:' . $user_id . ':' . $action, $ttl)) {
        return false;
    }
    whale_answer_callback(whale_t('action_in_progress', [], $user_id));
    return true;
}

function whale_is_test_label($label)
{
    global $textbotlang;
    $candidates = [];
    if (is_array($textbotlang)) {
        $candidates[] = $textbotlang['textbot']['userTest'] ?? null;
    }
    foreach ($candidates as $c) {
        if (is_string($c) && $c !== '' && whale_strip_skin_tone($c) === $label) {
            return true;
        }
    }
    return false;
}

/* ---------- cron locking ---------- */

function whale_cron_lock_dir()
{
    $dir = __DIR__ . '/../../storage/cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return is_dir($dir) ? $dir : sys_get_temp_dir();
}

/*
 * Keeps the file handle for the life of the process: the lock is released when the
 * cron exits, however it exits. $stale only matters when flock is unavailable.
 */
function whale_cron_guard($name, $stale = 600)
{
    static $handles = [];
    $name = preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $name);
    if ($name === '' || whale_test_mode()) {
        return true;
    }
    $file = whale_cron_lock_dir() . '/whale-cron-' . $name . '.lock';
    $fh = @fopen($file, 'c');
    if ($fh === false) {
        if (is_file($file) && (time() - (int) @filemtime($file)) < $stale) {
            return false;
        }
        @touch($file);
        return true;
    }
    if (!@flock($fh, LOCK_EX | LOCK_NB)) {
        @fclose($fh);
        return false;
    }
    @ftruncate($fh, 0);
    @fwrite($fh, getmypid() . '|' . date('Y-m-d H:i:s'));
    @fflush($fh);
    $handles[$name] = $fh; // released at process exit
    return true;
}
