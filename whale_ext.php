<?php
/*
 * WhaleVPN extensions for Mirza Pro.
 *
 * Everything custom lives under whale/ so upstream updates merge cleanly.
 * Upstream files only carry one-line hooks marked "// WhaleVPN" (grep for it).
 * Admin menu in the bot: /whale
 */
if (defined('WHALE_EXT_LOADED')) {
    return;
}
define('WHALE_EXT_LOADED', true);

foreach (['core', 'texts', 'guard', 'card', 'service', 'notify', 'commerce', 'health', 'admin', 'router'] as $whaleModule) {
    require_once __DIR__ . '/whale/lib/' . $whaleModule . '.php';
}
unset($whaleModule);

// One copy of each cron at a time. Mirza's crons carry no lock of their own, so a slow run
// (dead panel, many invoices) is joined by the next minute's run on top of it.
if (PHP_SAPI === 'cli' && !empty($_SERVER['SCRIPT_FILENAME'])
    && strpos(str_replace('\\', '/', (string) $_SERVER['SCRIPT_FILENAME']), '/cronbot/') !== false) {
    if (!whale_cron_guard(basename((string) $_SERVER['SCRIPT_FILENAME'], '.php'))) {
        exit;
    }
}
