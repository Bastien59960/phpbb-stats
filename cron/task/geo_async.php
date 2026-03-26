<?php
/**
 * Async geo-resolution task for stats extension.
 *
 * @package bastien59960/stats
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\cron\task;

class geo_async extends \phpbb\cron\task\base
{
    /** ip-api free hard limit */
    const GEO_API_LIMIT_PER_MIN = 45;
    /** safety target to keep margin below hard limit */
    const GEO_API_SAFE_PER_MIN = 40;
    /** Durée max d'un run (secondes) — libère le cron_lock avant la limite watchdog de 300s */
    const MAX_RUNTIME_SECONDS = 240;
    /** Batch max de sessions à enrichir via logs Apache par run */
    const APACHE_ASSET_SESSION_BATCH = 80;
    /** Historique max exploitable, aligné sur les 3 derniers access.log utilisés */
    const APACHE_ASSET_HISTORY_DAYS = 3;
    /** Tolérance avant le début de session pour rattacher un asset au chargement initial */
    const APACHE_ASSET_MATCH_LEAD_SECONDS = 45;
    /** Tolérance après la dernière page pour capturer les assets tardifs (ex: vidéo bannière) */
    const APACHE_ASSET_MATCH_TRAIL_SECONDS = 300;
    /** Délai max entre ressource directe et fallback HTML pour le nouveau signal */
    const DIRECT_RESOURCE_FALLBACK_MAX_SECONDS = 3;
    /** Fenêtre sans requête supplémentaire après le fallback HTML */
    const DIRECT_RESOURCE_NOFOLLOW_SECONDS = 10;
    /** Historique de récidive par IP pour promotion shadow -> strict */
    const DIRECT_RESOURCE_SIGNAL_HISTORY_DAYS = 14;

    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var \phpbb\config\config */
    protected $config;

    /** @var string */
    protected $table_prefix;

    /** @var bool */
    protected $cli_progress_active = false;

    /** @var int */
    protected $cli_progress_line_len = 0;

    /** @var int */
    protected $cli_terminal_cols = 0;

    /** @var int */
    protected $cli_pending_cache_total = -1;

    /** @var float */
    protected $cli_pending_cache_ts = 0.0;

    /** @var float[] unix timestamps (microtime) of live ip-api calls in last 60s */
    protected $live_lookup_timestamps = [];

    /** @var float */
    protected $last_live_lookup_ts = 0.0;

    /** @var int|null */
    protected $geo_ipv4_prefix_len = null;

    /** @var int|null */
    protected $geo_ipv6_prefix_len = null;

    /** @var bool|null */
    protected $has_apache_asset_columns = null;

    /** @var bool|null */
    protected $has_ajax_telemetry_columns = null;

    /** @var bool|null */
    protected $has_ajax_advanced_columns = null;

    /** @var bool|null */
    protected $has_cursor_columns = null;

    /** @var bool|null */
    protected $has_reactions_probe_columns = null;

    public function __construct(\phpbb\db\driver\driver_interface $db, \phpbb\config\config $config, $table_prefix)
    {
        $this->db = $db;
        $this->config = $config;
        $this->table_prefix = $table_prefix;
    }

    public function is_runnable()
    {
        // Ne jamais s'exécuter via le cron web : les sleeps/pauses API bloqueraient
        // le cron_lock phpBB pendant 5-10 min et empêcheraient les autres crons
        // (reactions, etc.) de tourner. Ce cron est géré par le crontab système.
        if (!$this->is_cli_runtime()) {
            return false;
        }
        return !empty($this->config['bastien59_stats_enabled']);
    }

    public function should_run()
    {
        $interval = max(60, (int)($this->config['bastien59_stats_geo_async_interval'] ?? 300));
        $last_run = (int)($this->config['bastien59_stats_geo_async_last_run'] ?? 0);
        return $last_run < (time() - $interval);
    }

    public function run()
    {
        $now = time();
        $this->config->set('bastien59_stats_geo_async_last_run', $now);

        $batch = max(5, min(120, (int)($this->config['bastien59_stats_geo_async_batch'] ?? 30)));
        $ttl_days = max(1, min(365, (int)($this->config['bastien59_stats_geo_cache_ttl_days'] ?? 45)));
        $ipv4_prefix_len = $this->get_geo_ipv4_prefix_len();

        $processed_total = 0;
        $scanned_total = 0;
        $cached_hits_total = 0;
        $live_hits_total = 0;
        $fail_hits_total = 0;
        $local_skips_total = 0;
        $deferred_live_total = 0;
        $apache_asset_summary = [
            'candidates' => 0,
            'updated' => 0,
            'events_matched' => 0,
            'events_seen' => 0,
            'resource_signal_candidates' => 0,
            'resource_signal_confirmed' => 0,
            'resource_signal_shadow' => 0,
            'resource_signal_strict' => 0,
            'aborted' => 0,
        ];
        $pending_probe_total = $this->get_pending_ip_count($ttl_days);
        $pending_probe_window = count($this->get_pending_ips($batch * 4, $ttl_days));
        $start_ts = microtime(true);
        $batch_index = 0;
        $no_progress_loops = 0;
        $estimated_loops = (int)ceil(max(1, (int)$pending_probe_total) / max(1, (int)$batch));
        $max_loops = max(1000, $estimated_loops * 8);
        $defer_live_until_next_run = false;

        if ($this->is_cli_runtime()) {
            $this->get_pending_ip_count_cached_cli($ttl_days, true);
        }

        if ($this->is_cli_runtime()) {
            $this->cli_log(sprintf(
                '[geo_async] Debut: pending_total=%d, window=%d, batch=%d, ttl=%dj, ipv4_prefix=/%d, max_loops=%d',
                (int)$pending_probe_total,
                (int)$pending_probe_window,
                (int)$batch,
                (int)$ttl_days,
                (int)$ipv4_prefix_len,
                (int)$max_loops
            ));
        }

        $deadline = $start_ts + self::MAX_RUNTIME_SECONDS;

        while ($batch_index < $max_loops) {
            if (microtime(true) >= $deadline) {
                if ($this->is_cli_runtime()) {
                    $this->cli_log(sprintf('[geo_async] Arret limite temps: %.0fs >= %ds.', microtime(true) - $start_ts, self::MAX_RUNTIME_SECONDS));
                }
                break;
            }

            $pending_total_all = $this->get_pending_ip_count($ttl_days);
            if ($pending_total_all <= 0) {
                break;
            }

            $pending_ips = $this->get_pending_ips($batch * 4, $ttl_days);
            $pending_window = count($pending_ips);
            if ($pending_window <= 0) {
                break;
            }

            $batch_index++;
            $processed_batch = 0;
            $scanned_batch = 0;
            $cached_hits_batch = 0;
            $live_hits_batch = 0;
            $fail_hits_batch = 0;
            $local_skips_batch = 0;
            $deferred_live_batch = 0;

            if ($this->is_cli_runtime()) {
                $global_start_done = max(0, (int)$pending_probe_total - (int)$pending_total_all);
                $global_start_pct = ($pending_probe_total > 0)
                    ? (((float)$global_start_done * 100.0) / (float)$pending_probe_total)
                    : 100.0;
                $this->cli_log(sprintf(
                    '[geo_async] Batch %d: pending_total=%d, window=%d, global=%d/%d (%.1f%%)',
                    (int)$batch_index,
                    (int)$pending_total_all,
                    (int)$pending_window,
                    (int)$global_start_done,
                    (int)max(0, $pending_probe_total),
                    (float)$global_start_pct
                ));
            }

            foreach ($pending_ips as $ip) {
                if ($processed_batch >= $batch) {
                    break;
                }
                if ($this->is_local_ip($ip)) {
                    $local_skips_batch++;
                    $local_skips_total++;
                    continue;
                }

                $scanned_batch++;
                $scanned_total++;
                $cached = $this->get_geo_cache($ip, $ttl_days);
                if ($cached !== false) {
                    if (trim((string)($cached['hostname'] ?? '')) === '') {
                        $resolved_hostname = trim((string)$this->resolve_hostname($ip));
                        $cached['hostname'] = ($resolved_hostname !== '') ? substr($resolved_hostname, 0, 255) : '-';
                        $this->set_geo_cache($ip, $cached);
                    }
                    $this->backfill_stats_for_ip($ip, $cached, $ttl_days);
                    $this->process_deferred_country_signals_for_ip($ip, $cached, $ttl_days);
                    $processed_batch++;
                    $processed_total++;
                    $cached_hits_batch++;
                    $cached_hits_total++;
                    $cache_key = strtolower(trim((string)($cached['__cache_key'] ?? '')));
                    $cache_kind = (strpos($cache_key, 'v4:') === 0)
                        ? ('cachev4/' . (int)$ipv4_prefix_len)
                        : 'cache';
                    list($global_done, $global_left) = $this->resolve_global_progress($pending_probe_total, $ttl_days, $pending_total_all);
                    $this->cli_progress($processed_batch, $batch, $scanned_batch, $pending_window, $cached_hits_batch, $live_hits_batch, $fail_hits_batch, (string)$ip . ' ' . $cache_kind, $global_done, $pending_probe_total, $global_left);
                    continue;
                }

                $this->throttle_before_live_lookup();
                $lookup_meta = [];
                $geo = $this->lookup_geo($ip, $lookup_meta);
                $this->register_live_lookup_attempt();
                if ($geo === false) {
                    $fail_hits_batch++;
                    $fail_hits_total++;
                    if ($this->should_retry_next_run_after_lookup_failure($lookup_meta)) {
                        $defer_live_until_next_run = true;
                        $deferred_live_batch++;
                        $deferred_live_total++;
                        list($global_done, $global_left) = $this->resolve_global_progress($pending_probe_total, $ttl_days, $pending_total_all);
                        $this->cli_progress($processed_batch, $batch, $scanned_batch, $pending_window, $cached_hits_batch, $live_hits_batch, $fail_hits_batch, (string)$ip . ' 429->next', $global_done, $pending_probe_total, $global_left);
                        if ($this->is_cli_runtime()) {
                            $this->cli_log(sprintf('[geo_async] HTTP 429 detecte sur %s: reprise des lookups live au prochain lancement.', (string)$ip));
                        }
                        break;
                    }
                    list($global_done, $global_left) = $this->resolve_global_progress($pending_probe_total, $ttl_days, $pending_total_all);
                    $this->cli_progress($processed_batch, $batch, $scanned_batch, $pending_window, $cached_hits_batch, $live_hits_batch, $fail_hits_batch, (string)$ip . ' fail', $global_done, $pending_probe_total, $global_left);
                    $this->maybe_pause_for_rate_limit($lookup_meta);
                    continue;
                }

                // Si ip-api répond success mais sans pays (IP réservée, non attribuée),
                // stocker 'ZZ' pour éviter les retentatives infinies à chaque run.
                if (($geo['country_code'] ?? '') === '') {
                    $geo['country_code'] = 'ZZ';
                }
                $geo['hostname'] = trim((string)($geo['hostname'] ?? ''));
                if ($geo['hostname'] === '') {
                    $geo['hostname'] = '-';
                }
                $this->set_geo_cache($ip, $geo);
                $this->backfill_stats_for_ip($ip, $geo, $ttl_days);
                $this->process_deferred_country_signals_for_ip($ip, $geo, $ttl_days);
                $processed_batch++;
                $processed_total++;
                $live_hits_batch++;
                $live_hits_total++;
                list($global_done, $global_left) = $this->resolve_global_progress($pending_probe_total, $ttl_days, $pending_total_all);
                $this->cli_progress($processed_batch, $batch, $scanned_batch, $pending_window, $cached_hits_batch, $live_hits_batch, $fail_hits_batch, (string)$ip . ' live', $global_done, $pending_probe_total, $global_left);
                $this->maybe_pause_for_rate_limit($lookup_meta);
            }

            if ($this->is_cli_runtime() && $this->cli_progress_active) {
                $this->cli_log('');
                $this->cli_progress_active = false;
            }

            if ($defer_live_until_next_run) {
                if ($this->is_cli_runtime()) {
                    $this->cli_log(sprintf(
                        '[geo_async] Arret anticipe: live deferres (batch=%d, deferred_batch=%d).',
                        (int)$batch_index,
                        (int)$deferred_live_batch
                    ));
                }
                break;
            }

            $pending_after_batch = $this->get_pending_ip_count($ttl_days);
            $pending_delta = max(0, (int)$pending_total_all - (int)$pending_after_batch);

            if ($processed_batch <= 0 || $pending_delta <= 0) {
                $no_progress_loops++;
                if ($this->is_cli_runtime()) {
                    $this->cli_log(sprintf(
                        '[geo_async] Batch %d sans progression utile (scan=%d, fail=%d, local_skip=%d, pending:%d->%d)',
                        (int)$batch_index,
                        (int)$scanned_batch,
                        (int)$fail_hits_batch,
                        (int)$local_skips_batch,
                        (int)$pending_total_all,
                        (int)$pending_after_batch
                    ));
                }
                if ($no_progress_loops >= 3) {
                    if ($this->is_cli_runtime()) {
                        $this->cli_log('[geo_async] Arret: aucun progres utile sur 3 batchs consecutifs (garde anti-boucle).');
                    }
                    break;
                }
            } else {
                $no_progress_loops = 0;
            }

            if (count($this->get_pending_ips(1, $ttl_days)) > 0) {
                $this->maybe_pause_between_batches($batch_index, $pending_after_batch, $live_hits_batch, $fail_hits_batch);
            }
        }

        if ($batch_index >= $max_loops && $this->is_cli_runtime()) {
            $this->cli_log(sprintf('[geo_async] Arret securite: max_loops=%d atteint.', (int)$max_loops));
        }

        $this->cleanup_geo_cache($ttl_days);
        $apache_asset_summary = $this->backfill_session_apache_assets($deadline);

        if ($this->is_cli_runtime()) {
            if ($this->cli_progress_active) {
                $this->cli_log('');
                $this->cli_progress_active = false;
            }
            $duration = microtime(true) - $start_ts;
            $pending_left = $this->get_pending_ip_count($ttl_days);
            $this->cli_log(sprintf(
                '[geo_async] Fin: loops=%d, ok=%d, scanned=%d, cache=%d, live=%d, fail=%d, deferred_live=%d, local_skip=%d, pending_left=%d, apache_sessions=%d, apache_updates=%d, apache_events=%d/%d, direct_resource=%d/%d, shadow=%d, strict=%d, duree=%.1fs',
                (int)$batch_index,
                (int)$processed_total,
                (int)$scanned_total,
                (int)$cached_hits_total,
                (int)$live_hits_total,
                (int)$fail_hits_total,
                (int)$deferred_live_total,
                (int)$local_skips_total,
                (int)$pending_left,
                (int)($apache_asset_summary['candidates'] ?? 0),
                (int)($apache_asset_summary['updated'] ?? 0),
                (int)($apache_asset_summary['events_matched'] ?? 0),
                (int)($apache_asset_summary['events_seen'] ?? 0),
                (int)($apache_asset_summary['resource_signal_confirmed'] ?? 0),
                (int)($apache_asset_summary['resource_signal_candidates'] ?? 0),
                (int)($apache_asset_summary['resource_signal_shadow'] ?? 0),
                (int)($apache_asset_summary['resource_signal_strict'] ?? 0),
                (float)$duration
            ));
        }
    }

    private function should_retry_next_run_after_lookup_failure(array $meta)
    {
        $http_code = isset($meta['http_code']) ? (int)$meta['http_code'] : 0;
        return ($http_code === 429);
    }

    /**
     * @return string[]
     */
    private function get_pending_ips($limit, $ttl_days)
    {
        $limit = max(1, (int)$limit);
        $cutoff = time() - ($ttl_days * 86400);

        $sql = 'SELECT stats.user_ip, MAX(stats.visit_time) AS last_seen
                FROM ' . $this->table_prefix . 'bastien59_stats stats
                WHERE stats.is_first_visit = 1
                AND stats.user_ip <> \'\'
                AND (
                    stats.country_code = \'\'
                    OR stats.hostname = \'\'
                    OR NOT EXISTS (
                        SELECT 1
                        FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache host_cache
                        WHERE host_cache.ip_address = stats.user_ip
                        AND host_cache.cached_time > ' . (int)$cutoff . '
                        AND host_cache.hostname <> \'\'
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache host_cache
                        WHERE host_cache.ip_address = stats.user_ip
                        AND host_cache.cached_time > ' . (int)$cutoff . '
                        AND host_cache.hostname <> \'\'
                        AND host_cache.hostname <> stats.hostname
                    )
                )
                AND stats.visit_time > ' . (int)$cutoff . '
                GROUP BY stats.user_ip
                ORDER BY last_seen DESC';

        $result = $this->db->sql_query_limit($sql, $limit);
        $ips = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $ip = trim((string)($row['user_ip'] ?? ''));
            if ($ip === '') {
                continue;
            }
            $ips[$ip] = true;
        }
        $this->db->sql_freeresult($result);

        return array_keys($ips);
    }

    private function get_pending_ip_count($ttl_days)
    {
        $cutoff = time() - (max(1, (int)$ttl_days) * 86400);
        $sql = 'SELECT COUNT(DISTINCT stats.user_ip) AS cnt
                FROM ' . $this->table_prefix . 'bastien59_stats stats
                WHERE stats.is_first_visit = 1
                AND stats.user_ip <> \'\'
                AND (
                    stats.country_code = \'\'
                    OR stats.hostname = \'\'
                    OR NOT EXISTS (
                        SELECT 1
                        FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache host_cache
                        WHERE host_cache.ip_address = stats.user_ip
                        AND host_cache.cached_time > ' . (int)$cutoff . '
                        AND host_cache.hostname <> \'\'
                    )
                    OR EXISTS (
                        SELECT 1
                        FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache host_cache
                        WHERE host_cache.ip_address = stats.user_ip
                        AND host_cache.cached_time > ' . (int)$cutoff . '
                        AND host_cache.hostname <> \'\'
                        AND host_cache.hostname <> stats.hostname
                    )
                )
                AND stats.visit_time > ' . (int)$cutoff;
        $result = $this->db->sql_query_limit($sql, 1);
        $cnt = (int)$this->db->sql_fetchfield('cnt');
        $this->db->sql_freeresult($result);
        return max(0, $cnt);
    }

    private function get_pending_ip_count_cached_cli($ttl_days, $force = false)
    {
        if (!$this->is_cli_runtime()) {
            return $this->get_pending_ip_count($ttl_days);
        }

        $now = microtime(true);
        if (
            $force
            || $this->cli_pending_cache_total < 0
            || (($now - $this->cli_pending_cache_ts) >= 1.0)
        ) {
            $this->cli_pending_cache_total = $this->get_pending_ip_count($ttl_days);
            $this->cli_pending_cache_ts = $now;
        }

        return max(0, (int)$this->cli_pending_cache_total);
    }

    private function resolve_global_progress($baseline_total, $ttl_days, $fallback_left)
    {
        $baseline_total = max(0, (int)$baseline_total);
        $pending_now = $this->get_pending_ip_count_cached_cli($ttl_days, false);
        if ($pending_now < 0) {
            $pending_now = max(0, (int)$fallback_left);
        }
        $done = max(0, $baseline_total - (int)$pending_now);
        return [$done, max(0, (int)$pending_now)];
    }

    private function get_geo_cache($ip, $ttl_days)
    {
        $keys = $this->build_geo_cache_keys($ip);
        if (empty($keys)) {
            return false;
        }

        $escaped = [];
        foreach ($keys as $key) {
            $escaped[] = '\'' . $this->db->sql_escape($key) . '\'';
        }

        $now = time();
        $ttl_sec = max(3600, (int)$ttl_days * 86400);
        $sql = 'SELECT ip_address, country_code, country_name, city, hostname
                FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache
                WHERE ip_address IN (' . implode(',', $escaped) . ')
                AND cached_time > ' . (int)($now - $ttl_sec);

        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $rows[(string)$row['ip_address']] = $row;
        }
        $this->db->sql_freeresult($result);

        $exact_key = $this->build_geo_cache_exact_key($ip);
        $scope_key = $this->build_geo_cache_scope_key($ip);
        $exact_row = ($exact_key !== '' && isset($rows[$exact_key])) ? $rows[$exact_key] : null;
        $scope_row = ($scope_key !== '' && isset($rows[$scope_key])) ? $rows[$scope_key] : null;

        $data = [
            'country_code' => '',
            'country_name' => '',
            'city' => '',
            'hostname' => '',
        ];

        if ($scope_row !== null) {
            $data['country_code'] = (string)($scope_row['country_code'] ?? '');
            $data['country_name'] = (string)($scope_row['country_name'] ?? '');
            $data['city'] = (string)($scope_row['city'] ?? '');
        }

        if ($exact_row !== null) {
            if ($data['country_code'] === '') {
                $data['country_code'] = (string)($exact_row['country_code'] ?? '');
            }
            if ($data['country_name'] === '') {
                $data['country_name'] = (string)($exact_row['country_name'] ?? '');
            }
            if ($data['city'] === '') {
                $data['city'] = (string)($exact_row['city'] ?? '');
            }
            $data['hostname'] = (string)($exact_row['hostname'] ?? '');
        }

        if (
            $data['country_code'] === ''
            && $data['country_name'] === ''
            && $data['city'] === ''
            && $data['hostname'] === ''
        ) {
            return false;
        }

        $data['__cache_key'] = ($scope_row !== null) ? (string)$scope_key : (string)$exact_key;
        return $data;
    }

    private function set_geo_cache($ip, array $geo)
    {
        $scope_key = $this->build_geo_cache_scope_key($ip);
        $exact_key = $this->build_geo_cache_exact_key($ip);
        if ($scope_key === '' && $exact_key === '') {
            return;
        }

        $now = time();
        $country_code = substr((string)($geo['country_code'] ?? ''), 0, 5);
        $country_name = substr((string)($geo['country_name'] ?? ''), 0, 100);
        $city = substr((string)($geo['city'] ?? ''), 0, 100);
        $hostname = trim((string)($geo['hostname'] ?? ''));
        if ($country_code === '' && $country_name === '' && $city === '' && $hostname === '') {
            return;
        }
        if ($scope_key !== '') {
            $sql = 'DELETE FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache
                    WHERE ip_address = \'' . $this->db->sql_escape($scope_key) . '\'';
            $this->db->sql_query($sql);

            $sql_ary = [
                'ip_address'   => $scope_key,
                'country_code' => $country_code,
                'country_name' => $country_name,
                'city'         => $city,
                // Le reverse DNS doit rester lié à l'IP exacte, jamais au /24 ou /48.
                'hostname'     => '',
                'cached_time'  => $now,
            ];

            $sql = 'INSERT INTO ' . $this->table_prefix . 'bastien59_stats_geo_cache '
                . $this->db->sql_build_array('INSERT', $sql_ary);
            $this->db->sql_query($sql);
        }

        if ($exact_key !== '') {
            $sql = 'DELETE FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache
                    WHERE ip_address = \'' . $this->db->sql_escape($exact_key) . '\'';
            $this->db->sql_query($sql);

            $sql_ary = [
                'ip_address'   => $exact_key,
                'country_code' => $country_code,
                'country_name' => $country_name,
                'city'         => $city,
                'hostname'     => substr($hostname, 0, 255),
                'cached_time'  => $now,
            ];

            $sql = 'INSERT INTO ' . $this->table_prefix . 'bastien59_stats_geo_cache '
                . $this->db->sql_build_array('INSERT', $sql_ary);
            $this->db->sql_query($sql);
        }
    }

    private function backfill_stats_for_ip($ip, array $geo, $ttl_days)
    {
        $country_code = strtoupper(trim((string)($geo['country_code'] ?? '')));
        $country_name = trim((string)($geo['country_name'] ?? ''));
        $hostname = trim((string)($geo['hostname'] ?? ''));

        if ($country_code === '' && $country_name === '' && $hostname === '') {
            return;
        }

        $cutoff = time() - (max(1, (int)$ttl_days) * 86400);
        $subnet_meta = $this->get_ipv4_subnet_meta($ip);
        $geo_scope_sql = ($subnet_meta !== false)
            ? 'AND user_ip LIKE \'' . $this->db->sql_escape((string)$subnet_meta['prefix_hint']) . '%\'
               AND user_ip NOT LIKE \'%:%\'
               AND INET_ATON(user_ip) BETWEEN ' . (int)$subnet_meta['start'] . ' AND ' . (int)$subnet_meta['end']
            : 'AND user_ip = \'' . $this->db->sql_escape($ip) . '\'';
        $hostname_scope_sql = 'AND user_ip = \'' . $this->db->sql_escape($ip) . '\'';

        $geo_set_parts = [];
        if ($country_code !== '') {
            $geo_set_parts[] = 'country_code = \'' . $this->db->sql_escape($country_code) . '\'';
        }
        if ($country_name !== '') {
            $geo_set_parts[] = 'country_name = \'' . $this->db->sql_escape($country_name) . '\'';
        }
        if (!empty($geo_set_parts)) {
            $sql = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                    SET ' . implode(', ', $geo_set_parts) . '
                    WHERE country_code = \'\'
                    AND is_first_visit = 1
                    AND visit_time > ' . (int)$cutoff . '
                    ' . $geo_scope_sql;
            $this->db->sql_query($sql);
        }

        if ($hostname !== '') {
            $sql = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                    SET hostname = \'' . $this->db->sql_escape($hostname) . '\'
                    WHERE hostname <> \'' . $this->db->sql_escape($hostname) . '\'
                    AND is_first_visit = 1
                    AND visit_time > ' . (int)$cutoff . '
                    ' . $hostname_scope_sql;
            $this->db->sql_query($sql);
        }
    }

    /**
     * Promeut les signaux pays-dépendants marqués en *_shadow quand la géoloc
     * est finalement connue par le cron (hors FR/CO), puis émet l'audit fail2ban.
     */
    private function process_deferred_country_signals_for_ip($ip, array $geo, $ttl_days)
    {
        $country_code = strtoupper(trim((string)($geo['country_code'] ?? '')));
        if ($country_code === '' || $country_code === '-' || $country_code === 'ZZ') {
            return;
        }
        if ($country_code === 'FR' || $country_code === 'CO') {
            return;
        }

        $shadow_to_strict = [
            'guest_fp_clone_multi_ip_shadow' => 'guest_fp_clone_multi_ip',
            'guest_cookie_clone_multi_ip_shadow' => 'guest_cookie_clone_multi_ip',
            'guest_cookie_ajax_fail_shadow' => 'guest_cookie_ajax_fail',
        ];

        $like_parts = [];
        foreach (array_keys($shadow_to_strict) as $shadow_sig) {
            $like_parts[] = "signals LIKE '%" . $this->db->sql_escape($shadow_sig) . "%'";
        }
        if (empty($like_parts)) {
            return;
        }

        $cutoff = time() - (max(1, (int)$ttl_days) * 86400);
        $subnet_meta = $this->get_ipv4_subnet_meta($ip);
        $scope_sql = ($subnet_meta !== false)
            ? 'AND user_ip LIKE \'' . $this->db->sql_escape((string)$subnet_meta['prefix_hint']) . '%\'
               AND user_ip NOT LIKE \'%:%\'
               AND INET_ATON(user_ip) BETWEEN ' . (int)$subnet_meta['start'] . ' AND ' . (int)$subnet_meta['end']
            : 'AND user_ip = \'' . $this->db->sql_escape((string)$ip) . '\'';

        $sql = 'SELECT log_id, session_id, user_id, user_ip, user_agent, page_url, screen_res, signals, bot_source
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE is_first_visit = 1
                AND user_id <= 1
                AND visit_time > ' . (int)$cutoff . '
                AND country_code = \'' . $this->db->sql_escape($country_code) . '\'
                AND signals <> \'\'
                AND (' . implode(' OR ', $like_parts) . ')
                ' . $scope_sql . '
                ORDER BY visit_time ASC';

        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result)) {
            $raw_signals = explode(',', (string)($row['signals'] ?? ''));
            $signals = [];
            foreach ($raw_signals as $sig) {
                $sig = trim((string)$sig);
                if ($sig === '') {
                    continue;
                }
                $signals[] = $sig;
            }
            if (empty($signals)) {
                continue;
            }

            $original_set = array_fill_keys($signals, true);
            $normalized = [];
            $normalized_set = [];
            $promoted_for_log = [];
            $changed = false;

            foreach ($signals as $sig) {
                if (isset($shadow_to_strict[$sig])) {
                    $strict = $shadow_to_strict[$sig];
                    $changed = true;
                    if (!isset($normalized_set[$strict])) {
                        $normalized[] = $strict;
                        $normalized_set[$strict] = true;
                    }
                    if (!isset($original_set[$strict])) {
                        $promoted_for_log[$strict] = true;
                    }
                    continue;
                }

                if (!isset($normalized_set[$sig])) {
                    $normalized[] = $sig;
                    $normalized_set[$sig] = true;
                }
            }

            if (!$changed) {
                continue;
            }

            $new_signals = substr(implode(',', $normalized), 0, 255);
            $sql_ary = [
                'signals' => $new_signals,
                'is_bot' => 1,
            ];
            if (trim((string)($row['bot_source'] ?? '')) === '') {
                $sql_ary['bot_source'] = 'behavior';
            }

            $sql_update = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                           SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                           WHERE log_id = ' . (int)$row['log_id'];
            $this->db->sql_query($sql_update);

            if (!empty($promoted_for_log)) {
                $this->write_security_audit_signal(
                    (string)($row['user_ip'] ?? ''),
                    (string)($row['session_id'] ?? ''),
                    (int)($row['user_id'] ?? 1),
                    implode(',', array_keys($promoted_for_log)),
                    (string)($row['page_url'] ?? ''),
                    (string)($row['user_agent'] ?? ''),
                    (string)($row['screen_res'] ?? ''),
                    $this->count_session_pages((string)($row['session_id'] ?? ''))
                );
            }
        }
        $this->db->sql_freeresult($result);
    }

    private function cleanup_geo_cache($ttl_days)
    {
        $cutoff = time() - (max(1, (int)$ttl_days) * 86400);
        $sql = 'DELETE FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache
                WHERE cached_time < ' . (int)$cutoff;
        $this->db->sql_query($sql);
    }

    private function backfill_session_apache_assets($deadline)
    {
        $summary = [
            'candidates' => 0,
            'updated' => 0,
            'events_seen' => 0,
            'events_matched' => 0,
            'resource_signal_candidates' => 0,
            'resource_signal_confirmed' => 0,
            'resource_signal_shadow' => 0,
            'resource_signal_strict' => 0,
            'aborted' => 0,
        ];

        if (!$this->has_apache_asset_columns()) {
            return $summary;
        }

        if (microtime(true) >= ((float)$deadline - 5.0)) {
            if ($this->is_cli_runtime()) {
                $this->cli_log('[geo_async] Apache assets: ignore (budget temps insuffisant).');
            }
            return $summary;
        }

        $settle_seconds = max(
            120,
            (int)($this->config['bastien59_stats_session_timeout'] ?? 900)
        );
        $targets = $this->get_pending_apache_asset_sessions(
            self::APACHE_ASSET_SESSION_BATCH,
            self::APACHE_ASSET_HISTORY_DAYS,
            $settle_seconds
        );
        $summary['candidates'] = count($targets);
        if (empty($targets)) {
            return $summary;
        }

        $target_sessions = [];
        $ips = [];
        $from_ts = 0;
        $to_ts = 0;
        foreach ($targets as $row) {
            $sid = (string)($row['session_id'] ?? '');
            if (!preg_match('/^[A-Za-z0-9]{32}$/', $sid)) {
                continue;
            }

            $target_sessions[$sid] = [
                'log_id' => (int)($row['log_id'] ?? 0),
                'session_id' => $sid,
                'user_ip' => (string)($row['user_ip'] ?? ''),
                'start_time' => (int)($row['start_time'] ?? 0),
                'end_time' => (int)($row['end_time'] ?? 0),
            ];
            $ip = trim((string)($row['user_ip'] ?? ''));
            if ($ip !== '') {
                $ips[$ip] = true;
            }
            $row_from = max(0, (int)($row['start_time'] ?? 0) - self::APACHE_ASSET_MATCH_LEAD_SECONDS);
            $row_to = (int)($row['end_time'] ?? 0) + self::APACHE_ASSET_MATCH_TRAIL_SECONDS;
            if ($from_ts <= 0 || ($row_from > 0 && $row_from < $from_ts)) {
                $from_ts = $row_from;
            }
            if ($row_to > $to_ts) {
                $to_ts = $row_to;
            }
        }

        $summary['candidates'] = count($target_sessions);

        if (empty($target_sessions) || empty($ips) || $from_ts <= 0 || $to_ts <= 0 || $from_ts > $to_ts) {
            return $summary;
        }

        if ($this->is_cli_runtime()) {
            $this->cli_log(sprintf(
                '[geo_async] Apache assets: %d session(s) a enrichir, fenetre=%s -> %s',
                (int)count($target_sessions),
                date('Y-m-d H:i:s', (int)$from_ts),
                date('Y-m-d H:i:s', (int)$to_ts)
            ));
        }

        $sessions_by_ip = $this->load_session_windows_for_ips(array_keys($ips), $from_ts, $to_ts);
        $counts = [];
        foreach ($target_sessions as $sid => $meta) {
            $counts[$sid] = ['banner' => 0, 'rank' => 0, 'avatar' => 0];
        }

        $this->scan_apache_asset_events(
            $sessions_by_ip,
            $target_sessions,
            $counts,
            $from_ts,
            $to_ts,
            $deadline,
            $summary
        );

        if (empty($summary['aborted'])) {
            $this->backfill_direct_resource_fallback_signals(
                $sessions_by_ip,
                $target_sessions,
                $counts,
                $from_ts,
                $to_ts,
                $deadline,
                $summary
            );
        }

        if (!empty($summary['aborted'])) {
            if ($this->is_cli_runtime()) {
                $this->cli_log('[geo_async] Apache assets: scan interrompu, report au prochain run.');
            }
            $summary['updated'] = 0;
            return $summary;
        }

        $scan_time = time();
        foreach ($target_sessions as $sid => $meta) {
            $log_id = (int)($meta['log_id'] ?? 0);
            if ($log_id <= 0) {
                continue;
            }

            $metric = $counts[$sid] ?? ['banner' => 0, 'rank' => 0, 'avatar' => 0];
            $sql = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                    SET apache_banner_hits = ' . (int)$metric['banner'] . ',
                        apache_rank_hits = ' . (int)$metric['rank'] . ',
                        apache_avatar_hits = ' . (int)$metric['avatar'] . ',
                        apache_asset_scan_time = ' . (int)$scan_time . '
                    WHERE log_id = ' . $log_id;
            $this->db->sql_query($sql);
            $summary['updated']++;
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function get_pending_apache_asset_sessions($limit, $history_days, $settle_seconds)
    {
        $limit = max(1, (int)$limit);
        $history_cutoff = time() - (max(1, (int)$history_days) * 86400);
        $settled_cutoff = time() - max(60, (int)$settle_seconds);

        $sql = 'SELECT landing.log_id, landing.session_id, landing.user_ip, landing.user_agent,
                       landing.visit_time AS start_time, MAX(s.visit_time) AS end_time
                FROM ' . $this->table_prefix . 'bastien59_stats landing
                INNER JOIN ' . $this->table_prefix . 'bastien59_stats s
                    ON s.session_id = landing.session_id
                WHERE landing.is_first_visit = 1
                AND landing.apache_asset_scan_time = 0
                AND landing.user_ip <> \'\'
                AND landing.visit_time >= ' . (int)$history_cutoff . '
                AND landing.session_id REGEXP \'^[A-Za-z0-9]{32}$\'
                GROUP BY landing.log_id, landing.session_id, landing.user_ip, landing.user_agent, landing.visit_time
                HAVING MAX(s.visit_time) <= ' . (int)$settled_cutoff . '
                ORDER BY end_time DESC';

        $rows = [];
        $result = $this->db->sql_query_limit($sql, $limit);
        while ($row = $this->db->sql_fetchrow($result)) {
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        return $rows;
    }

    /**
     * @param string[] $ips
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function load_session_windows_for_ips(array $ips, $from_ts, $to_ts)
    {
        $sessions_by_ip = [];
        if (empty($ips) || $from_ts <= 0 || $to_ts <= 0 || $from_ts > $to_ts) {
            return $sessions_by_ip;
        }

        foreach (array_chunk($ips, 500) as $chunk) {
            $quoted = [];
            foreach ($chunk as $ip) {
                $ip = trim((string)$ip);
                if ($ip === '') {
                    continue;
                }
                $quoted[] = '\'' . $this->db->sql_escape($ip) . '\'';
            }
            if (empty($quoted)) {
                continue;
            }

            $sql = 'SELECT user_ip, session_id, MAX(user_agent) AS user_agent,
                           MIN(visit_time) AS start_time, MAX(visit_time) AS end_time
                    FROM ' . $this->table_prefix . 'bastien59_stats
                    WHERE user_ip IN (' . implode(',', $quoted) . ')
                    AND visit_time BETWEEN ' . (int)$from_ts . ' AND ' . (int)$to_ts . '
                    AND session_id REGEXP \'^[A-Za-z0-9]{32}$\'
                    GROUP BY user_ip, session_id
                    ORDER BY user_ip ASC, start_time ASC';

            $result = $this->db->sql_query($sql);
            while ($row = $this->db->sql_fetchrow($result)) {
                $ip = (string)($row['user_ip'] ?? '');
                if ($ip === '') {
                    continue;
                }
                if (!isset($sessions_by_ip[$ip])) {
                    $sessions_by_ip[$ip] = [];
                }
                $sessions_by_ip[$ip][] = [
                    'session_id' => (string)($row['session_id'] ?? ''),
                    'start_time' => (int)($row['start_time'] ?? 0),
                    'end_time' => (int)($row['end_time'] ?? 0),
                    'ua_norm' => $this->normalize_user_agent_for_match((string)($row['user_agent'] ?? '')),
                ];
            }
            $this->db->sql_freeresult($result);
        }

        return $sessions_by_ip;
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $sessions_by_ip
     * @param array<string, array<string, mixed>> $target_sessions
     * @param array<string, array<string, int>> $counts
     * @param array<string, int> $summary
     */
    private function scan_apache_asset_events(array $sessions_by_ip, array $target_sessions, array &$counts, $from_ts, $to_ts, $deadline, array &$summary)
    {
        if (empty($sessions_by_ip) || empty($target_sessions)) {
            return;
        }

        $target_session_ids = array_fill_keys(array_keys($target_sessions), true);
        $logs = [
            '/var/log/apache2/forum_access.log.2.gz',
            '/var/log/apache2/forum_access.log.1',
            '/var/log/apache2/forum_access.log',
        ];
        $line_regex = '~^(\S+) \S+ \S+ \[([^\]]+)\] "([A-Z]+) ([^" ]+) [^"]*" (\d{3}) \S+ "([^"]*)" "([^"]*)"~';

        foreach ($logs as $log_file) {
            if (microtime(true) >= ((float)$deadline - 2.0)) {
                $summary['aborted'] = 1;
                if ($this->is_cli_runtime()) {
                    $this->cli_log('[geo_async] Apache assets: arret anticipe (budget temps atteint).');
                }
                break;
            }
            if (!is_file($log_file)) {
                continue;
            }

            $is_gz = (substr($log_file, -3) === '.gz');
            $fh = $is_gz ? @gzopen($log_file, 'rb') : @fopen($log_file, 'rb');
            if (!$fh) {
                continue;
            }

            while (true) {
                if (microtime(true) >= ((float)$deadline - 2.0)) {
                    $summary['aborted'] = 1;
                    break;
                }

                $line = $is_gz ? gzgets($fh) : fgets($fh);
                if ($line === false) {
                    break;
                }
                if (!preg_match($line_regex, $line, $m)) {
                    continue;
                }

                $ts = $this->parse_apache_log_ts((string)$m[2]);
                if ($ts <= 0 || $ts < $from_ts || $ts > $to_ts) {
                    continue;
                }

                $method = strtoupper((string)$m[3]);
                if ($method !== 'GET' && $method !== 'HEAD') {
                    continue;
                }

                $status = (int)$m[5];
                if ($status < 200 || $status >= 400) {
                    continue;
                }

                $asset_type = $this->classify_apache_asset_uri((string)$m[4]);
                if ($asset_type === '') {
                    continue;
                }

                $referer = (string)$m[6];
                if (!$this->is_forum_page_referer($referer)) {
                    continue;
                }

                $summary['events_seen']++;

                $ip = (string)$m[1];
                if (!isset($sessions_by_ip[$ip])) {
                    continue;
                }

                $sid = $this->match_apache_asset_to_session(
                    $sessions_by_ip[$ip],
                    $ts,
                    $this->normalize_user_agent_for_match((string)$m[7])
                );
                if ($sid === '' || !isset($target_session_ids[$sid])) {
                    continue;
                }

                $counts[$sid][$asset_type]++;
                $summary['events_matched']++;
            }

            if ($is_gz) {
                @gzclose($fh);
            } else {
                @fclose($fh);
            }
        }
    }

    /**
     * Détecte le motif:
     * ressource directe opaque -> fallback HTML immédiat -> aucun bootstrap navigateur.
     *
     * Le signal strict est réservé aux cas les plus solides:
     * - fallback `view=print`
     * - IP déjà vue sur ce motif
     * - PTR absent (`hostname = '-'`)
     * - PTR non résidentiel
     *
     * Les PTR résidentiels restent en observation `_shadow`.
     *
     * @param array<string, array<int, array<string, mixed>>> $sessions_by_ip
     * @param array<string, array<string, mixed>> $target_sessions
     * @param array<string, array<string, int>> $counts
     * @param array<string, int> $summary
     */
    private function backfill_direct_resource_fallback_signals(array $sessions_by_ip, array $target_sessions, array $counts, $from_ts, $to_ts, $deadline, array &$summary)
    {
        if (empty($target_sessions)) {
            return;
        }

        if (microtime(true) >= ((float)$deadline - 2.0)) {
            $summary['aborted'] = 1;
            return;
        }

        $session_ids = array_keys($target_sessions);
        $rows_by_session = $this->load_session_rows_for_sessions($session_ids);
        $snapshots = $this->load_session_bootstrap_snapshots($session_ids);
        $candidates = [];

        foreach ($target_sessions as $sid => $session_meta) {
            $candidate = $this->build_direct_resource_signal_candidate(
                $session_meta,
                $rows_by_session[$sid] ?? [],
                $snapshots[$sid] ?? [],
                $counts[$sid] ?? ['banner' => 0, 'rank' => 0, 'avatar' => 0]
            );
            if ($candidate === false) {
                continue;
            }
            $candidates[$sid] = $candidate;
        }

        $summary['resource_signal_candidates'] = count($candidates);
        if (empty($candidates)) {
            return;
        }

        $matches = $this->scan_apache_direct_resource_candidates(
            $sessions_by_ip,
            $candidates,
            $from_ts,
            $to_ts,
            $deadline,
            $summary
        );

        if (empty($matches)) {
            return;
        }

        foreach ($matches as $sid => $_matched) {
            if (!isset($candidates[$sid])) {
                continue;
            }
            $this->persist_direct_resource_signal_candidate($candidates[$sid], $summary);
        }
    }

    /**
     * @param string[] $session_ids
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function load_session_rows_for_sessions(array $session_ids)
    {
        $rows_by_session = [];
        $valid_ids = [];
        foreach ($session_ids as $sid) {
            $sid = trim((string)$sid);
            if (preg_match('/^[A-Za-z0-9]{32}$/', $sid)) {
                $valid_ids[] = $sid;
            }
        }
        if (empty($valid_ids)) {
            return $rows_by_session;
        }

        foreach (array_chunk($valid_ids, 400) as $chunk) {
            $quoted = [];
            foreach ($chunk as $sid) {
                $quoted[] = '\'' . $this->db->sql_escape($sid) . '\'';
            }

            $sql = 'SELECT session_id, log_id, user_id, user_ip, user_agent, hostname, country_code,
                           bot_source, visit_time, page_url, referer, screen_res, signals
                    FROM ' . $this->table_prefix . 'bastien59_stats
                    WHERE session_id IN (' . implode(',', $quoted) . ')
                    ORDER BY session_id ASC, visit_time ASC, log_id ASC';

            $result = $this->db->sql_query($sql);
            while ($row = $this->db->sql_fetchrow($result)) {
                $sid = (string)($row['session_id'] ?? '');
                if ($sid === '') {
                    continue;
                }
                if (!isset($rows_by_session[$sid])) {
                    $rows_by_session[$sid] = [];
                }
                $rows_by_session[$sid][] = $row;
            }
            $this->db->sql_freeresult($result);
        }

        return $rows_by_session;
    }

    /**
     * @param string[] $session_ids
     * @return array<string, array<string, int>>
     */
    private function load_session_bootstrap_snapshots(array $session_ids)
    {
        $snapshots = [];
        $valid_ids = [];
        foreach ($session_ids as $sid) {
            $sid = trim((string)$sid);
            if (preg_match('/^[A-Za-z0-9]{32}$/', $sid)) {
                $valid_ids[] = $sid;
                $snapshots[$sid] = [
                    'row_count' => 0,
                    'has_screen_res' => 0,
                    'has_screen_res_ajax' => 0,
                    'scroll_down' => 0,
                    'ajax_interact_mask' => 0,
                    'ajax_scroll_events' => 0,
                    'cursor_track_points' => 0,
                    'reactions_css_seen' => 0,
                    'reactions_js_seen' => 0,
                ];
            }
        }
        if (empty($valid_ids)) {
            return $snapshots;
        }

        $fields = [
            'session_id',
            'COUNT(*) AS row_count',
            "MAX(CASE WHEN screen_res <> '' THEN 1 ELSE 0 END) AS has_screen_res",
        ];
        if ($this->has_ajax_telemetry_columns()) {
            $fields[] = "MAX(CASE WHEN screen_res_ajax <> '' THEN 1 ELSE 0 END) AS has_screen_res_ajax";
            $fields[] = 'MAX(scroll_down_ajax) AS scroll_down';
        }
        if ($this->has_ajax_advanced_columns()) {
            $fields[] = 'MAX(ajax_interact_mask) AS ajax_interact_mask';
            $fields[] = 'MAX(ajax_scroll_events) AS ajax_scroll_events';
        }
        if ($this->has_cursor_columns()) {
            $fields[] = 'MAX(cursor_track_points) AS cursor_track_points';
        }
        if ($this->has_reactions_probe_columns()) {
            $fields[] = 'MAX(reactions_css_seen) AS reactions_css_seen';
            $fields[] = 'MAX(reactions_js_seen) AS reactions_js_seen';
        }

        foreach (array_chunk($valid_ids, 400) as $chunk) {
            $quoted = [];
            foreach ($chunk as $sid) {
                $quoted[] = '\'' . $this->db->sql_escape($sid) . '\'';
            }

            $sql = 'SELECT ' . implode(', ', $fields) . '
                    FROM ' . $this->table_prefix . 'bastien59_stats
                    WHERE session_id IN (' . implode(',', $quoted) . ')
                    GROUP BY session_id';

            $this->db->sql_return_on_error(true);
            $result = $this->db->sql_query($sql);
            $has_error = (bool)$this->db->get_sql_error_triggered();
            $this->db->sql_return_on_error(false);
            if ($has_error || $result === false) {
                if ($result !== false) {
                    $this->db->sql_freeresult($result);
                }
                continue;
            }

            while ($row = $this->db->sql_fetchrow($result)) {
                $sid = (string)($row['session_id'] ?? '');
                if ($sid === '' || !isset($snapshots[$sid])) {
                    continue;
                }
                $snapshots[$sid]['row_count'] = (int)($row['row_count'] ?? 0);
                $snapshots[$sid]['has_screen_res'] = (int)($row['has_screen_res'] ?? 0);
                $snapshots[$sid]['has_screen_res_ajax'] = (int)($row['has_screen_res_ajax'] ?? 0);
                $snapshots[$sid]['scroll_down'] = (int)($row['scroll_down'] ?? 0);
                $snapshots[$sid]['ajax_interact_mask'] = (int)($row['ajax_interact_mask'] ?? 0);
                $snapshots[$sid]['ajax_scroll_events'] = (int)($row['ajax_scroll_events'] ?? 0);
                $snapshots[$sid]['cursor_track_points'] = (int)($row['cursor_track_points'] ?? 0);
                $snapshots[$sid]['reactions_css_seen'] = (int)($row['reactions_css_seen'] ?? 0);
                $snapshots[$sid]['reactions_js_seen'] = (int)($row['reactions_js_seen'] ?? 0);
            }
            $this->db->sql_freeresult($result);
        }

        return $snapshots;
    }

    /**
     * @param array<string, mixed> $session_meta
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, int> $snapshot
     * @param array<string, int> $asset_counts
     * @return array<string, mixed>|false
     */
    private function build_direct_resource_signal_candidate(array $session_meta, array $rows, array $snapshot, array $asset_counts)
    {
        if (count($rows) !== 2) {
            return false;
        }

        $landing = $rows[0];
        $fallback = $rows[1];
        if ((int)($landing['user_id'] ?? 0) > 1) {
            return false;
        }

        $landing_url = $this->normalize_relative_target((string)($landing['page_url'] ?? ''));
        $fallback_url = $this->normalize_relative_target((string)($fallback['page_url'] ?? ''));
        $fallback_referer = $this->normalize_relative_target((string)($fallback['referer'] ?? ''));

        if ($this->classify_direct_resource_entry_uri($landing_url) === '') {
            return false;
        }
        if (!$this->is_forum_page_target($fallback_url)) {
            return false;
        }
        if ($fallback_referer === '' || !hash_equals($landing_url, $fallback_referer)) {
            return false;
        }

        $landing_time = (int)($landing['visit_time'] ?? 0);
        $fallback_time = (int)($fallback['visit_time'] ?? 0);
        $delta = $fallback_time - $landing_time;
        if ($landing_time <= 0 || $fallback_time <= 0 || $delta < 0 || $delta > self::DIRECT_RESOURCE_FALLBACK_MAX_SECONDS) {
            return false;
        }

        if ((int)($snapshot['row_count'] ?? 0) !== 2) {
            return false;
        }
        if ((int)($snapshot['has_screen_res'] ?? 0) !== 0) {
            return false;
        }
        if ((int)($snapshot['has_screen_res_ajax'] ?? 0) !== 0) {
            return false;
        }
        if ((int)($snapshot['scroll_down'] ?? 0) !== 0) {
            return false;
        }
        if ((int)($snapshot['ajax_interact_mask'] ?? 0) !== 0) {
            return false;
        }
        if ((int)($snapshot['ajax_scroll_events'] ?? 0) !== 0) {
            return false;
        }
        if ((int)($snapshot['cursor_track_points'] ?? 0) !== 0) {
            return false;
        }
        if ((int)($snapshot['reactions_css_seen'] ?? 0) !== 0) {
            return false;
        }
        if ((int)($snapshot['reactions_js_seen'] ?? 0) !== 0) {
            return false;
        }
        if ((int)($asset_counts['banner'] ?? 0) !== 0 || (int)($asset_counts['rank'] ?? 0) !== 0 || (int)($asset_counts['avatar'] ?? 0) !== 0) {
            return false;
        }

        return [
            'log_id' => (int)($landing['log_id'] ?? 0),
            'session_id' => (string)($session_meta['session_id'] ?? ''),
            'user_id' => (int)($landing['user_id'] ?? 1),
            'user_ip' => (string)($session_meta['user_ip'] ?? ''),
            'user_agent' => (string)($landing['user_agent'] ?? ''),
            'screen_res' => (string)($landing['screen_res'] ?? ''),
            'page_url' => $landing_url,
            'fallback_url' => $fallback_url,
            'landing_time' => $landing_time,
            'fallback_time' => $fallback_time,
            'signals' => (string)($landing['signals'] ?? ''),
            'bot_source' => (string)($landing['bot_source'] ?? ''),
            'hostname' => trim((string)($landing['hostname'] ?? '')),
            'country_code' => strtoupper(trim((string)($landing['country_code'] ?? ''))),
            'view_print' => (strpos($fallback_url, 'view=print') !== false),
        ];
    }

    /**
     * @param array<string, array<int, array<string, mixed>>> $sessions_by_ip
     * @param array<string, array<string, mixed>> $candidates
     * @param array<string, int> $summary
     * @return array<string, bool>
     */
    private function scan_apache_direct_resource_candidates(array $sessions_by_ip, array $candidates, $from_ts, $to_ts, $deadline, array &$summary)
    {
        $matches = [];
        if (empty($candidates) || empty($sessions_by_ip)) {
            return $matches;
        }

        $candidate_ids = array_fill_keys(array_keys($candidates), true);
        $events_by_session = [];
        $logs = [
            '/var/log/apache2/forum_access.log.2.gz',
            '/var/log/apache2/forum_access.log.1',
            '/var/log/apache2/forum_access.log',
        ];
        $line_regex = '~^(\S+) \S+ \S+ \[([^\]]+)\] "([A-Z]+) ([^" ]+) [^"]*" (\d{3}) \S+ "([^"]*)" "([^"]*)"~';

        foreach ($logs as $log_file) {
            if (microtime(true) >= ((float)$deadline - 2.0)) {
                $summary['aborted'] = 1;
                break;
            }
            if (!is_file($log_file)) {
                continue;
            }

            $is_gz = (substr($log_file, -3) === '.gz');
            $fh = $is_gz ? @gzopen($log_file, 'rb') : @fopen($log_file, 'rb');
            if (!$fh) {
                continue;
            }

            while (true) {
                if (microtime(true) >= ((float)$deadline - 2.0)) {
                    $summary['aborted'] = 1;
                    break;
                }

                $line = $is_gz ? gzgets($fh) : fgets($fh);
                if ($line === false) {
                    break;
                }
                if (!preg_match($line_regex, $line, $m)) {
                    continue;
                }

                $ts = $this->parse_apache_log_ts((string)$m[2]);
                if ($ts <= 0 || $ts < $from_ts || $ts > $to_ts) {
                    continue;
                }

                $method = strtoupper((string)$m[3]);
                if ($method !== 'GET' && $method !== 'HEAD') {
                    continue;
                }

                $ip = (string)$m[1];
                if (!isset($sessions_by_ip[$ip])) {
                    continue;
                }

                $sid = $this->match_apache_asset_to_session(
                    $sessions_by_ip[$ip],
                    $ts,
                    $this->normalize_user_agent_for_match((string)$m[7])
                );
                if ($sid === '' || !isset($candidate_ids[$sid])) {
                    continue;
                }

                $url = $this->normalize_relative_target((string)$m[4]);
                if ($url === '') {
                    continue;
                }

                if (!isset($events_by_session[$sid])) {
                    $events_by_session[$sid] = [];
                }
                $events_by_session[$sid][] = [
                    'ts' => $ts,
                    'url' => $url,
                    'status' => (int)$m[5],
                    'referer' => $this->normalize_relative_target((string)$m[6]),
                ];
            }

            if ($is_gz) {
                @gzclose($fh);
            } else {
                @fclose($fh);
            }
        }

        if (!empty($summary['aborted'])) {
            return $matches;
        }

        foreach ($candidates as $sid => $candidate) {
            $events = $events_by_session[$sid] ?? [];
            if (!$this->evaluate_direct_resource_apache_sequence($candidate, $events)) {
                continue;
            }
            $matches[$sid] = true;
            $summary['resource_signal_confirmed']++;
        }

        return $matches;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<int, array<string, mixed>> $events
     */
    private function evaluate_direct_resource_apache_sequence(array $candidate, array $events)
    {
        if (empty($events)) {
            return false;
        }

        usort($events, function ($a, $b) {
            $ats = (int)($a['ts'] ?? 0);
            $bts = (int)($b['ts'] ?? 0);
            if ($ats === $bts) {
                return strcmp((string)($a['url'] ?? ''), (string)($b['url'] ?? ''));
            }
            return ($ats < $bts) ? -1 : 1;
        });

        $landing_url = (string)($candidate['page_url'] ?? '');
        $fallback_url = (string)($candidate['fallback_url'] ?? '');
        $landing_time = (int)($candidate['landing_time'] ?? 0);
        if ($landing_url === '' || $fallback_url === '' || $landing_time <= 0) {
            return false;
        }

        $landing_idx = -1;
        foreach ($events as $idx => $event) {
            if ((string)($event['url'] ?? '') !== $landing_url) {
                continue;
            }
            $ts = (int)($event['ts'] ?? 0);
            if ($ts < ($landing_time - 5) || $ts > ($landing_time + 5)) {
                continue;
            }
            $landing_idx = (int)$idx;
            break;
        }

        if ($landing_idx < 0) {
            return false;
        }

        $landing_event = $events[$landing_idx];
        $fallback_idx = -1;
        for ($i = $landing_idx + 1, $n = count($events); $i < $n; $i++) {
            $event = $events[$i];
            $delta = (int)($event['ts'] ?? 0) - (int)($landing_event['ts'] ?? 0);
            if ($delta < 0) {
                continue;
            }
            if ($delta > self::DIRECT_RESOURCE_FALLBACK_MAX_SECONDS) {
                break;
            }

            if ((string)($event['url'] ?? '') !== $fallback_url) {
                return false;
            }
            if ((int)($event['status'] ?? 0) >= 400) {
                return false;
            }
            if ((string)($event['referer'] ?? '') !== $landing_url) {
                return false;
            }

            $fallback_idx = $i;
            break;
        }

        if ($fallback_idx < 0) {
            return false;
        }

        $fallback_ts = (int)($events[$fallback_idx]['ts'] ?? 0);
        for ($i = $fallback_idx + 1, $n = count($events); $i < $n; $i++) {
            $next_ts = (int)($events[$i]['ts'] ?? 0);
            if ($next_ts <= ($fallback_ts + self::DIRECT_RESOURCE_NOFOLLOW_SECONDS)) {
                return false;
            }
            break;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, int> $summary
     */
    private function persist_direct_resource_signal_candidate(array $candidate, array &$summary)
    {
        $strict_signal = 'direct_resource_page_fallback_no_bootstrap';
        $shadow_signal = $strict_signal . '_shadow';
        $existing_signals = $this->explode_signals_csv((string)($candidate['signals'] ?? ''));

        if (isset($existing_signals[$strict_signal])) {
            return;
        }

        $hostname = $this->resolve_direct_resource_hostname_for_candidate($candidate);
        $is_no_ptr = ($hostname === '-');
        $is_residential = (!$is_no_ptr && $hostname !== '' && $this->is_residentialish_hostname($hostname));
        $is_repeat = $this->has_prior_direct_resource_signal_for_ip(
            (string)($candidate['user_ip'] ?? ''),
            (int)($candidate['landing_time'] ?? 0),
            (int)($candidate['log_id'] ?? 0)
        );
        $is_view_print = !empty($candidate['view_print']);

        $promote_strict = $is_view_print || $is_repeat || $is_no_ptr || ($hostname !== '' && !$is_residential);
        if (!$promote_strict && isset($existing_signals[$shadow_signal])) {
            return;
        }

        $updated_signals = $promote_strict
            ? $this->upsert_signal_csv((string)($candidate['signals'] ?? ''), $strict_signal, [$shadow_signal])
            : $this->upsert_signal_csv((string)($candidate['signals'] ?? ''), $shadow_signal);

        if ($updated_signals === (string)($candidate['signals'] ?? '')) {
            return;
        }

        $sql_ary = [
            'signals' => substr($updated_signals, 0, 255),
        ];
        if ($hostname !== '' && $hostname !== (string)($candidate['hostname'] ?? '')) {
            $sql_ary['hostname'] = substr($hostname, 0, 255);
        }
        if ($promote_strict) {
            $sql_ary['is_bot'] = 1;
            if (trim((string)($candidate['bot_source'] ?? '')) === '') {
                $sql_ary['bot_source'] = 'behavior';
            }
        }

        $sql = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                SET ' . $this->db->sql_build_array('UPDATE', $sql_ary) . '
                WHERE log_id = ' . (int)($candidate['log_id'] ?? 0);
        $this->db->sql_query($sql);

        if ($promote_strict) {
            $summary['resource_signal_strict']++;
            $this->write_security_audit_signal(
                (string)($candidate['user_ip'] ?? ''),
                (string)($candidate['session_id'] ?? ''),
                (int)($candidate['user_id'] ?? 1),
                $strict_signal,
                (string)($candidate['page_url'] ?? ''),
                (string)($candidate['user_agent'] ?? ''),
                (string)($candidate['screen_res'] ?? ''),
                $this->count_session_pages((string)($candidate['session_id'] ?? ''))
            );
        } else {
            $summary['resource_signal_shadow']++;
        }
    }

    /**
     * @param array<string, mixed> $candidate
     */
    private function resolve_direct_resource_hostname_for_candidate(array $candidate)
    {
        $hostname = trim((string)($candidate['hostname'] ?? ''));
        if ($hostname !== '') {
            return $hostname;
        }

        $ip = trim((string)($candidate['user_ip'] ?? ''));
        if ($ip === '') {
            return '';
        }

        $ttl_days = max(1, (int)($this->config['bastien59_stats_geo_cache_ttl_days'] ?? 45));
        $cached = $this->get_geo_cache($ip, $ttl_days);
        if (is_array($cached)) {
            $cached_hostname = trim((string)($cached['hostname'] ?? ''));
            if ($cached_hostname !== '') {
                return $cached_hostname;
            }
        }

        $resolved = trim((string)$this->resolve_hostname($ip));
        return $resolved;
    }

    private function has_prior_direct_resource_signal_for_ip($ip, $visit_time, $exclude_log_id)
    {
        $ip = trim((string)$ip);
        if ($ip === '') {
            return false;
        }

        $cutoff = max(0, (int)$visit_time - (self::DIRECT_RESOURCE_SIGNAL_HISTORY_DAYS * 86400));
        $strict = $this->db->sql_escape('direct_resource_page_fallback_no_bootstrap');
        $shadow = $this->db->sql_escape('direct_resource_page_fallback_no_bootstrap_shadow');

        $sql = 'SELECT COUNT(*) AS cnt
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE is_first_visit = 1
                AND user_ip = \'' . $this->db->sql_escape($ip) . '\'
                AND visit_time >= ' . (int)$cutoff . '
                AND log_id <> ' . (int)$exclude_log_id . '
                AND (
                    visit_time < ' . (int)$visit_time . '
                    OR (visit_time = ' . (int)$visit_time . ' AND log_id < ' . (int)$exclude_log_id . ')
                )
                AND (
                    signals LIKE \'%' . $strict . '%\'
                    OR signals LIKE \'%' . $shadow . '%\'
                )';

        $result = $this->db->sql_query_limit($sql, 1);
        $count = (int)$this->db->sql_fetchfield('cnt');
        $this->db->sql_freeresult($result);

        return ($count > 0);
    }

    private function is_residentialish_hostname($hostname)
    {
        $host = strtolower(trim((string)$hostname));
        if ($host === '' || $host === '-') {
            return false;
        }

        $patterns = [
            '~\.rev\.sfr\.net$~',
            '~\.bbox\.fr$~',
            '~\.wanadoo\.fr$~',
            '~\.proxad\.net$~',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $host)) {
                return true;
            }
        }

        return false;
    }

    private function has_apache_asset_columns()
    {
        if ($this->has_apache_asset_columns !== null) {
            return $this->has_apache_asset_columns;
        }

        $sql = 'SELECT apache_banner_hits, apache_rank_hits, apache_avatar_hits, apache_asset_scan_time
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_apache_asset_columns = !$has_error;
        return $this->has_apache_asset_columns;
    }

    private function has_ajax_telemetry_columns()
    {
        if ($this->has_ajax_telemetry_columns !== null) {
            return $this->has_ajax_telemetry_columns;
        }

        $sql = 'SELECT screen_res_ajax, scroll_down_ajax, ajax_seen_time
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_ajax_telemetry_columns = !$has_error;
        return $this->has_ajax_telemetry_columns;
    }

    private function has_ajax_advanced_columns()
    {
        if ($this->has_ajax_advanced_columns !== null) {
            return $this->has_ajax_advanced_columns;
        }

        $sql = 'SELECT ajax_interact_mask, ajax_scroll_events
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_ajax_advanced_columns = !$has_error;
        return $this->has_ajax_advanced_columns;
    }

    private function has_cursor_columns()
    {
        if ($this->has_cursor_columns !== null) {
            return $this->has_cursor_columns;
        }

        $sql = 'SELECT cursor_track_points
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_cursor_columns = !$has_error;
        return $this->has_cursor_columns;
    }

    private function has_reactions_probe_columns()
    {
        if ($this->has_reactions_probe_columns !== null) {
            return $this->has_reactions_probe_columns;
        }

        $sql = 'SELECT reactions_extension_expected, reactions_css_seen, reactions_js_seen
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_reactions_probe_columns = !$has_error;
        return $this->has_reactions_probe_columns;
    }

    private function parse_apache_log_ts($raw)
    {
        static $cache = [];
        $key = (string)$raw;
        if (isset($cache[$key])) {
            return $cache[$key];
        }

        $dt = \DateTime::createFromFormat('d/M/Y:H:i:s O', $key);
        $cache[$key] = $dt ? (int)$dt->getTimestamp() : 0;
        return $cache[$key];
    }

    private function classify_apache_asset_uri($uri)
    {
        $raw = $this->normalize_relative_target((string)$uri);
        if ($raw === '') {
            return '';
        }

        $path = parse_url($raw, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }
        $path = strtolower($path);

        if (preg_match('~^/images/bannieres/forumbanniere[^/?]*\.(?:jpe?g|png|gif|webp|mp4)$~i', $path)) {
            return 'banner';
        }
        if (preg_match('~^/images/ranks/[^/?]+\.(?:jpe?g|png|gif|webp)$~i', $path)) {
            return 'rank';
        }
        if (preg_match('~^/images/avatars/.+$~i', $path)) {
            return 'avatar';
        }
        if ($path === '/download/file.php') {
            $query = parse_url($raw, PHP_URL_QUERY);
            if (is_string($query) && preg_match('/(?:^|&)avatar=/', $query)) {
                return 'avatar';
            }
        }

        return '';
    }

    private function is_forum_page_referer($referer)
    {
        return $this->is_forum_page_target($referer);
    }

    private function is_forum_page_target($url)
    {
        $raw = $this->normalize_relative_target((string)$url);
        if ($raw === '') {
            return false;
        }

        $path = parse_url($raw, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return false;
        }

        if ($path === '/') {
            return true;
        }

        return (bool)preg_match(
            '~^/(?:index\.php|viewtopic\.php|viewforum\.php|memberlist\.php|search\.php|posting\.php|ucp\.php|mcp\.php|faq\.php)(?:$|[/?])~i',
            $path
        ) || (bool)preg_match('~^/app\.php(?:/[^?]*)?$~i', $path);
    }

    private function classify_direct_resource_entry_uri($uri)
    {
        $raw = $this->normalize_relative_target((string)$uri);
        if ($raw === '') {
            return '';
        }

        $path = parse_url($raw, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return '';
        }

        if (preg_match('~^/[a-f0-9]{10,}\.(?:mp4|m4v|mov|avi|mkv|webm|jpg|jpeg|png|gif|webp|bmp|ogg|mpeg|mpg)$~i', $path)) {
            return 'opaque_media';
        }

        return '';
    }

    private function normalize_relative_target($url)
    {
        $raw = trim((string)$url);
        if ($raw === '' || $raw === '-') {
            return '';
        }

        $path = parse_url($raw, PHP_URL_PATH);
        if (!is_string($path)) {
            if (strpos($raw, '/') !== 0) {
                return '';
            }
            $path = $raw;
        }
        if ($path === '') {
            $path = '/';
        }

        $query = parse_url($raw, PHP_URL_QUERY);
        return ($query !== null && $query !== '')
            ? ($path . '?' . $query)
            : $path;
    }

    private function explode_signals_csv($signals_csv)
    {
        $set = [];
        foreach (explode(',', (string)$signals_csv) as $item) {
            $item = trim((string)$item);
            if ($item === '') {
                continue;
            }
            $set[$item] = true;
        }
        return $set;
    }

    private function upsert_signal_csv($signals_csv, $add_signal, array $remove_signals = [])
    {
        $target = trim((string)$add_signal);
        if ($target === '') {
            return (string)$signals_csv;
        }

        $remove_lookup = [];
        foreach ($remove_signals as $remove_signal) {
            $remove_signal = trim((string)$remove_signal);
            if ($remove_signal !== '') {
                $remove_lookup[$remove_signal] = true;
            }
        }

        $seen = [];
        $list = [];
        foreach (explode(',', (string)$signals_csv) as $item) {
            $item = trim((string)$item);
            if ($item === '' || isset($seen[$item]) || isset($remove_lookup[$item])) {
                continue;
            }
            $seen[$item] = true;
            $list[] = $item;
        }

        if (!isset($seen[$target])) {
            $list[] = $target;
        }

        return implode(',', $list);
    }

    private function normalize_user_agent_for_match($user_agent)
    {
        return strtolower(substr(trim((string)$user_agent), 0, 254));
    }

    /**
     * @param array<int, array<string, mixed>> $sessions
     */
    private function match_apache_asset_to_session(array $sessions, $ts, $ua_norm)
    {
        $ua_norm = $this->normalize_user_agent_for_match($ua_norm);
        $matching_exact = [];
        $matching_fallback = [];

        foreach ($sessions as $session) {
            $start_time = (int)($session['start_time'] ?? 0);
            $end_time = (int)($session['end_time'] ?? 0);
            if ($start_time <= 0 || $end_time <= 0) {
                continue;
            }
            if ($ts < ($start_time - self::APACHE_ASSET_MATCH_LEAD_SECONDS)) {
                continue;
            }
            if ($ts > ($end_time + self::APACHE_ASSET_MATCH_TRAIL_SECONDS)) {
                continue;
            }

            $session_ua = (string)($session['ua_norm'] ?? '');
            if ($ua_norm !== '' && $session_ua !== '' && $ua_norm === $session_ua) {
                $matching_exact[] = $session;
            } else {
                $matching_fallback[] = $session;
            }
        }

        $candidates = !empty($matching_exact) ? $matching_exact : $matching_fallback;
        if (empty($candidates)) {
            return '';
        }

        $best_sid = '';
        $best_start = -1;
        $best_end = -1;
        foreach ($candidates as $session) {
            $sid = (string)($session['session_id'] ?? '');
            $start_time = (int)($session['start_time'] ?? 0);
            $end_time = (int)($session['end_time'] ?? 0);
            if (!preg_match('/^[A-Za-z0-9]{32}$/', $sid)) {
                continue;
            }
            if ($start_time > $best_start || ($start_time === $best_start && $end_time > $best_end)) {
                $best_sid = $sid;
                $best_start = $start_time;
                $best_end = $end_time;
            }
        }

        return $best_sid;
    }

    private function count_session_pages($session_id)
    {
        $sid = trim((string)$session_id);
        if (!preg_match('/^[A-Za-z0-9]{32}$/', $sid)) {
            return 1;
        }

        $sql = 'SELECT COUNT(*) AS cnt
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE session_id = \'' . $this->db->sql_escape($sid) . '\'';
        $result = $this->db->sql_query_limit($sql, 1);
        $count = (int)$this->db->sql_fetchfield('cnt');
        $this->db->sql_freeresult($result);
        return max(1, $count);
    }

    private function write_security_audit_signal($ip, $session_id, $user_id, $signals, $page_url, $user_agent, $screen_res, $page_count)
    {
        $signals_str = trim((string)$signals);
        if ($signals_str === '') {
            return;
        }

        $dedup_key = md5((string)$session_id . '|' . $signals_str);
        $dedup_file = sys_get_temp_dir() . '/sec_audit_' . $dedup_key;
        if (@file_exists($dedup_file) && (time() - @filemtime($dedup_file)) < 3600) {
            return;
        }
        @touch($dedup_file);

        $log_file = $this->config['bastien59_stats_audit_log_path'] ?? '/var/log/security_audit.log';
        $ts = date('Y-m-d H:i:s');
        $ua_safe = str_replace('"', '\\"', substr((string)$user_agent, 0, 500));
        $page_safe = str_replace('"', '\\"', substr((string)$page_url, 0, 500));
        $res = trim((string)$screen_res);
        if ($res === '') {
            $res = '-';
        }

        $line = sprintf(
            '%s PHPBB-SIGNAL ip=%s session=%s user_id=%d signals="%s" page="%s" ua="%s" screen_res=%s page_count=%d',
            $ts,
            (string)$ip,
            (string)$session_id,
            (int)$user_id,
            $signals_str,
            $page_safe,
            $ua_safe,
            $res,
            (int)$page_count
        );
        @file_put_contents($log_file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    private function lookup_geo($ip, array &$meta = [])
    {
        $meta = [
            'http_code' => null,
            'remaining' => null,
            'ttl' => null,
            'api_status' => '',
            'api_message' => '',
        ];

        $hostname = $this->resolve_hostname($ip);
        $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,city';

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 1.6,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        $headers = (isset($http_response_header) && is_array($http_response_header)) ? $http_response_header : [];
        foreach ($headers as $header_line) {
            $line = trim((string)$header_line);
            if ($line === '') {
                continue;
            }
            if (preg_match('#^HTTP/\S+\s+(\d{3})#i', $line, $m)) {
                $meta['http_code'] = (int)$m[1];
                continue;
            }
            if (stripos($line, 'X-Rl:') === 0) {
                $meta['remaining'] = (int)trim(substr($line, 5));
                continue;
            }
            if (stripos($line, 'X-Ttl:') === 0) {
                $meta['ttl'] = (int)trim(substr($line, 6));
                continue;
            }
        }
        if ($response === false) {
            return false;
        }

        $data = @json_decode($response, true);
        if (!is_array($data)) {
            return false;
        }
        $meta['api_status'] = strtolower(trim((string)($data['status'] ?? '')));
        $meta['api_message'] = strtolower(trim((string)($data['message'] ?? '')));
        if ($meta['api_status'] !== 'success') {
            return false;
        }

        return [
            'country_code' => substr((string)($data['countryCode'] ?? ''), 0, 5),
            'country_name' => substr((string)($data['country'] ?? ''), 0, 100),
            'city' => substr((string)($data['city'] ?? ''), 0, 100),
            'hostname' => substr((string)$hostname, 0, 255),
        ];
    }

    private function throttle_before_live_lookup()
    {
        $safe_rate = max(1, min(self::GEO_API_LIMIT_PER_MIN - 1, self::GEO_API_SAFE_PER_MIN));
        $window_sec = 60.0;
        $min_interval_sec = $window_sec / $safe_rate;
        $now = microtime(true);

        $this->prune_live_lookup_window($now, $window_sec);

        $wait_sec = 0.0;
        $reason = '';

        if (!empty($this->live_lookup_timestamps) && count($this->live_lookup_timestamps) >= $safe_rate) {
            $oldest = (float)$this->live_lookup_timestamps[0];
            $until = ($oldest + $window_sec) - $now + 0.08;
            if ($until > $wait_sec) {
                $wait_sec = $until;
                $reason = sprintf('throttle fenetre 60s (target=%d/min)', (int)$safe_rate);
            }
        }

        if ($this->last_live_lookup_ts > 0) {
            $delta = $now - $this->last_live_lookup_ts;
            $until = $min_interval_sec - $delta;
            if ($until > $wait_sec) {
                $wait_sec = $until;
                $reason = sprintf('throttle intervalle %.2fs', (float)$min_interval_sec);
            }
        }

        if ($wait_sec > 0.0) {
            $this->pause_seconds($wait_sec, $reason);
        }
    }

    private function register_live_lookup_attempt()
    {
        $now = microtime(true);
        $this->live_lookup_timestamps[] = $now;
        $this->last_live_lookup_ts = $now;
        $this->prune_live_lookup_window($now, 60.0);
    }

    private function prune_live_lookup_window($now, $window_sec)
    {
        $now = (float)$now;
        $window_sec = max(1.0, (float)$window_sec);
        $cutoff = $now - $window_sec;
        $keep = [];
        foreach ($this->live_lookup_timestamps as $ts) {
            $t = (float)$ts;
            if ($t >= $cutoff) {
                $keep[] = $t;
            }
        }
        $this->live_lookup_timestamps = $keep;
    }

    private function maybe_pause_for_rate_limit(array $meta)
    {
        $http_code = isset($meta['http_code']) ? (int)$meta['http_code'] : 0;
        $remaining = isset($meta['remaining']) ? (int)$meta['remaining'] : -1;
        $ttl = isset($meta['ttl']) ? (int)$meta['ttl'] : 0;
        $status = strtolower(trim((string)($meta['api_status'] ?? '')));
        $message = strtolower(trim((string)($meta['api_message'] ?? '')));

        $pause_sec = 0;
        $reason = '';

        $quota_like_error = ($status !== '' && $status !== 'success')
            && (strpos($message, 'limit') !== false || strpos($message, 'quota') !== false);

        if ($http_code === 429 || $quota_like_error) {
            $pause_sec = max(3, min(90, ($ttl > 0 ? $ttl + 1 : 60)));
            $reason = 'quota depassee';
        } elseif ($remaining >= 0 && $remaining <= 4) {
            $pause_sec = max(2, min(60, ($ttl > 0 ? $ttl + 1 : 10)));
            $reason = 'quota basse (marge securite)';
        }

        if ($pause_sec <= 0) {
            return;
        }

        $extra = '';
        if ($remaining >= 0 || $ttl > 0 || $http_code > 0) {
            $extra = sprintf(
                ' (http=%s, X-Rl=%s, X-Ttl=%s)',
                $http_code > 0 ? $http_code : '-',
                $remaining >= 0 ? $remaining : '-',
                $ttl > 0 ? $ttl : '-'
            );
        }
        $this->pause_seconds((float)$pause_sec, $reason . $extra);
    }

    private function maybe_pause_between_batches($batch_index, $pending_window, $live_hits, $fail_hits = 0)
    {
        $pending_window = max(0, (int)$pending_window);
        $live_hits = max(0, (int)$live_hits);
        $fail_hits = max(0, (int)$fail_hits);
        if ($pending_window <= 0 || ($live_hits + $fail_hits) <= 0) {
            return;
        }

        // Pause fixe inter-batch demandee.
        $pause_sec = 5;

        $this->pause_seconds(
            (float)$pause_sec,
            sprintf(
                'pause inter-batch: batch=%d, pending_total=%d, live=%d, fail=%d',
                (int)$batch_index,
                (int)$pending_window,
                (int)$live_hits,
                (int)$fail_hits
            )
        );
    }

    private function pause_seconds($seconds, $reason = '')
    {
        $seconds = (float)$seconds;
        if ($seconds <= 0) {
            return;
        }

        if ($this->is_cli_runtime()) {
            $label = trim((string)$reason);
            if ($label === '') {
                $this->cli_log(sprintf('[geo_async] Pause %.2fs', $seconds));
            } else {
                $this->cli_log(sprintf('[geo_async] Pause %.2fs: %s', $seconds, $label));
            }
        }

        $micro = (int)round($seconds * 1000000);
        if ($micro > 0 && function_exists('usleep')) {
            usleep($micro);
            return;
        }

        sleep((int)ceil($seconds));
    }

    private function cli_progress($processed, $batch, $scanned, $pending_total, $cached_hits, $live_hits, $fail_hits, $label = '', $global_done = null, $global_total = null, $global_left = null)
    {
        if (!$this->is_cli_runtime()) {
            return;
        }

        $target = max(1, (int)$batch);
        $ratio = min(1, max(0, ((int)$processed / $target)));
        $percent = (int)round($ratio * 100);
        $bar_len = 24;
        $filled = (int)floor($ratio * $bar_len);
        $bar = str_repeat('#', $filled) . str_repeat('-', $bar_len - $filled);
        $tail = trim((string)$label);
        if (strlen($tail) > 52) {
            $tail = substr($tail, 0, 52);
        }
        if ($tail !== '') {
            $tail = ' | ' . $tail;
        }

        $global_txt = '';
        if ($global_total !== null) {
            $g_total = max(0, (int)$global_total);
            $g_done = max(0, (int)$global_done);
            $g_left = max(0, (int)$global_left);
            if ($g_total > 0) {
                $g_pct = max(0.0, min(100.0, ((float)$g_done * 100.0) / (float)$g_total));
                $global_txt = sprintf(' | global:%5.1f%% %d/%d left:%d', $g_pct, $g_done, $g_total, $g_left);
            } else {
                $global_txt = ' | global:100% 0/0 left:0';
            }
        }

        $line = sprintf(
            "[geo_async] [%s] %3d%% ok:%d/%d scan:%d/%d cache:%d live:%d fail:%d%s%s",
            $bar,
            (int)$percent,
            (int)$processed,
            (int)$target,
            (int)$scanned,
            max(1, (int)$pending_total),
            (int)$cached_hits,
            (int)$live_hits,
            (int)$fail_hits,
            $global_txt,
            $tail
        );

        $is_inline = $this->use_inline_progress();
        if ($is_inline) {
            $max_len = max(24, $this->get_cli_terminal_cols() - 1);
            if (strlen($line) > $max_len) {
                $line = substr($line, 0, max(1, $max_len - 3)) . '...';
            }

            $line_len = strlen($line);
            if ($this->cli_progress_line_len > $line_len) {
                $line .= str_repeat(' ', $this->cli_progress_line_len - $line_len);
                $line_len = strlen($line);
            }

            // Efface proprement la ligne courante avant re-affichage de la progression.
            echo "\r\033[2K" . $line;
            if (function_exists('flush')) {
                @flush();
            }
            $this->cli_progress_active = true;
            $this->cli_progress_line_len = $line_len;
            return;
        }

        // Sortie non-TTY (fichier/journal): imprimer des lignes stables.
        echo $line . "\n";
        if (function_exists('flush')) {
            @flush();
        }
        $this->cli_progress_active = false;
        $this->cli_progress_line_len = 0;
    }

    private function cli_log($message)
    {
        if (!$this->is_cli_runtime()) {
            return;
        }
        if ($this->cli_progress_active) {
            echo "\n";
            $this->cli_progress_active = false;
            $this->cli_progress_line_len = 0;
        }
        echo (string)$message . "\n";
        if (function_exists('flush')) {
            @flush();
        }
    }

    private function is_cli_runtime()
    {
        return (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg');
    }

    private function is_cli_tty_stdout()
    {
        if (!$this->is_cli_runtime() || !defined('STDOUT')) {
            return false;
        }
        if (function_exists('stream_isatty')) {
            return @stream_isatty(STDOUT);
        }
        if (function_exists('posix_isatty')) {
            return @posix_isatty(STDOUT);
        }
        return false;
    }

    private function use_inline_progress()
    {
        if (!$this->is_cli_tty_stdout()) {
            return false;
        }

        $force_plain = strtolower(trim((string)getenv('B59_STATS_PROGRESS_PLAIN')));
        if ($force_plain === '1' || $force_plain === 'true' || $force_plain === 'yes' || $force_plain === 'on') {
            return false;
        }

        $term = strtolower(trim((string)getenv('TERM')));
        if ($term === '' || $term === 'dumb') {
            return false;
        }

        return true;
    }

    private function get_cli_terminal_cols()
    {
        if ($this->cli_terminal_cols > 0) {
            return $this->cli_terminal_cols;
        }

        $cols = (int)getenv('COLUMNS');
        if ($cols <= 0 && function_exists('shell_exec')) {
            $stty = trim((string)@shell_exec('stty size 2>/dev/null'));
            if ($stty !== '' && preg_match('/^\d+\s+(\d+)$/', $stty, $m)) {
                $cols = (int)$m[1];
            }
        }
        if ($cols <= 0 && function_exists('shell_exec')) {
            $out = trim((string)@shell_exec('tput cols 2>/dev/null'));
            if ($out !== '' && ctype_digit($out)) {
                $cols = (int)$out;
            }
        }
        if ($cols <= 0) {
            $cols = 120;
        }
        $this->cli_terminal_cols = max(40, $cols);
        return $this->cli_terminal_cols;
    }

    private function resolve_hostname($ip)
    {
        $rdns_raw = @shell_exec('timeout 0.35 getent hosts ' . escapeshellarg($ip) . ' 2>/dev/null');
        if (!$rdns_raw) {
            return '';
        }

        $parts = preg_split('/\s+/', trim((string)$rdns_raw));
        $candidate = end($parts);
        if ($candidate && $candidate !== $ip) {
            return (string)$candidate;
        }

        return '';
    }

    /**
     * @return string[]
     */
    private function build_geo_cache_keys($ip)
    {
        $keys = [];
        $exact_key = $this->build_geo_cache_exact_key($ip);
        $scope_key = $this->build_geo_cache_scope_key($ip);

        if ($exact_key !== '') {
            $keys[] = $exact_key;
        }
        if ($scope_key !== '') {
            $keys[] = $scope_key;
        }

        return array_values(array_unique($keys));
    }

    private function build_geo_cache_exact_key($ip)
    {
        $ip = trim((string)$ip);
        if ($ip === '') {
            return '';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        return $ip;
    }

    private function build_geo_cache_scope_key($ip)
    {
        $ip = trim((string)$ip);
        if ($ip === '') {
            return '';
        }

        // IPv4 : clé sous-réseau uniquement (ex: v4:1.2.3.0/24)
        $v4_key = $this->get_ipv4_subnet_key($ip);
        if ($v4_key !== '') {
            return 'v4:' . $v4_key;
        }

        // IPv6 : clé sous-réseau uniquement (ex: v6:2001:db8::/48)
        $v6_key = $this->get_ipv6_subnet_key($ip);
        if ($v6_key !== '') {
            return 'v6:' . $v6_key;
        }

        return '';
    }

    private function get_ipv4_subnet_key($ip)
    {
        $meta = $this->get_ipv4_subnet_meta($ip);
        if ($meta === false) {
            return '';
        }
        return (string)$meta['key'];
    }

    private function get_ipv4_subnet_meta($ip)
    {
        $ip = trim((string)$ip);
        if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $ip, $m)) {
            return false;
        }

        $a = (int)$m[1];
        $b = (int)$m[2];
        $c = (int)$m[3];
        $d = (int)$m[4];
        if (
            $a < 0 || $a > 255 ||
            $b < 0 || $b > 255 ||
            $c < 0 || $c > 255 ||
            $d < 0 || $d > 255
        ) {
            return false;
        }

        $ip_num = ip2long($ip);
        if ($ip_num === false) {
            return false;
        }
        $ip_num = (int)sprintf('%u', $ip_num);

        $prefix_len = $this->get_geo_ipv4_prefix_len();
        $host_bits = 32 - (int)$prefix_len;
        $mask = ($host_bits <= 0)
            ? 0xFFFFFFFF
            : ((0xFFFFFFFF << $host_bits) & 0xFFFFFFFF);
        $start = (int)($ip_num & $mask);
        $end = (int)($start + ((1 << $host_bits) - 1));

        $o1 = (int)(($start >> 24) & 0xFF);
        $o2 = (int)(($start >> 16) & 0xFF);
        $o3 = (int)(($start >> 8) & 0xFF);
        $o4 = (int)($start & 0xFF);
        $start_ip = $o1 . '.' . $o2 . '.' . $o3 . '.' . $o4;

        $fixed_octets = (int)floor(((int)$prefix_len) / 8);
        if ($fixed_octets >= 3) {
            $prefix_hint = $o1 . '.' . $o2 . '.' . $o3 . '.';
        } elseif ($fixed_octets === 2) {
            $prefix_hint = $o1 . '.' . $o2 . '.';
        } else {
            $prefix_hint = $o1 . '.';
        }

        $start_check = ip2long($start_ip);
        if ($start_check === false) {
            return false;
        }
        $start_check = (int)sprintf('%u', $start_check);
        if ($start_check !== $start || $end < $start) {
            return false;
        }

        return [
            'key' => $start_ip . '/' . (int)$prefix_len,
            'prefix_hint' => $prefix_hint,
            'start' => $start,
            'end' => $end,
        ];
    }

    private function get_geo_ipv4_prefix_len()
    {
        if ($this->geo_ipv4_prefix_len !== null) {
            return (int)$this->geo_ipv4_prefix_len;
        }

        $bits = (int)($this->config['bastien59_stats_geo_ipv4_prefix_len'] ?? 24);
        $bits = max(16, min(32, $bits));
        $this->geo_ipv4_prefix_len = $bits;
        return (int)$this->geo_ipv4_prefix_len;
    }

    /**
     * Calcule la clé de sous-réseau IPv6 (ex: "2001:db8::/48").
     * La précision /48 correspond approximativement à un site/quartier urbain.
     */
    private function get_ipv6_subnet_key($ip)
    {
        $ip = trim((string)$ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return '';
        }

        $prefix_len = $this->get_geo_ipv6_prefix_len();
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return '';
        }

        // Masquer les bits hors préfixe
        $masked = '';
        for ($i = 0; $i < 16; $i++) {
            $byte_start = $i * 8;
            if ($byte_start >= $prefix_len) {
                $masked .= chr(0);
            } elseif ($byte_start + 8 <= $prefix_len) {
                $masked .= $bin[$i];
            } else {
                $bits = $prefix_len - $byte_start;
                $mask = 0xFF & (0xFF << (8 - $bits));
                $masked .= chr(ord($bin[$i]) & $mask);
            }
        }

        $network = @inet_ntop($masked);
        if ($network === false) {
            return '';
        }

        return $network . '/' . $prefix_len;
    }

    private function get_geo_ipv6_prefix_len()
    {
        if ($this->geo_ipv6_prefix_len !== null) {
            return (int)$this->geo_ipv6_prefix_len;
        }

        // /48 = précision quartier/site (standard MaxMind, RIPE). Min /32, max /64.
        $bits = (int)($this->config['bastien59_stats_geo_ipv6_prefix_len'] ?? 48);
        $bits = max(32, min(64, $bits));
        $this->geo_ipv6_prefix_len = $bits;
        return (int)$this->geo_ipv6_prefix_len;
    }

    private function is_local_ip($ip)
    {
        $ip = trim((string)$ip);
        if ($ip === '') {
            return true;
        }

        if (preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|127\.)/', $ip)) {
            return true;
        }

        if (preg_match('/^(::1|fe80:|fc00:|fd00:)/i', $ip)) {
            return true;
        }

        return false;
    }
}
