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
const KASPA_EXPLORER_TX = 'https://kaspa.stream/transactions';

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

// ─── page-world bridge for window.kasware ─────────────────────────────────
// Kasware injects into MAIN world; we run in ISOLATED. The bridge
// (content/x-bridge.js, world:"MAIN") proxies via window.postMessage.
let __bridgeReqId = 0;
function bridgeCall(method, params, timeoutMs = 60_000) {
  return new Promise((resolve, reject) => {
    const id = 'k' + (++__bridgeReqId);
    const t0 = setTimeout(() => {
      window.removeEventListener('message', onMsg);
      reject(new Error('Bridge timeout for ' + method));
    }, timeoutMs);
    const onMsg = (event) => {
      if (event.source !== window) return;
      const data = event.data;
      if (!data || data.kastip_kind !== 'response' || data.id !== id) return;
      clearTimeout(t0);
      window.removeEventListener('message', onMsg);
      if (data.ok) resolve(data.result);
      else reject(new Error(data.error || 'bridge_error'));
    };
    window.addEventListener('message', onMsg);
    window.postMessage({ kastip_kind: 'request', id, method, params }, '*');
  });
}
async function hasKasware() {
  try { return await bridgeCall('kasware:check', null, 1500); }
  catch { return false; }
}
async function kaswareRequestAccounts() {
  return await bridgeCall('kasware:requestAccounts');
}
async function kaswareSendKaspa(address, sompi, options) {
  return await bridgeCall('kasware:sendKaspa', { address, sompi, options });
}

// Kasware (and other wallets) return txid in various shapes — string, object,
// JSON-wrapped string, with/without "0x" prefix. Normalize to lowercase hex.
function normalizeTxid(raw) {
  if (raw == null) return '';
  if (typeof raw === 'string') {
    let s = raw.trim();
    // Strip outer quotes if Kasware double-encoded
    if ((s.startsWith('"') && s.endsWith('"')) || (s.startsWith("'") && s.endsWith("'"))) {
      s = s.slice(1, -1);
    }
    // Try parsing as JSON in case it's a stringified object/array
    try {
      const parsed = JSON.parse(s);
      if (typeof parsed !== 'string') return normalizeTxid(parsed);
      s = parsed;
    } catch { /* not JSON, plain string */ }
    return s.replace(/^0x/i, '').toLowerCase();
  }
  if (Array.isArray(raw)) {
    return raw.length > 0 ? normalizeTxid(raw[0]) : '';
  }
  if (typeof raw === 'object') {
    for (const key of ['txid', 'id', 'tx', 'transactionId', 'transaction_id', 'hash', 'txId']) {
      if (raw[key]) return normalizeTxid(raw[key]);
    }
  }
  return String(raw);
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

    // 3) Kasware sendKaspa (single-output) — call through MAIN-world bridge
    const haveKasware = await hasKasware();
    if (!haveKasware) {
      renderQrPane(modal, body, handle, init);
      return;
    }
    let rawTxid;
    try {
      const accs = await kaswareRequestAccounts();
      if (!accs || accs.length === 0) throw new Error('No wallet account');
      const sompi = Math.floor(amt * SOMPI_PER_KAS);
      rawTxid = await kaswareSendKaspa(init.receiver_address, sompi, { payload: init.payload });
      console.log('[KasTip] raw txid from kasware:', JSON.stringify(rawTxid));
    } catch (e) {
      if (e.message === 'NO_KASWARE') {
        renderQrPane(modal, body, handle, init);
        return;
      }
      showErr('Wallet error: ' + (e.message || e));
      return;
    }

    const txid = normalizeTxid(rawTxid);
    console.log('[KasTip] normalized txid:', txid, 'length:', txid.length);

    // 4) confirm
    try {
      const conf = (await bg({ type: 'api:tip-confirm', body: { tip_id: init.tip_id, txid } })).data;
      renderSuccessPane(modal, body, handle, amt, txid, conf, tweetUrl);
    } catch (e) {
      showErr('Sent but confirm failed: ' + e.message
        + ' | raw=' + JSON.stringify(rawTxid).slice(0, 80)
        + ' | normalized=' + txid.slice(0, 16) + '… (' + txid.length + ' chars)');
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
    <button id="kastip-qr-back" class="kastip-link-btn" type="button">← Back</button>
    <p style="color:#71767b;margin:.5rem 0 .5rem">Scan with any Kaspa wallet (Kaspium, Tangem, KSPR, etc.). We'll auto-detect the transaction once it lands on-chain — typically within a few seconds.</p>

    <div class="kastip-qr-amount-banner">
      <div class="kastip-qr-amount-big">${init.amount_kas} KAS</div>
      <div class="kastip-qr-amount-hint">Send <strong>exactly this amount</strong> — sending less will leave the tip unconfirmed.</div>
    </div>

    <div class="kastip-qr-toggle">
      <label>
        <input type="checkbox" id="kastip-qr-amount" checked>
        Include amount in QR (uncheck if your wallet ignores it)
      </label>
    </div>

    <div class="kastip-qr-wrap" id="kastip-qr"></div>
    <div class="kastip-uri-box" id="kastip-uri"></div>
    <button id="kastip-copy-uri" class="kastip-secondary-btn">Copy URI</button>

    <div class="kastip-qr-waiting" id="kastip-waiting">
      <span class="kastip-spinner"></span>
      <span>Waiting for transaction…</span>
    </div>

    <details class="kastip-manual-fallback">
      <summary>Wallet doesn't auto-detect? Paste TXID manually</summary>
      <input type="text" id="kastip-txid" placeholder="abc123…" autocomplete="off" autocapitalize="none" spellcheck="false">
      <button id="kastip-confirm" class="kastip-secondary-btn">Confirm tip</button>
    </details>
    <div class="kastip-error" id="kastip-qr-err" style="display:none"></div>
  `;
  // Track polling so we can cancel on close/back/success.
  let pollHandle = null;
  let pollExpired = false;
  const stopPolling = () => { if (pollHandle) { clearTimeout(pollHandle); pollHandle = null; } };

  // Cancel polling if modal is removed (user closed it via X)
  const observer = new MutationObserver(() => {
    if (!document.body.contains(modal)) { stopPolling(); observer.disconnect(); }
  });
  observer.observe(document.body, { childList: true });

  body.querySelector('#kastip-qr-back').addEventListener('click', () => {
    stopPolling();
    const fakeInfo = { kaspa_address: init.receiver_address };
    renderRegisteredPane(modal, body, handle, fakeInfo, null);
  });
  // QR — using qrcodejs@1.0.0 (loaded as content_script before this file).
  // Battle-tested library, same one used in kaspablocks.com payment flow.
  // Two URI variants:
  //   full:  kaspa:{addr}?amount={KAS}&label=tip-to-{handle}    — Kasware-friendly
  //   plain: kaspa:{addr}                                       — works in every wallet
  const fullUri = init.qr_uri;
  const plainUri = init.receiver_address;
  let currentUri = fullUri;

  function renderQr(uri) {
    currentUri = uri;
    const box = body.querySelector('#kastip-qr');
    box.innerHTML = '';  // clear — qrcodejs appends instead of replacing
    if (typeof QRCode === 'undefined') {
      box.textContent = 'QR library not loaded';
      return;
    }
    new QRCode(box, {
      text: uri,
      width: 220,
      height: 220,
      colorDark: '#000000',
      colorLight: '#ffffff',
      correctLevel: QRCode.CorrectLevel.M,
    });
    body.querySelector('#kastip-uri').textContent = uri;
  }
  renderQr(fullUri);

  body.querySelector('#kastip-qr-amount').addEventListener('change', (e) => {
    renderQr(e.target.checked ? fullUri : plainUri);
  });

  body.querySelector('#kastip-copy-uri').addEventListener('click', () => {
    navigator.clipboard.writeText(currentUri).then(() => {
      const btn = body.querySelector('#kastip-copy-uri');
      const orig = btn.textContent;
      btn.textContent = '✓ Copied';
      setTimeout(() => { btn.textContent = orig; }, 1500);
    });
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
      stopPolling();
      renderSuccessPane(modal, body, handle, init.amount_kas, txid, conf, null);
    } catch (e) {
      errEl.textContent = e.message;
      errEl.style.display = 'block';
    }
  });

  // ─── auto-poll status — auto-detects foreign-wallet TX via tx-watcher ──
  const POLL_INTERVAL_MS = 3000;
  const POLL_TIMEOUT_MS = 5 * 60 * 1000;
  const pollStart = Date.now();
  const waitingEl = body.querySelector('#kastip-waiting');

  const tick = async () => {
    if (!document.body.contains(modal)) return;  // modal closed
    if (Date.now() - pollStart > POLL_TIMEOUT_MS) {
      pollExpired = true;
      if (waitingEl) waitingEl.innerHTML = '<span style="color:#fca5a5">Timed out — try the manual TXID input below.</span>';
      return;
    }
    try {
      const r = await bg({ type: 'api:tip-status', tip_id: init.tip_id });
      const tip = r.data;
      if (tip && (tip.status === 'confirmed' || (tip.status === 'broadcast' && tip.txid))) {
        observer.disconnect();
        renderSuccessPane(modal, body, handle, init.amount_kas, tip.txid || '', { status: tip.status }, null);
        return;
      }
    } catch (e) {
      // Transient — keep polling
    }
    pollHandle = setTimeout(tick, POLL_INTERVAL_MS);
  };
  pollHandle = setTimeout(tick, POLL_INTERVAL_MS);
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

  const replyLines = [
    `Just sent you ${amt} KAS via @kastipapp ⚡`,
    `TX: ${KASPA_EXPLORER_TX}/${txid}`,
    `kastip.app`,
  ];
  const replyText = replyLines.join('\n');

  body.innerHTML = `
    <div style="text-align:center">
      <div style="font-size:2rem;margin:1rem 0">🎉</div>
      <h3 style="margin-bottom:.5rem">Tip sent to @${escapeHtml(handle)}</h3>
      <p style="color:#71767b;margin-bottom:.85rem">${amt} KAS — ${statusLabel}</p>
      <p style="font-size:.78rem;color:#71767b;margin-bottom:1rem;font-family:ui-monospace,monospace;word-break:break-all">
        <a href="${KASPA_EXPLORER_TX}/${escapeHtml(txid)}" target="_blank" rel="noopener" style="color:#49e9c9">${escapeHtml(txid.slice(0, 16))}…</a>
      </p>
    </div>

    <div class="kastip-reply-preview">
      <label class="kastip-reply-label">Suggested reply (copy or pre-fill):</label>
      <textarea id="kastip-reply-text" readonly>${escapeHtml(replyText)}</textarea>
      <div class="kastip-reply-actions">
        <button id="kastip-copy-reply" class="kastip-secondary-btn">📋 Copy</button>
        ${tweetUrl ? '<button id="kastip-prefill-reply" class="kastip-send-btn">↪ Pre-fill in reply box</button>' : ''}
      </div>
    </div>

    <button id="kastip-close-success" class="kastip-secondary-btn" style="margin-top:.75rem">Close</button>
  `;

  body.querySelector('#kastip-close-success').addEventListener('click', () => modal.remove());

  body.querySelector('#kastip-copy-reply').addEventListener('click', () => {
    navigator.clipboard.writeText(replyText).then(() => {
      const btn = body.querySelector('#kastip-copy-reply');
      const orig = btn.textContent;
      btn.textContent = '✓ Copied!';
      setTimeout(() => { btn.textContent = orig; }, 1500);
    });
  });

  if (tweetUrl) {
    body.querySelector('#kastip-prefill-reply').addEventListener('click', () => {
      const ok = prefillReply(replyLines);
      if (!ok) {
        // Reply box not on this page — auto-copy to clipboard so user can paste
        navigator.clipboard.writeText(replyText).then(() => {
          const btn = body.querySelector('#kastip-prefill-reply');
          btn.textContent = '⚠ No reply box — copied to clipboard';
          btn.disabled = true;
        });
      } else {
        modal.remove();
      }
    });
  }
}

// ─── auto-reply pre-fill on X ─────────────────────────────────────────────
// X's reply box is a React contenteditable. Simple approaches all have issues:
//   - execCommand('insertText', '\n')  → silently truncates after \n
//   - execCommand('insertParagraph')   → wraps content in <p> which X may
//                                         interpret as new-tweet-in-thread
//                                         and drop subsequent text
// Best technique: paste simulation. X has a real paste handler that knows
// how to render multi-line plain text correctly. Fallback to insertHTML
// with <br> if paste event isn't honored.
function prefillReply(lines) {
  const ta = document.querySelector(SELECTORS.replyTextarea);
  if (!ta) return false;
  const text = (Array.isArray(lines) ? lines : [String(lines)]).join('\n');
  ta.focus();

  // Try paste simulation first
  try {
    const dt = new DataTransfer();
    dt.setData('text/plain', text);
    const ev = new ClipboardEvent('paste', {
      clipboardData: dt,
      bubbles: true,
      cancelable: true,
    });
    const handled = !ta.dispatchEvent(ev);
    // dispatchEvent returns false if any handler called preventDefault — that
    // means X's handler took over (success). If true, X ignored it; fallback.
    if (handled) {
      console.log('[KasTip] reply pre-fill: paste-simulation succeeded');
      return true;
    }
  } catch (e) {
    console.warn('[KasTip] paste simulation threw:', e);
  }

  // Fallback: insertHTML with <br> for line breaks
  console.log('[KasTip] reply pre-fill: falling back to insertHTML');
  const escapeForHtml = (s) => s
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
  const html = text.split('\n').map(escapeForHtml).join('<br>');
  document.execCommand('insertHTML', false, html);
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
