# WP Traffic Logger

Logs incoming WordPress-handled traffic to rotating JSONL files and provides an admin dashboard viewer.

## Scope

- Public frontend requests
- REST requests
- AJAX requests
- Excludes regular wp-admin page loads

## Storage

- Log path: `wp-content/uploads/wp-traffic-logger/`
- Format: JSON Lines (`*.log`)
- Rotation: by max file size
- Retention: 14 days (daily WP-Cron cleanup)

## Security notes

- Full request snapshots are enabled.
- Authentication secrets are masked:
  - `Authorization`, `Cookie`, `Set-Cookie` headers
  - sensitive keys like `password`, `token`, `secret`, `nonce`, `api_key`, `session`, `auth`
- Dashboard access is restricted to admins (`manage_options`).
- Web access hardening files are created on a best-effort basis (`.htaccess`, `web.config`), but server file permissions remain the primary protection.
