// KasTip — background service worker (MV3).
//
// Lives short — wakes up on events, idles otherwise. Use chrome.storage for
// persistence, NOT in-memory state.
//
// Responsibilities:
//   1. OAuth flow via chrome.identity.launchWebAuthFlow.
//   2. Token storage in chrome.storage.local (single source of truth).
//   3. Message router between popup ↔ content script ↔ kastip.app API.

import { KASTIP_API, KASTIP_BASE, STORAGE_TOKEN, STORAGE_USER } from './lib/config.js';

// ─── token / user helpers ─────────────────────────────────────────────────
async function getToken() {
  const { [STORAGE_TOKEN]: token } = await chrome.storage.local.get(STORAGE_TOKEN);
  return token || null;
}
async function setToken(token) {
  await chrome.storage.local.set({ [STORAGE_TOKEN]: token });
}
async function clearAuth() {
  await chrome.storage.local.remove([STORAGE_TOKEN, STORAGE_USER]);
}
async function getCachedUser() {
  const { [STORAGE_USER]: user } = await chrome.storage.local.get(STORAGE_USER);
  return user || null;
}
async function setCachedUser(user) {
  await chrome.storage.local.set({ [STORAGE_USER]: user });
}

// ─── auth flow ────────────────────────────────────────────────────────────
async function startAuthFlow() {
  // chrome.identity.launchWebAuthFlow opens a window that closes when the auth
  // provider redirects to a URL matching the extension redirect URI. We pass
  // the extension's redirect URL to backend so it can bounce us back with token.
  const extRedirect = chrome.identity.getRedirectURL('oauth-callback');
  const authUrl = new URL(`${KASTIP_API}/auth/x/start`);
  authUrl.searchParams.set('client_kind', 'extension');
  authUrl.searchParams.set('ext_redirect', extRedirect);

  const responseUrl = await chrome.identity.launchWebAuthFlow({
    url: authUrl.toString(),
    interactive: true,
  });
  if (!responseUrl) throw new Error('OAuth was cancelled.');

  const url = new URL(responseUrl);
  const token = url.searchParams.get('token');
  const errorParam = url.searchParams.get('error');
  if (errorParam) throw new Error(`OAuth error: ${errorParam}`);
  if (!token || !/^[a-f0-9]{64}$/.test(token)) {
    throw new Error('No valid token in callback URL.');
  }

  await setToken(token);
  // Pre-fetch user so popup has it instantly
  try {
    const user = await apiFetch('GET', '/users/me');
    await setCachedUser(user);
  } catch {/* ignore — popup will retry */}
  return token;
}

async function logout() {
  // Best-effort tell server, then wipe local.
  try { await apiFetch('POST', '/auth/logout'); } catch {/* ignore */}
  await clearAuth();
}

// ─── API fetch wrapper (Bearer auth) ──────────────────────────────────────
async function apiFetch(method, path, body) {
  const token = await getToken();
  const headers = { 'Content-Type': 'application/json' };
  if (token) headers['Authorization'] = `Bearer ${token}`;

  const opts = { method, headers };
  if (body !== undefined) opts.body = JSON.stringify(body);

  const r = await fetch(`${KASTIP_API}${path}`, opts);
  const data = await r.json().catch(() => ({}));
  if (!r.ok) {
    if (r.status === 401) await clearAuth();
    const err = new Error(data.message || `HTTP ${r.status}`);
    err.status = r.status;
    err.code = data.error;
    throw err;
  }
  return data;
}

// ─── message router ────────────────────────────────────────────────────────
chrome.runtime.onMessage.addListener((msg, sender, sendResponse) => {
  (async () => {
    try {
      switch (msg.type) {
        case 'auth:status': {
          const token = await getToken();
          const user = await getCachedUser();
          sendResponse({ ok: true, signedIn: !!token, user });
          return;
        }
        case 'auth:start': {
          await startAuthFlow();
          const user = await getCachedUser();
          sendResponse({ ok: true, user });
          return;
        }
        case 'auth:logout': {
          await logout();
          sendResponse({ ok: true });
          return;
        }
        case 'auth:refresh-user': {
          const user = await apiFetch('GET', '/users/me');
          await setCachedUser(user);
          sendResponse({ ok: true, user });
          return;
        }
        case 'api:lookup': {
          const handle = encodeURIComponent(msg.handle);
          const data = await apiFetch('GET', `/users/lookup?handle=${handle}`);
          sendResponse({ ok: true, data });
          return;
        }
        case 'api:tip-initiate': {
          const data = await apiFetch('POST', '/tips/initiate', msg.body);
          sendResponse({ ok: true, data });
          return;
        }
        case 'api:tip-confirm': {
          const data = await apiFetch('POST', '/tips/confirm', msg.body);
          sendResponse({ ok: true, data });
          return;
        }
        default:
          sendResponse({ ok: false, error: 'unknown_message_type' });
      }
    } catch (err) {
      sendResponse({ ok: false, error: err.message, status: err.status, code: err.code });
    }
  })();
  return true;  // Keep message channel open for async sendResponse
});

// Optional: log lifecycle for debugging during development
chrome.runtime.onInstalled.addListener(({ reason }) => {
  console.log('[KasTip] background installed/updated:', reason);
});
