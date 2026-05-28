'use strict';

const $ = (s) => document.querySelector(s);

function bg(msg) {
  return new Promise((resolve, reject) => {
    chrome.runtime.sendMessage(msg, (resp) => {
      if (chrome.runtime.lastError) return reject(new Error(chrome.runtime.lastError.message));
      if (!resp || !resp.ok) return reject(new Error((resp && resp.error) || 'unknown error'));
      resolve(resp);
    });
  });
}

function show(stateId) {
  ['state-anon', 'state-anon-with-web', 'state-onboard-address', 'state-signed', 'state-loading'].forEach((id) => {
    document.getElementById(id).style.display = (id === stateId) ? '' : 'none';
  });
}

function fmtKas(n) {
  return Number(n || 0).toLocaleString(undefined, { minimumFractionDigits: 0, maximumFractionDigits: 8 }) + ' KAS';
}

async function checkPageStatus() {
  try {
    const [tab] = await chrome.tabs.query({ active: true, currentWindow: true });
    const onX = tab && tab.url && /^https:\/\/(www\.)?(x|twitter)\.com\//i.test(tab.url);
    const el = $('#page-status');
    if (onX) {
      el.textContent = '✓ Active on this page — tip buttons injected.';
      el.classList.add('active');
    } else {
      el.textContent = 'Open x.com to see tip buttons next to handles.';
      el.classList.remove('active');
    }
  } catch (_) { /* ignore */ }
}

let addrMode = 'onboard';   // 'onboard' (initial) | 'edit' (change later)
let cachedUser = null;

function shortAddr(a) {
  if (!a) return '';
  return a.length > 28 ? a.slice(0, 14) + '…' + a.slice(-10) : a;
}

function renderOnboardAddress(user, mode = 'onboard') {
  addrMode = mode;
  show('state-onboard-address');
  $('#onb-avatar').src = user.x_avatar_url || '';
  $('#onb-display-name').textContent = user.x_display_name || ('@' + user.x_username);
  $('#onb-handle').textContent = '@' + user.x_username;
  $('#addr-error').style.display = 'none';
  if (mode === 'edit') {
    $('#addr-lead').textContent = 'Change your Kaspa receiving address. New tips will arrive at the new address; past tips already sent are unaffected.';
    $('#kaspa-address').value = user.kaspa_address || '';
    $('#addr-save-btn').textContent = 'Update';
    $('#addr-cancel-btn').style.display = '';
    $('#addr-hint').style.display = 'none';
  } else {
    $('#addr-lead').textContent = 'One last step — your Kaspa address. Tips arrive directly here, peer-to-peer. We never hold your KAS.';
    $('#kaspa-address').value = '';
    $('#addr-save-btn').textContent = 'Save and continue';
    $('#addr-cancel-btn').style.display = 'none';
    $('#addr-hint').style.display = '';
  }
  $('#addr-save-btn').disabled = false;
  setTimeout(() => $('#kaspa-address').focus(), 0);
}

async function renderSigned(user) {
  cachedUser = user;
  if (user && user.needs_address) {
    renderOnboardAddress(user, 'onboard');
    return;
  }
  show('state-signed');
  $('#avatar').src = user.x_avatar_url || '';
  $('#display-name').textContent = user.x_display_name || ('@' + user.x_username);
  $('#handle').textContent = '@' + user.x_username;
  $('#stat-recv').textContent = fmtKas(user.total_received_kas);
  $('#stat-sent').textContent = fmtKas(user.total_sent_kas);
  $('#signed-address').textContent = shortAddr(user.kaspa_address);
  $('#signed-address').title = user.kaspa_address || '';
  await checkPageStatus();
}

async function init() {
  show('state-loading');
  try {
    const status = await bg({ type: 'auth:status' });
    if (status.signedIn) {
      if (status.user) {
        await renderSigned(status.user);
        // Soft refresh in background — picks up changes since last popup open
        // (e.g. tips sent in another tab, address added via kastip.app web flow).
        bg({ type: 'auth:refresh-user' })
          .then((r) => {
            // Don't disrupt the onboard form mid-typing if status didn't change.
            // But DO re-render if user just added an address (needs_address flipped).
            const onboardVisible = document.getElementById('state-onboard-address').style.display === '';
            if (onboardVisible && r.user.needs_address) {
              cachedUser = r.user;       // refresh cache only, leave form intact
              return;
            }
            renderSigned(r.user);
          })
          .catch(() => { /* network blip — keep showing cached */ });
      } else {
        const r = await bg({ type: 'auth:refresh-user' });
        await renderSigned(r.user);
      }
      return;
    }
    // Not signed in — check if we can offer auto-link with web session
    const web = await bg({ type: 'auth:check-web-session' });
    if (web.hasWebSession && web.user) {
      renderAnonWithWebSession(web.user);
    } else {
      show('state-anon');
    }
  } catch (err) {
    show('state-anon');
    console.warn('[KasTip popup] init error:', err.message);
  }
}

function renderAnonWithWebSession(user) {
  show('state-anon-with-web');
  $('#web-avatar').src = user.x_avatar_url || '';
  $('#web-display-name').textContent = user.x_display_name || ('@' + user.x_username);
  $('#web-handle').textContent = '@' + user.x_username;
}

$('#signin-btn').addEventListener('click', async () => {
  const btn = $('#signin-btn');
  btn.disabled = true;
  btn.textContent = 'Opening X…';
  try {
    const r = await bg({ type: 'auth:start' });
    await renderSigned(r.user);
  } catch (err) {
    btn.disabled = false;
    btn.textContent = 'Connect X';
    alert('Sign-in failed: ' + err.message);
  }
});

$('#link-web-btn').addEventListener('click', async () => {
  const btn = $('#link-web-btn');
  btn.disabled = true;
  btn.textContent = 'Linking…';
  try {
    const r = await bg({ type: 'auth:link-via-web' });
    await renderSigned(r.user);
  } catch (err) {
    btn.disabled = false;
    btn.textContent = 'Use this account';
    alert('Link failed: ' + err.message);
  }
});

$('#connect-different-btn').addEventListener('click', async () => {
  const btn = $('#connect-different-btn');
  btn.disabled = true;
  try {
    const r = await bg({ type: 'auth:start' });
    await renderSigned(r.user);
  } catch (err) {
    btn.disabled = false;
    alert('Sign-in failed: ' + err.message);
  }
});

$('#signout-btn').addEventListener('click', async () => {
  await bg({ type: 'auth:logout' });
  show('state-anon');
});

$('#onb-signout-btn').addEventListener('click', async () => {
  await bg({ type: 'auth:logout' });
  show('state-anon');
});

$('#change-addr-btn').addEventListener('click', () => {
  if (cachedUser) renderOnboardAddress(cachedUser, 'edit');
});

$('#addr-cancel-btn').addEventListener('click', () => {
  if (cachedUser) renderSigned(cachedUser);
});

$('#addr-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  const addr = $('#kaspa-address').value.trim();
  const btn = $('#addr-save-btn');
  const err = $('#addr-error');
  const originalLabel = btn.textContent;
  err.style.display = 'none';
  err.textContent = '';
  btn.disabled = true;
  btn.textContent = addrMode === 'edit' ? 'Updating…' : 'Saving…';
  try {
    const msgType = addrMode === 'edit' ? 'users:update-address' : 'users:save-address';
    const r = await bg({ type: msgType, address: addr });
    await renderSigned(r.user);
  } catch (e) {
    err.textContent = e.message || 'Save failed.';
    err.style.display = '';
    btn.disabled = false;
    btn.textContent = originalLabel;
  }
});

init();
