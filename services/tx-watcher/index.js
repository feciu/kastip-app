// KasTip — tx-watcher service.
//
// Subscribes to Kaspa block notifications via gRPC. For every new block,
// scans transactions for our payload prefix ("kastip:v1:") and forwards
// matches to the PHP backend at /api/internal/tx-detected.
//
// Cookbook lessons applied:
//   §1  — never hardcode a single seeder; cycle through fallbacks
//   §11 — wrap RPC calls with their own timeouts and log specific errors
//
// Run:
//   KASTIP_INTERNAL_TOKEN=... KASTIP_BACKEND_URL=https://kastip.app \
//     KASPA_SEEDERS=seeder2.kaspad.net:16110,seeder3.kaspad.net:16110 \
//     node index.js

import { ClientWrapper } from 'kaspa-rpc-client';

const SEEDERS = (process.env.KASPA_SEEDERS
  || 'seeder2.kaspad.net:16110,seeder3.kaspad.net:16110,seeder4.kaspad.net:16110'
).split(',').map(s => s.trim()).filter(Boolean);

const BACKEND_URL = (process.env.KASTIP_BACKEND_URL || 'https://kastip.app').replace(/\/+$/, '');
const INTERNAL_TOKEN = process.env.KASTIP_INTERNAL_TOKEN || '';
const PAYLOAD_PREFIX = process.env.KASTIP_PAYLOAD_PREFIX || 'kastip:v1:';

if (!INTERNAL_TOKEN) {
  console.error('[tx-watcher] FATAL: KASTIP_INTERNAL_TOKEN env var not set');
  process.exit(2);
}

let client = null;

// ─── helpers ──────────────────────────────────────────────────────────────
function log(...args) {
  console.log('[' + new Date().toISOString() + ']', ...args);
}
function err(...args) {
  console.error('[' + new Date().toISOString() + '] ERROR', ...args);
}

function hexToUtf8(hexStr) {
  if (!hexStr || typeof hexStr !== 'string') return '';
  try {
    return Buffer.from(hexStr, 'hex').toString('utf8');
  } catch {
    return '';
  }
}

async function postToBackend(body) {
  const url = `${BACKEND_URL}/api/internal/tx-detected`;
  try {
    const resp = await fetch(url, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${INTERNAL_TOKEN}`,
      },
      body: JSON.stringify(body),
    });
    const data = await resp.json().catch(() => ({}));
    if (!resp.ok) {
      err('backend POST failed', resp.status, data);
      return null;
    }
    return data;
  } catch (e) {
    err('backend POST threw:', e.message);
    return null;
  }
}

// ─── core: scan transactions inline from blockAdded notification ──────────
// The notification already carries full TX data (with verboseData), so we
// don't need a follow-up getBlock round-trip.
async function processBlockNotification(notif) {
  const transactions = notif?.block?.transactions || [];
  if (transactions.length === 0) return;

  for (const tx of transactions) {
    const payloadStr = hexToUtf8(tx.payload);
    if (!payloadStr.startsWith(PAYLOAD_PREFIX)) continue;

    const txid = tx.verboseData?.transactionId;
    if (!txid) {
      err('TX with KasTip payload has no verboseData.transactionId — skipping');
      continue;
    }

    const outputs = (tx.outputs || []).map((o) => ({
      address: o.verboseData?.scriptPublicKeyAddress || null,
      amount: parseInt(o.amount ?? o.value ?? '0', 10),
    }));

    log(`✨ KasTip TX ${txid.slice(0, 16)}… payload=${payloadStr}`);
    postToBackend({ txid, payload: payloadStr, outputs })
      .then((result) => {
        if (result) {
          log(`   backend → ${result.status}` + (result.tip_id ? ` (tip_id=${result.tip_id})` : ''));
        }
      });
  }
}

// ─── connection lifecycle ────────────────────────────────────────────────
async function connect() {
  log(`connecting to seeders: ${SEEDERS.join(', ')}`);
  const wrapper = new ClientWrapper({ hosts: SEEDERS, verbose: false });
  // Cookbook §1: wrap initialize with a timeout so a hung seeder doesn't pin us forever.
  await Promise.race([
    wrapper.initialize(),
    new Promise((_, reject) => setTimeout(() => reject(new Error('initialize timeout')), 10_000)),
  ]);
  client = await wrapper.getClient();
  log('connected — getting tipHash to confirm node is healthy');
  const info = await client.getInfo({});
  log(`node: ${info?.serverVersion || '?'}, isSynced=${info?.isSynced}, mempoolSize=${info?.mempoolSize ?? '?'}`);
}

async function startSubscription() {
  log('subscribing to blockAdded …');
  await client.subscribeBlockAdded((notif) => {
    try {
      processBlockNotification(notif);
    } catch (e) {
      err('callback error:', e.message);
    }
  });
  log('subscribed. Waiting for blocks…');
}

// ─── main ────────────────────────────────────────────────────────────────
async function main() {
  // Reconnect loop: if the connection drops or initialize fails, retry with backoff.
  let backoff = 2_000;
  for (;;) {
    try {
      await connect();
      await startSubscription();
      backoff = 2_000;  // reset on success
      // Keep process alive — subscription runs in background. Block on a never-resolving promise.
      await new Promise(() => {});
    } catch (e) {
      err('main loop:', e.message);
      log(`reconnecting in ${backoff / 1000}s…`);
      await new Promise(r => setTimeout(r, backoff));
      backoff = Math.min(backoff * 2, 60_000);
    }
  }
}

process.on('unhandledRejection', (reason) => {
  err('unhandledRejection:', reason);
});
process.on('SIGINT', () => { log('SIGINT — exiting'); process.exit(0); });
process.on('SIGTERM', () => { log('SIGTERM — exiting'); process.exit(0); });

main();
