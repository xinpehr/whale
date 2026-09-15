/* WhaleVPN mini app — vanilla JS, no build step */
(() => {
  'use strict';

  const tg = window.Telegram && window.Telegram.WebApp;
  const qs = new URLSearchParams(location.search);
  const IN_TG = !!(tg && tg.initData);
  const DEMO = !IN_TG || qs.get('demo') === '1';
  const API = '/whale/api.php';
  const TABS = ['/', '/buy', '/wallet'];
  const $ = (s, r = document) => r.querySelector(s);
  const view = $('#view');
  const state = { me: null };

  /* ---------------- utils ---------------- */
  const FA = '۰۱۲۳۴۵۶۷۸۹', AR = '٠١٢٣٤٥٦٧٨٩';
  const faDigits = (s) => String(s == null ? '' : s).replace(/\d/g, (d) => FA[d]);
  const fa = (n) => Number(n || 0).toLocaleString('fa-IR');
  const parseNum = (s) => {
    const t = String(s || '').replace(/[۰-۹]/g, (d) => FA.indexOf(d)).replace(/[٠-٩]/g, (d) => AR.indexOf(d)).replace(/\D/g, '');
    return t ? Math.min(parseInt(t, 10), 1e10) : 0;
  };
  const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const clamp = (v, a, b) => Math.min(b, Math.max(a, v));
  const GiB = 1073741824;
  const gbText = (bytes) => {
    const g = (bytes || 0) / GiB;
    const r = g >= 100 ? Math.round(g) : g >= 10 ? Math.round(g * 10) / 10 : Math.round(g * 100) / 100;
    return r.toLocaleString('fa-IR');
  };
  const dateFa = (ts) => {
    try { return new Date(ts * 1000).toLocaleDateString('fa-IR', { year: 'numeric', month: 'long', day: 'numeric' }); } catch (e) { return faDigits(new Date(ts * 1000).toISOString().slice(0, 10)); }
  };
  const tgv = (v) => IN_TG && typeof tg.isVersionAtLeast === 'function' && tg.isVersionAtLeast(v);
  const store = {
    get(k) { try { return sessionStorage.getItem(k); } catch (e) { return null; } },
    set(k, v) { try { v == null ? sessionStorage.removeItem(k) : sessionStorage.setItem(k, v); } catch (e) { /* storage blocked */ } },
  };

  function haptic(kind) {
    if (!tgv('6.1') || !tg.HapticFeedback) return;
    try {
      if (kind === 'success' || kind === 'error' || kind === 'warning') tg.HapticFeedback.notificationOccurred(kind);
      else if (kind === 'select') tg.HapticFeedback.selectionChanged();
      else tg.HapticFeedback.impactOccurred(kind || 'light');
    } catch (e) { /* unsupported */ }
  }

  function toast(msg, type) {
    const el = document.createElement('div');
    el.className = `toast toast--${type || 'info'}`;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    const icon = { error: '⚠️', success: '✅', info: '💬' }[type || 'info'];
    el.innerHTML = `<span class="toast__icon" aria-hidden="true">${icon}</span><span></span>`;
    el.lastChild.textContent = msg;
    $('#toasts').append(el);
    requestAnimationFrame(() => requestAnimationFrame(() => el.classList.add('is-in')));
    setTimeout(() => {
      el.classList.remove('is-in');
      setTimeout(() => el.remove(), 400);
    }, type === 'error' ? 4200 : 3000);
  }

  function copyText(text) {
    const fallback = () => {
      const ta = document.createElement('textarea');
      ta.value = text; ta.setAttribute('readonly', ''); ta.style.cssText = 'position:fixed;opacity:0;top:0';
      document.body.append(ta); ta.select();
      let ok = false;
      try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
      ta.remove();
      return ok;
    };
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(text).then(() => true, () => fallback());
    }
    return Promise.resolve(fallback());
  }

  function openLink(url) {
    if (IN_TG && typeof tg.openLink === 'function') { try { tg.openLink(url); return; } catch (e) { /* fall through */ } }
    window.open(url, '_blank', 'noopener');
  }

  function confirmBox(title, message, okText) {
    return new Promise((resolve) => {
      if (tgv('6.2') && tg.showPopup) {
        try {
          tg.showPopup({ title, message: message.slice(0, 256), buttons: [{ id: 'ok', type: 'default', text: okText || 'تأیید' }, { type: 'cancel' }] },
            (id) => resolve(id === 'ok'));
          return;
        } catch (e) { /* fall back to dialog */ }
      }
      const d = $('#confirm');
      $('#confirm-title').textContent = title;
      $('#confirm-msg').textContent = message;
      $('#confirm-ok').textContent = okText || 'تأیید';
      d.returnValue = '';
      d.addEventListener('close', () => resolve(d.returnValue === 'ok'), { once: true });
      if (typeof d.showModal === 'function') d.showModal(); else resolve(window.confirm(message));
    });
  }

  function setBusy(btn, busy) {
    if (!btn) return;
    btn.setAttribute('aria-busy', busy ? 'true' : 'false');
    btn.disabled = !!busy;
  }

  /* ---------------- API ---------------- */
  class ApiError extends Error {}
  let token = store.get('whale_token');

  async function verify() {
    let j = {};
    try {
      const r = await fetch('/api/verify', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ initData: tg.initData }) });
      j = await r.json();
    } catch (e) { throw new ApiError('اتصال به سرور برقرار نشد'); }
    if (!j || !j.status || !j.token) throw new ApiError(j && j.msg ? j.msg : 'دوباره از ربات باز کنید');
    token = j.token;
    store.set('whale_token', token);
  }

  async function api(action, opts) {
    const { params = {}, body } = opts || {};
    if (DEMO) return mock(action, params, body);
    const url = new URL(API, location.origin);
    url.searchParams.set('action', action);
    Object.keys(params).forEach((k) => url.searchParams.set(k, params[k]));
    for (let attempt = 0; attempt < 2; attempt++) {
      if (!token) {
        try { await verify(); } catch (e) { throw attempt ? new ApiError('🤖 دوباره از ربات باز کنید') : e; }
      }
      let r;
      try {
        r = await fetch(url, {
          method: body ? 'POST' : 'GET',
          headers: Object.assign({ Authorization: `Bearer ${token}` }, body ? { 'Content-Type': 'application/json' } : {}),
          body: body ? JSON.stringify(body) : undefined,
        });
      } catch (e) { throw new ApiError('اتصال برقرار نشد؛ اینترنت را بررسی کنید'); }
      if (r.status === 403 || r.status === 401) {
        token = null; store.set('whale_token', null);
        if (attempt === 0) continue;
        throw new ApiError('🤖 دوباره از ربات باز کنید');
      }
      let j;
      try { j = await r.json(); } catch (e) { throw new ApiError('پاسخ نامعتبر از سرور'); }
      if (!j || !j.ok) throw new ApiError((j && j.error) || 'خطایی رخ داد؛ دوباره تلاش کنید');
      return j;
    }
    throw new ApiError('🤖 دوباره از ربات باز کنید');
  }

  /** Common handling for purchase-like POST results. Returns true when finished in-app. */
  function handleResult(j) {
    if (j.pay_in_bot) {
      haptic('success');
      const msg = j.message || 'برای ادامه پرداخت به گفت‌وگوی ربات بروید 🤖';
      if (tgv('6.2') && tg.showPopup) {
        try {
          tg.showPopup({ title: '🧾 ادامه پرداخت در ربات', message: msg.slice(0, 256), buttons: [{ type: 'ok' }] }, () => tg.close());
          return false;
        } catch (e) { /* fall through */ }
      }
      toast(msg, 'info');
      return false;
    }
    if (j.done) { haptic('success'); toast(j.message || 'انجام شد 🎉', 'success'); return true; }
    toast(j.message || 'درخواست ثبت شد', 'info');
    return false;
  }

  function fail(e) {
    haptic('error');
    toast(e && e.message ? e.message : 'خطایی رخ داد', 'error');
  }

  /* ---------------- demo data ---------------- */
  const now = Math.floor(Date.now() / 1000);
  const DB = {
    balance: 125000,
    services: [
      { id: 'a1b2c3d4', username: '8407253161_ab12', product_name: 'یک ماهه ۵۰ گیگ', status: 'active', status_label: 'فعال', used_bytes: 30 * GiB, limit_bytes: 50 * GiB, expire_ts: now + 18 * 86400, days_left: 18, sub_url: 'https://sub.netwhale.space/sub/8407253161_ab12', add_url: 'https://bot.netwhale.space/whale/add.php?u=aHR0cHM6Ly9zdWIubmV0d2hhbGUuc3BhY2Uvc3ViLzg0MDcyNTMxNjFfYWIxMg', device_limit: 2,
        online_at: '2026/09/15 09:42', device_price: 30000, connected_devices: 1, can_renew: true, renew_block_reason: '' },
      { id: 'e5f6a7b8', username: '8407253161_cd34', product_name: 'سه ماهه ۱۵۰ گیگ', status: 'on_hold', status_label: 'در انتظار اتصال', used_bytes: 0, limit_bytes: 150 * GiB, expire_ts: 0, days_left: 90, sub_url: 'https://sub.netwhale.space/sub/8407253161_cd34', add_url: 'https://bot.netwhale.space/whale/add.php?u=aHR0cHM6Ly9zdWIubmV0d2hhbGUuc3BhY2Uvc3ViLzg0MDcyNTMxNjFfY2QzNA', device_limit: 1,
        online_at: 'offline', device_price: 0, connected_devices: 0, can_renew: false, renew_block_reason: 'این سرویس هنوز فعال نشده است؛ پس از اولین اتصال می‌توانید آن را تمدید کنید.' },
    ],
    plans: {
      products: [
        { code: 'p1', name: 'یک ماهه ۵۰ گیگ', volume_gb: 50, days: 30, price: 150000 },
        { code: 'p2', name: 'سه ماهه ۱۵۰ گیگ', volume_gb: 150, days: 90, price: 390000 },
      ],
      custom: { enabled: true, min_gb: 1, max_gb: 1000, min_days: 1, max_days: 365, day_price: 1000, base_unit: 4000,
        tiers: [{ min: 1, max: 10, unit: 4000 }, { min: 11, max: 100, unit: 3000 }, { min: 101, max: 1000, unit: 2500 }] },
    },
  };
  const fmtB = (b) => `${(b / GiB).toFixed(1).replace(/\.0$/, '')} GB`;
  function mockDetail(s) {
    const pct = s.limit_bytes ? Math.round((s.used_bytes / s.limit_bytes) * 100) : 0;
    return Object.assign({}, s, { used_fmt: fmtB(s.used_bytes), limit_fmt: s.limit_bytes ? fmtB(s.limit_bytes) : 'نامحدود', remaining_fmt: s.limit_bytes ? fmtB(s.limit_bytes - s.used_bytes) : 'نامحدود', percent: pct, device_price_fmt: s.device_price.toLocaleString('en-US') });
  }
  function mockQuote(gb, days) {
    const c = DB.plans.custom;
    const tier = c.tiers.find((t) => gb >= t.min && gb <= t.max);
    return gb * (tier ? tier.unit : c.base_unit) + days * c.day_price;
  }
  function mock(action, p, body) {
    const wait = body ? 600 : action === 'quote' ? 180 : 450;
    return new Promise((resolve, reject) => setTimeout(() => {
      const svc = (id) => DB.services.find((s) => s.id === id);
      const plans = () => JSON.parse(JSON.stringify(DB.plans, (k, v) => v));
      switch (action) {
        case 'me': return resolve({ ok: true, user: { id: '8407253161', name: 'علی', balance: DB.balance, balance_fmt: DB.balance.toLocaleString('en-US') }, brand: 'WhaleVPN', bot_username: 'whalvpnbot', support_url: 'https://t.me/whalvpnbot', services: DB.services, can_buy: true });
        case 'service': return svc(p.id) ? resolve({ ok: true, service: mockDetail(svc(p.id)) }) : reject(new ApiError('سرویس پیدا نشد'));
        case 'renew_plans': return resolve(Object.assign({ ok: true }, plans()));
        case 'buy_plans': return resolve(Object.assign({ ok: true, panel_id: 'demo' }, plans()));
        case 'quote': return resolve({ ok: true, price: mockQuote(+p.gb, +p.days) });
        case 'device': { const s = svc(body.id); s.device_limit += 1; return resolve({ ok: true, done: true, device_limit: s.device_limit, message: 'یک دستگاه به سرویس اضافه شد 🎉' }); }
        case 'renew': return resolve({ ok: true, done: true, message: 'سرویس با موفقیت تمدید شد 🎉' });
        case 'buy': return resolve({ ok: true, done: true, message: 'سرویس جدید ساخته شد 🎉', service_id: 'a1b2c3d4' });
        case 'topup': return resolve({ ok: true, pay_in_bot: true, message: 'لینک پرداخت در گفت‌وگوی ربات برایتان ارسال شد 🤖' });
        default: return reject(new ApiError('عملیات ناشناخته'));
      }
    }, wait));
  }

  /* ---------------- icons & fragments ---------------- */
  const ICON = {
    clock: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2"/><path d="M12 7v5l3 2" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
    device: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="6" y="2.5" width="12" height="19" rx="3" fill="none" stroke="currentColor" stroke-width="2"/><path d="M10.5 18h3" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>',
  };
  const WHALE_ART = '<svg viewBox="0 0 96 96" aria-hidden="true"><circle cx="48" cy="48" r="46" fill="currentColor" opacity=".08"/><path d="M40 30c0-5-2.6-8-5.6-10.5M40 30c0-5 2.6-8 5.6-10.5" fill="none" stroke="#5CC6FF" stroke-width="3" stroke-linecap="round"/><path d="M14 56c0-15 13-25 29-25 15 0 26 8 30 18l8.5-9.8c2.2-2.5 6-.6 5.1 2.6l-4 14 4 13.8c.9 3.2-2.9 5.1-5.1 2.6L73 62.4C67.6 74 55.4 80 41.6 80 25.5 80 14 71 14 56z" fill="#38B6FF"/><path d="M19 64c7 7 17 10 29 8.4" fill="none" stroke="#fff" stroke-opacity=".6" stroke-width="3" stroke-linecap="round"/><circle cx="30" cy="51" r="3.2" fill="#0A1A33"/></svg>';

  const statusPill = (s) => `<span class="pill pill--${esc(/^(active|on_hold|disabled|expired|limited)$/.test(s.status) ? s.status : 'unknown')}">${esc(faDigits(s.status_label || 'نامشخص'))}</span>`;
  const barClass = (pct, unlimited) => unlimited ? 'bar bar--inf' : pct >= 90 ? 'bar bar--danger' : pct >= 70 ? 'bar bar--warn' : 'bar';
  const daysText = (s) => (!s.expire_ts && !s.days_left) ? 'بدون محدودیت زمانی' : `${fa(Math.max(0, s.days_left || 0))} روز مانده`;
  const devText = (n) => n ? `${fa(n)} دستگاه` : 'دستگاه نامحدود';

  const skel = {
    home: () => `<div class="sk sk-card" style="height:170px;margin-top:14px"></div><div class="sk sk-line" style="width:40%;margin-top:28px"></div><div class="sk sk-card"></div><div class="sk sk-card"></div>`,
    detail: () => `<div class="sk sk-line" style="width:55%;height:22px;margin-top:18px"></div><div class="sk sk-card" style="height:190px"></div><div class="sk sk-card" style="height:58px"></div><div class="sk sk-card" style="height:120px"></div>`,
    plans: () => `<div class="sk sk-line" style="width:45%;height:26px;margin-top:18px"></div><div class="sk sk-line" style="width:70%"></div><div class="sk sk-card" style="height:74px"></div><div class="sk sk-card" style="height:74px"></div><div class="sk sk-card" style="height:74px"></div>`,
  };

  /* ---------------- router ---------------- */
  const stack = [];
  let renderSeq = 0;
  const routes = [
    [/^\/$/, homeScreen], [/^\/service\/([\w-]+)$/, serviceScreen], [/^\/renew\/([\w-]+)$/, (el, id, alive) => plansScreen(el, 'renew', id, alive)],
    [/^\/wallet$/, walletScreen], [/^\/buy$/, (el, _, alive) => plansScreen(el, 'buy', null, alive)],
  ];
  const go = (path) => { location.hash = `#${path}`; };
  function back() { if (stack.length > 1) history.back(); else location.hash = '#/'; }

  function onRoute() {
    const path = location.hash.replace(/^#/, '') || '/';
    let dir = 'none';
    if (stack.length > 1 && stack[stack.length - 2] === path) { stack.pop(); dir = 'back'; }
    else if (stack[stack.length - 1] !== path) {
      if (TABS.includes(path)) stack.length = 0;
      stack.push(path);
      dir = stack.length > 1 ? 'forward' : 'none';
    }
    render(path, dir);
  }

  async function render(path, dir) {
    const route = routes.find(([re]) => re.test(path));
    if (!route) { location.replace('#/'); return; }
    const seq = ++renderSeq;
    const alive = () => seq === renderSeq;
    const deep = !TABS.includes(path);
    document.body.classList.toggle('is-deep', deep);
    document.body.classList.remove('has-bar');
    document.querySelectorAll('body > .confirm-bar').forEach((n) => n.remove());
    $('#back').hidden = !deep || IN_TG;
    if (IN_TG && tgv('6.1')) { try { deep ? tg.BackButton.show() : tg.BackButton.hide(); } catch (e) { /* noop */ } }
    document.querySelectorAll('.tab').forEach((t) => {
      if (t.dataset.tab === path) t.setAttribute('aria-current', 'page'); else t.removeAttribute('aria-current');
    });
    const screen = document.createElement('section');
    screen.className = `screen screen--${dir}`;
    view.replaceChildren(screen);
    window.scrollTo(0, 0);
    if (dir !== 'none') view.focus({ preventScroll: true });
    try { await route[1](screen, (path.match(route[0]) || [])[1], alive); }
    catch (e) { if (alive()) errorState(screen, e, () => render(path, 'none')); }
  }

  function errorState(el, e, retry) {
    el.innerHTML = `<div class="card empty" role="alert">${WHALE_ART}<h3>😕 مشکلی پیش آمد</h3><p>${esc(e && e.message ? e.message : 'خطای ناشناخته')}</p><button class="btn btn--primary" type="button" data-retry>🔁 تلاش دوباره</button></div>`;
    $('[data-retry]', el).addEventListener('click', retry);
    haptic('error');
  }

  /* ---------------- screens ---------------- */
  async function homeScreen(el, _, alive) {
    el.innerHTML = skel.home();
    const j = await api('me');
    if (!alive()) return;
    state.me = j;
    const u = j.user || {};
    const services = j.services || [];
    const cards = services.map((s) => {
      const unlimited = !s.limit_bytes;
      const pct = unlimited ? 100 : clamp(Math.round((s.used_bytes / s.limit_bytes) * 100), 0, 100);
      const usage = unlimited ? `${gbText(s.used_bytes)} گیگ مصرف` : `${gbText(s.used_bytes)} از ${gbText(s.limit_bytes)} گیگ`;
      return `<button type="button" class="card svc pressable" data-go="/service/${esc(s.id)}" aria-label="${esc(s.product_name)}، ${esc(s.status_label)}">
        <span class="svc__top"><span><span class="svc__name">${esc(faDigits(s.product_name))}</span><span class="svc__user">${esc(s.username)}</span></span>${statusPill(s)}</span>
        <span class="svc__usage"><span>${usage}</span><span>${unlimited ? 'حجم نامحدود' : `${fa(pct)}٪`}</span></span>
        <span class="${barClass(pct, unlimited)}" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${unlimited ? 0 : pct}"><i style="width:${pct}%"></i></span>
        <span class="svc__meta"><span class="meta-chip">${ICON.clock}${daysText(s)}</span><span class="meta-chip">${ICON.device}${devText(s.device_limit)}</span></span>
      </button>`;
    }).join('');
    el.innerHTML = `
      <div class="card wallet" style="margin-top:14px">
        <div class="wallet__row"><span class="wallet__label">💰 موجودی کیف پول</span><span class="wallet__hello">${u.name ? `👋🏻 سلام، ${esc(u.name)}` : ''}</span></div>
        <div class="wallet__amount"><strong>${fa(u.balance)}</strong><span>تومان</span></div>
        <button type="button" class="btn btn--primary btn--sm" data-go="/wallet">💳 افزایش موجودی</button>
      </div>
      <div class="section-head"><h2>🛡️ سرویس‌های من<span class="count">${services.length ? fa(services.length) : ''}</span></h2>${j.can_buy && services.length ? '<button type="button" class="btn btn--secondary btn--sm" data-go="/buy">🛒 خرید</button>' : ''}</div>
      ${services.length ? cards : `<div class="card empty">${WHALE_ART}<h3>🐳 هنوز سرویسی ندارید</h3><p>با چند لمس یک سرویس پرسرعت بخرید و وصل شوید 🚀</p>${j.can_buy ? '<button type="button" class="btn btn--primary" data-go="/buy">🛒 خرید سرویس</button>' : ''}</div>`}
      ${j.support_url ? `<p style="text-align:center;margin-top:22px"><a class="btn btn--ghost btn--sm" href="${esc(j.support_url)}" data-link>💬 پشتیبانی</a></p>` : ''}`;
  }

  async function serviceScreen(el, id, alive) {
    el.innerHTML = skel.detail();
    const { service: s } = await api('service', { params: { id } });
    if (!alive()) return;
    const unlimited = !s.limit_bytes;
    const pct = unlimited ? 0 : clamp(Math.round(s.percent || 0), 0, 100);
    const R = 52, C = 2 * Math.PI * R;
    const expiry = s.expire_ts ? `${dateFa(s.expire_ts)}` : 'نامحدود';
    const online = !s.online_at || s.online_at === 'offline' ? 'آفلاین' : faDigits(s.online_at);
    el.innerHTML = `
      <div class="detail-head"><div><h1 class="h1">${esc(faDigits(s.product_name))}</h1><span class="svc__user" style="text-align:start">${esc(s.username)}</span></div>${statusPill(s)}</div>
      <div class="card">
        <div class="ring-wrap">
          <div class="ring" role="img" aria-label="${unlimited ? 'حجم نامحدود' : `${fa(pct)} درصد مصرف شده`}">
            <svg viewBox="0 0 120 120"><defs><linearGradient id="ringGrad" x1="0" y1="0" x2="1" y2="1"><stop offset="0" stop-color="${pct >= 90 ? '#FF8A80' : '#8AD8FF'}"/><stop offset="1" stop-color="${pct >= 90 ? '#FF453A' : pct >= 70 ? '#FF9F0A' : '#38B6FF'}"/></linearGradient></defs>
              <circle class="track" cx="60" cy="60" r="${R}"/><circle class="prog" cx="60" cy="60" r="${R}" stroke-dasharray="${C}" stroke-dashoffset="${C}"/></svg>
            <div class="ring__center">${unlimited ? '<strong>∞</strong><span>نامحدود</span>' : `<strong>${fa(pct)}٪</strong><span>مصرف شده</span>`}</div>
          </div>
          <dl class="kv">
            <div><dt>باقی‌مانده</dt><dd class="num">${esc(faDigits(s.remaining_fmt))}</dd></div>
            <div><dt>مصرف شده</dt><dd class="num">${esc(faDigits(s.used_fmt))}</dd></div>
            <div><dt>حجم کل</dt><dd class="num">${esc(faDigits(s.limit_fmt))}</dd></div>
          </dl>
        </div>
        <dl class="info-grid">
          <div class="info"><dt>📅 انقضا</dt><dd>${esc(expiry)}${s.expire_ts || s.days_left ? `<br><small>${daysText(s)}</small>` : ''}</dd></div>
          <div class="info"><dt>📡 آخرین اتصال</dt><dd><bdi dir="ltr">${esc(online)}</bdi></dd></div>
        </dl>
      </div>
      <div class="btn-stack">
        <button type="button" class="btn btn--primary btn--lg btn--block" data-act="add"><span>📲 افزودن به اپ با یک لمس</span></button>
        <button type="button" class="btn btn--secondary btn--block" data-act="copy"><span>🔗 کپی لینک ساب</span></button>
      </div>
      <div class="card" style="margin-top:16px">
        <h2 class="card-title">📱 دستگاه‌ها</h2>
        <div class="dev-row"><span>سقف دستگاه</span><b>${s.device_limit ? fa(s.device_limit) : 'نامحدود'}</b></div>
        <div class="dev-row"><span>دستگاه‌های متصل</span><b>${fa(s.connected_devices)}</b></div>
        ${s.device_price > 0 ? `<button type="button" class="btn btn--secondary btn--block" style="margin-top:12px" data-act="device"><span>➕ خرید دستگاه اضافه — ${fa(s.device_price)} تومان</span></button>` : ''}
      </div>
      <div style="margin-top:16px">
        <button type="button" class="btn btn--primary btn--block" data-act="renew" ${s.can_renew ? '' : 'disabled aria-describedby="renew-reason"'}><span>🔄 تمدید سرویس</span></button>
        ${!s.can_renew && s.renew_block_reason ? `<p class="reason" id="renew-reason">ℹ️ ${esc(faDigits(s.renew_block_reason))}</p>` : ''}
      </div>`;
    requestAnimationFrame(() => requestAnimationFrame(() => { const p = $('.prog', el); if (p) p.style.strokeDashoffset = String(C * (1 - pct / 100)); }));

    el.addEventListener('click', async (ev) => {
      const btn = ev.target.closest('[data-act]');
      if (!btn || btn.disabled) return;
      const act = btn.dataset.act;
      if (act === 'add') { if (s.add_url) openLink(s.add_url); else toast('لینک افزودن در دسترس نیست', 'error'); }
      else if (act === 'copy') {
        const ok = await copyText(s.sub_url || '');
        if (ok) { haptic('success'); toast('لینک ساب کپی شد؛ حالا در اپ اضافه‌اش کنید 👌🏻', 'success'); } else fail(new Error('کپی نشد؛ دوباره تلاش کنید'));
      } else if (act === 'renew') go(`/renew/${s.id}`);
      else if (act === 'device') {
        const ok = await confirmBox('📱 خرید دستگاه اضافه', `یک دستگاه به سقف این سرویس اضافه شود؟\n💵 هزینه: ${fa(s.device_price)} تومان`, '✅ خرید');
        if (!ok || !alive()) return;
        setBusy(btn, true);
        try {
          const r = await api('device', { body: { id: s.id } });
          if (handleResult(r) && alive()) render(`/service/${s.id}`, 'none');
        } catch (e) { fail(e); } finally { setBusy(btn, false); }
      }
    });
  }

  function makeStops(min, max, bands) {
    const out = [];
    let v = min;
    while (v <= max) {
      out.push(v);
      const band = bands.find(([limit]) => v < limit) || bands[bands.length - 1];
      const step = band[1];
      v = v < step ? v + 1 : Math.floor(v / step) * step + step;
    }
    if (out[out.length - 1] !== max) out.push(max);
    return out;
  }
  const nearestIdx = (stops, v) => stops.reduce((bi, s, i) => (Math.abs(s - v) < Math.abs(stops[bi] - v) ? i : bi), 0);

  async function plansScreen(el, mode, id, alive) {
    el.innerHTML = skel.plans();
    const j = await api(mode === 'renew' ? 'renew_plans' : 'buy_plans', { params: id ? { id } : {} });
    if (!alive()) return;
    const products = j.products || [];
    const c = Object.assign({ enabled: false, tiers: [] }, j.custom || {});
    const isRenew = mode === 'renew';
    if (!products.length && !c.enabled) {
      el.innerHTML = `<h1 class="h1">${isRenew ? '🔄 تمدید سرویس' : '🛒 خرید سرویس'}</h1><div class="card empty">${WHALE_ART}<h3>🗂️ پلنی در دسترس نیست</h3><p>لطفاً کمی بعد دوباره سر بزنید یا با پشتیبانی در تماس باشید.</p></div>`;
      return;
    }
    const gbStops = makeStops(c.min_gb || 1, c.max_gb || 1000, [[10, 1], [100, 5], [300, 10], [Infinity, 50]]);
    const dayStops = makeStops(c.min_days || 1, c.max_days || 365, [[31, 1], [90, 5], [Infinity, 15]]);
    const sel = { code: products.length ? products[0].code : '__custom', gi: nearestIdx(gbStops, 20), di: nearestIdx(dayStops, 30), price: null, loading: false };
    const volText = (g) => (g ? `${fa(g)} گیگ` : 'حجم نامحدود');
    const dayText = (d) => (d ? `${fa(d)} روز` : 'زمان نامحدود');

    el.innerHTML = `
      <h1 class="h1">${isRenew ? '🔄 تمدید سرویس' : '🛒 خرید سرویس'}</h1>
      <p class="lead">${isRenew ? 'یکی از پلن‌ها را برای تمدید انتخاب کنید ✨' : 'پلن مناسب خود را انتخاب کنید ✨'}</p>
      <div class="plans" role="radiogroup" aria-label="پلن‌ها">
        ${products.map((p) => `<button type="button" class="card plan" role="radio" aria-checked="false" data-code="${esc(p.code)}">
          <span class="plan__radio" aria-hidden="true"></span>
          <span class="plan__body"><span class="plan__name">${esc(faDigits(p.name))}</span><span class="plan__meta">${volText(p.volume_gb)} · ${dayText(p.days)}</span></span>
          <span class="plan__price">${fa(p.price)}<small>تومان</small></span></button>`).join('')}
        ${c.enabled ? `<button type="button" class="card plan" role="radio" aria-checked="false" data-code="__custom">
          <span class="plan__radio" aria-hidden="true"></span>
          <span class="plan__body"><span class="plan__name">🎛️ پلن دلخواه</span><span class="plan__meta">حجم و مدت را خودتان انتخاب کنید</span></span>
          <span class="plan__price" style="font-size:1.4rem;color:var(--accent)" aria-hidden="true">✦</span></button>` : ''}
      </div>
      ${c.enabled ? `<div class="card builder" id="builder" hidden>
        ${['gb', 'days'].map((k) => `<div class="field">
          <div class="field__head"><label for="r-${k}">${k === 'gb' ? '📦 حجم' : '⏳ مدت'}</label><output id="o-${k}" for="r-${k}"></output></div>
          <div class="stepper">
            <button type="button" class="step" data-step="${k}" data-dir="-1" aria-label="${k === 'gb' ? 'کاهش حجم' : 'کاهش مدت'}">−</button>
            <input type="range" id="r-${k}" min="0" max="${(k === 'gb' ? gbStops : dayStops).length - 1}" step="1">
            <button type="button" class="step" data-step="${k}" data-dir="1" aria-label="${k === 'gb' ? 'افزایش حجم' : 'افزایش مدت'}">+</button>
          </div></div>`).join('')}
        <div class="quote" aria-live="polite"><span>💵 قیمت پلن دلخواه</span><strong id="q-price">—</strong></div>
        ${c.tiers && c.tiers.length ? `<div class="tiers"><h3>📉 هرچه بیشتر، ارزان‌تر</h3><table><tbody>
          ${c.tiers.map((t, i) => `<tr data-tier="${i}"><td>${fa(t.min)} تا ${fa(t.max)} گیگ</td><td>${fa(t.unit)} تومان / گیگ</td></tr>`).join('')}
          </tbody></table>${c.day_price ? `<p class="hint">🗓️ هزینه هر روز: ${fa(c.day_price)} تومان</p>` : ''}</div>` : ''}
      </div>` : ''}
      <div class="confirm-bar"><button type="button" class="btn btn--primary btn--lg btn--block" id="submit"><span class="label">${isRenew ? '✅ تمدید' : '✅ خرید'}</span><span class="price"></span></button></div>`;
    document.body.classList.add('has-bar');
    const submit = $('#submit', el);
    document.body.append(submit.parentElement); // fixed bar must escape the transformed .screen
    const builder = $('#builder', el);
    let quoteTimer = 0, quoteSeq = 0;

    function paint() {
      el.querySelectorAll('.plan').forEach((b) => b.setAttribute('aria-checked', String(b.dataset.code === sel.code)));
      const custom = sel.code === '__custom';
      if (builder) builder.hidden = !custom;
      let price = null;
      if (custom) {
        const g = gbStops[sel.gi], d = dayStops[sel.di];
        [['gb', g, gbStops, sel.gi, `${fa(g)} گیگ`], ['days', d, dayStops, sel.di, `${fa(d)} روز`]].forEach(([k, , stops, idx, label]) => {
          const r = $(`#r-${k}`, el);
          r.value = idx; r.style.setProperty('--p', `${(idx / Math.max(1, stops.length - 1)) * 100}%`);
          r.setAttribute('aria-valuetext', label);
          $(`#o-${k}`, el).textContent = label;
          el.querySelector(`[data-step="${k}"][data-dir="-1"]`).disabled = idx <= 0;
          el.querySelector(`[data-step="${k}"][data-dir="1"]`).disabled = idx >= stops.length - 1;
        });
        el.querySelectorAll('[data-tier]').forEach((tr) => { const t = c.tiers[+tr.dataset.tier]; tr.classList.toggle('is-on', g >= t.min && g <= t.max); });
        const q = $('#q-price', el);
        q.parentElement.classList.toggle('is-loading', sel.loading);
        q.textContent = sel.loading ? 'در حال محاسبه…' : sel.price != null ? `${fa(sel.price)} تومان` : '—';
        price = sel.loading ? null : sel.price;
      } else {
        const p = products.find((x) => x.code === sel.code);
        price = p ? p.price : null;
      }
      $('.price', submit).textContent = price != null ? `${fa(price)} تومان` : '';
      submit.disabled = price == null;
    }

    function requestQuote() {
      sel.loading = true; paint();
      clearTimeout(quoteTimer);
      quoteTimer = setTimeout(async () => {
        const my = ++quoteSeq;
        try {
          const q = await api('quote', { params: Object.assign({ gb: gbStops[sel.gi], days: dayStops[sel.di] }, isRenew && id ? { id } : {}) });
          if (my !== quoteSeq || !alive()) return;
          sel.price = Number(q.price);
        } catch (e) { if (my === quoteSeq) { sel.price = null; fail(e); } }
        if (my === quoteSeq && alive()) { sel.loading = false; paint(); }
      }, 300);
    }

    el.addEventListener('click', async (ev) => {
      const plan = ev.target.closest('.plan');
      if (plan) {
        if (sel.code === plan.dataset.code) return;
        sel.code = plan.dataset.code; haptic('select'); paint();
        if (sel.code === '__custom' && sel.price == null) requestQuote();
        return;
      }
      const step = ev.target.closest('[data-step]');
      if (step && !step.disabled) {
        const k = step.dataset.step === 'gb' ? 'gi' : 'di';
        const stops = k === 'gi' ? gbStops : dayStops;
        sel[k] = clamp(sel[k] + Number(step.dataset.dir), 0, stops.length - 1);
        requestQuote();
        return;
      }
    });
    submit.addEventListener('click', async () => {
      if (!submit.disabled) {
        const custom = sel.code === '__custom';
        const p = products.find((x) => x.code === sel.code);
        const what = custom ? `پلن دلخواه 📦 ${fa(gbStops[sel.gi])} گیگ / ${fa(dayStops[sel.di])} روز` : `«${faDigits(p.name)}»`;
        const priceNow = custom ? sel.price : p.price;
        const ok = await confirmBox(isRenew ? '🔄 تأیید تمدید' : '🛒 تأیید خرید', `${isRenew ? 'تمدید سرویس با' : 'خرید'} ${what} به مبلغ ${fa(priceNow)} تومان؟ 💵`, isRenew ? '✅ تمدید' : '✅ خرید');
        if (!ok || !alive()) return;
        const body = custom ? { gb: gbStops[sel.gi], days: dayStops[sel.di] } : { code: sel.code };
        if (isRenew) body.id = id;
        setBusy(submit, true);
        try {
          const r = await api(isRenew ? 'renew' : 'buy', { body });
          if (handleResult(r) && alive()) {
            if (isRenew) back();
            else if (r.service_id) { stack.length = 0; stack.push('/'); location.hash = `#/service/${r.service_id}`; }
            else go('/');
          }
        } catch (e) { fail(e); } finally { setBusy(submit, false); }
      }
    });
    el.addEventListener('input', (ev) => {
      const r = ev.target.closest('input[type="range"]');
      if (!r) return;
      const k = r.id === 'r-gb' ? 'gi' : 'di';
      if (sel[k] !== +r.value) { sel[k] = +r.value; haptic('select'); requestQuote(); }
    });
    paint();
    if (sel.code === '__custom') requestQuote();
  }

  async function walletScreen(el, _, alive) {
    el.innerHTML = skel.plans();
    const j = state.me || await api('me');
    if (!alive()) return;
    state.me = j;
    const presets = [50000, 100000, 200000, 500000];
    let amount = 0;
    el.innerHTML = `
      <h1 class="h1">👛 کیف پول</h1>
      <div class="card wallet" style="margin-top:10px">
        <span class="wallet__label">💰 موجودی فعلی</span>
        <div class="wallet__amount" style="margin-bottom:0"><strong>${fa(j.user && j.user.balance)}</strong><span>تومان</span></div>
      </div>
      <div class="section-head"><h2>💳 افزایش موجودی</h2></div>
      <div class="card">
        <div class="chips" role="radiogroup" aria-label="مبالغ پیشنهادی">
          ${presets.map((p) => `<button type="button" class="chip" role="radio" aria-checked="false" data-amount="${p}">${fa(p)}</button>`).join('')}
        </div>
        <div class="input-wrap">
          <label for="amount">✏️ مبلغ دلخواه</label>
          <input class="input" id="amount" inputmode="numeric" autocomplete="off" enterkeyhint="done" placeholder="مثلاً ۷۵٬۰۰۰">
          <span class="input-unit">تومان</span>
        </div>
        <p class="reason">🤖 پس از تأیید، لینک پرداخت در گفت‌وگوی ربات برایتان ارسال می‌شود.</p>
      </div>
      <div class="confirm-bar"><button type="button" class="btn btn--primary btn--lg btn--block" id="pay" disabled><span class="label">💳 پرداخت</span><span class="price"></span></button></div>`;
    document.body.classList.add('has-bar');
    const input = $('#amount', el), pay = $('#pay', el);
    document.body.append(pay.parentElement);
    const paint = () => {
      el.querySelectorAll('.chip').forEach((ch) => ch.setAttribute('aria-checked', String(+ch.dataset.amount === amount)));
      $('.price', pay).textContent = amount ? `${fa(amount)} تومان` : '';
      pay.disabled = amount <= 0;
    };
    el.addEventListener('click', async (ev) => {
      const chip = ev.target.closest('.chip');
      if (chip) { amount = +chip.dataset.amount; input.value = fa(amount); haptic('select'); paint(); return; }
    });
    pay.addEventListener('click', async () => {
      if (amount > 0) {
        setBusy(pay, true);
        try { handleResult(await api('topup', { body: { amount } })); } catch (e) { fail(e); } finally { setBusy(pay, false); paint(); }
      }
    });
    input.addEventListener('input', () => {
      amount = parseNum(input.value);
      const formatted = amount ? fa(amount) : '';
      if (input.value !== formatted) input.value = formatted;
      paint();
    });
    input.addEventListener('keydown', (e) => { if (e.key === 'Enter') { input.blur(); if (!pay.disabled) pay.click(); } });
  }

  /* ---------------- global wiring ---------------- */
  function applyTheme() {
    let scheme = IN_TG && tg.colorScheme ? tg.colorScheme : (window.matchMedia && matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
    if (qs.get('theme') === 'dark' || qs.get('theme') === 'light') scheme = qs.get('theme');
    document.documentElement.dataset.theme = scheme;
    const bar = scheme === 'dark' ? '#0A1A33' : '#EAF4FF';
    const meta = document.querySelector('meta[name="theme-color"]');
    if (meta) meta.setAttribute('content', bar);
    if (tgv('6.1')) {
      try { tg.setHeaderColor(bar); tg.setBackgroundColor(scheme === 'dark' ? '#0F2547' : '#F7FBFF'); } catch (e) { /* noop */ }
      try { if (tgv('7.10')) tg.setBottomBarColor(scheme === 'dark' ? '#0A1A33' : '#F7FBFF'); } catch (e) { /* noop */ }
    }
  }

  document.addEventListener('click', (ev) => {
    const t = ev.target.closest('button, a, [data-go]');
    if (!t || t.disabled) return;
    if (!t.closest('.plan, .chip, [data-step]')) haptic('light');
    const goEl = ev.target.closest('[data-go]');
    if (goEl) { ev.preventDefault(); go(goEl.dataset.go); return; }
    const link = ev.target.closest('a[data-link]');
    if (link) { ev.preventDefault(); openLink(link.href); }
  });
  document.addEventListener('touchstart', () => {}, { passive: true }); // enables :active on iOS
  $('#back').addEventListener('click', back);
  const topbar = $('#topbar');
  window.addEventListener('scroll', () => topbar.classList.toggle('is-scrolled', window.scrollY > 4), { passive: true });

  async function boot() {
    if (tg) { try { tg.ready(); tg.expand(); } catch (e) { /* noop */ } }
    applyTheme();
    if (IN_TG) {
      try { tg.onEvent('themeChanged', applyTheme); } catch (e) { /* noop */ }
      try { if (tgv('6.1')) tg.BackButton.onClick(back); } catch (e) { /* noop */ }
      try { if (tgv('7.7')) tg.disableVerticalSwipes(); } catch (e) { /* noop */ }
    } else if (window.matchMedia) {
      const mq = matchMedia('(prefers-color-scheme: dark)');
      mq.addEventListener ? mq.addEventListener('change', applyTheme) : mq.addListener(applyTheme);
    }
    if (DEMO) $('#demo-badge').hidden = false;
    if (!DEMO && !token) {
      try { await verify(); } catch (e) {
        errorState((view.replaceChildren(document.createElement('section')), view.firstChild), new Error('🤖 دوباره از ربات باز کنید'), () => location.reload());
        return;
      }
    }
    window.addEventListener('hashchange', onRoute);
    onRoute();
  }
  boot();
})();
