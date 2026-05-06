'use strict';
//
// KasTip — page-world bridge (runs in MAIN world, NOT isolated).
//
// Why we need this:
//   Kasware (and most web3 wallets) injects `window.kasware` into the page's
//   own JS execution context (MAIN world). Chrome MV3 content scripts run in
//   an ISOLATED world by default — so isolated scripts cannot see
//   `window.kasware` directly.
//
//   This bridge runs in MAIN world (manifest content_scripts world:"MAIN")
//   and exposes a window.postMessage RPC for the isolated content script.
//
// Protocol:
//   isolated → main:  postMessage({kastip_kind:'request', id, method, params})
//   main → isolated:  postMessage({kastip_kind:'response', id, ok, result|error})
//
// Methods:
//   kasware:check            → boolean (true iff window.kasware present)
//   kasware:requestAccounts  → string[] (account addresses)
//   kasware:sendKaspa        → string  (txid)
//

(function () {
  if (window.__kastipBridgeReady) return;  // idempotent if injected twice
  window.__kastipBridgeReady = true;

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
        case 'kasware:check': {
          reply({ ok: true, result: !!window.kasware });
          return;
        }
        case 'kasware:requestAccounts': {
          if (!window.kasware) { reply({ ok: false, error: 'NO_KASWARE' }); return; }
          const accounts = await window.kasware.requestAccounts();
          reply({ ok: true, result: accounts });
          return;
        }
        case 'kasware:sendKaspa': {
          if (!window.kasware) { reply({ ok: false, error: 'NO_KASWARE' }); return; }
          const { address, sompi, options } = data.params || {};
          const txid = await window.kasware.sendKaspa(address, sompi, options || {});
          console.log('[KasTip bridge] kasware.sendKaspa returned:', txid, '(typeof:', typeof txid, ')');
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
