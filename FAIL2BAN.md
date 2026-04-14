# Fail2ban integration — phpBB Stats extension

The Stats extension writes two types of log lines to the security audit log (configurable in ACP,
default `/var/log/security_audit.log`):

- **`PHPBB-SIGNAL`** — behavioral signals emitted during page visits (bad UA, cookie cloning, no JS, etc.)
- **`PHPBB-XIP`** — cross-IP distributed-download signals emitted by the `geo_async` cron task

All fail2ban filters and example jails are shipped in the `fail2ban/` directory.

---

## Log format

```
2025-11-01 14:32:17 PHPBB-SIGNAL ip=1.2.3.4 session=abc123 user_id=0 signals="fake_legit_bot,no_screen_res" ua="Mozilla/5.0 ..." page="/viewtopic.php?t=42" screen_res=- page_count=1 hostname=- cc=CN
2025-11-01 14:33:05 PHPBB-XIP ip=1.2.3.4 cc=DE method=xip_dl_soft_v1 severity=soft score=72 topic_id=18 downloads=9 views=0 period_sec=3600
```

The `logpath` in every jail must match the path configured in the ACP settings.

---

## Signal catalogue

### High-confidence signals — `PHPBB-SIGNAL` (badbot)

These signals are strong indicators of non-human traffic. A single occurrence is usually
sufficient to justify a ban.

| Signal | Description |
|---|---|
| `fake_legit_bot` | UA claims to be Googlebot/Bingbot but reverse-DNS does not match |
| `posting_first_visit` | POST to posting.php on the very first page view of the session |
| `html_entities_in_url` | URL contains raw HTML entities (`&amp;`, `amp%3B`) — scrapers copying page source |
| `fake_chrome_build` | Chrome UA with a build number that does not match the claimed version |
| `old_firefox` | Firefox version well below current — typical of headless/legacy bots |
| `bad_gecko_date` | Gecko revision date inconsistent with the claimed browser version |
| `fake_safari_build` | Safari UA with inconsistent WebKit version |
| `template_literal` | UA contains JavaScript template-literal syntax — JS injection probe |
| `iphone_13_2_3` | Very specific iOS UA string associated with a known scraper fingerprint |
| `empty_ua` | No User-Agent header at all |
| `ua_pattern` | UA matches a known malicious pattern |
| `ajax_webdriver` | WebDriver API detected via JS probe |
| `ajax_scroll_profile` | Scroll profile matches bot baseline from collected behavior data |

### Moderate-confidence signals — `PHPBB-SIGNAL` (badbot-suspicious)

These signals are weaker individually. Recommend requiring 2–3 occurrences before banning.

| Signal | Description |
|---|---|
| `no_screen_res` | No screen resolution reported after N pages (no JavaScript) |
| `no_browser_signature` | No browser-specific JS property detected |
| `ajax_scroll_no_interact` | Zero scroll interaction events over multiple pages |
| `ajax_scroll_too_fast` | Scroll speed statistically impossible for a human |
| `ajax_scroll_jump` | Scroll position jumps inconsistent with human behavior |
| `learn_no_interact_outlier` | Too far from the registered-user interaction baseline |
| `learn_speed_outlier` | Reading speed far outside the learned distribution |
| `learn_sparse_scroll_outlier` | Scroll density far below the learned norm |
| `learn_jump_outlier` | Jump-scroll ratio exceeds the learned threshold |
| `learn_behavior_outlier` | Combined behavioral outlier from the ML model |

### Cookie / fingerprint cloning — `PHPBB-SIGNAL`

| Signal | Description |
|---|---|
| `guest_cookie_clone_multi_ip` | The same visitor cookie is used from multiple distinct IPs |
| `guest_cookie_ajax_fail` | The AJAX visitor-cookie verification fails (cookie presented but not recognised) |
| `guest_fp_clone_multi_ip` | Browser fingerprint shared across multiple IPs |

### Deferred interaction signal — `PHPBB-SIGNAL`

| Signal | Description |
|---|---|
| `cn_no_interaction_5m` | A guest from a targeted country viewed only one page and left within 5 minutes (first visit in 24h). Emitted by the async cron, not inline. |

### Cross-IP distributed download — `PHPBB-XIP`

| Method | Description |
|---|---|
| `xip_dl_soft_v1` | Moderate cross-IP download pattern (score below hard threshold) |
| `xip_dl_hard_v1` | Strong cross-IP download pattern (high score) |

---

## Filter files

The extension ships ready-to-use filter definitions in `fail2ban/`:

| File | Captures |
|---|---|
| `phpbb-badbot.conf` | All high-confidence `PHPBB-SIGNAL` signals |
| `phpbb-badbot-suspicious.conf` | All moderate-confidence `PHPBB-SIGNAL` signals |
| `phpbb-guest-cookie-clone.conf` | `guest_cookie_clone_multi_ip`, `guest_cookie_ajax_fail` |
| `phpbb-cn-no-interaction.conf` | `cn_no_interaction_5m` |
| `phpbb-crossip-soft.conf` | `PHPBB-XIP` soft detections |
| `phpbb-crossip-hard.conf` | `PHPBB-XIP` hard detections |

Copy them to `/etc/fail2ban/filter.d/` (or symlink them).

Additional filters for Apache access log (not included, ship separately):

- `phpbb-viewprofile-strict` — direct `GET /memberlist.php?mode=viewprofile` with no prior navigation
- `phpbb-register` — excessive POST to `ucp.php?mode=register`
- `phpbb-spam-bots` — `amp%3B` entities in URLs (scrapers replaying page-source links)
- `phpbb-ajax-abuse` — repeated POST to AJAX endpoints returning 403
- `phpbb-fakechrome` — UA matching known fake-Chrome botnet build patterns

---

## Recommended jail configuration

All jails below read from `security_audit.log`. Adapt `logpath`, `action`, `bantime`, and
`maxretry` to your environment. The values shown are a reasonable starting point.

### phpbb-badbot — high-confidence signals (ban on 1 hit)

```ini
[phpbb-badbot]
enabled   = true
port      = http,https
filter    = phpbb-badbot
backend   = auto
logpath   = /var/log/security_audit.log
maxretry  = 1
findtime  = 3600
bantime   = 86400
bantime.increment = true
bantime.factor    = 2
bantime.maxtime   = 2592000
```

### phpbb-badbot-suspicious — moderate signals (ban after 3 hits)

```ini
[phpbb-badbot-suspicious]
enabled   = true
port      = http,https
filter    = phpbb-badbot-suspicious
backend   = auto
logpath   = /var/log/security_audit.log
maxretry  = 3
findtime  = 3600
bantime   = 43200
bantime.increment = true
bantime.factor    = 2
bantime.maxtime   = 604800
```

### phpbb-guest-cookie-clone — cookie / AJAX cloning

```ini
[phpbb-guest-cookie-clone]
enabled   = true
port      = http,https
filter    = phpbb-guest-cookie-clone
backend   = auto
logpath   = /var/log/security_audit.log
maxretry  = 1
findtime  = 21600
bantime   = 43200
bantime.increment = true
bantime.factor    = 2
bantime.maxtime   = 1209600
```

### phpbb-guest-fingerprint-clone — fingerprint cloning

Same structure as `phpbb-guest-cookie-clone`, using filter `phpbb-guest-fingerprint-clone`.

### phpbb-cn-no-interaction — targeted country, no interaction

Full example in `fail2ban/jail.cn-no-interaction.local.example`.
The default action bans the entire `/24` subnet for 3 days.
Requires a custom nftables action; see `fail2ban/action.nftables-subnet24.local.example`.

### phpbb-crossip-soft / phpbb-crossip-hard — cross-IP downloading

Full examples in `fail2ban/jail.crossip.local.example`.
Hard detections warrant longer bans with incremental backoff.

---

## Deployment checklist

1. Copy (or symlink) `fail2ban/*.conf` filter files to `/etc/fail2ban/filter.d/`.
2. Add jail stanzas to `/etc/fail2ban/jail.d/` (one file per jail, or a single `phpbb.local`).
3. Set `logpath` to match the value configured in **ACP > Extensions > Stats Settings > Security log path**.
4. Ensure `www-data` (Apache/php-fpm) can write to the log file:
   ```bash
   touch /var/log/security_audit.log
   chown www-data:www-data /var/log/security_audit.log
   chmod 640 /var/log/security_audit.log
   ```
5. If you use logrotate, add `postrotate fail2ban-client set phpbb-badbot addignoreip 127.0.0.1 endaction` or configure `copytruncate` to avoid losing events during rotation.
6. Test each filter before enabling bans:
   ```bash
   fail2ban-regex /var/log/security_audit.log /etc/fail2ban/filter.d/phpbb-badbot.conf --print-all-matched
   ```
7. Reload fail2ban: `systemctl reload fail2ban`

---

## Notes

- The `cn_no_interaction_5m` signal is emitted by the **cron task** (`geo_async`), not inline.
  Ensure the cron runs at least every 5–10 minutes for timely detection.
- Cross-IP signals (`PHPBB-XIP`) also come from `geo_async`. Run the cron or the standalone
  `bin/cross_ip_audit.php --emit` script regularly.
- The `_shadow` variants of signals (e.g. `guest_cookie_clone_multi_ip_shadow`) are observation-only
  and do **not** trigger `PHPBB-SIGNAL` lines. They are stored in the stats DB for analysis only.
- Incremental ban (`bantime.increment`) is strongly recommended for all jails to escalate
  persistent offenders without manual intervention.
