'use strict';
//
// KasTip — page-world bridge (runs in MAIN world, NOT isolated).
//
// Why this exists:
//   Kaspa wallets (Kasware, Kastle, future others) inject their providers
//   into the page's MAIN JS context. Chrome MV3 content scripts run in
//   ISOLATED world by default — so without a bridge they can't see
//   `window.kasware` or `window.kastle`.
//
//   This file runs as content_script with world:"MAIN" (manifest), exposes
//   a window.postMessage RPC for the isolated content script to call.
//
// Protocol:
//   isolated → main:  postMessage({kastip_kind:'request', id, method, params})
//   main → isolated:  postMessage({kastip_kind:'response', id, ok, result|error})
//
// Universal wallet methods (preferred):
//   wallet:detect              → {kasware: bool, kastle: bool, ...}
//   wallet:requestAccounts     → params {walletId}                 → string[]
//   wallet:sendKaspa           → params {walletId, address, sompi, payload?} → txid string
//
// Backward-compatible Kasware-only aliases (used by older builds):
//   kasware:check, kasware:requestAccounts, kasware:sendKaspa
//

(function () {
  if (window.__kastipBridgeReady) return;
  window.__kastipBridgeReady = true;

  // ─── Wallet adapters ────────────────────────────────────────────────────
  // Each entry knows how to detect, list accounts, and send a tip in its
  // wallet's idiosyncratic API. To add a new wallet later, drop one entry here.
  function strToHex(str) {
    const bytes = new TextEncoder().encode(str);
    let hex = '';
    for (const b of bytes) hex += b.toString(16).padStart(2, '0');
    return hex;
  }

  const WALLETS = {
    kasware: {
      name: 'Kasware',
      detect: () => typeof window.kasware !== 'undefined' && window.kasware !== null,
      accounts: async () => await window.kasware.requestAccounts(),
      send: async ({ address, sompi, payload }) => {
        // Kasware accepts a plain-text payload and hex-encodes it internally.
        const opts = { priorityFee: 0 };
        if (payload) opts.payload = payload;
        return await window.kasware.sendKaspa(address, sompi, opts);
      },
    },
    kastle: {
      name: 'Kastle',
      // Kastle injects window.kastle and a separate window.kastle.ethereum
      // subprovider for EVM. Look for the Kaspa-specific methods (connect)
      // to distinguish from EVM-only injection.
      detect: () =>
        typeof window.kastle === 'object'
        && window.kastle !== null
        && typeof window.kastle.connect === 'function'
        && typeof window.kastle.sendKaspa === 'function',
      // Kastle: connect() returns bool handshake, then getAccount() returns
      // {address, publicKey}. NOT requestAccounts() like Kasware.
      accounts: async () => {
        const ok = await window.kastle.connect();
        if (!ok) throw new Error('Kastle connect rejected');
        const acc = await window.kastle.getAccount();
        return acc?.address ? [acc.address] : [];
      },
      send: async ({ address, sompi, payload }) => {
        // Kastle requires payload as a hex string ("0-9 a-f", even length).
        const opts = { priorityFee: 0 };
        if (payload) opts.payload = strToHex(payload);
        return await window.kastle.sendKaspa(address, sompi, opts);
      },
    },
  };

  function getAdapter(walletId) {
    const a = WALLETS[walletId];
    if (!a) throw new Error('unknown_wallet:' + walletId);
    if (!a.detect()) throw new Error('wallet_not_detected:' + walletId);
    return a;
  }

  // ─── Message handler ────────────────────────────────────────────────────
  window.addEventListener('message', async (event) => {
    if (event.source !== window) return;
    const data = event.data;
    if (!data || data.kastip_kind !== 'request') return;

    const reply = (payload) => window.postMessage({
      kastip_kind: 'response',
      id: data.id,
      ...payload,
    }, '*');

    try {
      switch (data.method) {
        // ─── universal multi-wallet methods ──────────────────────────────
        case 'wallet:detect': {
          const detected = {};
          for (const [id, w] of Object.entries(WALLETS)) {
            detected[id] = { name: w.name, detected: w.detect() };
          }
          reply({ ok: true, result: detected });
          return;
        }
        case 'wallet:requestAccounts': {
          const adapter = getAdapter(data.params?.walletId);
          const accounts = await adapter.accounts();
          reply({ ok: true, result: accounts });
          return;
        }
        case 'wallet:sendKaspa': {
          const { walletId, address, sompi, payload } = data.params || {};
          const adapter = getAdapter(walletId);
          const txid = await adapter.send({ address, sompi, payload });
          console.log('[KasTip bridge]', walletId, 'sendKaspa returned:', txid, '(typeof:', typeof txid, ')');
          reply({ ok: true, result: txid });
          return;
        }

        // ─── legacy Kasware-only aliases (older builds) ─────────────────
        case 'kasware:check': {
          reply({ ok: true, result: WALLETS.kasware.detect() });
          return;
        }
        case 'kasware:requestAccounts': {
          if (!WALLETS.kasware.detect()) { reply({ ok: false, error: 'NO_KASWARE' }); return; }
          const accounts = await WALLETS.kasware.accounts();
          reply({ ok: true, result: accounts });
          return;
        }
        case 'kasware:sendKaspa': {
          if (!WALLETS.kasware.detect()) { reply({ ok: false, error: 'NO_KASWARE' }); return; }
          const { address, sompi, options } = data.params || {};
          const txid = await window.kasware.sendKaspa(address, sompi, options || {});
          reply({ ok: true, result: txid });
          return;
        }

        default:
          reply({ ok: false, error: 'unknown_method:' + data.method });
      }
    } catch (e) {
      reply({ ok: false, error: e && e.message ? e.message : String(e) });
    }
  });
})();
