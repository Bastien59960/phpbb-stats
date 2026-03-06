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
    protected $has_cursor_columns = null;
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
     * k(token), s(session), i(log_id), a(mode), r(resolution),
     * b(interact_mask), d(first_scroll_ms), n(scroll_events), y(scroll_max_y),
     * w(webdriver_flag), v(telemetry_version),
     * m(cursor path), q(click path), z(device class), l(viewport)
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

        $telemetry_mode = (int)$this->request->variable('a', -1);
        if ($telemetry_mode !== 0 && $telemetry_mode !== 1 && $telemetry_mode !== 2) {
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

        $cursor_path_raw = trim((string)$this->request->variable('m', '', true));
        if (strlen($cursor_path_raw) > 16000) {
            $cursor_path_raw = substr($cursor_path_raw, 0, 16000);
        }
        $cursor_clicks_raw = trim((string)$this->request->variable('q', '', true));
        if (strlen($cursor_clicks_raw) > 8000) {
            $cursor_clicks_raw = substr($cursor_clicks_raw, 0, 8000);
        }
        $cursor_device_raw = strtolower(trim((string)$this->request->variable('z', '', true)));
        $viewport_raw = trim((string)$this->request->variable('l', '', true));

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
        if ($telemetry_mode === 1) {
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

        $cursor_features = null;
        $cursor_points = [];
        $cursor_clicks = [];
        if ($telemetry_mode === 2 && $this->has_cursor_columns()) {
            $cursor_points = $this->decode_cursor_triplets($cursor_path_raw, 260);
            $cursor_clicks = $this->decode_cursor_triplets($cursor_clicks_raw, 80);
            $cursor_features = $this->compute_cursor_metrics($cursor_points, $cursor_clicks, $first_scroll_ms);
            $cursor_device = $this->sanitize_cursor_device($cursor_device_raw);
            $cursor_viewport = $this->sanitize_viewport($viewport_raw);

            $update['cursor_track_points'] = (int)$cursor_features['points_count'];
            $update['cursor_track_duration_ms'] = (int)$cursor_features['duration_ms'];
            $encoded_path = json_encode($cursor_points);
            $encoded_clicks = json_encode($cursor_clicks);
            $update['cursor_track_path'] = ($encoded_path === false) ? '[]' : substr((string)$encoded_path, 0, 64000);
            $update['cursor_click_points'] = ($encoded_clicks === false) ? '[]' : substr((string)$encoded_clicks, 0, 64000);
            $update['cursor_device_class'] = $cursor_device;
            $update['cursor_viewport'] = $cursor_viewport;
            $update['cursor_total_distance'] = (int)$cursor_features['total_distance'];
            $update['cursor_avg_speed'] = (int)$cursor_features['avg_speed'];
            $update['cursor_max_speed'] = (int)$cursor_features['max_speed'];
            $update['cursor_direction_changes'] = (int)$cursor_features['direction_changes'];
            $update['cursor_linearity'] = (int)$cursor_features['linearity'];
            $update['cursor_click_count'] = (int)$cursor_features['click_count'];
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

        $signals_list = array_filter(array_map('trim', explode(',', (string)($row['signals'] ?? ''))));
        $emit_cookie_fail_signal = false;
        $emit_cursor_strict_signal = false;
        $has_signal_updates = false;
        $is_guest = ((int)($this->user->data['user_id'] ?? 1) <= 1);

        if ($telemetry_mode === 2 && is_array($cursor_features) && $is_guest) {
            $cursor_eval = $this->evaluate_cursor_signals($cursor_features, $this->sanitize_cursor_device($cursor_device_raw), $is_guest);
            foreach ($cursor_eval['signals'] as $cursor_signal) {
                if (!in_array($cursor_signal, $signals_list, true)) {
                    $signals_list[] = $cursor_signal;
                    $has_signal_updates = true;
                }
            }
            if (!empty($cursor_eval['strict_bot'])) {
                $emit_cursor_strict_signal = true;
                $update['is_bot'] = 1;
                if (empty($row['bot_source'])) {
                    $update['bot_source'] = 'behavior';
                }
            }
        }

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
            $js_active = ($resolution !== '' || $telemetry_mode === 1 || $telemetry_mode === 2);

            // Signal strict: JS actif mais cookie signé absent/invalide/incohérent en AJAX.
            if ($is_guest && $js_active && ($cookie_absent || $cookie_invalid || $cookie_mismatch)) {
                $cookie_signal = $country_excluded ? 'guest_cookie_ajax_fail_shadow' : 'guest_cookie_ajax_fail';
                if (!in_array($cookie_signal, $signals_list, true)) {
                    $signals_list[] = $cookie_signal;
                    $has_signal_updates = true;
                }
                if (!$country_excluded) {
                    $update['is_bot'] = 1;
                    if (empty($row['bot_source'])) {
                        $update['bot_source'] = 'behavior';
                    }
                    $emit_cookie_fail_signal = true;
                }
            }
        }

        if ($has_signal_updates) {
            $update['signals'] = substr(implode(',', array_values(array_unique($signals_list))), 0, 255);
        }

        if (!empty($update)) {
            $update['ajax_seen_time'] = time();

            $sql = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                    SET ' . $this->db->sql_build_array('UPDATE', $update) . '
                    WHERE log_id = ' . (int)$log_id . '
                    AND user_ip = \'' . $this->db->sql_escape((string)$this->user->ip) . '\'';
            $this->db->sql_return_on_error(true);
            $this->db->sql_query($sql);
            $sql_error = (bool)$this->db->get_sql_error_triggered();
            $this->db->sql_return_on_error(false);

            if ($sql_error) {
                return new JsonResponse(['ok' => 0], 500);
            }

            if ($emit_cookie_fail_signal || $emit_cursor_strict_signal) {
                $this->write_security_audit_signal(
                    (string)$this->user->ip,
                    $tracked_session,
                    (int)($this->user->data['user_id'] ?? 1),
                    (string)($update['signals'] ?? ($emit_cursor_strict_signal ? 'cursor_script_path' : 'guest_cookie_ajax_fail')),
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
     * Détecte si les colonnes cursor (migration 1.9.0) sont disponibles.
     */
    private function has_cursor_columns()
    {
        if ($this->has_cursor_columns !== null) {
            return $this->has_cursor_columns;
        }

        $sql = 'SELECT cursor_track_points, cursor_track_duration_ms, cursor_track_path, cursor_click_points,
                       cursor_device_class, cursor_viewport, cursor_total_distance, cursor_avg_speed,
                       cursor_max_speed, cursor_direction_changes, cursor_linearity, cursor_click_count
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

    /**
     * Convertit le format compact "t:x:y;t:x:y" en tableau borné.
     *
     * @return array<int,array{0:int,1:int,2:int}>
     */
    private function decode_cursor_triplets($raw, $limit)
    {
        $items = [];
        $text = trim((string)$raw);
        if ($text === '') {
            return $items;
        }

        $max = max(1, min(400, (int)$limit));
        $last_t = -1;
        $parts = explode(';', $text);
        foreach ($parts as $part) {
            if (count($items) >= $max) {
                break;
            }
            if (!preg_match('/^(\d{1,6}):(\d{1,5}):(\d{1,5})$/', trim((string)$part), $m)) {
                continue;
            }

            $t = max(0, min(120000, (int)$m[1]));
            $x = max(0, min(16384, (int)$m[2]));
            $y = max(0, min(16384, (int)$m[3]));
            if ($t < $last_t) {
                continue;
            }
            $last_t = $t;
            $items[] = [$t, $x, $y];
        }

        return $items;
    }

    /**
     * @param array<int,array{0:int,1:int,2:int}> $points
     * @param array<int,array{0:int,1:int,2:int}> $clicks
     * @return array<string,int>
     */
    private function compute_cursor_metrics(array $points, array $clicks, $duration_hint_ms = 0)
    {
        $points_count = count($points);
        $click_count = count($clicks);
        $duration_ms = max(0, min(120000, (int)$duration_hint_ms));
        if ($points_count >= 2) {
            $duration_ms = max($duration_ms, (int)$points[$points_count - 1][0] - (int)$points[0][0]);
        }

        $total_distance = 0.0;
        $max_speed = 0.0;
        $direction_changes = 0;
        $prev_dx = null;
        $prev_dy = null;

        for ($i = 1; $i < $points_count; $i++) {
            $dx = (float)$points[$i][1] - (float)$points[$i - 1][1];
            $dy = (float)$points[$i][2] - (float)$points[$i - 1][2];
            $dt = max(1, (int)$points[$i][0] - (int)$points[$i - 1][0]);
            $segment_dist = sqrt(($dx * $dx) + ($dy * $dy));
            $total_distance += $segment_dist;

            $speed = ($segment_dist * 1000.0) / (float)$dt;
            if ($speed > $max_speed) {
                $max_speed = $speed;
            }

            if ($segment_dist >= 2.0 && $prev_dx !== null && $prev_dy !== null) {
                $dot = ($prev_dx * $dx) + ($prev_dy * $dy);
                $n1 = sqrt(($prev_dx * $prev_dx) + ($prev_dy * $prev_dy));
                $n2 = sqrt(($dx * $dx) + ($dy * $dy));
                if ($n1 > 0.0 && $n2 > 0.0) {
                    $cos = max(-1.0, min(1.0, $dot / ($n1 * $n2)));
                    $angle = acos($cos) * 180.0 / 3.141592653589793;
                    if ($angle >= 40.0) {
                        $direction_changes++;
                    }
                }
            }

            if ($segment_dist >= 2.0) {
                $prev_dx = $dx;
                $prev_dy = $dy;
            }
        }

        $linearity = 0;
        if ($points_count >= 2 && $total_distance > 0.0) {
            $sx = (float)$points[0][1];
            $sy = (float)$points[0][2];
            $ex = (float)$points[$points_count - 1][1];
            $ey = (float)$points[$points_count - 1][2];
            $straight_dist = sqrt((($ex - $sx) * ($ex - $sx)) + (($ey - $sy) * ($ey - $sy)));
            $linearity = (int)round(max(0.0, min(100.0, ($straight_dist / $total_distance) * 100.0)));
        }

        $avg_speed = 0;
        if ($duration_ms > 0 && $total_distance > 0.0) {
            $avg_speed = (int)round(($total_distance * 1000.0) / (float)$duration_ms);
        }

        return [
            'points_count' => $points_count,
            'duration_ms' => $duration_ms,
            'total_distance' => (int)round(min(999999.0, $total_distance)),
            'avg_speed' => max(0, min(99999, $avg_speed)),
            'max_speed' => max(0, min(99999, (int)round($max_speed))),
            'direction_changes' => max(0, min(10000, (int)$direction_changes)),
            'linearity' => max(0, min(100, (int)$linearity)),
            'click_count' => max(0, min(200, $click_count)),
        ];
    }

    private function sanitize_cursor_device($raw)
    {
        $v = strtolower(trim((string)$raw));
        if ($v === 'desktop' || $v === 'mobile' || $v === 'tablet') {
            return $v;
        }
        return '';
    }

    private function sanitize_viewport($raw)
    {
        $v = trim((string)$raw);
        if (!preg_match('/^([1-9][0-9]{1,4})x([1-9][0-9]{1,4})$/', $v, $m)) {
            return '';
        }
        $w = (int)$m[1];
        $h = (int)$m[2];
        if ($w > 16384 || $h > 16384) {
            return '';
        }
        return $w . 'x' . $h;
    }

    /**
     * @param array<string,int> $features
     * @return array{signals:string[],strict_bot:int}
     */
    private function evaluate_cursor_signals(array $features, $device_class, $is_guest)
    {
        $signals = [];
        $strict_bot = 0;
        $device = strtolower(trim((string)$device_class));

        $points = (int)($features['points_count'] ?? 0);
        $duration = (int)($features['duration_ms'] ?? 0);
        $distance = (int)($features['total_distance'] ?? 0);
        $avg_speed = (int)($features['avg_speed'] ?? 0);
        $dir_changes = (int)($features['direction_changes'] ?? 0);
        $linearity = (int)($features['linearity'] ?? 0);
        $clicks = (int)($features['click_count'] ?? 0);

        if ($duration >= 2800 && $points <= 1) {
            $signals[] = 'cursor_no_movement';
        }
        if ($duration >= 2800 && $distance > 0 && $clicks === 0) {
            $signals[] = 'cursor_no_clicks';
        }
        if ($avg_speed >= 2600 && $dir_changes <= 2 && $points >= 8) {
            $signals[] = 'cursor_speed_outlier';
        }
        if ($device === 'desktop' && $points >= 16 && $distance >= 900 && $linearity >= 98 && $dir_changes <= 1 && $clicks === 0) {
            $signals[] = 'cursor_script_path';
            if ($is_guest) {
                $strict_bot = 1;
            }
        }

        return [
            'signals' => array_values(array_unique($signals)),
            'strict_bot' => $strict_bot,
        ];
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
        if ($cc === '' || $cc === '-' || $cc === 'ZZ') {
            return true;
        }
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
