<?php
declare(strict_types=1);

namespace KasTip\Web;

use KasTip\App;
use KasTip\Auth\Admin;
use KasTip\Auth\Session;

/**
 * GET /dashboard
 *
 * Logged-in user's home — sent/received tips, settings.
 *
 *   no session     → 302 to /api/auth/x/start?from=/dashboard
 *   no address     → 302 to /onboard/address
 *   else           → render shell, JS hydrates from /api/users/me + /api/tips/*
 */
final class Dashboard
{
    public static function render(): void
    {
        $session = Session::current();
        if ($session === null) {
            header('Location: /api/auth/x/start?from=' . urlencode('/dashboard'), true, 302);
            exit;
        }

        $stmt = App::db()->prepare("
            SELECT x_username, x_display_name, x_avatar_url, kaspa_address
            FROM users WHERE id = :id
        ");
        $stmt->execute(['id' => $session['user_id']]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            Session::destroy($session['session_token']);
            header('Location: /', true, 302);
            exit;
        }
        if ($user['kaspa_address'] === '') {
            header('Location: /onboard/address', true, 302);
            exit;
        }

        $isAdmin = Admin::isAdmin((int) $session['user_id']);
        self::renderHtml($user, $isAdmin);
    }

    private static function renderHtml(array $user, bool $isAdmin = false): void
    {
        $username    = htmlspecialchars($user['x_username'], ENT_QUOTES, 'UTF-8');
        $displayName = htmlspecialchars($user['x_display_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $avatar      = htmlspecialchars($user['x_avatar_url'] ?? '', ENT_QUOTES, 'UTF-8');
        $address     = htmlspecialchars($user['kaspa_address'], ENT_QUOTES, 'UTF-8');
        $addressTrunc = substr($user['kaspa_address'], 0, 14) . '…' . substr($user['kaspa_address'], -8);

        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KasTip — Dashboard</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{height:100%}
  body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:#0d0f1a;color:#e8eaed;min-height:100vh;
  }
  a{color:inherit;text-decoration:none}

  /* ─── header ─────────────────────────────────── */
  header{
    display:flex;align-items:center;justify-content:space-between;
    padding:1rem 1.5rem;border-bottom:1px solid #1f2335;
    position:sticky;top:0;background:rgba(13,15,26,.85);backdrop-filter:blur(8px);z-index:10;
  }
  .logo{
    font-size:1.4rem;font-weight:800;letter-spacing:-0.03em;
    background:linear-gradient(135deg,#49e9c9 0%,#2bb89c 100%);
    -webkit-background-clip:text;background-clip:text;color:transparent;
  }
  .user-chip{display:flex;align-items:center;gap:.5rem;font-size:.9rem}
  .user-chip img{width:32px;height:32px;border-radius:50%}
  .user-chip .handle{color:#a8b1c2}
  .logout-btn{
    background:transparent;border:1px solid #2a2f44;color:#a8b1c2;
    padding:.4rem .85rem;border-radius:6px;font-size:.85rem;cursor:pointer;
    margin-left:.75rem;
  }
  .logout-btn:hover{border-color:#49e9c9;color:#49e9c9}

  /* ─── layout ─────────────────────────────────── */
  main{max-width:880px;margin:0 auto;padding:2rem 1.5rem}

  .totals{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
    gap:1rem;margin-bottom:2rem;
  }
  .stat{
    background:#161927;border:1px solid #1f2335;border-radius:12px;padding:1.25rem;
  }
  .stat .lbl{font-size:.78rem;color:#5a6378;text-transform:uppercase;letter-spacing:.05em}
  .stat .val{font-size:1.6rem;font-weight:600;margin-top:.4rem}
  .stat .sub{font-size:.8rem;color:#a8b1c2;margin-top:.2rem}

  .tabs{
    display:flex;gap:.25rem;border-bottom:1px solid #1f2335;margin-bottom:1.5rem;
    overflow-x:auto;
  }
  .tab{
    padding:.75rem 1.25rem;background:none;border:none;color:#a8b1c2;
    font-size:.95rem;cursor:pointer;border-bottom:2px solid transparent;
    transition:color .15s,border-color .15s;white-space:nowrap;
  }
  .tab:hover{color:#e8eaed}
  .tab.active{color:#49e9c9;border-bottom-color:#49e9c9}

  .pane{display:none}
  .pane.active{display:block}

  /* ─── tip list ───────────────────────────────── */
  .tip-list{display:flex;flex-direction:column;gap:.5rem}
  .tip-row{
    display:grid;grid-template-columns:auto auto 1fr auto;gap:.75rem;align-items:center;
    background:#161927;border:1px solid #1f2335;border-radius:10px;padding:.7rem 1rem;
  }
  .tip-row .avatar{
    width:36px;height:36px;border-radius:50%;
    background:#0d0f1a;border:1px solid #2a2f44;
    display:flex;align-items:center;justify-content:center;
    color:#5a6378;font-size:.78rem;
  }
  .tip-row .avatar img{width:100%;height:100%;border-radius:50%}
  .tip-row .who{font-weight:500}
  .tip-row .when{font-size:.78rem;color:#5a6378}
  .tip-row .amt{font-weight:600;color:#49e9c9}
  .tip-row .status{font-size:.72rem;padding:.15rem .55rem;border-radius:99px;display:inline-block}
  .status-pending{background:rgba(245,158,11,.15);color:#fcd34d}
  .status-broadcast{background:rgba(245,158,11,.15);color:#fcd34d}
  .status-confirmed{background:rgba(73,233,201,.12);color:#49e9c9}
  .status-failed{background:rgba(239,68,68,.15);color:#fca5a5}
  .status-unclaimed{background:rgba(168,113,234,.15);color:#c4b5fd}
  .tx-link{color:#5a6378;font-size:.78rem}
  .tx-link:hover{color:#49e9c9}

  .empty{
    text-align:center;padding:3rem 1rem;color:#5a6378;font-size:.95rem;
    background:#161927;border:1px dashed #2a2f44;border-radius:12px;
  }

  /* ─── settings ───────────────────────────────── */
  .settings-card{
    background:#161927;border:1px solid #1f2335;border-radius:12px;padding:1.5rem;
    max-width:480px;
  }
  .settings-card h3{margin-bottom:1rem;font-size:1.05rem}
  label{display:block;font-size:.82rem;color:#a8b1c2;margin-bottom:.4rem;margin-top:1rem}
  label:first-of-type{margin-top:0}
  input[type=text]{
    width:100%;padding:.7rem .85rem;font-family:ui-monospace,SF Mono,Monaco,monospace;
    font-size:.85rem;background:#0d0f1a;border:1px solid #2a2f44;border-radius:6px;
    color:#e8eaed;outline:none;
  }
  input[type=text]:focus{border-color:#49e9c9}
  .toggle{display:flex;align-items:center;justify-content:space-between;padding:.85rem 0;border-top:1px solid #1f2335;margin-top:1rem}
  .toggle .descr{font-size:.85rem;color:#a8b1c2;max-width:280px;line-height:1.4}
  .switch{position:relative;width:44px;height:24px;background:#2a2f44;border-radius:99px;cursor:pointer;transition:background .2s}
  .switch.on{background:#49e9c9}
  .switch::after{content:"";position:absolute;top:2px;left:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:left .2s}
  .switch.on::after{left:22px}
  .save-btn{
    margin-top:1.25rem;width:100%;padding:.8rem;
    background:linear-gradient(135deg,#49e9c9 0%,#2bb89c 100%);
    border:none;border-radius:6px;color:#0d0f1a;font-weight:600;cursor:pointer;
  }
  .save-btn:hover{transform:translateY(-1px)}
  .save-btn:disabled{opacity:.5;cursor:not-allowed;transform:none}
  .toast{
    position:fixed;bottom:2rem;left:50%;transform:translateX(-50%);
    padding:.7rem 1.2rem;background:#161927;border:1px solid #49e9c9;color:#49e9c9;
    border-radius:8px;font-size:.9rem;display:none;z-index:100;
  }
  .toast.show{display:block}
  .toast.error{border-color:#ef4444;color:#fca5a5}

  /* ─── load more ─────────────────────────────── */
  .load-more{
    margin:1rem auto 0;padding:.65rem 1.25rem;
    background:transparent;border:1px solid #2a2f44;color:#a8b1c2;
    border-radius:6px;cursor:pointer;display:block;
  }
  .load-more:hover{border-color:#49e9c9;color:#49e9c9}
  .load-more:disabled{opacity:.5;cursor:not-allowed}

  /* ─── admin: welcomes tab ──────────────────────── */
  .admin-filters{display:flex;gap:.4rem;flex-wrap:wrap;margin-bottom:1rem}
  .admin-filter{
    padding:.4rem .8rem;background:#161927;border:1px solid #1f2335;
    border-radius:99px;color:#a8b1c2;font-size:.82rem;cursor:pointer;
    transition:border-color .15s,color .15s;
  }
  .admin-filter:hover{border-color:#49e9c9;color:#e8eaed}
  .admin-filter.active{background:rgba(73,233,201,.1);border-color:#49e9c9;color:#49e9c9}
  .admin-filter .cnt{
    margin-left:.4rem;font-weight:600;font-size:.78rem;
    color:#5a6378;
  }
  .admin-filter.active .cnt{color:#49e9c9}

  .admin-table-wrap{background:#161927;border:1px solid #1f2335;border-radius:12px;overflow:hidden}
  .admin-row{
    display:grid;grid-template-columns:auto 1fr auto auto auto;gap:1rem;align-items:center;
    padding:.7rem 1rem;border-bottom:1px solid #1f2335;
  }
  .admin-row:last-child{border-bottom:none}
  .admin-row .avatar{
    width:36px;height:36px;border-radius:50%;background:#0d0f1a;border:1px solid #2a2f44;
  }
  .admin-row .avatar img{width:100%;height:100%;border-radius:50%}
  .admin-row .who{display:flex;flex-direction:column;min-width:0}
  .admin-row .who strong{font-size:.92rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .admin-row .who .meta{font-size:.78rem;color:#5a6378}
  .admin-badge{
    font-size:.7rem;padding:.18rem .55rem;border-radius:99px;white-space:nowrap;
  }
  .b-needs{background:rgba(245,158,11,.15);color:#fcd34d}
  .b-tipped{background:rgba(73,233,201,.12);color:#49e9c9}
  .b-skipped{background:rgba(120,120,140,.15);color:#a8b1c2}
  .b-no_setup{background:rgba(168,113,234,.15);color:#c4b5fd}
  .b-active{background:rgba(73,233,201,.08);color:#49e9c9;border:1px solid rgba(73,233,201,.3)}

  .admin-actions{display:flex;gap:.4rem;align-items:center}
  .admin-btn{
    padding:.4rem .75rem;background:transparent;border:1px solid #2a2f44;
    color:#a8b1c2;font-size:.78rem;border-radius:6px;cursor:pointer;
    transition:border-color .15s,color .15s;
  }
  .admin-btn:hover{border-color:#49e9c9;color:#49e9c9}
  .admin-btn.danger:hover{border-color:#ef4444;color:#fca5a5}

  .admin-empty{padding:2rem 1rem;text-align:center;color:#5a6378;font-size:.9rem}
  .admin-loading{padding:1.5rem;text-align:center;color:#5a6378}

  @media (max-width:600px){
    .admin-row{grid-template-columns:auto 1fr;gap:.5rem .75rem}
    .admin-row .badge-col{grid-column:2;grid-row:2}
    .admin-row .activity-col{grid-column:2;grid-row:3;font-size:.78rem;color:#5a6378}
    .admin-row .admin-actions{grid-column:1/-1;grid-row:4;margin-top:.4rem}
  }
</style>
</head>
<body>

<header>
  <a href="/" class="logo">KasTip</a>
  <div class="user-chip">
    <?php if ($avatar !== ''): ?><img src="<?= $avatar ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
    <span><?= $displayName !== '' ? $displayName : '@' . $username ?></span>
    <span class="handle">@<?= $username ?></span>
    <button class="logout-btn" id="logout-btn">Sign out</button>
  </div>
</header>

<main>
  <div class="totals">
    <div class="stat">
      <div class="lbl">Received</div>
      <div class="val" id="stat-received">— KAS</div>
      <div class="sub" id="stat-received-count">0 tips</div>
    </div>
    <div class="stat">
      <div class="lbl">Sent</div>
      <div class="val" id="stat-sent">— KAS</div>
      <div class="sub" id="stat-sent-count">0 tips</div>
    </div>
    <div class="stat">
      <div class="lbl">Your address</div>
      <div class="val" style="font-family:ui-monospace,monospace;font-size:.8rem;word-break:break-all"><?= $addressTrunc ?></div>
      <div class="sub"><a href="#" id="copy-addr" style="color:#49e9c9">Copy full</a></div>
    </div>
  </div>

  <div class="tabs">
    <button class="tab active" data-pane="received">Received</button>
    <button class="tab" data-pane="sent">Sent</button>
    <button class="tab" data-pane="settings">Settings</button>
    <?php if ($isAdmin): ?><button class="tab" data-pane="admin">Welcomes</button><?php endif; ?>
  </div>

  <div class="pane active" id="pane-received">
    <div id="received-list" class="tip-list"></div>
    <button class="load-more" id="received-more" style="display:none">Load more</button>
  </div>

  <div class="pane" id="pane-sent">
    <div id="sent-list" class="tip-list"></div>
    <button class="load-more" id="sent-more" style="display:none">Load more</button>
  </div>

  <?php if ($isAdmin): ?>
  <div class="pane" id="pane-admin">
    <div class="admin-filters" id="admin-filters">
      <button class="admin-filter active" data-filter="needs">Needs welcome <span class="cnt" id="cnt-needs">–</span></button>
      <button class="admin-filter" data-filter="tipped">Tipped <span class="cnt" id="cnt-tipped">–</span></button>
      <button class="admin-filter" data-filter="skipped">Skipped <span class="cnt" id="cnt-skipped">–</span></button>
      <button class="admin-filter" data-filter="no_setup">No setup <span class="cnt" id="cnt-no_setup">–</span></button>
      <button class="admin-filter" data-filter="all">All <span class="cnt" id="cnt-all">–</span></button>
    </div>
    <div class="admin-table-wrap" id="admin-table">
      <div class="admin-loading">Loading…</div>
    </div>
  </div>
  <?php endif; ?>

  <div class="pane" id="pane-settings">
    <div class="settings-card">
      <h3>Account settings</h3>
      <form id="settings-form">
        <label for="kaspa-address">Kaspa receiving address</label>
        <input type="text" id="kaspa-address" name="kaspa_address" value="<?= $address ?>" autocomplete="off" autocapitalize="none" autocorrect="off" spellcheck="false">

        <div class="toggle">
          <div class="descr">
            <strong style="color:#e8eaed">Auto-reply pre-fill</strong><br>
            Pre-fill a thank-you reply on X after each tip you send (you still click Post manually).
          </div>
          <div class="switch on" id="auto-reply-toggle" data-on="true"></div>
        </div>

        <button type="submit" class="save-btn" id="save-btn">Save changes</button>
      </form>
    </div>
  </div>
</main>

<div class="toast" id="toast"></div>

<script>
const FULL_ADDRESS = <?= json_encode($user['kaspa_address']) ?>;

// ─── helpers ─────────────────────────────────────
const $ = sel => document.querySelector(sel);
const $$ = sel => Array.from(document.querySelectorAll(sel));

function formatKas(n){
  return Number(n).toLocaleString(undefined, {minimumFractionDigits:0, maximumFractionDigits:8}) + ' KAS';
}
function formatRel(iso){
  const d = new Date(iso.replace(' ','T') + 'Z');
  const sec = Math.floor((Date.now() - d.getTime()) / 1000);
  if (sec < 60) return sec + 's ago';
  if (sec < 3600) return Math.floor(sec/60) + 'm ago';
  if (sec < 86400) return Math.floor(sec/3600) + 'h ago';
  if (sec < 30*86400) return Math.floor(sec/86400) + 'd ago';
  return d.toISOString().slice(0,10);
}
function showToast(msg, isErr){
  const t = $('#toast');
  t.textContent = msg;
  t.classList.toggle('error', !!isErr);
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3000);
}
async function api(method, path, body){
  const opts = {method, credentials:'same-origin', headers:{'Content-Type':'application/json'}};
  if (body !== undefined) opts.body = JSON.stringify(body);
  const r = await fetch(path, opts);
  const data = await r.json().catch(() => ({}));
  if (!r.ok) throw Object.assign(new Error(data.message || 'request failed'), {status:r.status, data});
  return data;
}

// ─── tabs ────────────────────────────────────────
let adminLoaded = false;
$$('.tab').forEach(btn => {
  btn.addEventListener('click', () => {
    $$('.tab').forEach(b => b.classList.toggle('active', b === btn));
    const target = btn.dataset.pane;
    $$('.pane').forEach(p => p.classList.toggle('active', p.id === 'pane-' + target));
    if (target === 'admin' && !adminLoaded) {
      adminLoaded = true;
      loadAdminWelcomes('needs');
    }
  });
});

// ─── totals (from /api/users/me) ────────────────
async function loadMe(){
  const me = await api('GET', '/api/users/me');
  $('#stat-received').textContent = formatKas(me.total_received_kas);
  $('#stat-received-count').textContent = me.tip_count_received + (me.tip_count_received === 1 ? ' tip' : ' tips');
  $('#stat-sent').textContent = formatKas(me.total_sent_kas);
  $('#stat-sent-count').textContent = me.tip_count_sent + (me.tip_count_sent === 1 ? ' tip' : ' tips');
  // toggle
  const tog = $('#auto-reply-toggle');
  tog.classList.toggle('on', !!me.auto_reply_enabled);
  tog.dataset.on = String(!!me.auto_reply_enabled);
}

// ─── tip lists ──────────────────────────────────
function tipRowHtml(tip, kind){
  const otherHandle = kind === 'sent' ? tip.receiver_x_username : (tip.sender_x_username || '?');
  const otherAvatar = kind === 'sent' ? tip.receiver_x_avatar_url : tip.sender_x_avatar_url;
  const arrow = kind === 'sent' ? '→' : '←';
  const txLink = tip.txid
    ? `<a class="tx-link" href="https://kaspa.stream/transactions/${tip.txid}" target="_blank" rel="noopener">tx ↗</a>`
    : '';
  const msg = tip.message ? `<div style="font-size:.78rem;color:#a8b1c2;margin-top:.15rem">"${escapeHtml(tip.message)}"</div>` : '';
  const avatarBlock = otherAvatar
    ? `<div class="avatar"><img src="${escapeHtml(otherAvatar)}" alt="" referrerpolicy="no-referrer"></div>`
    : `<div class="avatar">@</div>`;
  return `
    <div class="tip-row">
      ${avatarBlock}
      <div>
        <div class="who">${arrow} @${escapeHtml(otherHandle)}</div>
        <div class="when">${formatRel(tip.initiated_at)}</div>
        ${msg}
      </div>
      <div></div>
      <div style="text-align:right">
        <div class="amt">${formatKas(tip.amount_kas)}</div>
        <div style="margin-top:.2rem">
          <span class="status status-${tip.status}">${tip.status}</span>
          ${txLink}
        </div>
      </div>
    </div>`;
}
function escapeHtml(s){ return String(s).replace(/[<>&"']/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'})[c]); }

const listState = {sent:{cursor:null, done:false}, received:{cursor:null, done:false}};

async function loadList(kind){
  const state = listState[kind];
  if (state.done) return;
  const params = new URLSearchParams({limit: 20});
  if (state.cursor) params.set('before', state.cursor);
  const data = await api('GET', `/api/tips/${kind}?${params}`);
  const list = $(`#${kind}-list`);
  if (data.items.length === 0 && !state.cursor) {
    list.innerHTML = `<div class="empty">No ${kind} tips yet.</div>`;
    state.done = true;
    return;
  }
  data.items.forEach(t => list.insertAdjacentHTML('beforeend', tipRowHtml(t, kind)));
  if (data.next_before) {
    state.cursor = data.next_before;
    $(`#${kind}-more`).style.display = 'block';
  } else {
    state.done = true;
    $(`#${kind}-more`).style.display = 'none';
  }
}
$('#received-more').addEventListener('click', () => loadList('received'));
$('#sent-more').addEventListener('click', () => loadList('sent'));

// ─── settings ──────────────────────────────────
$('#auto-reply-toggle').addEventListener('click', e => {
  const tog = e.currentTarget;
  const on = tog.dataset.on !== 'true';
  tog.classList.toggle('on', on);
  tog.dataset.on = String(on);
});
$('#settings-form').addEventListener('submit', async e => {
  e.preventDefault();
  const btn = $('#save-btn');
  btn.disabled = true;
  btn.textContent = 'Saving…';
  try {
    const newAddr = $('#kaspa-address').value.trim();
    const tog = $('#auto-reply-toggle').dataset.on === 'true';
    const body = {auto_reply_enabled: tog};
    if (newAddr !== FULL_ADDRESS) body.kaspa_address = newAddr;
    await api('PUT', '/api/users/me/settings', body);
    showToast('Saved.');
    if (newAddr !== FULL_ADDRESS) {
      // Reload page to refresh address banner
      setTimeout(() => location.reload(), 800);
    }
  } catch (err) {
    showToast(err.message || 'Save failed.', true);
  } finally {
    btn.disabled = false;
    btn.textContent = 'Save changes';
  }
});

// ─── copy address ─────────────────────────────
$('#copy-addr').addEventListener('click', e => {
  e.preventDefault();
  navigator.clipboard.writeText(FULL_ADDRESS).then(() => showToast('Address copied.'));
});

// ─── logout ────────────────────────────────────
$('#logout-btn').addEventListener('click', async () => {
  try { await api('POST', '/api/auth/logout'); } catch(_){}
  location.href = '/';
});

// ─── admin: welcomes ───────────────────────────
async function loadAdminWelcomes(filter){
  const wrap = document.getElementById('admin-table');
  if (!wrap) return;
  wrap.innerHTML = '<div class="admin-loading">Loading…</div>';
  // Update active filter button
  document.querySelectorAll('.admin-filter').forEach(b =>
    b.classList.toggle('active', b.dataset.filter === filter)
  );
  try {
    const data = await api('GET', '/api/admin/welcomes?filter=' + encodeURIComponent(filter));
    // Update counts
    ['needs','tipped','skipped','no_setup','all'].forEach(k => {
      const el = document.getElementById('cnt-' + k);
      if (el) el.textContent = data.totals[k] ?? 0;
    });
    if (!data.users.length) {
      wrap.innerHTML = '<div class="admin-empty">No users in this filter.</div>';
      return;
    }
    wrap.innerHTML = data.users.map(adminRowHtml).join('');
    wrap.querySelectorAll('[data-skip-id]').forEach(btn => {
      btn.addEventListener('click', () => toggleSkip(parseInt(btn.dataset.skipId, 10), btn.dataset.action, filter));
    });
  } catch (e) {
    wrap.innerHTML = '<div class="admin-empty">Load failed: ' + escapeHtml(e.message) + '</div>';
  }
}

function adminRowHtml(u){
  const display = u.x_display_name || ('@' + u.x_username);
  const avatar = u.x_avatar_url
    ? `<img src="${escapeHtml(u.x_avatar_url)}" alt="" referrerpolicy="no-referrer">`
    : '<div style="display:flex;align-items:center;justify-content:center;height:100%;color:#5a6378">@</div>';
  const badge = badgeFor(u);
  const activitySend = u.tips_sent_count > 0
    ? `<span class="admin-badge b-active">⚡ ${u.tips_sent_count} sent</span>`
    : '';
  const welcomeInfo = u.welcomed_by_you
    ? `<span class="admin-badge b-tipped" title="${u.welcome_kas} KAS in ${u.welcome_count} tip(s)">✓ ${u.welcome_kas} KAS</span>`
    : '';
  const xProfile = `<a class="admin-btn" href="https://x.com/${encodeURIComponent(u.x_username)}" target="_blank" rel="noopener">X ↗</a>`;
  const skipBtn = (u.category === 'needs')
    ? `<button class="admin-btn danger" data-skip-id="${u.id}" data-action="skipped">Skip</button>`
    : (u.welcome_status === 'skipped')
      ? `<button class="admin-btn" data-skip-id="${u.id}" data-action="pending">Un-skip</button>`
      : '';
  return `
    <div class="admin-row">
      <div class="avatar">${avatar}</div>
      <div class="who">
        <strong>${escapeHtml(display)}</strong>
        <span class="meta">@${escapeHtml(u.x_username)} · ${formatRel(u.created_at)}</span>
      </div>
      <div class="badge-col">${badge}</div>
      <div class="activity-col" style="display:flex;gap:.35rem">${welcomeInfo}${activitySend}</div>
      <div class="admin-actions">${xProfile}${skipBtn}</div>
    </div>
  `;
}

function badgeFor(u){
  const label = {needs:'Needs welcome', tipped:'Tipped', skipped:'Skipped', no_setup:'No setup'}[u.category] || u.category;
  return `<span class="admin-badge b-${u.category}">${label}</span>`;
}

async function toggleSkip(id, status, currentFilter){
  try {
    await api('POST', '/api/admin/users/' + id + '/welcome-status', {status});
    showToast(status === 'skipped' ? 'Marked as skipped.' : 'Restored to pending.');
    loadAdminWelcomes(currentFilter);
  } catch (e) {
    showToast('Update failed: ' + e.message, true);
  }
}

// Filter pill click handlers
document.querySelectorAll('.admin-filter').forEach(btn => {
  btn.addEventListener('click', () => loadAdminWelcomes(btn.dataset.filter));
});

// ─── boot ──────────────────────────────────────
loadMe().catch(err => showToast('Failed to load profile: ' + err.message, true));
loadList('received').catch(err => showToast('Failed to load received: ' + err.message, true));
loadList('sent').catch(err => showToast('Failed to load sent: ' + err.message, true));
</script>
</body>
</html>
        <?php
        exit;
    }
}
