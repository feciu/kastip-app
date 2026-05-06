'use strict';

// ============================================
// KasTip — content script (production)
// Injected into x.com / twitter.com pages
// ============================================
//
// Responsibilities:
//   1. Detect @handles on the page (MutationObserver — X is SPA)
//   2. Inject "💰 KasTip" button next to each handle
//   3. On click → modal with amount input
//   4. Resolve handle via /api/users/lookup (registered or invitation flow)
//   5. Send TX via Kasware (single-output sendKaspa); fallback to QR
//   6. Confirm via /api/tips/confirm
//   7. Pre-fill auto-reply on the original tweet (if enabled)

const MIN_TIP_KAS = 0.5;
const SOMPI_PER_KAS = 100_000_000;

const SELECTORS = {
  userName: '[data-testid="User-Name"]',
  ownProfile: '[data-testid="AppTabBar_Profile_Link"]',
  replyTextarea: '[data-testid="tweetTextarea_0"]',
};

let injectionCount = 0;
let lookupCache = new Map();           // handle → {data, fetched_at}
const LOOKUP_TTL = 60_000;

// ─── send to background (promisified) ─────────────────────────────────────
function bg(msg) {
  return new Promise((resolve, reject) => {
    chrome.runtime.sendMessage(msg, (resp) => {
      if (chrome.runtime.lastError) return reject(new Error(chrome.runtime.lastError.message));
      if (!resp || !resp.ok) return reject(new Error((resp && resp.error) || 'Background error'));
      resolve(resp);
    });
  });
}

// ─── observer + injection ─────────────────────────────────────────────────
function init() {
  console.log('[KasTip] content script active on', location.hostname);
  const observer = new MutationObserver(throttle(injectTipButtons, 250));
  observer.observe(document.body, { childList: true, subtree: true });
  injectTipButtons();
}

function throttle(fn, ms) {
  let t = 0, scheduled = null;
  return function () {
    const now = Date.now();
    if (now - t >= ms) {
      t = now;
      fn();
    } else if (!scheduled) {
      scheduled = setTimeout(() => { scheduled = null; t = Date.now(); fn(); }, ms);
    }
  };
}

function injectTipButtons() {
  const own = getOwnHandle();
  document.querySelectorAll(`${SELECTORS.userName}:not([data-kastip-injected])`).forEach((el) => {
    el.setAttribute('data-kastip-injected', '1');
    const handle = extractHandle(el);
    if (!handle) return;
    if (own && handle === own) return;
    const btn = createTipButton(handle, el);
    el.appendChild(btn);
    injectionCount++;
  });
}

function extractHandle(userNameEl) {
  const links = userNameEl.querySelectorAll('a[role="link"]');
  for (const link of links) {
    const href = link.getAttribute('href');
    if (!href || !href.startsWith('/')) continue;
    if (href.includes('/status/') || href.includes('/photo/') || href.includes('/i/')) continue;
    const m = href.match(/^\/([a-zA-Z0-9_]{1,15})\/?$/);
    if (m) return m[1].toLowerCase();
  }
  return null;
}

function getOwnHandle() {
  const sideLink = document.querySelector(SELECTORS.ownProfile);
  if (!sideLink) return null;
  const href = sideLink.getAttribute('href');
  return href ? href.replace(/^\//, '').toLowerCase() : null;
}

function createTipButton(handle, anchorEl) {
  const btn = document.createElement('button');
  btn.className = 'kastip-btn';
  btn.innerHTML = '<span class="kastip-icon">💰</span><span class="kastip-label">KasTip</span>';
  btn.title = `Tip KAS to @${handle}`;
  btn.dataset.handle = handle;
  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    e.stopImmediatePropagation();
    openTipDialog(handle, findTweetUrl(anchorEl));
  });
  return btn;
}

function findTweetUrl(el) {
  // Walk up to find a tweet container, then extract status URL.
  let node = el;
  for (let i = 0; i < 10 && node; i++) {
    const link = node.querySelector?.('a[role="link"][href*="/status/"]');
    if (link) {
      const href = link.getAttribute('href');
      if (href && href.includes('/status/')) {
        return new URL(href, location.origin).toString();
      }
    }
    node = node.parentElement;
  }
  return null;
}

// ─── modal ────────────────────────────────────────────────────────────────
function openTipDialog(handle, tweetUrl) {
  document.getElementById('kastip-modal')?.remove();

  const modal = document.createElement('div');
  modal.id = 'kastip-modal';
  modal.innerHTML = renderModalShell(handle);
  document.body.appendChild(modal);

  const close = () => modal.remove();
  modal.querySelector('.kastip-close').addEventListener('click', close);
  modal.querySelector('.kastip-modal-overlay').addEventListener('click', close);

  // Lookup handle then transition to right pane
  const body = modal.querySelector('.kastip-modal-body');
  body.innerHTML = '<div class="kastip-loading">Looking up @' + escapeHtml(handle) + '…</div>';

  lookup(handle).then((info) => {
    if (info.registered) {
      renderRegisteredPane(modal, body, handle, info, tweetUrl);
    } else {
      renderUnregisteredPane(modal, body, handle, tweetUrl);
    }
  }).catch((err) => {
    body.innerHTML = `<div class="kastip-error">Lookup failed: ${escapeHtml(err.message)}</div>`;
  });
}

function renderModalShell(handle) {
  return `
    <div class="kastip-modal-overlay"></div>
    <div class="kastip-modal-content">
      <div class="kastip-modal-header">
        <span>💰 Tip @${escapeHtml(handle)}</span>
        <button class="kastip-close" aria-label="Close">×</button>
      </div>
      <div class="kastip-modal-body"></div>
    </div>
  `;
}

async function lookup(handle) {
  const cached = lookupCache.get(handle);
  if (cached && (Date.now() - cached.fetched_at) < LOOKUP_TTL) return cached.data;
  const resp = await bg({ type: 'api:lookup', handle });
  lookupCache.set(handle, { data: resp.data, fetched_at: Date.now() });
  return resp.data;
}

// ─── REGISTERED pane ──────────────────────────────────────────────────────
function renderRegisteredPane(modal, body, handle, info, tweetUrl) {
  const trunc = info.kaspa_address.slice(0, 14) + '…' + info.kaspa_address.slice(-8);
  body.innerHTML = `
    <div class="kastip-amount-row">
      <label for="kastip-amount">Amount (KAS):</label>
      <input type="number" id="kastip-amount" min="${MIN_TIP_KAS}" step="0.1" value="5">
    </div>
    <div class="kastip-quick-amounts">
      <button data-amount="1">1</button>
      <button data-amount="5">5</button>
      <button data-amount="10">10</button>
      <button data-amount="25">25</button>
      <button data-amount="50">50</button>
    </div>
    <div class="kastip-breakdown">
      <div class="kastip-row">
        <span>📤 @${escapeHtml(handle)} receives:</span>
        <span class="kastip-receiver-amount">5.00 KAS</span>
      </div>
      <div class="kastip-row" style="font-size:.8em;color:#71767b">
        <span>+ network fee</span>
        <span>~0.0001 KAS</span>
      </div>
    </div>
    <div class="kastip-recipient-info">
      <span>To: <code>${escapeHtml(trunc)}</code></span>
    </div>
    <div class="kastip-error" id="kastip-err" style="display:none"></div>
    <button id="kastip-send" class="kastip-send-btn">Send via Kasware</button>
    <div style="text-align:center;margin-top:.5rem">
      <a href="#" id="kastip-show-qr" style="font-size:.8rem;color:#71767b">No Kasware? Show QR</a>
    </div>
  `;

  const amountInput = body.querySelector('#kastip-amount');
  const receiverDisplay = body.querySelector('.kastip-receiver-amount');
  const errEl = body.querySelector('#kastip-err');

  const showErr = (m) => { errEl.textContent = m; errEl.style.display = 'block'; };
  const clearErr = () => { errEl.textContent = ''; errEl.style.display = 'none'; };

  const update = () => {
    const v = parseFloat(amountInput.value) || 0;
    receiverDisplay.textContent = `${v.toFixed(2)} KAS`;
  };
  amountInput.addEventListener('input', update);
  body.querySelectorAll('.kastip-quick-amounts button').forEach((btn) => {
    btn.addEventListener('click', () => { amountInput.value = btn.dataset.amount; update(); });
  });

  body.querySelector('#kastip-send').addEventListener('click', async () => {
    clearErr();
    const amt = parseFloat(amountInput.value);
    if (!Number.isFinite(amt) || amt < MIN_TIP_KAS) { showErr(`Minimum tip is ${MIN_TIP_KAS} KAS.`); return; }

    // 1) require auth
    const status = await bg({ type: 'auth:status' });
    if (!status.signedIn) {
      try { await bg({ type: 'auth:start' }); }
      catch (e) { showErr('Sign-in cancelled or failed.'); return; }
    }

    // 2) initiate
    let init;
    try {
      init = (await bg({ type: 'api:tip-initiate', body: {
        receiver_handle: handle,
        amount_kas: amt,
        tweet_url: tweetUrl || null,
      } })).data;
    } catch (e) { showErr(e.message); return; }

    // Edge: if API says unregistered, switch to invitation pane
    if (init.receiver_status === 'unregistered') {
      renderUnregisteredPaneWithData(modal, body, handle, init);
      return;
    }

    // 3) Kasware sendKaspa (single-output)
    if (!window.kasware) {
      // Kasware not detected → switch to QR fallback with this initiated tip
      renderQrPane(modal, body, handle, init);
      return;
    }
    let txid;
    try {
      const accs = await window.kasware.requestAccounts();
      if (!accs || accs.length === 0) throw new Error('No wallet account');
      const sompi = Math.floor(amt * SOMPI_PER_KAS);
      txid = await window.kasware.sendKaspa(init.receiver_address, sompi, { payload: init.payload });
    } catch (e) {
      showErr('Wallet error: ' + (e.message || e));
      return;
    }

    // 4) confirm
    try {
      const conf = (await bg({ type: 'api:tip-confirm', body: { tip_id: init.tip_id, txid } })).data;
      renderSuccessPane(modal, body, handle, amt, txid, conf, tweetUrl);
    } catch (e) {
      showErr('Sent but confirm failed: ' + e.message);
    }
  });

  body.querySelector('#kastip-show-qr').addEventListener('click', async (e) => {
    e.preventDefault();
    clearErr();
    const amt = parseFloat(amountInput.value);
    if (!Number.isFinite(amt) || amt < MIN_TIP_KAS) { showErr(`Set amount first (min ${MIN_TIP_KAS} KAS).`); return; }

    const status = await bg({ type: 'auth:status' });
    if (!status.signedIn) {
      try { await bg({ type: 'auth:start' }); } catch (_) { showErr('Sign-in cancelled.'); return; }
    }

    let init;
    try {
      init = (await bg({ type: 'api:tip-initiate', body: {
        receiver_handle: handle,
        amount_kas: amt,
        tweet_url: tweetUrl || null,
      } })).data;
    } catch (e) { showErr(e.message); return; }

    if (init.receiver_status === 'unregistered') {
      renderUnregisteredPaneWithData(modal, body, handle, init);
      return;
    }
    renderQrPane(modal, body, handle, init);
  });
}

// ─── QR fallback pane ─────────────────────────────────────────────────────
async function renderQrPane(modal, body, handle, init) {
  body.innerHTML = `
    <p style="color:#71767b;margin-bottom:.75rem">Scan with any Kaspa wallet, then paste the TXID below.</p>
    <div class="kastip-qr-wrap" id="kastip-qr"></div>
    <div class="kastip-uri-box" id="kastip-uri">${escapeHtml(init.qr_uri)}</div>
    <button id="kastip-copy-uri" class="kastip-secondary-btn">Copy URI</button>
    <label for="kastip-txid" style="margin-top:1rem;display:block">After sending, paste TXID:</label>
    <input type="text" id="kastip-txid" placeholder="abc123…" autocomplete="off" autocapitalize="none" spellcheck="false">
    <div class="kastip-error" id="kastip-qr-err" style="display:none"></div>
    <button id="kastip-confirm" class="kastip-send-btn">Confirm tip</button>
  `;
  // QR — use bundled qr.js (loaded as web_accessible_resource)
  try {
    const { generateQrSvg } = await import(chrome.runtime.getURL('lib/qr.js'));
    body.querySelector('#kastip-qr').innerHTML = generateQrSvg(init.qr_uri, 196);
  } catch (e) {
    body.querySelector('#kastip-qr').textContent = 'QR generation failed: ' + e.message;
  }

  body.querySelector('#kastip-copy-uri').addEventListener('click', () => {
    navigator.clipboard.writeText(init.qr_uri).then(() => alert('Copied!'));
  });

  const errEl = body.querySelector('#kastip-qr-err');
  body.querySelector('#kastip-confirm').addEventListener('click', async () => {
    errEl.style.display = 'none';
    const txid = body.querySelector('#kastip-txid').value.trim();
    if (!/^[a-f0-9]{32,128}$/i.test(txid)) {
      errEl.textContent = 'Invalid TXID format.';
      errEl.style.display = 'block';
      return;
    }
    try {
      const conf = (await bg({ type: 'api:tip-confirm', body: { tip_id: init.tip_id, txid } })).data;
      renderSuccessPane(modal, body, handle, init.amount_kas, txid, conf, null);
    } catch (e) {
      errEl.textContent = e.message;
      errEl.style.display = 'block';
    }
  });
}

// ─── UNREGISTERED pane ────────────────────────────────────────────────────
function renderUnregisteredPane(modal, body, handle, tweetUrl) {
  body.innerHTML = `
    <div class="kastip-unregistered">
      <p>⚠️ <strong>@${escapeHtml(handle)}</strong> doesn't have a KasTip account yet.</p>
      <p>To tip them, share the invite reply below — they'll be able to claim future tips after registering.</p>
      <button id="kastip-gen-invite" class="kastip-send-btn">Generate invite</button>
    </div>
  `;
  body.querySelector('#kastip-gen-invite').addEventListener('click', async () => {
    try {
      const status = await bg({ type: 'auth:status' });
      if (!status.signedIn) await bg({ type: 'auth:start' });
      const init = (await bg({ type: 'api:tip-initiate', body: {
        receiver_handle: handle, amount_kas: 1, tweet_url: tweetUrl || null,
      } })).data;
      renderUnregisteredPaneWithData(modal, body, handle, init);
    } catch (e) {
      body.innerHTML += `<div class="kastip-error">${escapeHtml(e.message)}</div>`;
    }
  });
}

function renderUnregisteredPaneWithData(modal, body, handle, init) {
  const inv = init.invitation;
  body.innerHTML = `
    <div class="kastip-unregistered">
      <p>📬 <strong>@${escapeHtml(handle)}</strong> doesn't have KasTip yet.</p>
      <p style="font-size:.85em;color:#71767b">Copy this reply — they'll see the invite link under their tweet:</p>
      <div class="kastip-suggested-reply">${escapeHtml(inv.suggested_reply)}</div>
      <button id="kastip-copy-reply" class="kastip-send-btn">Copy invite reply</button>
    </div>
  `;
  body.querySelector('#kastip-copy-reply').addEventListener('click', () => {
    navigator.clipboard.writeText(inv.suggested_reply).then(() => {
      alert('Reply copied! Paste it under the tweet.');
      modal.remove();
    });
  });
}

// ─── SUCCESS pane ─────────────────────────────────────────────────────────
function renderSuccessPane(modal, body, handle, amt, txid, conf, tweetUrl) {
  const statusLabel = {
    confirmed: '✅ Confirmed on-chain',
    broadcast: '📡 Broadcast (verifying)',
    pending: '⏳ Pending',
  }[conf.status] || conf.status;

  body.innerHTML = `
    <div style="text-align:center">
      <div style="font-size:2rem;margin:1rem 0">🎉</div>
      <h3 style="margin-bottom:.5rem">Tip sent to @${escapeHtml(handle)}</h3>
      <p style="color:#71767b;margin-bottom:1rem">${amt} KAS — ${statusLabel}</p>
      <p style="font-size:.78rem;color:#71767b;margin-bottom:1rem;font-family:ui-monospace,monospace;word-break:break-all">
        <a href="https://explorer.kaspa.org/txs/${escapeHtml(txid)}" target="_blank" rel="noopener" style="color:#49e9c9">${escapeHtml(txid.slice(0, 16))}…</a>
      </p>
      ${tweetUrl ? '<button id="kastip-prefill-reply" class="kastip-send-btn">Pre-fill reply on this tweet</button>' : ''}
      <button id="kastip-close-success" class="kastip-secondary-btn" style="margin-top:.5rem">Close</button>
    </div>
  `;
  body.querySelector('#kastip-close-success').addEventListener('click', () => modal.remove());
  if (tweetUrl) {
    body.querySelector('#kastip-prefill-reply').addEventListener('click', () => {
      const replyText = `Just tipped ${amt} KAS to @${handle} via @KasTip ⚡\n\nkastip.app`;
      const ok = prefillReply(replyText);
      if (!ok) {
        alert("Couldn't find a reply box on this page. Open the tweet, click Reply, then come back here.");
      } else {
        modal.remove();
      }
    });
  }
}

// ─── auto-reply pre-fill on X ─────────────────────────────────────────────
function prefillReply(text) {
  const ta = document.querySelector(SELECTORS.replyTextarea);
  if (!ta) return false;
  // X uses React contenteditable for tweetTextarea — direct value won't work.
  // Use the textContent and dispatch an input event so React state syncs.
  ta.focus();
  document.execCommand('insertText', false, text);
  return true;
}

// ─── helpers ───────────────────────────────────────────────────────────────
function escapeHtml(s) {
  return String(s).replace(/[<>&"']/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'})[c]);
}

// ─── start ────────────────────────────────────────────────────────────────
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', init);
} else {
  init();
}
