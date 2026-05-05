# KasTip — backend + web app

Non-custodial Kaspa tipping for X (Twitter). PHP 8.3 + MariaDB 10.11 + nginx 1.24, hosted under Cloudflare proxy at `https://kastip.app`.

See `/home/kastip/00-CONTEXT.md` for project context, decisions, and roadmap.

## Layout

```
kastip-app/
├── public/              ← nginx doc root (only this is web-accessible)
│   ├── index.php        ← single front-controller
│   └── assets/
├── src/                 ← PHP source code (NOT in doc root)
│   ├── api/             ← /api/* endpoint handlers
│   ├── auth/            ← OAuth (X PKCE), session/bearer auth
│   ├── lib/             ← address validation, kaspa REST, helpers
│   └── models/          ← PDO query layer
├── config/
│   ├── secrets.php      ← DB pass, X OAuth, etc. (chmod 600, NEVER commit)
│   └── secrets.example.php  ← template, safe to commit
├── migrations/
│   └── schema.sql       ← initial DB schema
└── tests/               ← (empty for MVP, PHPUnit later)
```

## Setup (already done on this server)

1. nginx vhost: `/etc/nginx/sites-available/kastip.app` — TLS via Cloudflare Origin Cert
2. MariaDB: database `kastip`, user `kastip`@`localhost` (creds in `config/secrets.php`)
3. PHP 8.3 FPM socket: `/run/php/php8.3-fpm.sock`

To deploy schema on a fresh DB:
```bash
mysql -u kastip -p kastip < migrations/schema.sql
```

## Notes

- No Composer / external PHP libs in MVP — bare PHP 8.3 (PDO, curl, json, hash, random_bytes).
- No Twitter API costs — OAuth2 PKCE only, scope `users.read`.
- Non-custodial: server NEVER holds private keys or signs transactions.
- Fee-less MVP (Kasware lacks multi-output) — donate address on `/support`, post-Toccata revisit.
