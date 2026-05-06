# KasTip — browser extension

Tip Kaspa (KAS) to anyone on X with one click. Non-custodial, peer-to-peer, no fees.

## Layout

```
extension/
├── manifest.json           ← MV3 config
├── background.js           ← service worker — auth, API router, message hub
├── content/
│   ├── x-injector.js       ← injects "💰 KasTip" buttons on x.com
│   └── x-injector.css
├── popup/                  ← extension toolbar UI
│   ├── popup.html
│   ├── popup.css
│   └── popup.js
├── lib/
│   ├── config.js           ← shared constants (API base, selectors)
│   └── qr.js               ← bundled QR generator (offline, no CDN)
├── icons/                  ← 16/48/128 px PNG
└── build.sh                ← bundle into .zip for store submission
```

## Local development (sideload in Chrome)

1. `chrome://extensions/`
2. Toggle **Developer mode** (top-right)
3. Click **Load unpacked** → select this `extension/` directory
4. Ikona "KasTip" pojawia się w toolbarze
5. Click **Connect X** → OAuth → token stored in `chrome.storage.local`
6. Open `https://x.com` → "💰 KasTip" buttons appear next to handles

After code changes: click **🔄 Reload** on the extension card and refresh the X tab.

## Key flows

### Auth (chrome.identity.launchWebAuthFlow)

```
popup           background          backend (kastip.app)        X
  │                │                       │                   │
  │  click         │                       │                   │
  │  "Connect X"   │                       │                   │
  ├───────────────►│                       │                   │
  │                │  launchWebAuthFlow    │                   │
  │                │  url=/api/auth/x/start                    │
  │                │  ?ext_redirect=https://{ext_id}.chromiumapp.org/oauth-callback
  │                │                       │                   │
  │                │   browser opens X authorize ──────────────►
  │                │                       │  user authorizes  │
  │                │                       │◄──────────────────┤
  │                │                       │  exchange code     │
  │                │                       │  fetch /users/me   │
  │                │                       │  upsert user       │
  │                │                       │  Session::create   │
  │                │  redirect to ext_redirect?token=...        │
  │                │◄──────────────────────┤                    │
  │                │  parse token → save in chrome.storage.local│
  │  signed in     │                       │                    │
  │◄───────────────┤                       │                    │
```

### Tip (registered receiver, with Kasware)

```
content (x-injector)    background          kastip.app          Kasware       Kaspa
  │                       │                   │                   │            │
  │  click "💰 KasTip"    │                   │                   │            │
  │  → modal              │                   │                   │            │
  │  api:lookup           │                   │                   │            │
  │  ────────────────────►│                   │                   │            │
  │                       │  GET /users/lookup│                   │            │
  │                       │  ────────────────►│                   │            │
  │                       │  {registered:true,│                   │            │
  │                       │   kaspa_address}  │                   │            │
  │                       │◄──────────────────┤                   │            │
  │  api:tip-initiate     │                   │                   │            │
  │  ────────────────────►│                   │                   │            │
  │                       │  POST /tips/initiate                  │            │
  │                       │  ────────────────►│                   │            │
  │                       │  {tip_id, payload, qr_uri, ...}       │            │
  │                       │◄──────────────────┤                   │            │
  │  window.kasware.sendKaspa(receiver, sompi, {payload})         │            │
  │  ────────────────────────────────────────────────────────────►│            │
  │                       │                   │       sign + broadcast         │
  │                       │                   │                   ├───────────►│
  │                       │                   │                   │  txid      │
  │                       │                   │                   │◄───────────┤
  │  api:tip-confirm      │                   │                   │            │
  │  ────────────────────►│                   │                   │            │
  │                       │  POST /tips/confirm                   │            │
  │                       │  ────────────────►│                   │            │
  │                       │                   │  fetch TX from api.kaspa.org   │
  │                       │                   │  verify outputs               │
  │                       │  {status:confirmed}                   │            │
  │                       │◄──────────────────┤                   │            │
  │  ✅ render success    │                   │                   │            │
```

### Tip (no Kasware → QR fallback)

Same as above through `api:tip-initiate`. Then content script renders a QR
of `init.qr_uri` (kaspa: BIP-21 URI) using bundled `lib/qr.js`. User scans
with their wallet (Kaspium, Tangem, etc.), broadcasts, copies TXID,
pastes back into the modal — `api:tip-confirm` proceeds normally.

## Build for submission

```
./build.sh                 # → ./kastip-extension-<version>.zip
```

Upload the resulting zip to:
- Chrome Web Store dashboard ($5 one-time dev fee)
- Firefox Add-ons (`addons.mozilla.org`, free)

Make sure to bump `manifest.json:version` before each store update.

## Known constraints

- **Cross-tab OAuth**: `chrome.identity.launchWebAuthFlow` opens a popup window.
  X uses the user's current `x.com` browser session — there's no account picker
  built into OAuth 2.0. To switch X accounts, the user must sign out of x.com
  in the popup window first. See `/u/{handle}` page on the web app for the
  same warning shown to claimers.
- **Single-output Kasware**: `window.kasware.sendKaspa()` only supports one
  recipient. We do NOT charge a service fee in MVP — when smart contracts ship
  (post-Toccata), we'll revisit atomic split via covenants.
- **DOM brittleness**: X changes its DOM frequently. Selectors live in
  `lib/config.js` (`X_SELECTORS`) — any breakage = patch there + bump version.
