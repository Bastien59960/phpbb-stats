<?php
/**
 * Stats Extension - Probabilistic session model
 *
 * Builds a lightweight bot-vs-human probability model from recent aggregated
 * sessions stored in bastien59_stats, then scores observed ACP sessions.
 *
 * This service is intentionally ACP-side first: it avoids adding heavy model
 * recomputation on the live forum request path while still surfacing a useful
 * probabilistic diagnosis for operators.
 *
 * @package bastien59960/stats
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\service;

class session_probability_model
{
    protected $db;
    protected $config;
    protected $table_prefix;
    protected $capabilities = null;
    protected $probability_table_exists = null;
    protected $model_cache = null;
    protected $model_cache_time = 0;
    protected $posts_per_page = null;
    protected $viewtopic_media_cache = [];
    protected $missing_path_cache = [];
    protected $rdns_feature_cache = [];

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        \phpbb\config\config $config,
        $table_prefix
    ) {
        $this->db = $db;
        $this->config = $config;
        $this->table_prefix = $table_prefix;
    }

    /**
     * Score an observed ACP session.
     *
     * @param array $session_row Landing/session row already loaded by ACP.
     * @param array $pages Full grouped timeline rows.
     * @param array $context Additional ACP-side booleans:
     *   - has_ip_multi_cookie
     *   - has_cookie_multi_ip
     *
     * @return array{
     *   probability_pct:int,
     *   probability_raw:float,
     *   class:string,
     *   scope:string,
     *   human_samples:int,
     *   bot_samples:int,
     *   factors:array<int,array<string,mixed>>,
     *   features:array<string,mixed>
     * }
     */
    public function assess_session(array $session_row, array $pages, array $context = [])
    {
        $features = $this->extract_session_features($session_row, $pages, $context);
        $model = $this->get_probability_model();

        $profile_key = $features['profile_key'];
        $min_samples = max(8, (int)($this->config['bastien59_stats_learning_min_samples'] ?? 25));

        $human_model = $this->pick_model_bucket($model, 'human', $profile_key, $min_samples);
        $bot_model = $this->pick_model_bucket($model, 'bot', $profile_key, $min_samples);

        $human_samples = (int)($human_model['sample_count'] ?? 0);
        $bot_samples = (int)($bot_model['sample_count'] ?? 0);
        $scope = (($human_model['scope'] ?? 'global') === 'profile' || ($bot_model['scope'] ?? 'global') === 'profile')
            ? 'profile'
            : 'global';

        $prior = $this->get_base_bot_prior($session_row);
        $log_odds = log($prior / max(0.0001, 1.0 - $prior));
        $factors = [];

        foreach ($this->get_model_feature_definitions() as $feature_key => $feature_def) {
            if (!array_key_exists($feature_key, $features)) {
                continue;
            }

            $observed = !empty($features[$feature_key]) ? 1 : 0;
            $human_rate = $this->resolve_model_rate($human_model, $feature_key, (float)$feature_def['default_human']);
            $bot_rate = $this->resolve_model_rate($bot_model, $feature_key, (float)$feature_def['default_bot']);
            $delta = $this->compute_log_odds_delta($observed, $human_rate, $bot_rate, (float)$feature_def['weight']);

            if (abs($delta) < 0.03) {
                continue;
            }

            $log_odds += $delta;
            $factors[] = [
                'code' => $feature_key,
                'source' => 'model',
                'observed' => $observed,
                'delta' => $delta,
                'human_rate' => $human_rate,
                'bot_rate' => $bot_rate,
            ];
        }

        foreach ($this->get_heuristic_feature_definitions() as $feature_key => $feature_def) {
            if (!array_key_exists($feature_key, $features)) {
                continue;
            }
            if (!$this->should_score_heuristic_feature($feature_key, $features)) {
                continue;
            }

            $observed = !empty($features[$feature_key]) ? 1 : 0;
            $delta = $this->compute_log_odds_delta(
                $observed,
                (float)$feature_def['human_rate'],
                (float)$feature_def['bot_rate'],
                (float)$feature_def['weight']
            );

            if (abs($delta) < 0.03) {
                continue;
            }

            $log_odds += $delta;
            $factor = [
                'code' => $feature_key,
                'source' => 'heuristic',
                'observed' => $observed,
                'delta' => $delta,
                'human_rate' => (float)$feature_def['human_rate'],
                'bot_rate' => (float)$feature_def['bot_rate'],
            ];

            if (
                $feature_key === 'has_missing_expected_media'
                || $feature_key === 'has_loaded_expected_media'
                || $feature_key === 'has_human_media_bundle'
            ) {
                $factor['expected_media_count'] = (int)$features['expected_media_count'];
                $factor['loaded_media_count'] = (int)$features['loaded_media_count'];
            } elseif ($feature_key === 'has_missing_media_path') {
                $factor['missing_media_path_count'] = (int)$features['missing_media_path_count'];
            } elseif ($feature_key === 'has_cookie_set_burst' || $feature_key === 'has_cookie_stable_issue') {
                $factor['cookie_set_count'] = (int)$features['cookie_set_count'];
            }

            $factors[] = $factor;
        }

        usort($factors, function ($a, $b) {
            $a_abs = abs((float)($a['delta'] ?? 0));
            $b_abs = abs((float)($b['delta'] ?? 0));
            if ($a_abs === $b_abs) {
                return strcmp((string)($a['code'] ?? ''), (string)($b['code'] ?? ''));
            }
            return ($a_abs < $b_abs) ? 1 : -1;
        });

        $probability_raw = 1.0 / (1.0 + exp(-$log_odds));

        // If the learned sample is weak, keep the output closer to neutral.
        $sample_strength = min(1.0, ($human_samples + $bot_samples) / 120.0);
        $probability_raw = 0.5 + (($probability_raw - 0.5) * $sample_strength);

        $probability_pct = max(0, min(100, (int)round($probability_raw * 100)));
        $class = 'low';
        if ($probability_pct >= 90) {
            $class = 'very-high';
        } elseif ($probability_pct >= 75) {
            $class = 'high';
        } elseif ($probability_pct >= 45) {
            $class = 'medium';
        }

        return [
            'probability_pct' => $probability_pct,
            'probability_raw' => $probability_raw,
            'class' => $class,
            'scope' => $scope,
            'human_samples' => $human_samples,
            'bot_samples' => $bot_samples,
            'factors' => array_slice($factors, 0, 6),
            'features' => $features,
        ];
    }

    private function get_base_bot_prior(array $session_row)
    {
        $user_id = (int)($session_row['user_id'] ?? 0);
        $is_bot = !empty($session_row['is_bot']) ? 1 : 0;

        if ($is_bot) {
            return 0.72;
        }
        if ($user_id > 1) {
            return 0.04;
        }

        return 0.16;
    }

    private function get_probability_model()
    {
        $ttl = max(60, (int)($this->config['bastien59_stats_probability_model_ttl'] ?? 300));
        if ($this->model_cache !== null && (time() - $this->model_cache_time) <= $ttl) {
            return $this->model_cache;
        }

        if ($this->has_probability_model_table()) {
            $persisted = $this->load_persisted_probability_model($ttl);
            if ($persisted !== null) {
                $this->model_cache = $persisted;
                $this->model_cache_time = time();
                return $this->model_cache;
            }
        }

        $model = $this->build_probability_model_from_stats();

        if ($this->has_probability_model_table()) {
            $this->persist_probability_model($model);
        }

        $this->model_cache = $model;
        $this->model_cache_time = time();

        return $this->model_cache;
    }

    private function build_probability_model_from_stats()
    {
        $caps = $this->get_capabilities();
        $cutoff = time() - max(7, (int)($this->config['bastien59_stats_probability_model_days'] ?? 30)) * 86400;
        $member_session_max_seconds = $this->get_member_session_max_seconds();
        $session_bucket_expr = $this->build_session_bucket_sql_expr(
            'session_id',
            'user_id',
            'visit_time',
            $member_session_max_seconds
        );

        $feature_sql = [
            'has_screen_res' => "MAX(CASE WHEN screen_res <> '' THEN 1 ELSE 0 END) AS has_screen_res",
            'has_referer' => "MAX(CASE WHEN referer <> '' AND referer <> '-' THEN 1 ELSE 0 END) AS has_referer",
            'has_view_print' => "MAX(CASE WHEN LOWER(page_url) LIKE '%view=print%' THEN 1 ELSE 0 END) AS has_view_print",
            'has_downloads' => "MAX(CASE WHEN LOWER(page_url) LIKE '%download/file.php%' THEN 1 ELSE 0 END) AS has_downloads",
            'has_multi_page' => "CASE WHEN SUM(CASE WHEN LOWER(page_url) LIKE '%download/file.php%' THEN 0 ELSE 1 END) >= 2 THEN 1 ELSE 0 END AS has_multi_page",
        ];

        if ($caps['ajax']) {
            $feature_sql['has_ajax'] = "MAX(CASE WHEN ajax_seen_time > 0 OR screen_res_ajax <> '' THEN 1 ELSE 0 END) AS has_ajax";
            $feature_sql['has_scroll'] = 'MAX(CASE WHEN scroll_down_ajax = 1 THEN 1 ELSE 0 END) AS has_scroll';
        }
        if ($caps['ajax_advanced']) {
            $feature_sql['has_interact'] = 'MAX(CASE WHEN ajax_interact_mask > 0 THEN 1 ELSE 0 END) AS has_interact';
        }
        if ($caps['cookie_debug']) {
            $feature_sql['has_preexisting_cookie'] = 'MAX(CASE WHEN visitor_cookie_preexisting = 1 THEN 1 ELSE 0 END) AS has_preexisting_cookie';
            $feature_sql['has_ajax_cookie_ok'] = 'MAX(CASE WHEN visitor_cookie_ajax_state = 1 THEN 1 ELSE 0 END) AS has_ajax_cookie_ok';
        }
        if ($caps['reactions']) {
            $feature_sql['expects_reactions'] = 'MAX(CASE WHEN reactions_extension_expected = 1 THEN 1 ELSE 0 END) AS expects_reactions';
            $feature_sql['has_reactions_missing'] = 'MAX(CASE WHEN reactions_extension_expected = 1 AND reactions_css_seen = 0 THEN 1 ELSE 0 END) AS has_reactions_missing';
        }
        if ($caps['apache']) {
            $feature_sql['has_apache_ui_assets'] = 'MAX(CASE WHEN COALESCE(apache_banner_hits, 0) + COALESCE(apache_rank_hits, 0) + COALESCE(apache_avatar_hits, 0) > 0 THEN 1 ELSE 0 END) AS has_apache_ui_assets';
        }
        if ($caps['cursor']) {
            $feature_sql['has_cursor'] = 'MAX(CASE WHEN cursor_track_points > 2 THEN 1 ELSE 0 END) AS has_cursor';
        }

        $outer_sums = [];
        foreach (array_keys($feature_sql) as $feature_key) {
            $outer_sums[] = 'SUM(' . $feature_key . ') AS ' . $feature_key . '_hits';
        }

        $guest_human_signals = [];
        if ($caps['ajax']) {
            $guest_human_signals[] = "(ajax_seen_time > 0 AND screen_res_ajax <> '')";
            $guest_human_signals[] = 'scroll_down_ajax = 1';
        }
        if ($caps['ajax_advanced']) {
            $guest_human_signals[] = 'ajax_interact_mask > 0';
        }
        if ($caps['cursor']) {
            $guest_human_signals[] = 'cursor_track_points > 2';
        }
        if ($caps['cookie_debug']) {
            $guest_human_signals[] = 'visitor_cookie_preexisting = 1';
            $guest_human_signals[] = 'visitor_cookie_ajax_state = 1';
        }
        if ($caps['apache']) {
            $guest_human_signals[] = '(COALESCE(apache_banner_hits, 0) + COALESCE(apache_rank_hits, 0) + COALESCE(apache_avatar_hits, 0) > 0)';
        }
        $guest_human_expr = !empty($guest_human_signals)
            ? '(' . implode(' OR ', $guest_human_signals) . ')'
            : '0 = 1';

        $sql = 'SELECT actor_class, user_os, user_device, COUNT(*) AS sample_count, '
            . implode(', ', $outer_sums) . '
                FROM (
                    SELECT ' . $session_bucket_expr . ' AS session_bucket,
                           CASE
                               WHEN MAX(CASE WHEN is_bot = 1 AND bot_source <> \'phpbb\' THEN 1 ELSE 0 END) = 1 THEN \'bot\'
                               WHEN MAX(CASE WHEN user_id > 1 THEN 1 ELSE 0 END) = 1 THEN \'human\'
                               WHEN MAX(CASE WHEN user_id <= 1 AND is_bot = 0 AND ' . $guest_human_expr . ' THEN 1 ELSE 0 END) = 1 THEN \'human\'
                               ELSE \'\'
                           END AS actor_class,
                           MAX(CASE WHEN user_os <> \'\' THEN user_os ELSE \'Inconnu\' END) AS user_os,
                           MAX(CASE WHEN user_device <> \'\' THEN user_device ELSE \'Inconnu\' END) AS user_device,
                           ' . implode(', ', array_values($feature_sql)) . '
                    FROM ' . $this->table_prefix . 'bastien59_stats
                    WHERE visit_time >= ' . (int)$cutoff . '
                    GROUP BY ' . $session_bucket_expr . '
                ) model_rows
                WHERE actor_class <> \'\'
                GROUP BY actor_class, user_os, user_device';

        $result = $this->db->sql_query($sql);
        $model = [
            'profiles' => [],
            'global' => [
                'human' => ['sample_count' => 0, 'feature_hits' => []],
                'bot' => ['sample_count' => 0, 'feature_hits' => []],
            ],
            'feature_keys' => array_keys($feature_sql),
        ];

        while ($row = $this->db->sql_fetchrow($result)) {
            $actor_class = trim((string)($row['actor_class'] ?? ''));
            if ($actor_class !== 'human' && $actor_class !== 'bot') {
                continue;
            }

            $profile_key = $this->build_profile_key(
                (string)($row['user_os'] ?? ''),
                (string)($row['user_device'] ?? '')
            );
            if ($profile_key === '') {
                $profile_key = 'inconnu|inconnu';
            }

            $bucket = [
                'scope' => 'profile',
                'sample_count' => (int)($row['sample_count'] ?? 0),
                'feature_hits' => [],
            ];
            foreach (array_keys($feature_sql) as $feature_key) {
                $bucket['feature_hits'][$feature_key] = (int)($row[$feature_key . '_hits'] ?? 0);
                if (!isset($model['global'][$actor_class]['feature_hits'][$feature_key])) {
                    $model['global'][$actor_class]['feature_hits'][$feature_key] = 0;
                }
                $model['global'][$actor_class]['feature_hits'][$feature_key] += (int)$bucket['feature_hits'][$feature_key];
            }

            if (!isset($model['profiles'][$profile_key])) {
                $model['profiles'][$profile_key] = [];
            }
            $model['profiles'][$profile_key][$actor_class] = $bucket;
            $model['global'][$actor_class]['sample_count'] += (int)$bucket['sample_count'];
        }
        $this->db->sql_freeresult($result);

        $model['global']['human']['scope'] = 'global';
        $model['global']['bot']['scope'] = 'global';

        return $model;
    }

    private function pick_model_bucket(array $model, $actor_class, $profile_key, $min_samples)
    {
        $profile_bucket = [];
        if ($profile_key !== '' && isset($model['profiles'][$profile_key][$actor_class])) {
            $profile_bucket = $model['profiles'][$profile_key][$actor_class];
        }

        if (!empty($profile_bucket) && (int)($profile_bucket['sample_count'] ?? 0) >= (int)$min_samples) {
            return $profile_bucket;
        }

        return $model['global'][$actor_class];
    }

    private function resolve_model_rate(array $bucket, $feature_key, $default_rate)
    {
        $sample_count = max(0, (int)($bucket['sample_count'] ?? 0));
        $hits = max(0, (int)($bucket['feature_hits'][$feature_key] ?? 0));
        if ($sample_count <= 0) {
            return $default_rate;
        }

        // Light Laplace smoothing to avoid 0/1 probabilities.
        return ($hits + 1.0) / ($sample_count + 2.0);
    }

    private function compute_log_odds_delta($observed, $human_rate, $bot_rate, $weight)
    {
        $p_h = min(0.98, max(0.02, (float)$human_rate));
        $p_b = min(0.98, max(0.02, (float)$bot_rate));
        $w = max(0.05, (float)$weight);

        if ((int)$observed === 1) {
            return log($p_b / $p_h) * $w;
        }

        return log((1.0 - $p_b) / (1.0 - $p_h)) * $w;
    }

    private function get_model_feature_definitions()
    {
        return [
            'has_screen_res' => ['weight' => 0.65, 'default_human' => 0.92, 'default_bot' => 0.25],
            'has_ajax' => ['weight' => 0.90, 'default_human' => 0.82, 'default_bot' => 0.18],
            'has_scroll' => ['weight' => 0.45, 'default_human' => 0.62, 'default_bot' => 0.20],
            'has_interact' => ['weight' => 0.80, 'default_human' => 0.74, 'default_bot' => 0.12],
            'has_preexisting_cookie' => ['weight' => 0.95, 'default_human' => 0.78, 'default_bot' => 0.14],
            'has_ajax_cookie_ok' => ['weight' => 0.90, 'default_human' => 0.72, 'default_bot' => 0.08],
            'has_referer' => ['weight' => 0.35, 'default_human' => 0.58, 'default_bot' => 0.28],
            'has_view_print' => ['weight' => 0.90, 'default_human' => 0.01, 'default_bot' => 0.34],
            'has_reactions_missing' => ['weight' => 0.65, 'default_human' => 0.05, 'default_bot' => 0.35],
            'has_apache_ui_assets' => ['weight' => 0.25, 'default_human' => 0.35, 'default_bot' => 0.10],
            'has_multi_page' => ['weight' => 0.30, 'default_human' => 0.44, 'default_bot' => 0.22],
            'has_cursor' => ['weight' => 0.75, 'default_human' => 0.40, 'default_bot' => 0.03],
        ];
    }

    private function get_heuristic_feature_definitions()
    {
        return [
            'has_ip_multi_cookie' => ['weight' => 1.10, 'human_rate' => 0.03, 'bot_rate' => 0.72],
            'has_cookie_multi_ip' => ['weight' => 0.80, 'human_rate' => 0.07, 'bot_rate' => 0.45],
            'has_ua_switch' => ['weight' => 1.20, 'human_rate' => 0.01, 'bot_rate' => 0.70],
            'has_consistent_ua' => ['weight' => 0.95, 'human_rate' => 0.80, 'bot_rate' => 0.18],
            'has_missing_path' => ['weight' => 1.25, 'human_rate' => 0.02, 'bot_rate' => 0.68],
            'has_missing_media_path' => ['weight' => 1.80, 'human_rate' => 0.005, 'bot_rate' => 0.82],
            'has_missing_expected_media' => ['weight' => 1.35, 'human_rate' => 0.08, 'bot_rate' => 0.75],
            'has_loaded_expected_media' => ['weight' => 0.95, 'human_rate' => 0.72, 'bot_rate' => 0.20],
            'has_human_media_bundle' => ['weight' => 1.85, 'human_rate' => 0.78, 'bot_rate' => 0.06],
            'has_multi_loaded_media' => ['weight' => 1.05, 'human_rate' => 0.69, 'bot_rate' => 0.11],
            'has_human_scroll_bundle' => ['weight' => 1.30, 'human_rate' => 0.76, 'bot_rate' => 0.08],
            'has_verified_rdns' => ['weight' => 0.40, 'human_rate' => 0.40, 'bot_rate' => 0.16],
            'has_missing_rdns' => ['weight' => 0.55, 'human_rate' => 0.18, 'bot_rate' => 0.44],
            'has_cookie_set_burst' => ['weight' => 1.55, 'human_rate' => 0.01, 'bot_rate' => 0.62],
            'has_cookie_stable_issue' => ['weight' => 1.10, 'human_rate' => 0.64, 'bot_rate' => 0.16],
        ];
    }

    private function extract_session_features(array $session_row, array $pages, array $context)
    {
        $features = [
            'profile_key' => $this->build_profile_key(
                (string)($session_row['user_os'] ?? ''),
                (string)($session_row['user_device'] ?? '')
            ),
            'has_screen_res' => false,
            'has_ajax' => false,
            'has_scroll' => false,
            'has_interact' => false,
            'has_preexisting_cookie' => false,
            'has_ajax_cookie_ok' => false,
            'has_referer' => false,
            'has_view_print' => false,
            'has_reactions_missing' => false,
            'has_apache_ui_assets' => false,
            'has_multi_page' => false,
            'has_cursor' => false,
            'has_ua_switch' => false,
            'has_consistent_ua' => false,
            'has_ip_multi_cookie' => !empty($context['has_ip_multi_cookie']),
            'has_cookie_multi_ip' => !empty($context['has_cookie_multi_ip']),
            'has_missing_path' => false,
            'has_missing_media_path' => false,
            'has_missing_expected_media' => false,
            'has_loaded_expected_media' => false,
            'has_human_media_bundle' => false,
            'has_multi_loaded_media' => false,
            'has_human_scroll_bundle' => false,
            'has_verified_rdns' => false,
            'has_missing_rdns' => false,
            'has_cookie_set_burst' => false,
            'has_cookie_stable_issue' => false,
            'missing_media_path_count' => 0,
            'cookie_set_count' => 0,
            'expected_media_count' => 0,
            'loaded_media_count' => 0,
        ];

        $ua_map = [];
        $html_page_count = 0;
        $reactions_expected = false;
        $loaded_attachment_ids = [];

        foreach ($pages as $page) {
            $page_url = trim((string)($page['page_url'] ?? ''));
            $page_url_lc = strtolower($page_url);
            $user_agent = trim((string)($page['user_agent'] ?? ''));
            if ($user_agent !== '') {
                $ua_map[$user_agent] = true;
            }

            if (!$this->is_download_file_url($page_url)) {
                $html_page_count++;
            } else {
                $attach_id = $this->extract_download_attach_id($page_url);
                if ($attach_id > 0) {
                    $loaded_attachment_ids[$attach_id] = true;
                }
            }

            if (!$features['has_screen_res'] && trim((string)($page['screen_res'] ?? '')) !== '') {
                $features['has_screen_res'] = true;
            }
            if (
                !$features['has_ajax']
                && (
                    trim((string)($page['screen_res_ajax'] ?? '')) !== ''
                    || (int)($page['ajax_seen_time'] ?? 0) > 0
                )
            ) {
                $features['has_ajax'] = true;
            }
            if (!$features['has_scroll'] && !empty($page['scroll_down_ajax'])) {
                $features['has_scroll'] = true;
            }
            if (!$features['has_interact'] && (int)($page['ajax_interact_mask'] ?? 0) > 0) {
                $features['has_interact'] = true;
            }
            if (!$features['has_preexisting_cookie'] && !empty($page['visitor_cookie_preexisting'])) {
                $features['has_preexisting_cookie'] = true;
            }
            if (!$features['has_ajax_cookie_ok'] && (int)($page['visitor_cookie_ajax_state'] ?? 0) === 1) {
                $features['has_ajax_cookie_ok'] = true;
            }
            if (
                $this->is_valid_visitor_cookie_hash((string)($page['visitor_cookie_hash'] ?? ''))
                && empty($page['visitor_cookie_preexisting'])
            ) {
                $features['cookie_set_count']++;
            }
            $referer = trim((string)($page['referer'] ?? ''));
            if (!$features['has_referer'] && $referer !== '' && $referer !== '-') {
                $features['has_referer'] = true;
            }
            if (!$features['has_view_print'] && strpos($page_url_lc, 'view=print') !== false) {
                $features['has_view_print'] = true;
            }
            if (!$features['has_cursor'] && (int)($page['cursor_track_points'] ?? 0) > 2) {
                $features['has_cursor'] = true;
            }
            if (
                !$features['has_apache_ui_assets']
                && (
                    (int)($page['apache_banner_hits'] ?? 0) > 0
                    || (int)($page['apache_rank_hits'] ?? 0) > 0
                    || (int)($page['apache_avatar_hits'] ?? 0) > 0
                )
            ) {
                $features['has_apache_ui_assets'] = true;
            }

            if ((int)($page['reactions_extension_expected'] ?? 0) === 1) {
                $reactions_expected = true;
                if ((int)($page['reactions_css_seen'] ?? 0) === 0) {
                    $features['has_reactions_missing'] = true;
                }
            }

            if (!$features['has_missing_path'] && $this->is_missing_path_url($page_url)) {
                $features['has_missing_path'] = true;
            }
            if ($this->is_missing_media_path_url($page_url)) {
                $features['has_missing_media_path'] = true;
                $features['missing_media_path_count']++;
            }
        }

        $features['has_multi_page'] = ($html_page_count >= 2);
        $features['has_ua_switch'] = (count($ua_map) >= 2);
        $features['has_consistent_ua'] = (!empty($ua_map) && count($ua_map) === 1);

        $media_expectation = $this->compute_session_media_expectation($pages, array_keys($loaded_attachment_ids));
        $features['expected_media_count'] = (int)$media_expectation['expected_count'];
        $features['loaded_media_count'] = (int)$media_expectation['loaded_count'];
        $features['has_missing_expected_media'] = ($features['expected_media_count'] > 0 && $features['loaded_media_count'] === 0);
        $features['has_loaded_expected_media'] = ($features['expected_media_count'] > 0 && $features['loaded_media_count'] > 0);
        $features['has_multi_loaded_media'] = ($features['loaded_media_count'] >= 3);
        $features['has_human_media_bundle'] = (
            $features['expected_media_count'] >= 3
            && $features['loaded_media_count'] >= min(3, $features['expected_media_count'])
            && (
                $features['has_ajax']
                || $features['has_scroll']
                || $features['has_cursor']
                || $features['has_preexisting_cookie']
                || $features['has_ajax_cookie_ok']
            )
        );
        $features['has_human_scroll_bundle'] = (
            $features['has_scroll']
            && (
                $features['has_interact']
                || $features['has_cursor']
                || $features['has_ajax']
                || $features['has_preexisting_cookie']
                || $features['has_ajax_cookie_ok']
            )
        );
        $features['has_cookie_set_burst'] = ($features['cookie_set_count'] >= 3);
        $features['has_cookie_stable_issue'] = (
            $features['cookie_set_count'] >= 1
            && $features['cookie_set_count'] <= 2
            && (
                $features['has_preexisting_cookie']
                || $features['has_ajax_cookie_ok']
                || $features['has_human_media_bundle']
                || ($features['has_ajax'] && $features['has_multi_page'])
            )
        );
        $rdns_features = $this->extract_reverse_dns_features($session_row);
        $features['has_verified_rdns'] = !empty($rdns_features['has_verified_rdns']);
        $features['has_missing_rdns'] = !empty($rdns_features['has_missing_rdns']);

        // When reactions are not expected on the session at all, do not keep the
        // feature active just because all rows defaulted to zero.
        if (!$reactions_expected) {
            $features['has_reactions_missing'] = false;
        }

        return $features;
    }

    /**
     * Some heuristics are only meaningful in one direction.
     *
     * Example: a coherent "page + expected images + interaction" bundle is a
     * strong human signal when present, but its absence alone should not be
     * counted again as a bot signal because other factors already cover that
     * space (missing expected media, no AJAX, no cursor, etc.).
     */
    private function should_score_heuristic_feature($feature_key, array $features)
    {
        if ($feature_key === 'has_missing_expected_media') {
            return ((int)($features['expected_media_count'] ?? 0) > 0);
        }

        if ($feature_key === 'has_loaded_expected_media') {
            return !empty($features['has_loaded_expected_media']);
        }

        if ($feature_key === 'has_human_media_bundle') {
            return !empty($features['has_human_media_bundle']);
        }

        if ($feature_key === 'has_multi_loaded_media') {
            return !empty($features['has_multi_loaded_media']);
        }

        if ($feature_key === 'has_human_scroll_bundle') {
            return !empty($features['has_human_scroll_bundle']);
        }

        if ($feature_key === 'has_ua_switch') {
            return !empty($features['has_ua_switch']);
        }

        if ($feature_key === 'has_consistent_ua') {
            return !empty($features['has_consistent_ua']);
        }

        if ($feature_key === 'has_verified_rdns') {
            return !empty($features['has_verified_rdns']);
        }

        if ($feature_key === 'has_missing_rdns') {
            return !empty($features['has_missing_rdns']);
        }

        if ($feature_key === 'has_cookie_stable_issue') {
            return ((int)($features['cookie_set_count'] ?? 0) > 0);
        }

        return true;
    }

    private function extract_reverse_dns_features(array $session_row)
    {
        $ip = trim((string)($session_row['user_ip'] ?? ''));
        $hostname = trim((string)($session_row['session_hostname'] ?? ''));
        if ($hostname === '') {
            $hostname = trim((string)($session_row['hostname'] ?? ''));
        }

        $cache_key = $ip . '|' . $hostname;
        if (isset($this->rdns_feature_cache[$cache_key])) {
            return $this->rdns_feature_cache[$cache_key];
        }

        $features = [
            'has_verified_rdns' => false,
            'has_missing_rdns' => false,
        ];

        if ($ip === '' || $hostname === '') {
            $this->rdns_feature_cache[$cache_key] = $features;
            return $features;
        }

        if ($hostname === '-') {
            $features['has_missing_rdns'] = true;
            $this->rdns_feature_cache[$cache_key] = $features;
            return $features;
        }

        if (strpos($ip, ':') !== false) {
            $aaaa_records = @dns_get_record($hostname, DNS_AAAA);
            if (!empty($aaaa_records)) {
                foreach ($aaaa_records as $record) {
                    if (!empty($record['ipv6']) && trim((string)$record['ipv6']) === $ip) {
                        $features['has_verified_rdns'] = true;
                        break;
                    }
                }
            }
        } else {
            $forward_ips = @gethostbynamel($hostname);
            if ($forward_ips !== false && in_array($ip, $forward_ips, true)) {
                $features['has_verified_rdns'] = true;
            }
        }

        $this->rdns_feature_cache[$cache_key] = $features;
        return $features;
    }

    private function compute_session_media_expectation(array $pages, array $loaded_attachment_ids)
    {
        $expected_attachment_ids = [];
        foreach ($pages as $page) {
            $page_url = trim((string)($page['page_url'] ?? ''));
            if ($page_url === '' || $this->is_download_file_url($page_url)) {
                continue;
            }

            $page_attachment_ids = $this->get_viewtopic_page_image_attachment_ids($page_url);
            foreach ($page_attachment_ids as $attach_id) {
                $expected_attachment_ids[(int)$attach_id] = true;
            }
        }

        $loaded_count = 0;
        foreach (array_keys($expected_attachment_ids) as $attach_id) {
            if (isset($loaded_attachment_ids[(int)$attach_id])) {
                $loaded_count++;
            }
        }

        return [
            'expected_count' => count($expected_attachment_ids),
            'loaded_count' => $loaded_count,
        ];
    }

    private function get_viewtopic_page_image_attachment_ids($page_url)
    {
        $raw_url = trim((string)$page_url);
        if ($raw_url === '') {
            return [];
        }

        $path = parse_url($raw_url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return [];
        }
        if (strtolower(basename($path)) !== 'viewtopic.php') {
            return [];
        }

        parse_str((string)parse_url($raw_url, PHP_URL_QUERY), $query);
        if (strtolower(trim((string)($query['view'] ?? ''))) === 'print') {
            return [];
        }

        $topic_id = 0;
        $start = max(0, (int)($query['start'] ?? 0));
        if (!empty($query['p'])) {
            $post_id = (int)$query['p'];
            if ($post_id <= 0) {
                return [];
            }

            $sql = 'SELECT post_id, topic_id, post_time
                    FROM ' . $this->table_prefix . 'posts
                    WHERE post_id = ' . (int)$post_id;
            $result = $this->db->sql_query_limit($sql, 1);
            $post_row = $this->db->sql_fetchrow($result);
            $this->db->sql_freeresult($result);
            if (!$post_row) {
                return [];
            }

            $topic_id = (int)($post_row['topic_id'] ?? 0);
            if ($topic_id <= 0) {
                return [];
            }

            $sql = 'SELECT COUNT(*) AS ordinal_pos
                    FROM ' . $this->table_prefix . 'posts
                    WHERE topic_id = ' . (int)$topic_id . '
                      AND (
                           post_time < ' . (int)$post_row['post_time'] . '
                           OR (post_time = ' . (int)$post_row['post_time'] . ' AND post_id <= ' . (int)$post_id . ')
                      )';
            $result = $this->db->sql_query_limit($sql, 1);
            $ordinal = (int)$this->db->sql_fetchfield('ordinal_pos');
            $this->db->sql_freeresult($result);
            if ($ordinal > 0) {
                $start = (int)(floor(($ordinal - 1) / max(1, $this->get_posts_per_page())) * $this->get_posts_per_page());
            }
        } elseif (!empty($query['t'])) {
            $topic_id = (int)$query['t'];
        }

        if ($topic_id <= 0) {
            return [];
        }

        $cache_key = $topic_id . '|' . $start . '|' . $this->get_posts_per_page();
        if (isset($this->viewtopic_media_cache[$cache_key])) {
            return $this->viewtopic_media_cache[$cache_key];
        }

        $sql = 'SELECT post_id
                FROM ' . $this->table_prefix . 'posts
                WHERE topic_id = ' . (int)$topic_id . '
                ORDER BY post_time ASC, post_id ASC';
        $result = $this->db->sql_query_limit($sql, max(1, $this->get_posts_per_page()), (int)$start);
        $post_ids = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $post_ids[] = (int)$row['post_id'];
        }
        $this->db->sql_freeresult($result);

        if (empty($post_ids)) {
            return $this->viewtopic_media_cache[$cache_key] = [];
        }

        $sql = 'SELECT attach_id
                FROM ' . $this->table_prefix . 'attachments
                WHERE topic_id = ' . (int)$topic_id . '
                  AND post_msg_id IN (' . implode(',', array_map('intval', $post_ids)) . ')
                  AND mimetype LIKE \'image/%\'';
        $result = $this->db->sql_query($sql);
        $attachment_ids = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $attachment_ids[] = (int)$row['attach_id'];
        }
        $this->db->sql_freeresult($result);

        $this->viewtopic_media_cache[$cache_key] = $attachment_ids;
        return $this->viewtopic_media_cache[$cache_key];
    }

    private function get_posts_per_page()
    {
        if ($this->posts_per_page !== null) {
            return $this->posts_per_page;
        }

        $this->posts_per_page = max(1, (int)($this->config['posts_per_page'] ?? 20));
        return $this->posts_per_page;
    }

    private function build_profile_key($user_os, $user_device)
    {
        $os = trim((string)$user_os);
        $device = trim((string)$user_device);
        if ($os === '') {
            $os = 'Inconnu';
        }
        if ($device === '') {
            $device = 'Inconnu';
        }

        return strtolower($os . '|' . $device);
    }

    private function is_valid_visitor_cookie_hash($hash)
    {
        return (bool)preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)$hash)));
    }

    private function is_download_file_url($url)
    {
        $raw_url = strtolower(trim((string)$url));
        return ($raw_url !== '' && strpos($raw_url, 'download/file.php') !== false);
    }

    private function extract_download_attach_id($url)
    {
        $query = (string)parse_url((string)$url, PHP_URL_QUERY);
        if ($query === '') {
            return 0;
        }

        parse_str($query, $params);
        return max(0, (int)($params['id'] ?? 0));
    }

    private function is_missing_path_url($page_url)
    {
        $raw_url = trim((string)$page_url);
        if ($raw_url === '') {
            return false;
        }
        $cache_key = strtolower($raw_url);
        if (isset($this->missing_path_cache[$cache_key])) {
            return $this->missing_path_cache[$cache_key];
        }

        $path = parse_url($raw_url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $raw_url;
        }

        $normalized_path = '/' . ltrim($path, '/');
        $path_lc = strtolower($normalized_path);

        if (!$this->looks_like_direct_resource_path($path_lc)) {
            return $this->missing_path_cache[$cache_key] = false;
        }

        $candidate = $this->resolve_public_url_path_to_file($normalized_path);
        if ($candidate === null) {
            return $this->missing_path_cache[$cache_key] = false;
        }

        return $this->missing_path_cache[$cache_key] = !is_file($candidate);
    }

    private function is_missing_media_path_url($page_url)
    {
        $raw_url = trim((string)$page_url);
        if ($raw_url === '' || !$this->is_missing_path_url($raw_url)) {
            return false;
        }

        $path = parse_url($raw_url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $raw_url;
        }
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return in_array($extension, ['mp3', 'wav', 'ogg', 'm4a', 'flac', 'mp4', 'm4v', 'avi', 'mpg', 'mpeg', 'mov', 'wmv', 'mkv', 'webm'], true);
    }

    private function looks_like_direct_resource_path($path_lc)
    {
        if ($path_lc === '' || $path_lc === '/' || strpos($path_lc, '/download/file.php') === 0) {
            return false;
        }

        $basename = basename($path_lc);
        if ($basename === '' || $basename === '/' || strpos($basename, '.php') !== false) {
            return false;
        }

        if (preg_match('#^/(?:index|viewtopic|viewforum|search|memberlist|posting|ucp|mcp|faq|app\.php)(?:$|[/?])#', $path_lc)) {
            return false;
        }

        return (bool)preg_match('/\.[a-z0-9]{2,5}$/', $basename);
    }

    private function resolve_public_url_path_to_file($normalized_path)
    {
        $path = trim((string)$normalized_path);
        if ($path === '' || $path === '/') {
            return null;
        }

        $root = defined('PHPBB_ROOT_PATH')
            ? PHPBB_ROOT_PATH
            : dirname(__DIR__, 4) . '/';

        return $root . ltrim($path, '/');
    }

    private function get_capabilities()
    {
        if ($this->capabilities !== null) {
            return $this->capabilities;
        }

        $this->capabilities = [
            'ajax' => $this->probe_columns('screen_res_ajax, scroll_down_ajax, ajax_seen_time'),
            'ajax_advanced' => $this->probe_columns('ajax_interact_mask'),
            'cookie_debug' => $this->probe_columns('visitor_cookie_preexisting, visitor_cookie_ajax_state'),
            'reactions' => $this->probe_columns('reactions_extension_expected, reactions_css_seen'),
            'apache' => $this->probe_columns('apache_banner_hits, apache_rank_hits, apache_avatar_hits'),
            'cursor' => $this->probe_columns('cursor_track_points'),
        ];

        return $this->capabilities;
    }

    private function has_probability_model_table()
    {
        if ($this->probability_table_exists !== null) {
            return $this->probability_table_exists;
        }

        $sql = 'SELECT scope, actor_class, profile_hash, feature_key
                FROM ' . $this->table_prefix . 'bastien59_stats_probability_model
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->probability_table_exists = !$has_error;
        return $this->probability_table_exists;
    }

    private function load_persisted_probability_model($ttl)
    {
        $cutoff = time() - max(60, (int)$ttl);
        $sql = 'SELECT scope, actor_class, profile_hash, profile_key, feature_key,
                       sample_count, hit_count, updated_time
                FROM ' . $this->table_prefix . 'bastien59_stats_probability_model
                WHERE updated_time >= ' . (int)$cutoff . '
                ORDER BY scope ASC, actor_class ASC, profile_key ASC, feature_key ASC';
        $result = $this->db->sql_query($sql);

        $rows = [];
        $max_updated = 0;
        while ($row = $this->db->sql_fetchrow($result)) {
            $rows[] = $row;
            $max_updated = max($max_updated, (int)($row['updated_time'] ?? 0));
        }
        $this->db->sql_freeresult($result);

        if (empty($rows) || $max_updated < $cutoff) {
            return null;
        }

        $model = [
            'profiles' => [],
            'global' => [
                'human' => ['scope' => 'global', 'sample_count' => 0, 'feature_hits' => []],
                'bot' => ['scope' => 'global', 'sample_count' => 0, 'feature_hits' => []],
            ],
            'feature_keys' => [],
        ];

        foreach ($rows as $row) {
            $scope = trim((string)($row['scope'] ?? ''));
            $actor_class = trim((string)($row['actor_class'] ?? ''));
            $profile_key = trim((string)($row['profile_key'] ?? ''));
            $feature_key = trim((string)($row['feature_key'] ?? ''));
            if ($feature_key === '' || ($actor_class !== 'human' && $actor_class !== 'bot')) {
                continue;
            }

            $model['feature_keys'][$feature_key] = true;
            $bucket = [
                'scope' => ($scope === 'profile') ? 'profile' : 'global',
                'sample_count' => (int)($row['sample_count'] ?? 0),
                'feature_hits' => [
                    $feature_key => (int)($row['hit_count'] ?? 0),
                ],
            ];

            if ($scope === 'profile' && $profile_key !== '') {
                if (!isset($model['profiles'][$profile_key][$actor_class])) {
                    $model['profiles'][$profile_key][$actor_class] = [
                        'scope' => 'profile',
                        'sample_count' => (int)($row['sample_count'] ?? 0),
                        'feature_hits' => [],
                    ];
                }
                $model['profiles'][$profile_key][$actor_class]['sample_count'] = (int)($row['sample_count'] ?? 0);
                $model['profiles'][$profile_key][$actor_class]['feature_hits'][$feature_key] = (int)($row['hit_count'] ?? 0);
            } else {
                $model['global'][$actor_class]['sample_count'] = (int)($row['sample_count'] ?? 0);
                $model['global'][$actor_class]['feature_hits'][$feature_key] = (int)($row['hit_count'] ?? 0);
            }
        }

        $model['feature_keys'] = array_keys($model['feature_keys']);

        return $model;
    }

    private function persist_probability_model(array $model)
    {
        $table = $this->table_prefix . 'bastien59_stats_probability_model';
        $updated_time = time();

        $this->db->sql_query('DELETE FROM ' . $table);

        $insert_rows = [];
        foreach (['human', 'bot'] as $actor_class) {
            $bucket = $model['global'][$actor_class] ?? [];
            $sample_count = (int)($bucket['sample_count'] ?? 0);
            foreach ((array)($bucket['feature_hits'] ?? []) as $feature_key => $hit_count) {
                $insert_rows[] = [
                    'scope' => 'global',
                    'actor_class' => $actor_class,
                    'profile_hash' => 'global',
                    'profile_key' => '',
                    'feature_key' => (string)$feature_key,
                    'sample_count' => $sample_count,
                    'hit_count' => (int)$hit_count,
                    'updated_time' => $updated_time,
                ];
            }
        }

        foreach ((array)($model['profiles'] ?? []) as $profile_key => $actors) {
            $profile_key = (string)$profile_key;
            $profile_hash = md5($profile_key);
            foreach ((array)$actors as $actor_class => $bucket) {
                $sample_count = (int)($bucket['sample_count'] ?? 0);
                foreach ((array)($bucket['feature_hits'] ?? []) as $feature_key => $hit_count) {
                    $insert_rows[] = [
                        'scope' => 'profile',
                        'actor_class' => (string)$actor_class,
                        'profile_hash' => $profile_hash,
                        'profile_key' => $profile_key,
                        'feature_key' => (string)$feature_key,
                        'sample_count' => $sample_count,
                        'hit_count' => (int)$hit_count,
                        'updated_time' => $updated_time,
                    ];
                }
            }
        }

        if (!empty($insert_rows)) {
            $this->db->sql_multi_insert($table, $insert_rows);
        }
    }

    private function get_member_session_max_seconds()
    {
        $hours = (int)($this->config['bastien59_stats_member_session_max_hours'] ?? 24);
        $hours = max(1, min(168, $hours));
        return $hours * 3600;
    }

    private function build_session_bucket_sql_expr($session_id_col, $user_id_col, $visit_time_col, $member_session_max_seconds)
    {
        $max_seconds = max(3600, (int)$member_session_max_seconds);
        return 'CASE WHEN ' . $user_id_col . ' > 1
                THEN CONCAT(' . $session_id_col . ', \':\', FLOOR(' . $visit_time_col . ' / ' . $max_seconds . '))
                ELSE ' . $session_id_col . '
            END';
    }

    private function probe_columns($columns_sql)
    {
        $sql = 'SELECT ' . $columns_sql . '
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        return !$has_error;
    }
}
