<?php
declare(strict_types=1);

namespace KasTip\Web;

use KasTip\App;
use KasTip\Auth\Session;

/**
 * GET /  — public landing page.
 *
 * If user is signed-in with address → 302 to /dashboard.
 * If signed-in without address → 302 to /onboard/address.
 * Otherwise render marketing landing.
 */
final class Landing
{
    public static function render(): void
    {
        $session = Session::current();
        if ($session !== null) {
            $stmt = App::db()->prepare("SELECT kaspa_address FROM users WHERE id = :id");
            $stmt->execute(['id' => $session['user_id']]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                header('Location: ' . ($row['kaspa_address'] === '' ? '/onboard/address' : '/dashboard'), true, 302);
                exit;
            }
        }
        self::renderHtml();
    }

    private static function renderHtml(): void
    {
        $donate = (string) App::config('donate_address', '');
        $hasDonate = $donate !== '';
        // kaspa: URI for QR + wallet click-through. Single-output, no amount specified.
        $donateUri = $hasDonate ? $donate . '?label=' . rawurlencode('Support KasTip') : '';

        header('Content-Type: text/html; charset=utf-8');
        ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KasTip — Tip Kaspa to anyone on X with one click</title>
<meta name="description" content="Browser extension that injects a tip button on every X profile. Send KAS directly to creators — non-custodial, peer-to-peer, no fees.">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  html,body{height:100%}
  body{
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;
    background:#0d0f1a;color:#e8eaed;line-height:1.5;
  }
  a{color:inherit;text-decoration:none}
  ::selection{background:rgba(73,233,201,.3)}

  /* ─── header ─────────────────────────────────── */
  header{
    display:flex;align-items:center;justify-content:space-between;
    padding:1.25rem 1.5rem;max-width:1100px;margin:0 auto;
  }
  .logo{
    font-size:1.5rem;font-weight:800;letter-spacing:-0.03em;
    background:linear-gradient(135deg,#49e9c9 0%,#2bb89c 100%);
    -webkit-background-clip:text;background-clip:text;color:transparent;
  }
  .nav-cta{
    padding:.55rem 1.1rem;border:1px solid #49e9c9;color:#49e9c9;
    border-radius:6px;font-size:.9rem;font-weight:500;
    transition:background .15s,color .15s;
  }
  .nav-cta:hover{background:#49e9c9;color:#0d0f1a}

  /* ─── hero ─────────────────────────────────── */
  .hero{
    max-width:780px;margin:4rem auto 5rem;padding:0 1.5rem;text-align:center;
  }
  .hero h1{
    font-size:clamp(2.2rem,5.5vw,3.6rem);font-weight:800;letter-spacing:-0.03em;
    line-height:1.1;margin-bottom:1.25rem;
  }
  .hero h1 .accent{
    background:linear-gradient(135deg,#49e9c9 0%,#2bb89c 100%);
    -webkit-background-clip:text;background-clip:text;color:transparent;
  }
  .hero p.lead{
    font-size:1.15rem;color:#a8b1c2;max-width:560px;margin:0 auto 2rem;
  }
  .cta-row{display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap}
  .btn-primary{
    padding:.85rem 1.6rem;
    background:linear-gradient(135deg,#49e9c9 0%,#2bb89c 100%);
    border:none;border-radius:8px;color:#0d0f1a;font-weight:600;font-size:1rem;
    cursor:pointer;text-decoration:none;display:inline-block;
    transition:transform .1s;
  }
  .btn-primary:hover{transform:translateY(-1px)}
  .btn-secondary{
    padding:.85rem 1.6rem;background:transparent;border:1px solid #2a2f44;
    border-radius:8px;color:#e8eaed;font-weight:500;cursor:pointer;
    transition:border-color .15s,color .15s;
  }
  .btn-secondary:hover{border-color:#49e9c9;color:#49e9c9}

  .badge{
    display:inline-block;padding:.3rem .85rem;
    background:rgba(73,233,201,.08);border:1px solid rgba(73,233,201,.25);
    border-radius:99px;color:#49e9c9;font-size:.8rem;margin-bottom:1.25rem;
  }

  /* ─── how-it-works ─────────────────────────── */
  section.how{
    max-width:1000px;margin:0 auto 5rem;padding:0 1.5rem;
  }
  section.how h2{
    text-align:center;font-size:1.8rem;font-weight:700;margin-bottom:2.5rem;
  }
  .steps{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:1rem;
  }
  .step{
    background:#161927;border:1px solid #1f2335;border-radius:14px;padding:1.5rem;
    transition:border-color .15s,transform .15s;
  }
  .step:hover{border-color:#49e9c9}
  .step-num{
    display:inline-block;width:32px;height:32px;line-height:32px;text-align:center;
    background:rgba(73,233,201,.1);color:#49e9c9;border-radius:50%;
    font-weight:600;font-size:.95rem;margin-bottom:.85rem;
  }
  .step h3{font-size:1.05rem;margin-bottom:.5rem}
  .step p{color:#a8b1c2;font-size:.92rem}

  /* ─── why ─────────────────────────────────── */
  section.why{
    max-width:880px;margin:0 auto 5rem;padding:0 1.5rem;
  }
  section.why h2{text-align:center;font-size:1.8rem;font-weight:700;margin-bottom:2rem}
  .feats{
    display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1rem;
  }
  .feat{
    padding:1.25rem;background:#0d0f1a;
  }
  .feat-icon{font-size:1.4rem;margin-bottom:.5rem}
  .feat h4{font-size:.95rem;margin-bottom:.3rem;color:#49e9c9}
  .feat p{font-size:.85rem;color:#a8b1c2}

  /* ─── support ─────────────────────────────── */
  section.support{
    max-width:760px;margin:0 auto 5rem;padding:2rem 1.5rem;
    background:#161927;border:1px solid #1f2335;border-radius:14px;
  }
  section.support .inner{
    display:grid;grid-template-columns:1fr;gap:1.5rem;
    align-items:center;
  }
  @media(min-width:640px){
    section.support .inner{grid-template-columns:1fr auto}
  }
  section.support h2{font-size:1.4rem;margin-bottom:.5rem}
  section.support p{color:#a8b1c2;font-size:.92rem;margin-bottom:1rem}
  .donate-addr{
    display:flex;gap:.5rem;align-items:center;
    padding:.65rem .85rem;background:#0d0f1a;border:1px solid #2a2f44;
    border-radius:6px;font-family:ui-monospace,Monaco,monospace;
    font-size:.78rem;word-break:break-all;
  }
  .donate-addr code{flex:1;color:#a8b1c2}
  .donate-addr button{
    background:transparent;border:1px solid #2a2f44;color:#a8b1c2;
    padding:.25rem .6rem;border-radius:4px;font-size:.75rem;cursor:pointer;flex-shrink:0;
  }
  .donate-addr button:hover{border-color:#49e9c9;color:#49e9c9}
  .qr-wrap{
    background:#fff;padding:.5rem;border-radius:8px;
    display:flex;align-items:center;justify-content:center;
  }
  .qr-wrap img,.qr-wrap canvas{display:block;width:140px;height:140px}

  /* ─── footer ─────────────────────────────── */
  footer{
    text-align:center;padding:2rem 1.5rem 3rem;color:#5a6378;font-size:.85rem;
    border-top:1px solid #1f2335;
  }
  footer a{color:#a8b1c2;border-bottom:1px solid #3a4356}
  footer a:hover{color:#49e9c9;border-color:#49e9c9}
</style>
</head>
<body>

<header>
  <a href="/" class="logo">KasTip</a>
  <a href="/api/auth/x/start?from=%2Fdashboard" class="nav-cta">Connect X</a>
</header>

<section class="hero">
  <span class="badge">⚡ Built on Kaspa — fastest blockchain</span>
  <h1>Tip <span class="accent">Kaspa</span> to anyone on X<br>with one click.</h1>
  <p class="lead">Browser extension that adds a tip button next to every @handle. Send KAS directly between wallets — non-custodial, peer-to-peer, no fees.</p>
  <div class="cta-row">
    <a href="/api/auth/x/start?from=%2Fdashboard" class="btn-primary">Connect X to start</a>
    <a href="#how" class="btn-secondary">How it works</a>
  </div>
</section>

<section class="how" id="how">
  <h2>How it works</h2>
  <div class="steps">
    <div class="step">
      <div class="step-num">1</div>
      <h3>Connect X</h3>
      <p>Sign in with your X account so creators know who's tipping. We never read your tweets — only your public handle.</p>
    </div>
    <div class="step">
      <div class="step-num">2</div>
      <h3>Add your Kaspa address</h3>
      <p>This is where tips will arrive. We never hold funds; every tip goes peer-to-peer between sender and receiver wallets.</p>
    </div>
    <div class="step">
      <div class="step-num">3</div>
      <h3>Tip on X</h3>
      <p>Install the KasTip browser extension. A "💰 KasTip" button appears next to every handle. One click, one transaction.</p>
    </div>
  </div>
</section>

<section class="why">
  <h2>Why KasTip</h2>
  <div class="feats">
    <div class="feat">
      <div class="feat-icon">🛡️</div>
      <h4>Non-custodial</h4>
      <p>We never touch your KAS. Your wallet signs every transaction directly to the recipient.</p>
    </div>
    <div class="feat">
      <div class="feat-icon">⚡</div>
      <h4>Near-zero fees</h4>
      <p>Kaspa network fees are ~0.0001 KAS. KasTip itself takes nothing in MVP.</p>
    </div>
    <div class="feat">
      <div class="feat-icon">🌐</div>
      <h4>Wallet-agnostic</h4>
      <p>Works with Kasware out of the box, plus QR fallback for KSPR, Tangem, Kaspium and others.</p>
    </div>
    <div class="feat">
      <div class="feat-icon">🔓</div>
      <h4>Open ecosystem</h4>
      <p>Recipient doesn't even need an account — they get a viral invite link to claim future tips.</p>
    </div>
  </div>
</section>

<?php if ($hasDonate): ?>
<section class="support" id="support">
  <div class="inner">
    <div>
      <h2>Support KasTip</h2>
      <p>KasTip is a free, open-ish project. If you find it useful, a small KAS tip keeps it running. No fees, no lock-in — straight to a cold wallet.</p>
      <div class="donate-addr">
        <code id="donate-addr"><?= htmlspecialchars($donate, ENT_QUOTES, 'UTF-8') ?></code>
        <button id="copy-donate" type="button">Copy</button>
      </div>
    </div>
    <div class="qr-wrap">
      <canvas id="donate-qr" width="140" height="140"></canvas>
    </div>
  </div>
</section>
<?php endif; ?>

<footer>
  <p>
    KasTip — built on <a href="https://kaspa.org" target="_blank" rel="noopener">Kaspa</a>.
    <a href="/terms">Terms</a> ·
    <a href="/privacy">Privacy</a> ·
    <a href="https://x.com/kastipapp" target="_blank" rel="noopener">@kastipapp</a>
  </p>
</footer>

<?php if ($hasDonate): ?>
<script>
const DONATE_URI = <?= json_encode($donateUri) ?>;
const DONATE_ADDR = <?= json_encode($donate) ?>;

document.getElementById('copy-donate').addEventListener('click', () => {
  navigator.clipboard.writeText(DONATE_ADDR).then(() => {
    const btn = document.getElementById('copy-donate');
    const orig = btn.textContent;
    btn.textContent = 'Copied!';
    btn.style.color = '#49e9c9';
    btn.style.borderColor = '#49e9c9';
    setTimeout(() => { btn.textContent = orig; btn.style.color = ''; btn.style.borderColor = ''; }, 1500);
  });
});

// Tiny self-contained QR encoder (subset, version 4, ECC L). For MVP we use
// a public CDN-hosted minimal lib. To stay zero-deps we draw via a quick API:
// goqr.me serves QR PNGs but we want offline/CSP-friendly inline. Use a tiny
// inline implementation: kjua-style generator is overkill here; instead embed
// a dataURL-producing helper. For now use Google Chart API as a CSP-allowed CDN
// (we will switch to bundled qrcode.js when extension ships).
(function(){
  const canvas = document.getElementById('donate-qr');
  const ctx = canvas.getContext('2d');
  const img = new Image();
  img.crossOrigin = 'anonymous';
  // Use api.qrserver.com — CSP-permissive, no key needed, free tier.
  img.src = 'https://api.qrserver.com/v1/create-qr-code/?data=' + encodeURIComponent(DONATE_URI) + '&size=140x140&margin=0&color=0d0f1a&bgcolor=ffffff';
  img.onload = () => ctx.drawImage(img, 0, 0, 140, 140);
  img.onerror = () => {
    ctx.fillStyle = '#0d0f1a';
    ctx.font = '11px monospace';
    ctx.fillText('QR unavailable', 18, 70);
  };
})();
</script>
<?php endif; ?>

</body>
</html>
        <?php
        exit;
    }
}
