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
  ['state-anon', 'state-signed', 'state-loading'].forEach((id) => {
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

async function renderSigned(user) {
  show('state-signed');
  $('#avatar').src = user.x_avatar_url || '';
  $('#display-name').textContent = user.x_display_name || ('@' + user.x_username);
  $('#handle').textContent = '@' + user.x_username;
  $('#stat-recv').textContent = fmtKas(user.total_received_kas);
  $('#stat-sent').textContent = fmtKas(user.total_sent_kas);
  $('#warn-onboard').style.display = user.needs_address ? '' : 'none';
  await checkPageStatus();
}

async function init() {
  show('state-loading');
  try {
    const status = await bg({ type: 'auth:status' });
    if (!status.signedIn) {
      show('state-anon');
      return;
    }
    if (status.user) {
      await renderSigned(status.user);
    } else {
      // Refresh from server
      const r = await bg({ type: 'auth:refresh-user' });
      await renderSigned(r.user);
    }
  } catch (err) {
    show('state-anon');
    console.warn('[KasTip popup] init error:', err.message);
  }
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

$('#signout-btn').addEventListener('click', async () => {
  await bg({ type: 'auth:logout' });
  show('state-anon');
});

init();
