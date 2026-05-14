<?php
declare(strict_types=1);

namespace KasTip\Web;

final class Terms
{
    public static function render(): void
    {
        $body = <<<'HTML'
<h2>1. What KasTip is</h2>
<p>KasTip is a browser extension and companion web app at <a href="https://kastip.app">kastip.app</a> that helps users send Kaspa (KAS) cryptocurrency to other X (Twitter) users. KasTip is <strong>non-custodial</strong>: every transaction is signed by the user's own wallet and broadcast directly to the Kaspa network. KasTip never holds, escrows, or has access to user funds.</p>

<h2>2. Eligibility</h2>
<p>You may use KasTip if you are at least the age of majority in your jurisdiction and the use of cryptocurrency is legal where you live. KasTip is provided globally, but it is your responsibility to comply with your local laws and tax obligations.</p>

<h2>3. Accounts</h2>
<p>Creating a KasTip account requires signing in with your X (Twitter) account via OAuth 2.0. We store only your public X profile information (numeric user ID, handle, display name, avatar URL) and the Kaspa receiving address you provide. We never request, store, or have access to your X password.</p>
<p>You are responsible for the security of your X account credentials and your Kaspa wallet's private keys. <strong>If you lose your private keys, no one — including KasTip — can recover your funds.</strong></p>

<h2>4. Tipping mechanics</h2>
<ul>
  <li><strong>Tips are final.</strong> Once a transaction is broadcast on the Kaspa network it cannot be reversed by KasTip or any other party. Always double-check the recipient handle and amount before approving.</li>
  <li><strong>KasTip charges no service fee in MVP.</strong> 100% of the tipped amount (minus standard Kaspa network fees, currently ~0.0001 KAS per transaction) goes to the recipient.</li>
  <li><strong>Receiver registration.</strong> If the recipient X handle has no KasTip account, no transaction is sent. Instead, you receive an invitation link that the receiver can use to register and claim future tips.</li>
  <li><strong>Minimum tip amount: 0.5 KAS</strong> (anti-spam floor).</li>
</ul>

<h2>5. Wallets and third-party software</h2>
<p>KasTip integrates with third-party Kaspa wallet software, including but not limited to Kasware, Kastle, and any wallet that supports the standard <code>kaspa:</code> URI format. These wallets are independent products with their own terms and security models. KasTip is not responsible for losses, errors, or vulnerabilities in third-party wallets.</p>

<h2>6. Disclaimers</h2>
<p>THE KASTIP SERVICE IS PROVIDED "AS IS" AND "AS AVAILABLE" WITHOUT WARRANTIES OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING WITHOUT LIMITATION WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE, OR NON-INFRINGEMENT. The Kaspa network, X platform, and connected wallets are operated by third parties; KasTip does not guarantee their uptime, accuracy, or behaviour.</p>
<p>Cryptocurrency transactions are irreversible and may be subject to network congestion, fee market dynamics, or temporary indexer unavailability. KasTip is not liable for delays, mis-attribution, or losses caused by network conditions or wallet behaviour outside our control.</p>

<h2>7. Acceptable use</h2>
<p>You agree not to use KasTip to:</p>
<ul>
  <li>Send tips to or from accounts engaged in fraud, harassment, terrorism financing, money laundering, sanctions evasion, or other illegal activity.</li>
  <li>Spam, bot, or attempt to exploit KasTip's matching or invitation flows.</li>
  <li>Attempt to compromise KasTip's backend, extension, or other users' accounts.</li>
</ul>
<p>We reserve the right to deactivate accounts that violate these terms.</p>

<h2>8. Changes to these terms</h2>
<p>We may update these terms over time. Continued use of KasTip after changes constitutes acceptance. Material changes will be announced via the project's X account (<a href="https://x.com/kastipapp" target="_blank" rel="noopener">@kastipapp</a>) or on the KasTip landing page.</p>

<h2>9. Contact</h2>
<p>For questions, bug reports, or take-down requests, contact <a href="mailto:fecpiotr@gmail.com">fecpiotr@gmail.com</a> or open an issue at the project repository.</p>
HTML;

        StaticPage::render(
            'Terms of Service',
            'Non-custodial tipping — what you should know before using KasTip.',
            $body,
            '2026-05-14'
        );
    }
}
