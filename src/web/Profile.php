<?php
declare(strict_types=1);

namespace KasTip\Web;

use KasTip\App;

/**
 * GET /u/{handle}
 *
 * Public-facing profile page. Two distinct flows:
 *
 *   Registered receiver:
 *     - Shows avatar, display name, X handle, totals
 *     - "Tip via Kasware" button → opens modal with amount input → kasware.sendKaspa flow
 *     - QR fallback for users without Kasware (kaspa: URI rendered as QR via api.qrserver.com)
 *
 *   Unregistered receiver:
 *     - Shows invitation pitch
 *     - Connect X CTA — after OAuth + onboard, viewer's account is created
 *     - If ?invite=<token> in URL, marks invitations.clicked_at (viral tracking)
 *
 * Handle validation: only [a-z0-9_]{1,15} (X username constraints).
 */
final class Profile
{
    private const HANDLE_REGEX = '/^[a-z0-9_]{1,15}$/';

    public static function render(string $handle): void
    {
        $handle = strtolower(ltrim(trim($handle), '@'));
        if (!preg_match(self::HANDLE_REGEX, $handle)) {
            App::abort(404, 'No such handle.');
        }

        // Track invite click if present (best-effort).
        $inviteToken = isset($_GET['invite']) ? (string) $_GET['invite'] : '';
        if ($inviteToken !== '' && preg_match('/^[a-f0-9]{16,64}$/', $inviteToken)) {
            try {
                App::db()->prepare("
                    UPDATE invitations
                    SET clicked_at = COALESCE(clicked_at, NOW())
                    WHERE invite_token = :t AND invitee_x_username = :h
                ")->execute(['t' => $inviteToken, 'h' => $handle]);
            } catch (\Throwable $e) { /* non-fatal */ }
        }

        $stmt = App::db()->prepare("
            SELECT id, x_username, x_display_name, x_avatar_url, kaspa_address,
                   total_received_kas, tip_count_received, created_at
            FROM users WHERE x_username = :h LIMIT 1
        ");
        $stmt->execute(['h' => $handle]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($user && $user['kaspa_address'] !== '') {
            self::renderRegistered($user, $inviteToken);
        }

        self::renderUnregistered($handle, $inviteToken);
    }

    private static function renderRegistered(array $user, string $inviteToken): void
    {
        $username      = htmlspecialchars($user['x_username'], ENT_QUOTES, 'UTF-8');
        $displayName   = htmlspecialchars($user['x_display_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $avatar        = htmlspecialchars($user['x_avatar_url'] ?? '', ENT_QUOTES, 'UTF-8');
        $address       = $user['kaspa_address'];
        $totalReceived = (float) $user['total_received_kas'];
        $tipCount      = (int) $user['tip_count_received'];

        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>@<?= $username ?> — Tip on KasTip</title>
<meta name="description" content="Send KAS directly to @<?= $username ?> via KasTip. Non-custodial, peer-to-peer, no fees.">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{height:100%}
  body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:#0d0f1a;color:#e8eaed;min-height:100vh;
  }
  a{color:inherit;text-decoration:none}

  header{
    display:flex;align-items:center;justify-content:space-between;
    padding:1.25rem 1.5rem;max-width:1100px;margin:0 auto;
  }
  .logo{
    font-size:1.5rem;font-weight:800;letter-spacing:-0.03em;
    background:linear-gradient(135deg,#49e9c9 0%,#2bb89c 100%);
    -webkit-background-clip:text;background-clip:text;color:transparent;
  }

  main{max-width:540px;margin:2rem auto 4rem;padding:0 1.5rem;text-align:center}
  .avatar{width:96px;height:96px;border-radius:50%;margin:0 auto 1rem;border:3px solid #1f2335}
  .name{font-size:1.4rem;font-weight:700}
  .handle{color:#a8b1c2;margin-bottom:1.5rem}
  .stats{
    display:flex;justify-content:center;gap:2.5rem;margin:1.5rem 0 2rem;
  }
  .stat .v{font-size:1.5rem;font-weight:700;color:#49e9c9}
  .stat .l{font-size:.78rem;color:#5a6378;text-transform:uppercase;letter-spacing:.05em;margin-top:.25rem}

  .actions{display:flex;flex-direction:column;gap:.6rem;max-width:320px;margin:0 auto}
  .btn{
    padding:.95rem 1.5rem;border-radius:8px;font-weight:600;font-size:1rem;
    cursor:pointer;border:none;transition:transform .1s;
  }
  .btn:hover{transform:translateY(-1px)}
  .btn-primary{
    background:linear-gradient(135deg,#49e9c9 0%,#2bb89c 100%);
    color:#0d0f1a;
  }
  .btn-secondary{
    background:transparent;border:1px solid #2a2f44;color:#e8eaed;
  }
  .btn-secondary:hover{border-color:#49e9c9;color:#49e9c9}

  .addr-box{
    margin-top:2rem;padding:1rem;background:#161927;border:1px solid #1f2335;
    border-radius:8px;font-family:ui-monospace,Monaco,monospace;font-size:.78rem;
    word-break:break-all;color:#a8b1c2;
  }

  /* ─── tip modal ────────────────────────────── */
  .modal-overlay{
    position:fixed;inset:0;background:rgba(0,0,0,.7);backdrop-filter:blur(4px);
    display:none;align-items:center;justify-content:center;z-index:50;padding:1rem;
  }
  .modal-overlay.show{display:flex}
  .modal{
    background:#161927;border:1px solid #2a2f44;border-radius:14px;padding:1.5rem;
    max-width:420px;width:100%;
  }
  .modal h3{font-size:1.15rem;margin-bottom:.5rem}
  .modal .modal-sub{color:#a8b1c2;font-size:.9rem;margin-bottom:1.25rem}
  .modal label{display:block;font-size:.82rem;color:#a8b1c2;margin-bottom:.4rem;margin-top:.85rem}
  .modal input[type=number]{
    width:100%;padding:.7rem .85rem;font-size:1rem;
    background:#0d0f1a;border:1px solid #2a2f44;border-radius:6px;
    color:#e8eaed;outline:none;
  }
  .modal input:focus{border-color:#49e9c9}
  .quick-amounts{display:flex;gap:.4rem;margin-top:.5rem}
  .quick-amounts button{
    flex:1;padding:.5rem;background:transparent;border:1px solid #2a2f44;
    border-radius:5px;color:#a8b1c2;cursor:pointer;font-size:.85rem;
  }
  .quick-amounts button:hover{border-color:#49e9c9;color:#49e9c9}
  .modal-actions{display:flex;gap:.5rem;margin-top:1.25rem}
  .modal-actions button{flex:1}
  .modal-info{
    margin-top:1rem;padding:.7rem;background:rgba(73,233,201,.05);
    border:1px solid rgba(73,233,201,.15);border-radius:6px;
    font-size:.82rem;color:#a8b1c2;line-height:1.4;
  }
  .modal-error{
    margin-top:.85rem;padding:.65rem;border-radius:6px;
    background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);
    color:#fca5a5;font-size:.85rem;display:none;
  }
  .modal-error.show{display:block}
  .qr-wrap-modal{
    margin:1rem auto;padding:.7rem;background:#fff;border-radius:8px;
    width:fit-content;
  }
  .qr-wrap-modal img,.qr-wrap-modal canvas{display:block;width:200px;height:200px}
  .txid-form{margin-top:1rem;display:none}
  .txid-form.show{display:block}
</style>
</head>
<body>
<header>
  <a href="/" class="logo">KasTip</a>
</header>

<main>
  <?php if ($avatar !== ''): ?><img class="avatar" src="<?= $avatar ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
  <div class="name"><?= $displayName !== '' ? $displayName : '@' . $username ?></div>
  <div class="handle">@<?= $username ?></div>

  <div class="stats">
    <div class="stat">
      <div class="v"><?= number_format($totalReceived, 2) ?> KAS</div>
      <div class="l">received</div>
    </div>
    <div class="stat">
      <div class="v"><?= $tipCount ?></div>
      <div class="l">tips</div>
    </div>
  </div>

  <div class="actions">
    <button class="btn btn-primary" id="tip-btn">💰 Tip @<?= $username ?></button>
    <a href="https://x.com/<?= $username ?>" target="_blank" rel="noopener" class="btn btn-secondary">View on X</a>
  </div>

  <div class="addr-box" title="Receiving address">
    <?= htmlspecialchars($address, ENT_QUOTES, 'UTF-8') ?>
  </div>
</main>

<!-- Tip modal -->
<div class="modal-overlay" id="modal">
  <div class="modal">
    <h3>💰 Tip @<?= $username ?></h3>
    <div class="modal-sub">Sent peer-to-peer from your wallet. KasTip never holds funds.</div>

    <div id="modal-amount-pane">
      <label>Amount (KAS)</label>
      <input type="number" id="amount" min="0.5" step="0.1" value="5">
      <div class="quick-amounts">
        <button data-amt="1">1</button>
        <button data-amt="5">5</button>
        <button data-amt="10">10</button>
        <button data-amt="25">25</button>
        <button data-amt="50">50</button>
      </div>
      <div class="modal-info">📤 @<?= $username ?> receives <span id="amt-display">5</span> KAS<br>+ ~0.0001 KAS network fee paid by your wallet</div>
      <div class="modal-error" id="err"></div>
      <div class="modal-actions">
        <button class="btn btn-secondary" id="cancel-btn">Cancel</button>
        <button class="btn btn-primary" id="send-btn">Send via Kasware</button>
      </div>
      <div style="text-align:center;margin-top:.75rem"><a href="#" id="show-qr" style="font-size:.82rem;color:#a8b1c2">No Kasware? Show QR</a></div>
    </div>

    <div id="modal-qr-pane" style="display:none">
      <div class="modal-sub">Scan with any Kaspa wallet app.</div>
      <div class="qr-wrap-modal"><img id="qr-img" alt="" width="200" height="200"></div>
      <div style="font-size:.78rem;font-family:ui-monospace,monospace;color:#a8b1c2;word-break:break-all;background:#0d0f1a;padding:.6rem;border-radius:6px" id="qr-uri"></div>
      <div class="txid-form" id="txid-pane">
        <label>After sending, paste TXID to confirm:</label>
        <input type="text" id="txid" placeholder="abc123...">
        <button class="btn btn-primary" id="confirm-btn" style="margin-top:.65rem;width:100%">Confirm tip</button>
      </div>
      <div class="modal-actions">
        <button class="btn btn-secondary" id="qr-back-btn">Back</button>
      </div>
    </div>
  </div>
</div>

<script>
const HANDLE = <?= json_encode($username) ?>;
const RECEIVER_ADDR = <?= json_encode($address) ?>;
const SOMPI_PER_KAS = 100_000_000;

const $ = s => document.querySelector(s);
const showErr = m => { const e = $('#err'); e.textContent = m; e.classList.add('show'); };
const clearErr = () => { $('#err').classList.remove('show'); $('#err').textContent = ''; };

// ─── modal control ─────────────────────────────
$('#tip-btn').addEventListener('click', () => {
  $('#modal').classList.add('show');
  $('#modal-amount-pane').style.display = '';
  $('#modal-qr-pane').style.display = 'none';
  clearErr();
});
$('#cancel-btn').addEventListener('click', () => $('#modal').classList.remove('show'));
$('#qr-back-btn').addEventListener('click', () => {
  $('#modal-amount-pane').style.display = '';
  $('#modal-qr-pane').style.display = 'none';
});
$('#modal').addEventListener('click', e => { if (e.target.id === 'modal') $('#modal').classList.remove('show'); });

// ─── amount controls ────────────────────────
function getAmount(){ return parseFloat($('#amount').value || '0'); }
function refreshAmtDisplay(){ $('#amt-display').textContent = (Number.isFinite(getAmount()) ? getAmount() : '—'); }
$('#amount').addEventListener('input', refreshAmtDisplay);
document.querySelectorAll('.quick-amounts button').forEach(b => {
  b.addEventListener('click', () => { $('#amount').value = b.dataset.amt; refreshAmtDisplay(); });
});

// ─── tip flow (Kasware) ───────────────────────
$('#send-btn').addEventListener('click', async () => {
  clearErr();
  const amt = getAmount();
  if (!Number.isFinite(amt) || amt < 0.5) { showErr('Minimum tip is 0.5 KAS.'); return; }

  // Step 1: initiate (creates pending row, returns receiver_address + payload)
  let init;
  try {
    init = await fetch('/api/tips/initiate', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({receiver_handle: HANDLE, amount_kas: amt})
    }).then(async r => { const d = await r.json(); if (!r.ok) throw new Error(d.message || 'initiate failed'); return d; });
  } catch (e) {
    if (e.message && e.message.includes('Authentication')) {
      // Not signed in — redirect to OAuth, come back here
      window.location.href = '/api/auth/x/start?from=' + encodeURIComponent(window.location.pathname + window.location.search);
      return;
    }
    showErr(e.message); return;
  }

  // Step 2: ask Kasware to send
  if (!window.kasware) {
    showErr('Kasware wallet not detected. Use the QR fallback below.');
    return;
  }
  let txid;
  try {
    const accounts = await window.kasware.requestAccounts();
    if (!accounts || accounts.length === 0) throw new Error('Wallet not connected.');
    const sompi = Math.floor(amt * SOMPI_PER_KAS);
    txid = await window.kasware.sendKaspa(RECEIVER_ADDR, sompi, {payload: init.payload});
  } catch (e) {
    showErr('Wallet error: ' + (e.message || e));
    return;
  }

  // Step 3: confirm
  try {
    const conf = await fetch('/api/tips/confirm', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({tip_id: init.tip_id, txid})
    }).then(async r => { const d = await r.json(); if (!r.ok) throw new Error(d.message || 'confirm failed'); return d; });
    alert('Tip sent! Status: ' + conf.status + (conf.note ? ' (' + conf.note + ')' : ''));
    $('#modal').classList.remove('show');
  } catch (e) {
    showErr('Sent but confirm failed: ' + e.message + '. Your TX should still propagate.');
  }
});

// ─── QR fallback ─────────────────────────────
$('#show-qr').addEventListener('click', async (e) => {
  e.preventDefault();
  clearErr();
  const amt = getAmount();
  if (!Number.isFinite(amt) || amt < 0.5) { showErr('Set amount first (min 0.5 KAS).'); return; }

  // Initiate so we have a tip_id ready when user comes back with txid
  let init;
  try {
    init = await fetch('/api/tips/initiate', {
      method:'POST', credentials:'same-origin',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({receiver_handle: HANDLE, amount_kas: amt})
    }).then(async r => { const d = await r.json(); if (!r.ok) throw new Error(d.message || 'initiate failed'); return d; });
  } catch (e) {
    if (e.message && e.message.includes('Authentication')) {
      window.location.href = '/api/auth/x/start?from=' + encodeURIComponent(window.location.pathname + window.location.search);
      return;
    }
    showErr(e.message); return;
  }

  $('#modal-amount-pane').style.display = 'none';
  $('#modal-qr-pane').style.display = '';
  $('#qr-uri').textContent = init.qr_uri;
  $('#txid-pane').classList.add('show');
  $('#qr-img').src = 'https://api.qrserver.com/v1/create-qr-code/?data=' + encodeURIComponent(init.qr_uri) + '&size=200x200&margin=0&color=0d0f1a&bgcolor=ffffff';
  $('#confirm-btn').onclick = async () => {
    const txid = $('#txid').value.trim();
    if (!/^[a-f0-9]{32,128}$/i.test(txid)) { showErr('Bad TXID format.'); return; }
    try {
      const conf = await fetch('/api/tips/confirm', {
        method:'POST', credentials:'same-origin',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({tip_id: init.tip_id, txid})
      }).then(async r => { const d = await r.json(); if (!r.ok) throw new Error(d.message || 'confirm failed'); return d; });
      alert('Tip confirmed! Status: ' + conf.status);
      $('#modal').classList.remove('show');
    } catch (e) { showErr(e.message); }
  };
});
</script>

</body>
</html>
        <?php
        exit;
    }

    private static function renderUnregistered(string $handle, string $inviteToken): void
    {
        $hasInvite = $inviteToken !== '';
        $cancelled = isset($_GET['cancelled']);
        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>@<?= htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') ?> on KasTip — claim your tips</title>
<meta name="description" content="Someone wants to tip @<?= htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') ?> via KasTip. Sign in with X and add a Kaspa address to claim future tips.">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{height:100%}
  body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:#0d0f1a;color:#e8eaed;
    display:flex;flex-direction:column;min-height:100vh;
  }
  a{color:inherit;text-decoration:none}
  header{
    display:flex;align-items:center;justify-content:space-between;
    padding:1.25rem 1.5rem;max-width:1100px;width:100%;margin:0 auto;
  }
  .logo{
    font-size:1.5rem;font-weight:800;letter-spacing:-0.03em;
    background:linear-gradient(135deg,#49e9c9 0%,#2bb89c 100%);
    -webkit-background-clip:text;background-clip:text;color:transparent;
  }
  main{
    flex:1;display:flex;align-items:center;justify-content:center;padding:2rem 1.5rem;
  }
  .invite-card{
    max-width:520px;text-align:center;
    padding:2.5rem 1.75rem;background:#161927;border:1px solid #1f2335;border-radius:16px;
  }
  .badge{
    display:inline-block;padding:.3rem .85rem;
    background:rgba(73,233,201,.08);border:1px solid rgba(73,233,201,.25);
    border-radius:99px;color:#49e9c9;font-size:.8rem;margin-bottom:1.25rem;
  }
  h1{font-size:1.75rem;font-weight:700;letter-spacing:-0.02em;margin-bottom:.85rem;line-height:1.2}
  h1 .handle{
    background:linear-gradient(135deg,#49e9c9 0%,#2bb89c 100%);
    -webkit-background-clip:text;background-clip:text;color:transparent;
  }
  p.lead{color:#a8b1c2;margin-bottom:2rem;line-height:1.55}
  .btn{
    display:inline-block;padding:.95rem 1.6rem;
    background:linear-gradient(135deg,#49e9c9 0%,#2bb89c 100%);
    border:none;border-radius:8px;color:#0d0f1a;font-weight:600;font-size:1rem;
    cursor:pointer;text-decoration:none;
    transition:transform .1s;
  }
  .btn:hover{transform:translateY(-1px)}
  .smalls{margin-top:1.5rem;font-size:.82rem;color:#5a6378;line-height:1.5}
  .smalls strong{color:#a8b1c2}
</style>
</head>
<body>
<header>
  <a href="/" class="logo">KasTip</a>
</header>

<main>
  <div class="invite-card">
    <?php if ($hasInvite): ?>
      <span class="badge">📬 Invitation pending</span>
      <h1>Someone tried to tip<br><span class="handle">@<?= htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') ?></span></h1>
      <p class="lead">KasTip is a non-custodial way for X users to receive Kaspa (KAS) cryptocurrency. To claim this and future tips, link your X account and provide a Kaspa receiving address.</p>
    <?php else: ?>
      <span class="badge">⚡ Not registered yet</span>
      <h1><span class="handle">@<?= htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') ?></span><br>doesn't have KasTip</h1>
      <p class="lead">If this is your X handle, you can register in 30 seconds and start receiving KAS tips from anyone on X.</p>
    <?php endif; ?>

    <?php if ($cancelled): ?>
      <div style="margin-bottom:1.25rem;padding:.75rem 1rem;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);border-radius:8px;color:#fcd34d;font-size:.88rem">
        Sign-in cancelled. Try again whenever you're ready.
      </div>
    <?php endif; ?>

    <a href="/api/auth/x/start?from=<?= urlencode('/u/' . $handle) ?>" class="btn">Connect X to claim</a>

    <div class="smalls" style="margin-top:1rem;padding:.85rem 1rem;background:rgba(73,233,201,.06);border:1px solid rgba(73,233,201,.2);border-radius:8px;text-align:left">
      <strong style="color:#49e9c9">⚠️ Make sure you're logged into X as @<?= htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') ?></strong><br>
      X uses your current browser session — it can't show an account picker. If you're logged in as a different handle, either:
      <ul style="margin-top:.5rem;padding-left:1.25rem;line-height:1.7">
        <li>Sign out of <a href="https://x.com/logout" target="_blank" rel="noopener" style="color:#49e9c9">x.com/logout</a> and sign in as @<?= htmlspecialchars($handle, ENT_QUOTES, 'UTF-8') ?>, then come back here</li>
        <li>Or open this page in a private/incognito tab and sign into X there</li>
      </ul>
    </div>

    <div class="smalls">
      <strong>How it works:</strong> sign in with X → add your Kaspa address → done. We never hold funds; tips arrive directly to your wallet.
    </div>
  </div>
</main>

</body>
</html>
        <?php
        exit;
    }
}
