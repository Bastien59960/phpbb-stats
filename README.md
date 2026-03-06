# Bastien59 Stats - phpBB 3.3+ Extension

[Français](README.fr.md)

**Turn your ACP into an anti-bot operations center.**

As AI training demand grows, genuinely human-written forum content becomes more valuable than ever. This also increases large-scale scraping pressure from automated traffic. Bastien59 Stats helps phpBB administrators detect and contain abusive bot behavior with actionable telemetry, behavioral signals, and Fail2ban-ready security events.

## Why install it

- Quickly identify who consumes your resources (humans vs bots).
- Detect automation patterns that bypass simple User-Agent checks.
- Correlate session, AJAX telemetry, signed visitor cookie, IP, country, and cursor traces.
- Feed Fail2ban with high-signal events without exposing server secrets.

## Key features

### Operations-focused ACP

- Overview with human/bot counters, traffic sources, OS, devices, and screen resolutions.
- **Sessions** tab with timeline, visited pages, cookie/AJAX diagnostics, signals, and country/flag display.
- **Pages** tab (top pages + full referers).
- **Map** tab (jVectorMap) for geographic distribution.
- **Behavior** tab with members/guests/bots comparison, learned profiles, outlier signals, cursor capture health, and recent cases with SVG traces.

### Multi-signal bot detection

Strict and observation signals depending on geo context:

- `old_chrome_*`, `old_firefox`, `no_screen_res`
- `ajax_webdriver`, `ajax_scroll_profile`
- `guest_fp_clone_multi_ip` and `_shadow`
- `guest_cookie_clone_multi_ip` and `_shadow`
- `guest_cookie_ajax_fail` and `_shadow`
- `cursor_no_movement`, `cursor_no_clicks`, `cursor_speed_outlier`, `cursor_script_path`
- `learn_*_outlier` based on learned behavior profiles

### AJAX telemetry + signed visitor cookie

- Secure `POST /stats/px` endpoint (link token + same-origin + session checks).
- Collects resolution, scroll, interactions, webdriver, and cursor/touch traces.
- Signed visitor cookie `b59_vid` (stored hashed in DB, never in clear text).
- Distinct AJAX cookie states: absent, invalid, mismatch.

### Robust async geolocation (`geo_async` cron task)

- IP resolution via `ip-api.com` with DB caching.
- IPv4 cache by full IP and by `/16` prefix (`v4:a.b`) to avoid redundant live lookups.
- Configurable cache TTL (default 45 days).
- Safe throttling policy: 40 req/min target, 45 req/min service limit, fixed 5s inter-batch pause, additional quota-aware pauses.
- On HTTP 429: live lookup loop stops early and remaining IPs are retried on next run.
- CLI progress output for batch and global progression.

### Security bridge / Fail2ban

- Writes `PHPBB-SIGNAL` lines (behavior signals) to `security_audit.log`.
- `bin/cross_ip_audit.php` detects distributed cross-IP attachment download patterns (`PHPBB-XIP`).
- Included Fail2ban snippets: `fail2ban/phpbb-guest-cookie-clone.conf`, `fail2ban/phpbb-crossip-soft.conf`, `fail2ban/phpbb-crossip-hard.conf`, `fail2ban/jail.guest-cookie-clone.local.example`, `fail2ban/jail.crossip.local.example`.

## Requirements

- PHP `>= 7.1.3`
- phpBB `>= 3.3.0`

## Installation

1. Copy `bastien59960/stats` into `ext/`.
2. Enable the extension:

```bash
php bin/phpbbcli.php extension:enable bastien59960/stats
```

## Update

After updating files:

```bash
php bin/phpbbcli.php db:migrate
php bin/phpbbcli.php cache:purge
```

## Uninstall

```bash
php bin/phpbbcli.php extension:disable bastien59960/stats
php bin/phpbbcli.php extension:purge bastien59960/stats
```

## Quick ACP setup

In **Extensions > Stats Settings**:

- Enable/disable tracking.
- Set human and bot retention.
- Configure session timeout.
- Configure security log path (default `/var/log/security_audit.log`).
- Tune browser/JS detection thresholds.

Production checklist:

- Ensure PHP process can write to security log.
- Enable matching Fail2ban jails.
- Ensure phpBB cron runs regularly.

## Cron and useful commands

### Run phpBB cron

```bash
php /var/www/forum/bin/phpbbcli.php cron:run
```

### Run only async geolocation task

```bash
php /var/www/forum/bin/phpbbcli.php cron:run cron.task.bastien59960.stats.geo_async
```

### Cross-IP audit (dry-run)

```bash
php ext/bastien59960/stats/bin/cross_ip_audit.php --target=86400 --context=172800 --verbose
```

### Cross-IP audit (emit to `security_audit.log`)

```bash
php ext/bastien59960/stats/bin/cross_ip_audit.php --emit --target=10800 --context=86400
```

Example cron (every 30 minutes):

```cron
*/30 * * * * php /var/www/forum/ext/bastien59960/stats/bin/cross_ip_audit.php --emit --target=10800 --context=86400 >> /var/log/phpbb_crossip_audit.log 2>&1
```

### Reactions assets backfill (optional)

If `bastien59960/reactions` is enabled:

```bash
php ext/bastien59960/stats/bin/backfill_reactions_assets.php --window=120 --verbose
php ext/bastien59960/stats/bin/backfill_reactions_assets.php --apply --window=120
```

## Stored data (summary)

Main tables:

- `bastien59_stats`: sessions/pages, signals, AJAX, cookie hash, cursor metrics, diagnostics.
- `bastien59_stats_geo_cache`: geolocation cache + IPv4 `/16` keys.
- `bastien59_stats_behavior_profile`: learned behavior profiles.
- `bastien59_stats_behavior_seen`: dedup table for learned sessions.

## Security and privacy

- No passwords, API tokens, or server secrets are versioned.
- Visitor cookie is stored as hash in DB.
- AJAX endpoint enforces method, token, session, and same-origin checks.
- Country-sensitive FR/CO signals can be kept in observation mode when applicable.

## Known limits

- Geo map relies on jVectorMap assets loaded from CDN.
- Geolocation depends on `ip-api.com` availability.
- Network blocking is delegated to Fail2ban (not performed directly by this extension).

## License

[GPL-2.0-only](LICENSE)

## Author

**Bastien** (`bastien59960`)
