<?php
declare(strict_types=1);

namespace KasTip\Web;

final class Support
{
    public static function render(): void
    {
        $body = <<<'HTML'
<p>Pick the channel that fits — all reach the maintainer.</p>

<h2>🐛 Found a bug or want to suggest a feature?</h2>
<p><strong>GitHub Issues</strong> is the fastest path. Other users see your report (and the fix), and the conversation stays public and searchable.</p>
<p>→ <a href="https://github.com/feciu/kastip-app/issues" target="_blank" rel="noopener">github.com/feciu/kastip-app/issues</a></p>

<h2>💬 Quick question or chat?</h2>
<p>DM or @-mention <strong>@kastipapp</strong> on X. Fastest for short questions, async response.</p>
<p>→ <a href="https://x.com/kastipapp" target="_blank" rel="noopener">x.com/kastipapp</a></p>

<h2>📧 Private / sensitive matters</h2>
<p>Email for anything that shouldn't be public — privacy requests, security disclosures, takedowns, business inquiries.</p>
<p>→ <a href="mailto:fecpiotr@gmail.com">fecpiotr@gmail.com</a></p>

<h2>🔒 Security disclosure</h2>
<p>If you find a vulnerability, please <strong>do not</strong> file a public GitHub issue. Email <a href="mailto:fecpiotr@gmail.com">fecpiotr@gmail.com</a> with subject prefix <code>[SECURITY]</code>. We aim to respond within 48 hours and coordinate disclosure responsibly.</p>

<h2>What information helps</h2>
<p>When reporting a bug, please include where you can:</p>
<ul>
  <li>Browser + version (e.g. Chrome 130 on macOS)</li>
  <li>Your X handle (so we can find related logs)</li>
  <li>Steps to reproduce</li>
  <li>Any error message or screenshot</li>
  <li>For tip-related issues: the tip ID (visible in the dashboard) or the transaction ID</li>
</ul>

<h2>Response times</h2>
<p>This is an indie project — usually a same-day or next-day response for GitHub and X. Email for sensitive matters: within 48 hours, longer if you catch us on a weekend.</p>

<h2>Common questions before you reach out</h2>

<h3>"My tip is stuck on 'pending' or 'waiting'."</h3>
<p>This usually means one of three things:</p>
<ul>
  <li><strong>Your wallet never broadcast the transaction.</strong> Check your wallet's outgoing transaction history. If there's no record, the wallet was closed/cancelled before the broadcast.</li>
  <li><strong>You sent a different amount than the dashboard shows.</strong> KasTip's matcher prefers exact-amount matches. If you sent <em>less</em> than expected, no match — tip stays pending until the 30-minute window expires.</li>
  <li><strong>The Kaspa indexer is temporarily slow.</strong> Real-time confirmation usually takes 1-10 seconds; in rare cases the indexer falls behind by minutes. Refresh the dashboard.</li>
</ul>

<h3>"I lost access to my Kaspa wallet — can KasTip recover my funds?"</h3>
<p>No. KasTip is non-custodial — we never have access to your private keys or seed phrase. If you lose your seed, nobody can recover your KAS. Always back up your seed phrase offline before depositing funds.</p>

<h3>"How do I change the address tips go to?"</h3>
<p>Sign in at <a href="https://kastip.app/dashboard">kastip.app/dashboard</a> → Settings tab → update the Kaspa receiving address. Future tips arrive at the new address. Past tips already sent are immutable on-chain.</p>

<h3>"Can I delete my account?"</h3>
<p>Yes. Email <a href="mailto:fecpiotr@gmail.com">fecpiotr@gmail.com</a> from the X account in question (or include the X handle and we'll verify via DM). We delete your profile, sessions, and tip records within 14 days. On-chain transactions remain on the Kaspa blockchain — that's outside our control.</p>
HTML;

        StaticPage::render(
            'Support',
            'Get help, report a bug, or contact the maintainer.',
            $body,
            '2026-05-14'
        );
    }
}
