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

foreach (['core', 'texts', 'service', 'notify', 'commerce', 'health', 'admin', 'router'] as $whaleModule) {
    require_once __DIR__ . '/whale/lib/' . $whaleModule . '.php';
}
unset($whaleModule);
