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
- **Sessions** tab with timeline, visited pages, attachment downloads, cookie/AJAX diagnostics, signals, and country/flag display.
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
- When this cookie is present, it becomes the primary session anchor inside the extension: forum pages and downloads stay grouped in the same tracked session even if the IP changes mid-navigation, as long as the session timeout is not exceeded.

### Robust async geolocation (`geo_async` cron task)

- IP resolution via `ip-api.com` with DB caching.
- IPv4 cache by full IP and by configurable prefix (default `/24`, key format `v4:a.b.c.n/24`) to avoid redundant live lookups while keeping good country precision.
- Configurable cache TTL (default 45 days).
- ACP now distinguishes **Reverse DNS pending** (cron has not processed the IP yet) from **no PTR found** (cron already ran and returned a negative result).
- Safe throttling policy: 40 req/min target, 45 req/min service limit, fixed 5s inter-batch pause, additional quota-aware pauses.
- On HTTP 429: live lookup loop stops early and remaining IPs are retried on next run.
- CLI progress output for batch and global progression.
- The same cron also backfills per-session Apache asset counters from `forum_access.log*` to count forum banner, rank image, and avatar loads.

### Unified page + download timeline

- `download/file.php` hits are logged into the same tracked session as HTML pages through the phpBB hook `core.download_file_send_to_browser_before`.
- The ACP timeline keeps chronological `page -> downloads` ordering and exposes referer, UA, IP, geolocation, and hostname on those entries as well.
- The landing request is also rendered inside the chronology as row `#1`, then every sub-row shows `IP - elapsed time` so IP switches stay visible inside the same session.
- Timeline duration is computed chronologically between successive rows; same-second bursts stay visible as `0s` instead of a plain `-`.
- The last timeline column now shows a shortened hash of the signed `b59_vid` cookie together with its per-row match state (`OK`, `Mismatch`, `Missing`, `N/A`) without ever exposing the raw cookie value.
- Downloads stay visible in **Sessions** but are excluded from HTML/JS-only behavior scoring (`page_count`, no-interaction style rules, previous page duration updates).
- Sessions made only of direct downloads now show explicit `N/A` labels for AJAX, reactions CSS/JS, and Apache asset diagnostics instead of a misleading "missing" state.
- ACP session cards now use a visual frame by verdict: green (OK or legitimate phpBB bot), orange (suspicion), red (strict signal).

### Security bridge / Fail2ban

- Writes `PHPBB-SIGNAL` lines (behavior signals) to `security_audit.log`.
- `geo_async` also emits the `direct_resource_page_fallback_no_bootstrap` signal from Apache logs for the pattern `opaque direct resource -> HTML fallback -> no browser bootstrap`.
  - `_shadow` when the PTR looks residential.
  - strict when `view=print`, the IP recurs, there is no PTR (`hostname='-'`), or the PTR is non-residential.
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
- If system cron runs in CLI under a different user than the web server, point `bastien59_stats_audit_log_path` to a path writable by both contexts (for example `store/security_audit.log`).
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

- `bastien59_stats`: sessions/pages, signals, AJAX, cookie hash, cursor metrics, diagnostics, attachment downloads, and per-session Apache counters (`apache_*_hits`, `apache_asset_scan_time`).
- `bastien59_stats_geo_cache`: geolocation cache + IPv4 subnet keys (`/24` by default, configurable in ACP).
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
- Banner/rank/avatar Apache counters are informational only: they are reconstructed afterward from recent Apache logs and therefore do not cover history older than retained logs.

## Operational note

- The ACP **Clear Statistics** button only deletes `bastien59_stats`. The GeoIP / Reverse DNS cache table (`bastien59_stats_geo_cache`) is intentionally preserved.

## License

[GPL-2.0-only](LICENSE)

## Author

**Bastien** (`bastien59960`)
