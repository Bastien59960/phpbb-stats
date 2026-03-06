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

    public function __construct(\phpbb\db\driver\driver_interface $db, \phpbb\config\config $config, $table_prefix)
    {
        $this->db = $db;
        $this->config = $config;
        $this->table_prefix = $table_prefix;
    }

    public function is_runnable()
    {
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

        while ($batch_index < $max_loops) {
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

        if ($this->is_cli_runtime()) {
            if ($this->cli_progress_active) {
                $this->cli_log('');
                $this->cli_progress_active = false;
            }
            $duration = microtime(true) - $start_ts;
            $pending_left = $this->get_pending_ip_count($ttl_days);
            $this->cli_log(sprintf(
                '[geo_async] Fin: loops=%d, ok=%d, scanned=%d, cache=%d, live=%d, fail=%d, deferred_live=%d, local_skip=%d, pending_left=%d, duree=%.1fs',
                (int)$batch_index,
                (int)$processed_total,
                (int)$scanned_total,
                (int)$cached_hits_total,
                (int)$live_hits_total,
                (int)$fail_hits_total,
                (int)$deferred_live_total,
                (int)$local_skips_total,
                (int)$pending_left,
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

        $sql = 'SELECT user_ip, MAX(visit_time) AS last_seen
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE is_first_visit = 1
                AND user_ip <> \'\'
                AND country_code = \'\'
                AND visit_time > ' . (int)$cutoff . '
                GROUP BY user_ip
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
        $sql = 'SELECT COUNT(DISTINCT user_ip) AS cnt
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE is_first_visit = 1
                AND user_ip <> \'\'
                AND country_code = \'\'
                AND visit_time > ' . (int)$cutoff;
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
                AND cached_time > ' . (int)($now - $ttl_sec) . '
                AND country_code <> \'\'';

        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $rows[(string)$row['ip_address']] = $row;
        }
        $this->db->sql_freeresult($result);

        foreach ($keys as $key) {
            if (!isset($rows[$key])) {
                continue;
            }
            $r = $rows[$key];
            return [
                'country_code' => (string)($r['country_code'] ?? ''),
                'country_name' => (string)($r['country_name'] ?? ''),
                'city' => (string)($r['city'] ?? ''),
                'hostname' => (string)($r['hostname'] ?? ''),
                '__cache_key' => (string)$key,
            ];
        }

        // Fallback IPv4 paramétrable (ACP): compromis coût API / précision géoloc.
        $subnet_key = $this->get_ipv4_subnet_key($ip);
        if ($subnet_key !== '') {
            $geo = $this->get_geo_cache_from_subnet($subnet_key, $ttl_sec);
            if ($geo !== false) {
                $this->set_geo_cache($ip, $geo);
                $geo['__cache_key'] = 'v4:' . $subnet_key;
                return $geo;
            }
        }

        return false;
    }

    private function get_geo_cache_from_subnet($subnet_key, $ttl_sec)
    {
        $subnet_key = trim((string)$subnet_key);
        if ($subnet_key === '') {
            return false;
        }

        $now = time();
        $sql = 'SELECT ip_address, country_code, country_name, city, hostname
                FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache
                WHERE cached_time > ' . (int)($now - max(3600, (int)$ttl_sec)) . '
                AND country_code <> \'\'
                AND ip_address = \'' . $this->db->sql_escape('v4:' . $subnet_key) . '\'
                ORDER BY cached_time DESC';
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        if (!$row) {
            return false;
        }

        return [
            'country_code' => (string)($row['country_code'] ?? ''),
            'country_name' => (string)($row['country_name'] ?? ''),
            'city' => (string)($row['city'] ?? ''),
            'hostname' => (string)($row['hostname'] ?? ''),
            '__cache_key' => (string)($row['ip_address'] ?? ''),
        ];
    }

    private function set_geo_cache($ip, array $geo)
    {
        $keys = $this->build_geo_cache_keys($ip);
        $keys = array_values(array_unique(array_filter($keys, function ($v) {
            return trim((string)$v) !== '';
        })));

        if (empty($keys)) {
            return;
        }

        $now = time();
        foreach ($keys as $key) {
            $sql = 'DELETE FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache
                    WHERE ip_address = \'' . $this->db->sql_escape($key) . '\'';
            $this->db->sql_query($sql);

            $sql_ary = [
                'ip_address' => $key,
                'country_code' => substr((string)($geo['country_code'] ?? ''), 0, 5),
                'country_name' => substr((string)($geo['country_name'] ?? ''), 0, 100),
                'city' => substr((string)($geo['city'] ?? ''), 0, 100),
                'hostname' => substr((string)($geo['hostname'] ?? ''), 0, 255),
                'cached_time' => $now,
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

        $set_parts = [];
        if ($country_code !== '') {
            $set_parts[] = 'country_code = \'' . $this->db->sql_escape($country_code) . '\'';
        }
        if ($country_name !== '') {
            $set_parts[] = 'country_name = \'' . $this->db->sql_escape($country_name) . '\'';
        }
        if ($hostname !== '') {
            $set_parts[] = 'hostname = CASE WHEN hostname = \'\' THEN \'' . $this->db->sql_escape($hostname) . '\' ELSE hostname END';
        }

        if (empty($set_parts)) {
            return;
        }

        $cutoff = time() - (max(1, (int)$ttl_days) * 86400);
        $subnet_meta = $this->get_ipv4_subnet_meta($ip);

        if ($subnet_meta !== false) {
            $sql = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                    SET ' . implode(', ', $set_parts) . '
                    WHERE country_code = \'\'
                    AND is_first_visit = 1
                    AND visit_time > ' . (int)$cutoff . '
                    AND user_ip LIKE \'' . $this->db->sql_escape((string)$subnet_meta['prefix_hint']) . '%\'
                    AND user_ip NOT LIKE \'%:%\'
                    AND INET_ATON(user_ip) BETWEEN ' . (int)$subnet_meta['start'] . ' AND ' . (int)$subnet_meta['end'];
            $this->db->sql_query($sql);
            return;
        }

        $sql = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                SET ' . implode(', ', $set_parts) . '
                WHERE country_code = \'\'
                AND is_first_visit = 1
                AND visit_time > ' . (int)$cutoff . '
                AND user_ip = \'' . $this->db->sql_escape($ip) . '\'';
        $this->db->sql_query($sql);
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
        $ip = trim((string)$ip);
        if ($ip === '') {
            return $keys;
        }

        $keys[] = $ip;
        $subnet_key = $this->get_ipv4_subnet_key($ip);
        if ($subnet_key !== '') {
            $keys[] = 'v4:' . $subnet_key;
        }

        return $keys;
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
