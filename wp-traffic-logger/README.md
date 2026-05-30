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

## Sampling and load

- Every captured public/REST/AJAX request is written on `shutdown`, which adds I/O to each
  front-end hit. On high-traffic sites, lower the `sample_rate` option (0.0–1.0, default `1.0`)
  to record only a fraction of requests, or use the `wtl_should_log_request` filter for custom
  rules.
- Disk budget worst case is roughly `max_file_size` × 1000 rotation files per day, retained for
  `retention_days`. Size `max_file_size`/`retention_days`/`sample_rate` to fit available disk.

## Security notes

- Full request snapshots are enabled.
- Authentication secrets are masked:
  - `Authorization`, `Cookie`, `Set-Cookie` headers
  - sensitive keys like `password`, `token`, `secret`, `nonce`, `api_key`, `session`, `auth`
  - cookie values for auth/session cookies (`wordpress*`, `wp_*`, `wp-settings*`,
    `comment_author*`, `woocommerce*`, `PHPSESSID`) — only cookie names are retained
  - common secret-bearing fields in raw bodies (JSON and form-urlencoded), including
    `client_secret`, `refresh_token`, `access_token`, `private_key`
- `X-Forwarded-For` is **not** trusted by default (it is client-spoofable). Set the `trust_proxy`
  option when the site sits behind a known reverse proxy to record the forwarded client IP.
- Dashboard access is restricted to admins (`manage_options`).
- Web access hardening files are created on a best-effort basis (`.htaccess`, `web.config`), but server file permissions remain the primary protection.
