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

    const LINK_NAME = 'b59_stats_px';

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
     * Expected payload keys: k(token), s(session), i(log_id), a(scroll_flag), r(resolution)
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

        // Migration 1.2.0 absente -> endpoint noop (évite les erreurs SQL, client marqué OK)
        if (!$this->has_ajax_telemetry_columns()) {
            return new JsonResponse(['ok' => 1]);
        }

        $sql = 'SELECT session_id, user_ip, visit_time
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

        $update = [];
        if ($resolution !== '') {
            $update['screen_res_ajax'] = substr($resolution, 0, 20);
        }
        if ($scroll_flag === 1) {
            $update['scroll_down_ajax'] = 1;
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
}
