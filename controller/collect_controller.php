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
}
