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
    /** @var \phpbb\db\driver\driver_interface */
    protected $db;

    /** @var \phpbb\config\config */
    protected $config;

    /** @var string */
    protected $table_prefix;

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

        $pending_ips = $this->get_pending_ips($batch * 4, $ttl_days);
        $processed = 0;
        foreach ($pending_ips as $ip) {
            if ($processed >= $batch) {
                break;
            }
            if ($this->is_local_ip($ip)) {
                continue;
            }

            $cached = $this->get_geo_cache($ip, $ttl_days);
            if ($cached !== false) {
                $this->backfill_stats_for_ip($ip, $cached, $ttl_days);
                $this->process_deferred_country_signals_for_ip($ip, $cached, $ttl_days);
                $processed++;
                continue;
            }

            $geo = $this->lookup_geo($ip);
            if ($geo === false) {
                continue;
            }

            $this->set_geo_cache($ip, $geo);
            $this->backfill_stats_for_ip($ip, $geo, $ttl_days);
            $this->process_deferred_country_signals_for_ip($ip, $geo, $ttl_days);
            $processed++;
        }

        $this->cleanup_geo_cache($ttl_days);
    }

    /**
     * @return string[]
     */
    private function get_pending_ips($limit, $ttl_days)
    {
        $limit = max(1, (int)$limit);
        $cutoff = time() - ($ttl_days * 86400);

        $sql = 'SELECT DISTINCT user_ip
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE is_first_visit = 1
                AND user_ip <> \'\'
                AND country_code = \'\'
                AND visit_time > ' . (int)$cutoff . '
                ORDER BY user_ip ASC';

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
            ];
        }

        return false;
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
        $subnet = $this->get_ipv4_subnet16_prefix($ip);

        if ($subnet !== '') {
            $sql = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                    SET ' . implode(', ', $set_parts) . '
                    WHERE country_code = \'\'
                    AND is_first_visit = 1
                    AND visit_time > ' . (int)$cutoff . '
                    AND user_ip LIKE \'' . $this->db->sql_escape($subnet . '.%') . '\'';
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
        $subnet = $this->get_ipv4_subnet16_prefix($ip);
        $scope_sql = ($subnet !== '')
            ? 'AND user_ip LIKE \'' . $this->db->sql_escape($subnet . '.%') . '\''
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

    private function lookup_geo($ip)
    {
        $hostname = $this->resolve_hostname($ip);
        $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,city';

        $ctx = stream_context_create([
            'http' => [
                'timeout' => 1.6,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) {
            return false;
        }

        $data = @json_decode($response, true);
        if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
            return false;
        }

        return [
            'country_code' => substr((string)($data['countryCode'] ?? ''), 0, 5),
            'country_name' => substr((string)($data['country'] ?? ''), 0, 100),
            'city' => substr((string)($data['city'] ?? ''), 0, 100),
            'hostname' => substr((string)$hostname, 0, 255),
        ];
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
        $subnet = $this->get_ipv4_subnet16_prefix($ip);
        if ($subnet !== '') {
            $keys[] = 'v4:' . $subnet;
        }

        return $keys;
    }

    private function get_ipv4_subnet16_prefix($ip)
    {
        $ip = trim((string)$ip);
        if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.\d{1,3}\.\d{1,3}$/', $ip, $m)) {
            return '';
        }

        $a = (int)$m[1];
        $b = (int)$m[2];
        if ($a < 0 || $a > 255 || $b < 0 || $b > 255) {
            return '';
        }

        return $a . '.' . $b;
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
