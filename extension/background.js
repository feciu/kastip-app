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

// ─── auto-link with web (kastip.app) cookie session ──────────────────────
//
// If the user is already signed into kastip.app in this browser, the popup
// can offer to link the extension to that same KasTip account WITHOUT going
// through OAuth (which would silently bind to whatever X session happens to
// be active in the browser).
//
// Flow:
//   1. chrome.cookies.get to detect HTTPOnly kastip_session cookie (extension
//      cookie permission can read HTTPOnly; web JS cannot — that's the point).
//   2. fetch /api/auth/whoami with credentials:'include' to verify cookie is
//      still valid; if so, fetch /api/users/me to display avatar/handle.
//   3. User clicks "Use this account" → POST /api/auth/extension-link with
//      credentials:'include'. Backend mints a fresh extension-kind session
//      and returns its token + user. Save both.
async function detectWebSession() {
  try {
    const cookie = await chrome.cookies.get({ url: KASTIP_BASE, name: 'kastip_session' });
    if (!cookie || !cookie.value) return { hasWebSession: false };

    // Cookie present — verify it's still authenticated, fetch user info.
    const whoamiResp = await fetch(`${KASTIP_API}/auth/whoami`, {
      method: 'GET',
      credentials: 'include',
    });
    const whoami = await whoamiResp.json().catch(() => ({}));
    if (!whoami.authenticated) return { hasWebSession: false };

    const meResp = await fetch(`${KASTIP_API}/users/me`, {
      method: 'GET',
      credentials: 'include',
    });
    if (!meResp.ok) return { hasWebSession: true, user: null };
    const me = await meResp.json();
    return {
      hasWebSession: true,
      user: {
        x_username: me.x_username,
        x_display_name: me.x_display_name,
        x_avatar_url: me.x_avatar_url,
      },
    };
  } catch (e) {
    console.warn('[KasTip] detectWebSession failed:', e);
    return { hasWebSession: false };
  }
}

async function linkViaWeb() {
  const r = await fetch(`${KASTIP_API}/auth/extension-link`, {
    method: 'POST',
    credentials: 'include',
  });
  const data = await r.json().catch(() => ({}));
  if (!r.ok || !data.ok) {
    throw new Error(data.message || `link failed (${r.status})`);
  }
  await setToken(data.token);
  await setCachedUser(data.user);
  return data.user;
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
        case 'auth:check-web-session': {
          const r = await detectWebSession();
          sendResponse({ ok: true, ...r });
          return;
        }
        case 'auth:link-via-web': {
          const user = await linkViaWeb();
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
        case 'users:save-address': {
          await apiFetch('POST', '/users/register', { kaspa_address: msg.address });
          const user = await apiFetch('GET', '/users/me');
          await setCachedUser(user);
          sendResponse({ ok: true, user });
          return;
        }
        case 'users:update-address': {
          await apiFetch('PUT', '/users/me/settings', { kaspa_address: msg.address });
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
        case 'api:tip-status': {
          const data = await apiFetch('GET', `/tips/${msg.tip_id}/status`);
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
