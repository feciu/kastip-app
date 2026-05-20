# KasTip

**Tip Kaspa (KAS) to anyone on X with one click. Non-custodial, peer-to-peer, no fees.**

[![Chrome Web Store](https://img.shields.io/badge/Chrome%20Web%20Store-live-49e9c9)](https://chromewebstore.google.com/detail/kpipgcpkodogldocgpflfoigpemihcme)
[![Web app](https://img.shields.io/badge/Web-kastip.app-49e9c9)](https://kastip.app)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

KasTip adds a "💰 Tip" button next to every handle on X. One click → choose amount → approve in your wallet → KAS lands directly in the author's address. The KasTip server only coordinates — it never holds keys, never signs transactions, never takes a cut.

- **Live:** [kastip.app](https://kastip.app)
- **Chrome extension:** [kpipgcpkodogldocgpflfoigpemihcme](https://chromewebstore.google.com/detail/kpipgcpkodogldocgpflfoigpemihcme)
- **Follow:** [@KasTipApp](https://x.com/kastipapp)

## How to verify the security claims

This repo exists so you don't have to trust us — you can read the code. Quick pointers to the things people most often ask about:

| Claim | Where to verify |
|---|---|
| "We don't post on your behalf / read your DMs." | OAuth scopes requested: `src/auth/XOauth.php` (search `'scope'`) and `config/secrets.example.php`. Only `tweet.read users.read` — read-only. |
| "We don't hold custody of your KAS." | There is no signing code on the server. Tip transactions are built in the browser by your wallet (`extension/content/x-bridge.js`) and broadcast by it directly. The backend only watches the chain to mark tips as confirmed (`services/tx-watcher/index.js`). |
| "We don't see your X password." | We never touch it. OAuth 2.0 PKCE flow with X — see `src/auth/XOauth.php`. X handles the login UI on its own domain. |
| "We don't store anything we don't need." | Database schema: `migrations/schema.sql`. No tweet bodies, no DMs, no follower lists. Just: handle, display name, avatar URL, Kaspa address, tip records (sender/receiver/amount/txid/status). |
| "Tips are real on-chain transactions." | Every confirmed tip has a `txid` — paste it into [explorer.kaspa.org](https://explorer.kaspa.org) to verify independently. |

If you find anything that contradicts the above, please open an issue or DM [@KasTipApp](https://x.com/kastipapp) — security disclosures via email (`fecpiotr@gmail.com`, subject prefix `[SECURITY]`) are also welcome and acknowledged within 48h.

## Repository layout

```
kastip-app/
├── extension/             ← browser extension (MV3, Chrome + Firefox)
│   ├── manifest.json
│   ├── background.js      ← service worker
│   ├── content/           ← injected into x.com
│   ├── popup/             ← toolbar UI
│   ├── lib/               ← bundled qrcodejs (see lib/README.md)
│   ├── icons/
│   └── build.sh           ← package into .zip for store upload
│
├── public/                ← nginx doc root (only this is web-accessible)
│   └── index.php          ← single front-controller
│
├── src/                   ← PHP source (NOT in doc root)
│   ├── api/               ← /api/* endpoint handlers
│   ├── auth/              ← X OAuth (PKCE), session/bearer auth
│   ├── lib/               ← address validation, kaspa REST helpers
│   ├── models/            ← PDO query layer
│   └── web/               ← server-rendered pages (landing, dashboard, etc.)
│
├── services/
│   └── tx-watcher/        ← Node.js service: subscribes to Kaspa block events,
│                            matches incoming TXs to pending tips, marks confirmed
│
├── config/
│   ├── secrets.php        ← DB pass, X OAuth, etc. (chmod 640, NEVER commit)
│   └── secrets.example.php  ← template — copy and fill in
│
├── migrations/
│   └── schema.sql         ← initial DB schema
│
└── file_developer/        ← Chrome Web Store / AMO listing assets
```

## Self-hosting / development

Bare PHP 8.3 (no Composer, no external libs) + MariaDB 10.11+ + nginx + Node.js 20+ for the tx-watcher.

```bash
# 1. Database
mysql -u root -p
> CREATE DATABASE kastip;
> CREATE USER 'kastip'@'localhost' IDENTIFIED BY 'your-pass';
> GRANT ALL ON kastip.* TO 'kastip'@'localhost';
mysql -u kastip -p kastip < migrations/schema.sql

# 2. Config
cp config/secrets.example.php config/secrets.php
$EDITOR config/secrets.php    # fill in DB pass, X OAuth, session_secret
chmod 640 config/secrets.php  # readable by php-fpm group (www-data)
chown root:www-data config/secrets.php

# 3. nginx — point doc root at public/, route everything to index.php
# 4. PHP-FPM 8.3 — standard config
# 5. tx-watcher:
cd services/tx-watcher
npm install
cp .env.example .env  # fill in INTERNAL_TOKEN, DB creds
node index.js  # or systemd unit
```

For the X Developer Portal app: register at [developer.x.com](https://developer.x.com), pick **"Native App / SPA"** as the type (this makes it a public client — PKCE, no client secret in the token exchange). Add `https://your-domain/api/auth/x/callback` as redirect URI.

## Browser extension build

```bash
cd extension
./build.sh  # produces kastip-extension-X.Y.Z.zip
```

Sideload for dev: `chrome://extensions` → Developer mode → Load unpacked → select `extension/` dir.

## Contributing

Bug reports + PRs welcome. For non-trivial changes, please open an issue first to discuss the approach.

For security disclosures, please **do not** file a public issue — email `fecpiotr@gmail.com` with subject prefix `[SECURITY]`.

## License

MIT — see [LICENSE](LICENSE).
