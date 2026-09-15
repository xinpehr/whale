<?php
/**
 * WhaleVPN one-tap "add subscription to app" page.
 * URL: /whale/add.php?u=<base64url(subscription URL)>[&a=<app key>]
 */
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

const WHALE_BRAND = 'WhaleVPN';

function whale_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function whale_b64url_decode(string $s): ?string
{
    $s = rtrim(trim($s), '=');
    if ($s === '' || strlen($s) > 4096 || !preg_match('~^[A-Za-z0-9_\-+/]+$~', $s)) {
        return null;
    }
    $s = strtr($s, '-_', '+/');
    $rem = strlen($s) % 4;
    if ($rem === 1) {
        return null;
    }
    if ($rem) {
        $s .= str_repeat('=', 4 - $rem);
    }
    $out = base64_decode($s, true);
    return $out === false ? null : $out;
}

function whale_host_of(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }
    if (!preg_match('~^[a-z][a-z0-9+.\-]*://~i', $value)) {
        $value = 'https://' . $value;
    }
    $host = parse_url($value, PHP_URL_HOST);
    return is_string($host) && $host !== '' ? strtolower(rtrim($host, '.')) : null;
}

/** Returns the validated https URL or null. */
function whale_valid_sub_url(?string $url): ?string
{
    if ($url === null || $url === '' || strlen($url) > 2048 || preg_match('~[\x00-\x20\x7F]~', $url)) {
        return null;
    }
    if (!mb_check_encoding($url, 'UTF-8') || filter_var($url, FILTER_VALIDATE_URL) === false) {
        return null;
    }
    $p = parse_url($url);
    if (!is_array($p) || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass'])) {
        return null;
    }
    return $url;
}

function whale_allowed_hosts(): array
{
    $hosts = [];
    /** @var PDO $pdo */
    global $pdo, $domainhosts; // declare before require so config.php assigns the globals
    require_once __DIR__ . '/../config.php';
    if (isset($domainhosts) && ($h = whale_host_of((string) $domainhosts))) {
        $hosts[$h] = true;
    }
    if (isset($pdo) && $pdo instanceof PDO) {
        try {
            $stmt = $pdo->query('SELECT linksubx, url_panel FROM marzban_panel');
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                foreach (['linksubx', 'url_panel'] as $col) {
                    if ($h = whale_host_of($row[$col] ?? null)) {
                        $hosts[$h] = true;
                    }
                }
            }
        } catch (Throwable $e) {
            error_log('whale add.php allowlist query failed: ' . $e->getMessage());
        }
    }
    return $hosts;
}

$preview = getenv('WHALE_ADD_PREVIEW') === '1';
$subUrl = whale_valid_sub_url(whale_b64url_decode((string) ($_GET['u'] ?? '')));
if ($subUrl !== null && !$preview) {
    $allowed = whale_allowed_hosts();
    $host = whale_host_of($subUrl);
    if ($host === null || !isset($allowed[$host])) {
        $subUrl = null;
    }
}

$apps = [];
$primary = null;
$autoLink = null;

if ($subUrl !== null) {
    $enc = rawurlencode($subUrl);
    $catalog = [
        'happ'      => ['Happ', 'happ://add/' . $subUrl, 'https://www.happ.su/', '#1F2937'],
        'v2rayng'   => ['v2rayNG', 'v2rayng://install-config?url=' . $enc, 'https://github.com/2dust/v2rayNG/releases', '#2E7D32'],
        'streisand' => ['Streisand', 'streisand://import/' . $subUrl, 'https://apps.apple.com/app/streisand/id6450534064', '#6D4AFF'],
        'hiddify'   => ['Hiddify', 'hiddify://import/' . $subUrl . '#' . WHALE_BRAND, 'https://hiddify.com/', '#0B8A7A'],
        'v2box'     => ['V2Box', 'v2box://install-sub?url=' . $enc . '&name=' . WHALE_BRAND, 'https://apps.apple.com/app/v2box-v2ray-client/id6446814690', '#1565C0'],
        'v2raytun'  => ['v2RayTun', 'v2raytun://import/' . $subUrl, 'https://v2raytun.com/', '#E65100'],
        'karing'    => ['Karing', 'karing://install-config?url=' . $enc . '&name=' . WHALE_BRAND, 'https://karing.app/', '#AD1457'],
    ];

    $ua = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
    if (preg_match('~Android~i', $ua)) {
        $order = ['v2rayng', 'happ', 'hiddify', 'v2raytun', 'karing'];
        $platform = 'Android';
    } elseif (preg_match('~iPhone|iPad|iPod|Macintosh|Mac OS X~i', $ua)) {
        $order = ['streisand', 'v2box', 'happ', 'hiddify', 'v2raytun', 'karing'];
        $platform = 'Apple';
    } elseif (preg_match('~Windows|Linux|X11|CrOS~i', $ua)) {
        $order = ['hiddify', 'happ', 'karing'];
        $platform = 'Desktop';
    } else {
        $order = ['happ', 'hiddify', 'v2rayng', 'streisand', 'v2box', 'v2raytun', 'karing'];
        $platform = '';
    }
    foreach ($order as $key) {
        [$name, $link, $store, $color] = $catalog[$key];
        $apps[] = ['key' => $key, 'name' => $name, 'link' => $link, 'store' => $store, 'color' => $color];
    }
    $primary = array_shift($apps);

    $a = strtolower((string) ($_GET['a'] ?? ''));
    if ($a !== '' && isset($catalog[$a])) {
        $autoLink = $catalog[$a][1];
    }
} else {
    http_response_code(400);
}

$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex, nofollow">
<meta name="referrer" content="no-referrer">
<meta name="theme-color" content="#0A1A33">
<title><?= $subUrl !== null ? 'افزودن WhaleVPN به اپ' : 'لینک نامعتبر است' ?></title>
<style>
@font-face { font-family: "Vazir"; src: url("/app/fonts/Vazir-Light.woff2") format("woff2"), url("/app/fonts/Vazir-Light.woff") format("woff"); font-weight: 100 350; font-display: swap; }
@font-face { font-family: "Vazir"; src: url("/app/fonts/Vazir-Medium.woff2") format("woff2"), url("/app/fonts/Vazir-Medium.woff") format("woff"); font-weight: 351 599; font-display: swap; }
@font-face { font-family: "Vazir"; src: url("/app/fonts/Vazir-Bold.woff2") format("woff2"), url("/app/fonts/Vazir-Bold.woff") format("woff"); font-weight: 600 900; font-display: swap; }
:root {
  --sky-500: #38B6FF; --sky-400: #5CC6FF; --sky-300: #8AD8FF; --success: #34C759; --danger: #FF453A;
  --bg-top: #EAF4FF; --bg-bottom: #F7FBFF; --glow: rgba(56,182,255,.28);
  --text: #0A1A33; --text-2: rgba(10,26,51,.66); --text-3: rgba(10,26,51,.45);
  --material: rgba(255,255,255,.72); --material-strong: rgba(255,255,255,.9); --edge: rgba(255,255,255,.95);
  --stroke: rgba(10,26,51,.08); --fill: rgba(10,26,51,.06);
  --shadow: 0 1px 2px rgba(10,26,51,.06), 0 10px 30px -12px rgba(15,37,71,.22);
  --accent: #0A8FE0; --accent-text: #fff; --accent-grad: linear-gradient(135deg, #38B6FF, #1E88E5);
  --focus: 0 0 0 3px rgba(56,182,255,.55); --ease: cubic-bezier(.2,.8,.2,1);
  color-scheme: light;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg-top: #0A1A33; --bg-bottom: #0F2547; --glow: rgba(56,182,255,.22);
    --text: #F2F8FF; --text-2: rgba(226,239,255,.72); --text-3: rgba(226,239,255,.48);
    --material: rgba(22,51,99,.46); --material-strong: rgba(22,51,99,.8); --edge: rgba(255,255,255,.16);
    --stroke: rgba(138,216,255,.1); --fill: rgba(138,216,255,.08);
    --shadow: 0 14px 34px -14px rgba(0,0,0,.55);
    --accent: #5CC6FF; --accent-text: #0A1A33; --accent-grad: linear-gradient(135deg, #8AD8FF, #38B6FF);
    color-scheme: dark;
  }
}
* { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
body {
  margin: 0; min-height: 100vh; min-height: 100dvh; color: var(--text); background: var(--bg-bottom);
  font: 400 1rem/1.6 "Vazir", system-ui, -apple-system, "Segoe UI", Tahoma, sans-serif;
  padding: max(20px, env(safe-area-inset-top)) 16px max(28px, env(safe-area-inset-bottom));
}
body::before {
  content: ""; position: fixed; inset: 0; z-index: -1;
  background: radial-gradient(120% 60% at 85% -10%, var(--glow), transparent 60%), radial-gradient(90% 50% at 0% 110%, rgba(56,182,255,.14), transparent 60%), linear-gradient(180deg, var(--bg-top), var(--bg-bottom));
}
.wrap { max-width: 480px; margin: 0 auto; }
:focus-visible { outline: none; box-shadow: var(--focus); }
.brand { display: flex; align-items: center; gap: 8px; margin: 4px 2px 22px; }
.brand span { font-size: 1.2rem; font-weight: 700; letter-spacing: -.02em; line-height: 1; }
.brand b { color: var(--accent); }
h1 { font-size: 1.55rem; line-height: 1.3; margin: 0 2px 6px; font-weight: 700; }
.lead { margin: 0 2px 20px; color: var(--text-2); font-size: .94rem; }
.card {
  background: var(--material); -webkit-backdrop-filter: blur(24px) saturate(160%); backdrop-filter: blur(24px) saturate(160%);
  border: 1px solid var(--stroke); border-radius: 22px; box-shadow: inset 0 1px 0 var(--edge), var(--shadow); padding: 16px;
}
.card + .card { margin-top: 12px; }
.btn {
  position: relative; display: flex; align-items: center; justify-content: center; gap: 10px; width: 100%;
  min-height: 48px; padding: 10px 18px; border: 0; border-radius: 16px; font: inherit; font-weight: 700; text-decoration: none; cursor: pointer;
  transition: transform .12s ease-out, opacity .2s ease; touch-action: manipulation; -webkit-user-select: none; user-select: none;
}
.btn:active, .app:active { transform: scale(.97); }
.btn--primary { min-height: 60px; font-size: 1.08rem; border-radius: 18px; background: var(--accent-grad); color: var(--accent-text); box-shadow: inset 0 1px 0 rgba(255,255,255,.45), 0 10px 26px -10px rgba(56,182,255,.85); }
.btn--secondary { background: var(--fill); color: var(--accent); }
.primary-meta { display: flex; justify-content: space-between; align-items: center; margin-top: 10px; font-size: .85rem; color: var(--text-2); }
.dl { display: inline-flex; align-items: center; min-height: 44px; padding: 0 12px; margin-inline-end: -12px; color: var(--accent); font-size: .85rem; font-weight: 500; text-decoration: none; border-radius: 12px; }
.section { margin: 24px 4px 10px; font-size: .95rem; font-weight: 700; }
.list { padding: 4px 16px; }
.row { display: flex; align-items: center; gap: 12px; min-height: 64px; border-bottom: 1px solid var(--stroke); }
.row:last-child { border-bottom: 0; }
.app { flex: 1; display: flex; align-items: center; gap: 12px; min-height: 56px; color: var(--text); text-decoration: none; font-weight: 700; border-radius: 12px; transition: transform .12s ease-out; }
.icon { flex: none; width: 40px; height: 40px; border-radius: 11px; display: grid; place-items: center; color: #fff; font-weight: 700; font-size: 1.05rem; box-shadow: inset 0 1px 0 rgba(255,255,255,.3); font-family: system-ui, sans-serif; }
.app small { display: block; font-weight: 400; color: var(--text-3); font-size: .78rem; }
.url {
  display: block; margin: 12px 0 0; padding: 12px 14px; border-radius: 14px; background: var(--fill);
  font: 500 .82rem/1.55 ui-monospace, "SF Mono", Menlo, Consolas, monospace; direction: ltr; text-align: left;
  overflow-wrap: anywhere; user-select: all; -webkit-user-select: all; color: var(--text);
}
.steps { margin: 0; padding-inline-start: 1.4em; list-style: persian; color: var(--text-2); font-size: .9rem; }
.steps li + li { margin-top: 4px; }
.toast {
  position: fixed; inset-inline: 16px; top: max(16px, env(safe-area-inset-top)); margin: 0 auto; max-width: 360px; padding: 11px 16px; border-radius: 16px;
  background: var(--material-strong); -webkit-backdrop-filter: blur(24px); backdrop-filter: blur(24px); border: 1px solid var(--stroke);
  box-shadow: 0 12px 30px -10px rgba(0,0,0,.45); text-align: center; font-weight: 500;
  opacity: 0; transform: translateY(-12px); transition: opacity .25s ease, transform .35s var(--ease); pointer-events: none;
}
.toast.is-in { opacity: 1; transform: none; }
.error { text-align: center; padding: 36px 20px; }
.error h1 { margin-bottom: 8px; }
.error p { color: var(--text-2); margin: 0; }
.error svg { margin-bottom: 8px; }
@media (prefers-reduced-motion: reduce) { .btn, .app, .toast { transition: opacity .2s ease; transform: none !important; } }
@media (prefers-reduced-transparency: reduce) { .card, .toast { -webkit-backdrop-filter: none; backdrop-filter: none; background: var(--bg-bottom); } }
@media (prefers-contrast: more) { :root { --stroke: rgba(127,127,127,.6); --text-2: var(--text); } .card { -webkit-backdrop-filter: none; backdrop-filter: none; background: var(--bg-bottom); } }
</style>
</head>
<body>
<main class="wrap">
  <div class="brand" dir="ltr" style="justify-content:flex-end">
    <span>Whale<b>VPN</b></span>
    <svg viewBox="0 0 32 32" width="30" height="30" aria-hidden="true"><path d="M13 8.2c0-2-1-3.2-2.2-4.2M13 8.2c0-2 1-3.2 2.2-4.2" fill="none" stroke="#5CC6FF" stroke-width="1.6" stroke-linecap="round"/><path d="M3.5 18.5C3.5 13 8.2 9.5 14.2 9.5c5.6 0 9.6 2.9 11 6.6l3.2-3.7c.8-.9 2.2-.2 1.9 1l-1.5 5.2 1.5 5.1c.3 1.2-1.1 1.9-1.9 1l-3.3-3.8c-2 4.3-6.5 6.6-11.6 6.6-5.8 0-10-3.4-10-9z" fill="#38B6FF"/><path d="M5.2 21.4c2.6 2.6 6.4 3.7 10.8 3.1" fill="none" stroke="#fff" stroke-opacity=".55" stroke-width="1.4" stroke-linecap="round"/><circle cx="9.4" cy="16.4" r="1.3" fill="#0A1A33"/></svg>
  </div>

<?php if ($subUrl === null): ?>
  <div class="card error" role="alert">
    <svg viewBox="0 0 48 48" width="56" height="56" aria-hidden="true"><circle cx="24" cy="24" r="22" fill="#FF453A" opacity=".14"/><path d="M24 13v14" stroke="#FF453A" stroke-width="4" stroke-linecap="round"/><circle cx="24" cy="34" r="2.6" fill="#FF453A"/></svg>
    <h1>⛔ لینک نامعتبر است</h1>
    <p>این لینک خراب یا منقضی شده است 😕 لطفاً دوباره از داخل ربات WhaleVPN روی «📲 افزودن به اپ» بزنید.</p>
  </div>
<?php else: ?>
  <h1>📲 افزودن WhaleVPN به اپ</h1>
  <p class="lead">👋🏻 اپ موردنظر را نصب کنید، سپس روی دکمه بزنید تا اشتراک شما خودکار اضافه شود ✨</p>

  <section class="card" aria-label="اپ پیشنهادی">
    <a class="btn btn--primary" href="<?= whale_h($primary['link']) ?>">
      <span class="icon" style="background:<?= whale_h($primary['color']) ?>;width:34px;height:34px" aria-hidden="true"><?= whale_h(mb_substr($primary['name'], 0, 1)) ?></span>
      <span>⚡ افزودن به <?= whale_h($primary['name']) ?></span>
    </a>
    <div class="primary-meta">
      <span>✨ پیشنهادی برای دستگاه شما</span>
      <a class="dl" href="<?= whale_h($primary['store']) ?>" target="_blank" rel="noopener noreferrer">⬇️ دانلود <?= whale_h($primary['name']) ?></a>
    </div>
  </section>

<?php if ($apps): ?>
  <h2 class="section">🧩 اپ‌های دیگر</h2>
  <ul class="card list" style="list-style:none;margin:0">
<?php foreach ($apps as $app): ?>
    <li class="row">
      <a class="app" href="<?= whale_h($app['link']) ?>">
        <span class="icon" style="background:<?= whale_h($app['color']) ?>" aria-hidden="true"><?= whale_h(mb_substr($app['name'], 0, 1)) ?></span>
        <span><?= whale_h($app['name']) ?><small>👆🏻 افزودن با یک لمس</small></span>
      </a>
      <a class="dl" href="<?= whale_h($app['store']) ?>" target="_blank" rel="noopener noreferrer" aria-label="دانلود <?= whale_h($app['name']) ?>">⬇️ دانلود</a>
    </li>
<?php endforeach; ?>
  </ul>
<?php endif; ?>

  <h2 class="section">✍🏻 اضافه کردن دستی</h2>
  <section class="card">
    <button class="btn btn--secondary" type="button" id="copy">🔗 کپی لینک ساب</button>
    <code class="url" id="sub-url"><?= whale_h($subUrl) ?></code>
    <ol class="steps" style="margin-top:12px">
      <li>اگر اپ باز نشد، لینک بالا را کپی کنید 📋</li>
      <li>در اپ گزینه «افزودن از کلیپ‌بورد» یا علامت ➕ را بزنید 👌🏻</li>
    </ol>
  </section>
  <div class="toast" id="toast" role="status" aria-live="polite"></div>
<?php endif; ?>
</main>
<?php if ($subUrl !== null): ?>
<script>
(function () {
  'use strict';
  var SUB = <?= json_encode($subUrl, $jsonFlags) ?>;
  var AUTO = <?= json_encode($autoLink, $jsonFlags) ?>;
  var toastEl = document.getElementById('toast'), timer = 0;
  function toast(msg) {
    toastEl.textContent = msg; toastEl.classList.add('is-in');
    clearTimeout(timer); timer = setTimeout(function () { toastEl.classList.remove('is-in'); }, 2600);
  }
  function fallbackCopy() {
    var ta = document.createElement('textarea');
    ta.value = SUB; ta.setAttribute('readonly', ''); ta.style.cssText = 'position:fixed;top:0;opacity:0';
    document.body.appendChild(ta); ta.select(); ta.setSelectionRange(0, SUB.length);
    var ok = false; try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    document.body.removeChild(ta); return ok;
  }
  document.getElementById('copy').addEventListener('click', function () {
    var done = function (ok) { toast(ok ? '✅ لینک ساب کپی شد' : '⚠️ کپی نشد؛ لینک را دستی انتخاب کنید'); };
    if (navigator.clipboard && window.isSecureContext) {
      navigator.clipboard.writeText(SUB).then(function () { done(true); }, function () { done(fallbackCopy()); });
    } else { done(fallbackCopy()); }
  });
  document.addEventListener('touchstart', function () {}, { passive: true });
  if (AUTO) { window.addEventListener('load', function () { setTimeout(function () { location.href = AUTO; }, 250); }); }
})();
</script>
<?php endif; ?>
</body>
</html>
