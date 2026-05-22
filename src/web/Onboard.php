<?php
declare(strict_types=1);

namespace KasTip\Web;

use KasTip\App;
use KasTip\Auth\Session;

/**
 * GET /onboard/address
 *
 * Server-rendered page after first sign-in. Asks for Kaspa receiving address.
 *
 *   no session       → 302 to /api/auth/x/start?from=/onboard/address
 *   has address      → 302 to /  (no need to onboard)
 *   no address yet   → render form
 */
final class Onboard
{
    public static function renderAddressForm(): void
    {
        $session = Session::current();
        if ($session === null) {
            header('Location: /api/auth/x/start?from=' . urlencode('/onboard/address'), true, 302);
            exit;
        }

        $stmt = App::db()->prepare("
            SELECT x_username, x_display_name, x_avatar_url, kaspa_address
            FROM users
            WHERE id = :id
        ");
        $stmt->execute(['id' => $session['user_id']]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$user) {
            Session::destroy($session['session_token']);
            header('Location: /', true, 302);
            exit;
        }

        if ($user['kaspa_address'] !== '') {
            header('Location: /', true, 302);
            exit;
        }

        self::render($user);
    }

    private static function render(array $user): void
    {
        $username = htmlspecialchars($user['x_username'], ENT_QUOTES, 'UTF-8');
        $displayName = htmlspecialchars($user['x_display_name'] ?? '', ENT_QUOTES, 'UTF-8');
        $avatarUrl = htmlspecialchars($user['x_avatar_url'] ?? '', ENT_QUOTES, 'UTF-8');

        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KasTip — set your Kaspa address</title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{height:100%}
  body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:#0d0f1a;color:#e8eaed;
    display:flex;align-items:center;justify-content:center;
    min-height:100vh;padding:2rem;
  }
  .wrap{max-width:520px;width:100%}
  .logo{
    font-size:2.5rem;font-weight:800;letter-spacing:-0.04em;
    background:linear-gradient(135deg,#49e9c9 0%,#2bb89c 100%);
    -webkit-background-clip:text;background-clip:text;color:transparent;
    text-align:center;margin-bottom:.5rem;
  }
  .greeting{
    display:flex;align-items:center;gap:.75rem;
    margin:2rem 0 1rem;padding:1rem;
    background:rgba(73,233,201,.06);
    border:1px solid rgba(73,233,201,.2);
    border-radius:12px;
  }
  .greeting img{width:48px;height:48px;border-radius:50%}
  .greeting .meta{font-size:.95rem}
  .greeting .meta strong{display:block}
  .greeting .meta span{color:#a8b1c2;font-size:.85rem}
  h1{font-size:1.4rem;font-weight:600;margin-bottom:.5rem}
  p.lead{color:#a8b1c2;line-height:1.5;margin-bottom:1.5rem;font-size:.95rem}
  label{display:block;font-size:.85rem;color:#a8b1c2;margin-bottom:.4rem}
  input[type=text]{
    width:100%;padding:.85rem 1rem;font-family:ui-monospace,SF Mono,Monaco,monospace;
    font-size:.9rem;background:#1a1d2e;border:1px solid #2a2f44;border-radius:8px;
    color:#e8eaed;outline:none;
  }
  input[type=text]:focus{border-color:#49e9c9}
  input[type=text]::placeholder{color:#5a6378}
  button{
    width:100%;padding:.95rem 1rem;margin-top:1rem;
    background:linear-gradient(135deg,#49e9c9 0%,#2bb89c 100%);
    border:none;border-radius:8px;color:#0d0f1a;font-weight:600;font-size:1rem;
    cursor:pointer;transition:transform .1s;
  }
  button:hover{transform:translateY(-1px)}
  button:disabled{opacity:.5;cursor:not-allowed;transform:none}
  .hint{margin-top:1rem;font-size:.8rem;color:#5a6378}
  .error{
    margin-top:1rem;padding:.75rem 1rem;
    background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);
    border-radius:8px;color:#fca5a5;font-size:.9rem;display:none;
  }
  .error.show{display:block}
</style>
</head>
<body>
<div class="wrap">
  <div class="logo">KasTip</div>

  <div class="greeting">
    <?php if ($avatarUrl !== ''): ?><img src="<?= $avatarUrl ?>" alt="" referrerpolicy="no-referrer"><?php endif; ?>
    <div class="meta">
      <strong>Welcome, <?= $displayName !== '' ? $displayName : '@' . $username ?>!</strong>
      <span>@<?= $username ?> — signed in via X</span>
    </div>
  </div>

  <h1>One last step — your Kaspa address</h1>
  <p class="lead">Tips will arrive directly here. KasTip never holds your KAS — every transaction goes peer-to-peer between wallets.</p>

  <form id="addr-form">
    <label for="kaspa-address">Kaspa receiving address</label>
    <input
      type="text"
      id="kaspa-address"
      name="kaspa_address"
      placeholder="kaspa:qpz..."
      autocomplete="off"
      autocapitalize="none"
      autocorrect="off"
      spellcheck="false"
      required
    >
    <button type="submit" id="submit-btn">Save and continue</button>
    <div class="error" id="error"></div>
  </form>

  <p class="hint">Don't have a Kaspa wallet yet? Install <a href="https://kasware.xyz" target="_blank" rel="noopener" style="color:#49e9c9">Kasware</a> or <a href="https://chromewebstore.google.com/detail/kastle/oambclflhjfppdmkghokjmpppmaebego" target="_blank" rel="noopener" style="color:#49e9c9">Kastle</a>, create a wallet, and copy your receiving address here.</p>
</div>

<script>
(function(){
  const form = document.getElementById('addr-form');
  const input = document.getElementById('kaspa-address');
  const btn = document.getElementById('submit-btn');
  const err = document.getElementById('error');

  form.addEventListener('submit', async function(e){
    e.preventDefault();
    err.classList.remove('show');
    err.textContent = '';
    btn.disabled = true;
    btn.textContent = 'Saving…';

    try {
      const resp = await fetch('/api/users/register', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        credentials: 'same-origin',
        body: JSON.stringify({kaspa_address: input.value.trim()})
      });
      const data = await resp.json().catch(()=>({}));
      if (!resp.ok) {
        throw new Error(data.message || 'Save failed.');
      }
      // Success — go to dashboard (currently /, until Etap C builds it)
      window.location.href = '/';
    } catch(e){
      err.textContent = e.message || 'Something went wrong.';
      err.classList.add('show');
      btn.disabled = false;
      btn.textContent = 'Save and continue';
    }
  });
})();
</script>
</body>
</html>
        <?php
        exit;
    }
}
