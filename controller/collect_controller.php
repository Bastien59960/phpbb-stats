<?php
/**
 * Stats Extension - Secure AJAX collector
 *
 * @package bastien59960/stats
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\controller;

use Symfony\Component\HttpFoundation\JsonResponse;

class collect_controller
{
    protected $db;
    protected $request;
    protected $user;
    protected $config;
    protected $table_prefix;
    protected $has_ajax_telemetry_columns = null;
    protected $has_ajax_advanced_columns = null;
    protected $has_visitor_cookie_column = null;
    protected $has_visitor_cookie_debug_columns = null;

    const LINK_NAME = 'b59_stats_px';
    const VISITOR_COOKIE_NAME = 'b59_vid';

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        \phpbb\request\request $request,
        \phpbb\user $user,
        \phpbb\config\config $config,
        $table_prefix
    ) {
        $this->db = $db;
        $this->request = $request;
        $this->user = $user;
        $this->config = $config;
        $this->table_prefix = $table_prefix;
    }

    /**
     * POST /stats/px
     * Expected payload keys:
     * k(token), s(session), i(log_id), a(scroll_flag), r(resolution),
     * b(interact_mask), d(first_scroll_ms), n(scroll_events), y(scroll_max_y),
     * w(webdriver_flag), v(telemetry_version)
     */
    public function collect()
    {
        if (empty($this->config['bastien59_stats_enabled'])) {
            return new JsonResponse(['ok' => 0], 403);
        }

        if (strtoupper($this->request->server('REQUEST_METHOD', 'GET')) !== 'POST') {
            return new JsonResponse(['ok' => 0], 405);
        }

        if (!$this->request->is_ajax()) {
            return new JsonResponse(['ok' => 0], 400);
        }

        if (!$this->is_same_origin_request()) {
            return new JsonResponse(['ok' => 0], 403);
        }

        $token = $this->request->variable('k', '', true);
        if (!check_link_hash($token, self::LINK_NAME)) {
            return new JsonResponse(['ok' => 0], 403);
        }

        $sid = $this->request->variable('s', '', true);
        if (!$this->is_valid_sid($sid) || $sid !== (string)($this->user->session_id ?? '')) {
            return new JsonResponse(['ok' => 0], 403);
        }

        $log_id = (int)$this->request->variable('i', 0);
        if ($log_id <= 0) {
            return new JsonResponse(['ok' => 0], 400);
        }

        $scroll_flag = (int)$this->request->variable('a', -1);
        if ($scroll_flag !== 0 && $scroll_flag !== 1) {
            return new JsonResponse(['ok' => 0], 400);
        }

        $resolution = trim((string)$this->request->variable('r', '', true));
        if ($resolution !== '' && !$this->is_valid_resolution($resolution)) {
            return new JsonResponse(['ok' => 0], 400);
        }

        $interact_mask = (int)$this->request->variable('b', 0);
        if ($interact_mask < 0 || $interact_mask > 255) {
            return new JsonResponse(['ok' => 0], 400);
        }

        $first_scroll_ms = (int)$this->request->variable('d', 0);
        if ($first_scroll_ms < 0 || $first_scroll_ms > 120000) {
            return new JsonResponse(['ok' => 0], 400);
        }

        $scroll_events = (int)$this->request->variable('n', 0);
        if ($scroll_events < 0 || $scroll_events > 10000) {
            return new JsonResponse(['ok' => 0], 400);
        }

        $scroll_max_y = (int)$this->request->variable('y', 0);
        if ($scroll_max_y < 0 || $scroll_max_y > 500000) {
            return new JsonResponse(['ok' => 0], 400);
        }

        $webdriver_flag = (int)$this->request->variable('w', 0);
        if ($webdriver_flag !== 0 && $webdriver_flag !== 1) {
            return new JsonResponse(['ok' => 0], 400);
        }

        $telemetry_ver = (int)$this->request->variable('v', 0);
        if ($telemetry_ver < 0 || $telemetry_ver > 9) {
            return new JsonResponse(['ok' => 0], 400);
        }

        // Migration 1.2.0 absente -> endpoint noop (évite les erreurs SQL, client marqué OK)
        if (!$this->has_ajax_telemetry_columns()) {
            return new JsonResponse(['ok' => 1]);
        }

        $cookie_hash_select = $this->has_visitor_cookie_column()
            ? ', visitor_cookie_hash'
            : ', \'\' AS visitor_cookie_hash';
        $sql = 'SELECT session_id, user_ip, visit_time, page_url, user_agent, screen_res,
                       country_code, signals, bot_source' . $cookie_hash_select . '
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE log_id = ' . (int)$log_id;
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row) {
            return new JsonResponse(['ok' => 1]);
        }

        if (!hash_equals((string)$row['user_ip'], (string)$this->user->ip)) {
            return new JsonResponse(['ok' => 0], 403);
        }

        $session_timeout = (int)($this->config['bastien59_stats_session_timeout'] ?? 900);
        $max_age = max(600, $session_timeout * 4);
        if ((time() - (int)$row['visit_time']) > $max_age) {
            return new JsonResponse(['ok' => 1]);
        }

        $tracked_session = (string)$row['session_id'];
        if ($tracked_session === '') {
            return new JsonResponse(['ok' => 1]);
        }

        $ajax_cookie_raw = trim((string)$this->request->variable(self::VISITOR_COOKIE_NAME, '', true, \phpbb\request\request_interface::COOKIE));
        $ajax_cookie_id = $this->parse_signed_visitor_cookie($ajax_cookie_raw);
        $ajax_cookie_hash = ($ajax_cookie_id !== '') ? hash('sha256', $ajax_cookie_id) : '';
        // 0 = none, 1 = valid, 2 = absent, 3 = invalid, 4 = mismatch
        $ajax_cookie_state = 0;
        if ($ajax_cookie_hash !== '') {
            $ajax_cookie_state = 1;
        } elseif ($ajax_cookie_raw === '') {
            $ajax_cookie_state = 2;
        } else {
            $ajax_cookie_state = 3;
        }

        $update = [];
        if ($resolution !== '') {
            $update['screen_res_ajax'] = substr($resolution, 0, 20);
        }
        if ($scroll_flag === 1) {
            $update['scroll_down_ajax'] = 1;
        }

        $has_advanced_columns = $this->has_ajax_advanced_columns();
        if ($has_advanced_columns) {
            if ($interact_mask > 0) {
                $update['ajax_interact_mask'] = $interact_mask;
            }
            if ($first_scroll_ms > 0) {
                $update['ajax_first_scroll_ms'] = $first_scroll_ms;
            }
            if ($scroll_events > 0) {
                $update['ajax_scroll_events'] = $scroll_events;
            }
            if ($scroll_max_y > 0) {
                $update['ajax_scroll_max_y'] = $scroll_max_y;
            }
            if ($webdriver_flag === 1) {
                $update['ajax_webdriver'] = 1;
            }
            if ($telemetry_ver > 0) {
                $update['ajax_telemetry_ver'] = $telemetry_ver;
            }
        }

        if ($this->has_visitor_cookie_debug_columns()) {
            if ($ajax_cookie_hash !== '') {
                $update['visitor_cookie_ajax_state'] = $ajax_cookie_state;
                $update['visitor_cookie_ajax_hash'] = substr(strtolower($ajax_cookie_hash), 0, 64);
            } else {
                $update['visitor_cookie_ajax_state'] = $ajax_cookie_state;
                $update['visitor_cookie_ajax_hash'] = '';
            }
        }
        if ($ajax_cookie_hash !== '' && $this->has_visitor_cookie_column()) {
            $update['visitor_cookie_hash'] = substr(strtolower($ajax_cookie_hash), 0, 64);
        }

        $emit_cookie_fail_signal = false;
        if ($this->has_visitor_cookie_debug_columns()) {
            $country_code = strtoupper(trim((string)($row['country_code'] ?? '')));
            $country_excluded = $this->is_guest_clone_country_excluded($country_code);
            $existing_hash = strtolower(trim((string)($row['visitor_cookie_hash'] ?? '')));
            $existing_hash_valid = (bool)preg_match('/^[a-f0-9]{64}$/', $existing_hash);
            $ajax_hash_valid = (bool)preg_match('/^[a-f0-9]{64}$/', $ajax_cookie_hash);
            $cookie_mismatch = ($ajax_hash_valid && $existing_hash_valid && !hash_equals($existing_hash, $ajax_cookie_hash));
            if ($cookie_mismatch) {
                $ajax_cookie_state = 4;
                $update['visitor_cookie_ajax_state'] = 4;
            }
            $cookie_absent = ($ajax_cookie_state === 2);
            $cookie_invalid = ($ajax_cookie_state === 3);
            $is_guest = ((int)($this->user->data['user_id'] ?? 1) <= 1);
            $js_active = ($resolution !== '' || $scroll_flag === 1);

            // Signal strict: JS actif mais cookie signé absent/invalide/incohérent en AJAX.
            if ($is_guest && $js_active && ($cookie_absent || $cookie_invalid || $cookie_mismatch)) {
                $signals_list = array_filter(array_map('trim', explode(',', (string)($row['signals'] ?? ''))));
                $cookie_signal = $country_excluded ? 'guest_cookie_ajax_fail_shadow' : 'guest_cookie_ajax_fail';
                if (!in_array($cookie_signal, $signals_list, true)) {
                    $signals_list[] = $cookie_signal;
                }
                $update['signals'] = substr(implode(',', $signals_list), 0, 255);
                if (!$country_excluded) {
                    $update['is_bot'] = 1;
                    if (empty($row['bot_source'])) {
                        $update['bot_source'] = 'behavior';
                    }
                    $emit_cookie_fail_signal = true;
                }
            }
        }

        if (!empty($update)) {
            $update['ajax_seen_time'] = time();

            $sql = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                    SET ' . $this->db->sql_build_array('UPDATE', $update) . '
                    WHERE session_id = \'' . $this->db->sql_escape($tracked_session) . '\'
                    AND user_ip = \'' . $this->db->sql_escape((string)$this->user->ip) . '\'';
            $this->db->sql_return_on_error(true);
            $this->db->sql_query($sql);
            $sql_error = (bool)$this->db->get_sql_error_triggered();
            $this->db->sql_return_on_error(false);

            if ($sql_error) {
                return new JsonResponse(['ok' => 0], 500);
            }

            if ($emit_cookie_fail_signal) {
                $this->write_security_audit_signal(
                    (string)$this->user->ip,
                    $tracked_session,
                    (int)($this->user->data['user_id'] ?? 1),
                    (string)($update['signals'] ?? 'guest_cookie_ajax_fail'),
                    (string)($row['page_url'] ?? ''),
                    (string)($row['user_agent'] ?? ''),
                    ($resolution !== '' ? $resolution : (string)($row['screen_res'] ?? '')),
                    $this->count_session_pages($tracked_session)
                );
            }
        }

        return new JsonResponse(['ok' => 1]);
    }

    private function is_valid_sid($sid)
    {
        return (bool)preg_match('/^[A-Za-z0-9]{32}$/', (string)$sid);
    }

    private function is_valid_resolution($resolution)
    {
        if (!preg_match('/^([1-9][0-9]{1,4})x([1-9][0-9]{1,4})$/', $resolution, $m)) {
            return false;
        }

        $w = (int)$m[1];
        $h = (int)$m[2];
        return ($w <= 16384 && $h <= 16384);
    }

    private function is_same_origin_request()
    {
        $origin = trim((string)$this->request->server('HTTP_ORIGIN', ''));
        $referer = trim((string)$this->request->server('HTTP_REFERER', ''));
        $source = ($origin !== '') ? $origin : $referer;
        if ($source === '') {
            return true;
        }

        $host = strtolower((string)parse_url($source, PHP_URL_HOST));
        if ($host === '') {
            return false;
        }

        $allowed_hosts = $this->get_allowed_hosts();
        if (isset($allowed_hosts[$host])) {
            return true;
        }

        $cookie_domain = strtolower(ltrim((string)($this->config['cookie_domain'] ?? ''), '.'));
        if ($cookie_domain !== '') {
            if ($host === $cookie_domain) {
                return true;
            }
            if (substr($host, -strlen('.' . $cookie_domain)) === '.' . $cookie_domain) {
                return true;
            }
        }

        return false;
    }

    private function get_allowed_hosts()
    {
        $hosts = [];

        $server_name = strtolower(trim((string)$this->request->server('SERVER_NAME', '')));
        if ($server_name !== '') {
            $hosts[$server_name] = true;
        }

        $http_host = strtolower(trim((string)$this->request->server('HTTP_HOST', '')));
        if ($http_host !== '') {
            $http_host = preg_replace('/:\d+$/', '', $http_host);
            if (!empty($http_host)) {
                $hosts[$http_host] = true;
            }
        }

        return $hosts;
    }

    /**
     * Détecte si les colonnes AJAX (migration 1.2.0) sont disponibles.
     */
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

    /**
     * Détecte si les colonnes AJAX avancées (migration 1.3.0) sont disponibles.
     */
    private function has_ajax_advanced_columns()
    {
        if ($this->has_ajax_advanced_columns !== null) {
            return $this->has_ajax_advanced_columns;
        }

        $sql = 'SELECT ajax_interact_mask, ajax_first_scroll_ms, ajax_scroll_events,
                       ajax_scroll_max_y, ajax_webdriver, ajax_telemetry_ver
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

    /**
     * Détecte si la colonne visitor_cookie_hash (migration 1.7.0) est disponible.
     */
    private function has_visitor_cookie_column()
    {
        if ($this->has_visitor_cookie_column !== null) {
            return $this->has_visitor_cookie_column;
        }

        $sql = 'SELECT visitor_cookie_hash
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_visitor_cookie_column = !$has_error;
        return $this->has_visitor_cookie_column;
    }

    /**
     * Détecte si les colonnes debug cookie (migration 1.8.0) sont disponibles.
     */
    private function has_visitor_cookie_debug_columns()
    {
        if ($this->has_visitor_cookie_debug_columns !== null) {
            return $this->has_visitor_cookie_debug_columns;
        }

        $sql = 'SELECT visitor_cookie_preexisting, visitor_cookie_ajax_state, visitor_cookie_ajax_hash
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_visitor_cookie_debug_columns = !$has_error;
        return $this->has_visitor_cookie_debug_columns;
    }

    /**
     * Valide et extrait l'identifiant d'un cookie signé format v1.<id>.<sig>.
     */
    private function parse_signed_visitor_cookie($raw)
    {
        if (!preg_match('/^v1\.([a-f0-9]{32})\.([a-f0-9]{24})$/i', (string)$raw, $m)) {
            return '';
        }

        $id = strtolower((string)$m[1]);
        $sig = strtolower((string)$m[2]);
        foreach ($this->get_visitor_cookie_secrets() as $secret) {
            $expected = substr(hash_hmac('sha256', 'v1|' . $id, $secret), 0, 24);
            if (hash_equals($expected, $sig)) {
                return $id;
            }
        }

        return '';
    }

    /**
     * Secret local pour signature du cookie visiteur.
     */
    private function get_visitor_cookie_secret()
    {
        $secrets = $this->get_visitor_cookie_secrets();
        return $secrets[0];
    }

    /**
     * Secrets de validation du cookie visiteur.
     * Le premier est utilise pour signer les nouveaux cookies.
     * Les suivants sont acceptes en lecture pour compatibilite.
     *
     * @return string[]
     */
    private function get_visitor_cookie_secrets()
    {
        $secrets = [];

        $seed_primary = (string)($this->config['bastien59_stats_cookie_secret'] ?? '');
        if ($seed_primary === '') {
            $seed_primary = (string)($this->config['cookie_name'] ?? '') . '|' . (string)($this->config['server_name'] ?? '');
        }
        if ($seed_primary === '') {
            $seed_primary = 'b59-fallback-seed';
        }
        $secrets[] = hash('sha256', 'b59-visitor-cookie|' . $seed_primary);

        // Compat ancienne signature (listener historique base sur rand_seed).
        $seed_legacy = (string)($this->config['rand_seed'] ?? '');
        if ($seed_legacy !== '') {
            $legacy = hash('sha256', 'b59-visitor-cookie|' . $seed_legacy);
            if (!in_array($legacy, $secrets, true)) {
                $secrets[] = $legacy;
            }
        }

        return $secrets;
    }

    /**
     * Exclusion géographique stricte FR/CO pour signaux clone/cookie.
     */
    private function is_guest_clone_country_excluded($country_code)
    {
        $cc = strtoupper(trim((string)$country_code));
        return ($cc === 'FR' || $cc === 'CO');
    }

    /**
     * Compte rapidement les pages de la session (pour le log audit).
     */
    private function count_session_pages($session_id)
    {
        if (!$this->is_valid_sid($session_id)) {
            return 1;
        }

        $sql = 'SELECT COUNT(*) AS cnt
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE session_id = \'' . $this->db->sql_escape((string)$session_id) . '\'';
        $result = $this->db->sql_query_limit($sql, 1);
        $count = (int)$this->db->sql_fetchfield('cnt');
        $this->db->sql_freeresult($result);
        return max(1, $count);
    }

    /**
     * Émet une ligne PHPBB-SIGNAL dédiée dans security_audit.log.
     */
    private function write_security_audit_signal($ip, $session_id, $user_id, $signals, $page_url, $user_agent, $screen_res, $page_count)
    {
        $signals_str = trim((string)$signals);
        if ($signals_str === '') {
            return;
        }

        // Déduplication 1h par session + signaux.
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
}
