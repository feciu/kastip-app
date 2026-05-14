<?php
declare(strict_types=1);

namespace KasTip\Web;

final class Privacy
{
    public static function render(): void
    {
        $body = <<<'HTML'
<h2>Summary in plain English</h2>
<ul>
  <li><strong>We never see your private keys.</strong> Every transaction is signed by your own wallet (Kasware, Kastle, Kaspium, etc.) — KasTip's backend has no way to spend your KAS.</li>
  <li><strong>We never read your tweets, DMs, or timeline.</strong> The only X API call we make is <code>GET /2/users/me</code> during sign-in, to confirm you own the X handle.</li>
  <li><strong>What we store</strong> is the minimum needed to make tipping work: your X handle, display name, avatar URL, and the Kaspa receiving address you tell us.</li>
  <li><strong>You can delete</strong> your KasTip account by emailing us — see "Your rights" below.</li>
</ul>

<h2>1. Who runs this</h2>
<p>KasTip is an independent project operated by an individual developer (contact <a href="mailto:fecpiotr@gmail.com">fecpiotr@gmail.com</a>). The service is hosted in Germany (Hetzner Cloud, Falkenstein / Helsinki). KasTip is not affiliated with the Kaspa Foundation, X Corp, or any wallet provider.</p>

<h2>2. What data we collect</h2>

<h3>From your X account (via OAuth 2.0)</h3>
<ul>
  <li><strong>Numeric X user ID</strong> (e.g. <code>2008951886578348032</code>) — primary key for your KasTip account.</li>
  <li><strong>X handle</strong> (e.g. <code>kastipapp</code>) — to display "tipped @yourhandle" UI.</li>
  <li><strong>Display name</strong> (e.g. "KasTip") and <strong>avatar URL</strong> — to render your profile in the dashboard and on <code>/u/{handle}</code> public pages.</li>
</ul>
<p>Scope requested: <code>tweet.read users.read</code> (the minimum X allows for <code>/users/me</code>). We do not read or store any tweet content, follower lists, DMs, or any other timeline data.</p>

<h3>From you directly</h3>
<ul>
  <li><strong>Kaspa receiving address</strong> — public information, the address where tips arrive. We validate it via the public api.kaspa.org indexer before storing.</li>
  <li><strong>Auto-reply preference</strong> — a single boolean (on/off).</li>
</ul>

<h3>Automatic, technical</h3>
<ul>
  <li><strong>Session tokens</strong> — 32-byte random hex stored as HTTPOnly cookie (web) or in <code>chrome.storage.local</code> (extension). 30-day expiry.</li>
  <li><strong>IP address</strong> — read from the <code>CF-Connecting-IP</code> header (we sit behind Cloudflare). Used only for rate-limiting (anti-spam); not stored in plain text. We store a SHA-256 hash that's tied to the rate-limit window and discarded.</li>
  <li><strong>Tip records</strong> — for every tip you send or receive: sender/receiver handles, Kaspa addresses, amount, optional tweet URL, optional short message (≤ 280 chars), transaction ID, timestamp. Used to populate your dashboard and to settle disputes.</li>
  <li><strong>Invitations</strong> — when you try to tip someone who hasn't registered yet, we record their handle plus the intended amount (info-only — no funds are escrowed) so we can issue an invite link.</li>
</ul>

<h3>What we explicitly do NOT collect</h3>
<ul>
  <li>Your X password or email address.</li>
  <li>Any Kaspa wallet private key, mnemonic, or seed.</li>
  <li>Tweet content, DMs, follower or following lists.</li>
  <li>Cross-site tracking cookies. No third-party analytics on KasTip pages.</li>
  <li>Marketing/advertising identifiers.</li>
</ul>

<h2>3. How we use the data</h2>
<p>Only to provide the tipping service:</p>
<ul>
  <li>Authenticate your sessions across the web app and browser extension.</li>
  <li>Look up Kaspa addresses by X handle (your handle → your address) so other users can tip you.</li>
  <li>Verify on-chain transactions against your pending tip records.</li>
  <li>Show you your tip history on the dashboard.</li>
  <li>Prevent spam via rate limits.</li>
</ul>
<p>We do not sell or rent any of this data. We do not share it with advertisers.</p>

<h2>4. What's public on-chain (out of our control)</h2>
<p>The Kaspa blockchain is a public ledger. Every tip is a real on-chain transaction visible to anyone via block explorers (<a href="https://kaspa.stream" target="_blank" rel="noopener">kaspa.stream</a>, <a href="https://explorer.kaspa.org" target="_blank" rel="noopener">explorer.kaspa.org</a>). The following are publicly observable on-chain and we cannot redact them:</p>
<ul>
  <li>Sender and receiver Kaspa addresses.</li>
  <li>Amounts.</li>
  <li>Timestamps and TX IDs.</li>
  <li>For tips sent via Kasware or Kastle: the payload field which includes our internal tip ID (e.g. <code>kastip:v1:tip:42:1730000000:abc12345</code>) — this links the transaction to a specific tip intent.</li>
</ul>
<p>If you want privacy on-chain, wait for stealth address support which is on the Kaspa roadmap (post-Toccata hardfork).</p>

<h2>5. Cookies and similar technologies</h2>
<p>We use one cookie: <code>kastip_session</code>, HTTPOnly + Secure + SameSite=Lax, set when you sign in on the web app. It contains nothing but a random session token. 30-day expiry. Clearing it logs you out.</p>
<p>The browser extension stores its session token in <code>chrome.storage.local</code>, not in cookies. The extension also reads (with your install-time permission) the <code>kastip_session</code> cookie to offer one-click "use my web account" linking — this is local-only and never sent to any third party.</p>

<h2>6. Third parties we depend on</h2>
<ul>
  <li><strong>X (Twitter)</strong> — for OAuth 2.0 sign-in and for the public X profile we display. Their <a href="https://x.com/en/privacy" target="_blank" rel="noopener">Privacy Policy</a>.</li>
  <li><strong>Cloudflare</strong> — CDN and TLS termination for kastip.app. <a href="https://www.cloudflare.com/privacypolicy/" target="_blank" rel="noopener">Privacy Policy</a>.</li>
  <li><strong>Hetzner</strong> (DE) — physical hosting. <a href="https://www.hetzner.com/legal/privacy-policy/" target="_blank" rel="noopener">Privacy Policy</a>.</li>
  <li><strong>api.kaspa.org</strong> — public Kaspa indexer used to validate addresses and verify transactions. Operated by the Kaspa community.</li>
  <li><strong>api.qrserver.com</strong> — referenced from the kastip.app landing page only (to render a "Support KasTip" QR). The extension itself uses bundled <code>qrcodejs</code> and does not call this service.</li>
  <li><strong>Kaspa wallet providers</strong> (Kasware, Kastle, etc.) — when you click "Send tip" the request is handled by your installed wallet. KasTip does not see your wallet account contents.</li>
</ul>

<h2>7. Data retention</h2>
<ul>
  <li>Tip records and user profiles: retained while your account exists.</li>
  <li>Sessions: 30 days, then garbage-collected.</li>
  <li>OAuth state rows: 10 minutes (one-time use during sign-in).</li>
  <li>Rate-limit buckets: a few hours (rolling windows).</li>
  <li>Cancelled / expired tip intents: kept indefinitely for audit but not used in matching after 30 minutes.</li>
</ul>

<h2>8. Your rights (GDPR / global)</h2>
<p>You have the right to:</p>
<ul>
  <li><strong>Access</strong> — request a copy of all data we have about you.</li>
  <li><strong>Correction</strong> — fix wrong data (you can change your Kaspa address yourself from the dashboard settings).</li>
  <li><strong>Deletion</strong> — request full account deletion. Note: on-chain transactions are permanent and cannot be removed.</li>
  <li><strong>Portability</strong> — get your data in a machine-readable format.</li>
  <li><strong>Object / withdraw consent</strong> — by deleting your account.</li>
</ul>
<p>To exercise any of these rights, email <a href="mailto:fecpiotr@gmail.com">fecpiotr@gmail.com</a>. We aim to respond within 14 days.</p>

<h2>9. Security</h2>
<p>We use industry-standard practices: TLS 1.2+ everywhere, HTTPOnly + Secure cookies, OAuth 2.0 + PKCE for X sign-in, parameterised SQL queries, secrets stored outside the document root with restricted file permissions. Hot wallet keys are not stored on the server because we are non-custodial — there are no funds for an attacker to steal from KasTip's infrastructure.</p>

<h2>10. International transfers</h2>
<p>Our backend is hosted in the EU (Germany). If you access KasTip from outside the EU, your data is transferred to and processed in the EU under the safeguards of the GDPR.</p>

<h2>11. Children</h2>
<p>KasTip is not intended for users under the age of 18 (or the age of majority in your jurisdiction, if higher). We do not knowingly collect data from minors.</p>

<h2>12. Changes to this policy</h2>
<p>If we materially change what we collect or how we use it, we will update this page and announce the change via <a href="https://x.com/kastipapp" target="_blank" rel="noopener">@kastipapp</a> or on the landing page. The "Last updated" date at the top of this page tells you when the current version was published.</p>

<h2>13. Contact</h2>
<p>Privacy questions, data-subject access requests, or security disclosures: <a href="mailto:fecpiotr@gmail.com">fecpiotr@gmail.com</a>.</p>
HTML;

        StaticPage::render(
            'Privacy Policy',
            'What we collect, why, and how to make us delete it.',
            $body,
            '2026-05-14'
        );
    }
}
