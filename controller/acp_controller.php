<?php
/**
 * Stats Extension - ACP Controller
 *
 * @package bastien59960/stats
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\controller;

class acp_controller
{
    protected $db;
    protected $template;
    protected $request;
    protected $user;
    protected $config;
    protected $table_prefix;
    protected $has_ajax_telemetry_columns = null;
    protected $has_ajax_advanced_columns = null;
    protected $has_cursor_columns = null;
    protected $has_reactions_probe_columns = null;
    protected $has_visitor_cookie_column = null;
    protected $has_visitor_cookie_debug_columns = null;
    protected $has_behavior_learning_tables = null;

    public function __construct($db, $template, $request, $user, $config, $table_prefix)
    {
        $this->db = $db;
        $this->template = $template;
        $this->request = $request;
        $this->user = $user;
        $this->config = $config;
        $this->table_prefix = $table_prefix;
    }

    public function display($u_action)
    {
        $this->user->add_lang_ext('bastien59960/stats', 'acp/info_acp_stats');

        // Gestion du formulaire (effacer les stats)
        if ($this->request->is_set_post('submit_clear')) {
            if (!check_form_key('bastien59_stats')) {
                trigger_error('FORM_INVALID');
            }

            $sql = 'DELETE FROM ' . $this->table_prefix . 'bastien59_stats';
            $this->db->sql_query($sql);

            trigger_error($this->user->lang('STATS_CLEARED') . adm_back_link($u_action));
        }

        add_form_key('bastien59_stats');

        // Période d'affichage (24h par défaut, configurable)
        $hours = $this->request->variable('hours', 24);
        $start_time = time() - ($hours * 3600);

        // Filtre bots
        $show_bots = $this->request->variable('show_bots', 1);
        $bot_filter = ($show_bots) ? '' : ' AND is_bot = 0';

        // Limite d'affichage (500 par défaut)
        $display_limit = $this->request->variable('limit', 500);
        if ($display_limit < 10) $display_limit = 10;
        if ($display_limit > 5000) $display_limit = 5000;

        // ================================================================
        // 1. STATISTIQUES GLOBALES
        // ================================================================
        $this->assign_global_stats($start_time, $bot_filter);

        // ================================================================
        // 2. LISTE DES SESSIONS (visiteurs uniques)
        // ================================================================
        $this->assign_sessions($start_time, $bot_filter, $display_limit);

        // ================================================================
        // 3. GRAPHIQUES / STATISTIQUES AGRÉGÉES
        // ================================================================
        // OS, appareils et résolutions : toujours humains uniquement (les bots polluent ces métriques)
        $this->assign_stats_block('user_os', 'STATS_OS', $start_time, ' AND is_bot = 0', 10);
        $this->assign_stats_block('user_device', 'STATS_DEVICE', $start_time, ' AND is_bot = 0', 5);
        $this->assign_stats_block('screen_res', 'STATS_RES', $start_time, ' AND is_bot = 0', 10);
        $this->assign_stats_block('referer_type', 'STATS_REFERER', $start_time, $bot_filter, 15);

        // Sources de trafic séparées humains/bots
        $this->assign_stats_block('referer_type', 'STATS_REFERER_HUMANS', $start_time, ' AND is_bot = 0', 30);
        $this->assign_stats_block('referer_type', 'STATS_REFERER_BOTS', $start_time, ' AND is_bot = 1', 30);

        // Referers complets cliquables (externes uniquement)
        $this->assign_full_referers($start_time, $display_limit);

        // Top pages visitées
        $this->assign_top_pages($start_time, $bot_filter, $display_limit);

        // Statistiques par pays (pour la carte)
        $this->assign_country_stats($start_time, $bot_filter);

        // Variables template
        $this->template->assign_vars([
            'U_ACTION'        => $u_action,
            'FILTER_HOURS'    => $hours,
            'SHOW_BOTS'       => $show_bots,
            'DISPLAY_LIMIT'   => $display_limit,
        ]);
    }

    /**
     * Onglet ACP "Comportements": apprentissage et comparaison profils.
     */
    public function display_behavior($u_action)
    {
        $this->user->add_lang_ext('bastien59960/stats', 'acp/info_acp_stats');

        $hours = max(1, min(720, (int)$this->request->variable('hours', 24)));
        $profile_limit = max(20, min(1000, (int)$this->request->variable('profile_limit', 100)));
        $min_samples = max(5, min(5000, (int)$this->request->variable('min_samples', (int)($this->config['bastien59_stats_learning_min_samples'] ?? 25))));
        $start_time = time() - ($hours * 3600);
        $learning_enabled = !empty($this->config['bastien59_stats_learning_enabled']);

        $this->template->assign_vars([
            'U_ACTION' => $u_action,
            'FILTER_HOURS' => $hours,
            'PROFILE_LIMIT' => $profile_limit,
            'MIN_SAMPLES' => $min_samples,
            'LEARNING_ENABLED' => $learning_enabled ? 1 : 0,
            'HAS_LEARNING_TABLES' => $this->has_behavior_learning_tables() ? 1 : 0,
            'BEHAVIOR_GROUP_SESSIONS_TOTAL_PURE' => 0,
            'BEHAVIOR_GROUP_SESSIONS_TOTAL_ALL' => 0,
            'BEHAVIOR_GROUP_SESSIONS_MIXED' => 0,
        ]);

        if (!$this->has_behavior_learning_tables()) {
            return;
        }

        $this->assign_behavior_profiles($min_samples, $profile_limit);
        $this->assign_behavior_group_comparison($start_time);
        $this->assign_behavior_telemetry_focus_comparison($start_time);
        $this->assign_behavior_cursor_capture_health($start_time);
        $this->assign_behavior_outlier_signals($start_time);
        $this->assign_recent_behavior_cases($start_time, 200);
    }

    /**
     * Statistiques globales (compteurs)
     */
    private function assign_global_stats($start_time, $bot_filter)
    {
        // Pages vues HUMAINS uniquement
        $sql = 'SELECT COUNT(*) as total FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time > ' . $start_time . ' AND is_bot = 0';
        $result = $this->db->sql_query($sql);
        $total_pageviews = (int)$this->db->sql_fetchfield('total');
        $this->db->sql_freeresult($result);

        // Visiteurs uniques HUMAINS uniquement (par IP)
        $sql = 'SELECT COUNT(DISTINCT user_ip) as total FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time > ' . $start_time . ' AND is_bot = 0';
        $result = $this->db->sql_query($sql);
        $unique_visitors = (int)$this->db->sql_fetchfield('total');
        $this->db->sql_freeresult($result);

        // Bots détectés (sessions uniques)
        $sql = 'SELECT COUNT(DISTINCT user_ip) as total FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time > ' . $start_time . ' AND is_bot = 1';
        $result = $this->db->sql_query($sql);
        $total_bots = (int)$this->db->sql_fetchfield('total');
        $this->db->sql_freeresult($result);

        // Pages vues par les bots
        $sql = 'SELECT COUNT(*) as total FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time > ' . $start_time . ' AND is_bot = 1';
        $result = $this->db->sql_query($sql);
        $bot_pageviews = (int)$this->db->sql_fetchfield('total');
        $this->db->sql_freeresult($result);

        // Pourcentage bots (basé sur les pages vues totales)
        $total_all_pageviews = $total_pageviews + $bot_pageviews;
        $bot_percent = ($total_all_pageviews > 0) ? round(($bot_pageviews / $total_all_pageviews) * 100, 1) : 0;

        // Durée moyenne de session pour les humains
        $sql = 'SELECT AVG(duration) as avg_duration FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time > ' . $start_time . ' AND is_bot = 0 AND duration > 0';
        $result = $this->db->sql_query($sql);
        $avg_duration_humans = (int)$this->db->sql_fetchfield('avg_duration');
        $this->db->sql_freeresult($result);

        // Durée moyenne de session pour les bots
        $sql = 'SELECT AVG(duration) as avg_duration FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time > ' . $start_time . ' AND is_bot = 1 AND duration > 0';
        $result = $this->db->sql_query($sql);
        $avg_duration_bots = (int)$this->db->sql_fetchfield('avg_duration');
        $this->db->sql_freeresult($result);

        $this->template->assign_vars([
            'TOTAL_PAGEVIEWS'       => number_format($total_pageviews, 0, ',', ' '),
            'UNIQUE_VISITORS'       => number_format($unique_visitors, 0, ',', ' '),
            'TOTAL_BOTS'            => number_format($total_bots, 0, ',', ' '),
            'BOT_PAGEVIEWS'         => number_format($bot_pageviews, 0, ',', ' '),
            'BOT_PERCENT'           => $bot_percent,
            'AVG_DURATION_HUMANS'   => $this->format_duration($avg_duration_humans),
            'AVG_DURATION_BOTS'     => $this->format_duration($avg_duration_bots),
            'L_STATS_BOT_PAGEVIEWS_LABEL' => sprintf($this->user->lang('STATS_BOT_PAGEVIEWS'), $bot_percent),
        ]);
    }

    /**
     * Liste des sessions avec pages visitées
     * Optimisé : 2 requêtes au lieu de N+1 (plus de boucle de requêtes par session)
     */
    private function assign_sessions($start_time, $bot_filter, $limit = 5000)
    {
        $has_ajax_telemetry_columns = $this->has_ajax_telemetry_columns();
        $has_cursor_columns = $this->has_cursor_columns();
        $interaction_predicates = [];
        if ($has_ajax_telemetry_columns) {
            $interaction_predicates[] = 'ajax_seen_time > 0';
            $interaction_predicates[] = 'scroll_down_ajax = 1';
        }
        if ($has_cursor_columns) {
            $interaction_predicates[] = 'cursor_track_points > 0';
            $interaction_predicates[] = 'cursor_click_count > 0';
        }
        $interaction_condition = !empty($interaction_predicates)
            ? '(' . implode(' OR ', $interaction_predicates) . ')'
            : '0 = 1';

        // Requête 1 : sessions uniques triées par dernière activité.
        // Sélectionne une seule ligne "landing" par session_id (fallback robuste si plusieurs
        // is_first_visit=1 existent pour la même clé session).
        $sql = 'SELECT s.*, sess.page_count, sess.last_visit_time, sess.has_interaction, sess.last_interaction_time,
                       sess.session_country_code, sess.session_country_name, sess.session_hostname
                FROM ' . $this->table_prefix . 'bastien59_stats s
                INNER JOIN (
                    SELECT session_id,
                           COUNT(*) AS page_count,
                           MAX(visit_time) AS last_visit_time,
                           COALESCE(
                               MIN(CASE
                                       WHEN is_first_visit = 1
                                            AND page_url NOT LIKE \'%search_id=active_topics%\'
                                           THEN log_id
                                       ELSE NULL
                                   END),
                               MIN(CASE
                                       WHEN page_url NOT LIKE \'%search_id=active_topics%\'
                                           THEN log_id
                                       ELSE NULL
                                   END),
                               MIN(CASE WHEN is_first_visit = 1 THEN log_id ELSE NULL END),
                               MIN(log_id)
                           ) AS landing_log_id,
                           COALESCE(
                               NULLIF(MAX(CASE WHEN is_first_visit = 1 AND country_code <> \'\' THEN UPPER(country_code) ELSE \'\' END), \'\'),
                               NULLIF(MAX(CASE WHEN country_code <> \'\' THEN UPPER(country_code) ELSE \'\' END), \'\')
                           ) AS session_country_code,
                           COALESCE(
                               NULLIF(MAX(CASE WHEN is_first_visit = 1 AND country_name <> \'\' THEN country_name ELSE \'\' END), \'\'),
                               NULLIF(MAX(CASE WHEN country_name <> \'\' THEN country_name ELSE \'\' END), \'\')
                           ) AS session_country_name,
                           COALESCE(
                               NULLIF(MAX(CASE WHEN is_first_visit = 1 AND hostname <> \'\' THEN hostname ELSE \'\' END), \'\'),
                               NULLIF(MAX(CASE WHEN hostname <> \'\' THEN hostname ELSE \'\' END), \'\')
                           ) AS session_hostname,
                           MAX(CASE WHEN ' . $interaction_condition . ' THEN 1 ELSE 0 END) AS has_interaction,
                           MAX(CASE WHEN ' . $interaction_condition . ' THEN visit_time ELSE 0 END) AS last_interaction_time
                    FROM ' . $this->table_prefix . 'bastien59_stats
                    GROUP BY session_id
                ) sess ON sess.session_id = s.session_id
                       AND s.log_id = sess.landing_log_id
                WHERE sess.last_visit_time > ' . (int)$start_time . $bot_filter . '
                ORDER BY sess.has_interaction DESC,
                         CASE
                             WHEN sess.has_interaction = 1 THEN sess.last_interaction_time
                             ELSE sess.last_visit_time
                         END DESC,
                         sess.last_visit_time DESC';

        $result = $this->db->sql_query_limit($sql, $limit);

        $sessions = [];
        $session_ids = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $sessions[] = $row;
            $session_ids[] = '\'' . $this->db->sql_escape($row['session_id']) . '\'';
        }
        $this->db->sql_freeresult($result);

        if (empty($sessions)) {
            return;
        }

        // Requête 2 : Récupérer TOUTES les pages de TOUTES les sessions en une requête
        $pages_by_session = [];
        $has_cookie_column = $this->has_visitor_cookie_column();
        $has_cookie_debug_columns = $this->has_visitor_cookie_debug_columns();
        $has_reactions_probe_columns = $this->has_reactions_probe_columns();
        $extra_ajax_columns = $this->has_ajax_telemetry_columns()
            ? ', screen_res_ajax, scroll_down_ajax, ajax_seen_time'
            : '';
        $extra_cookie_columns = $has_cookie_column
            ? ', visitor_cookie_hash'
            : '';
        $extra_cookie_debug_columns = $has_cookie_debug_columns
            ? ', visitor_cookie_preexisting, visitor_cookie_ajax_state, visitor_cookie_ajax_hash'
            : '';
        $extra_cursor_columns = $has_cursor_columns
            ? ', cursor_track_points, cursor_track_duration_ms, cursor_track_path, cursor_click_points,
               cursor_device_class, cursor_viewport, cursor_total_distance, cursor_avg_speed,
               cursor_max_speed, cursor_direction_changes, cursor_linearity, cursor_click_count'
            : '';
        $sql_pages = 'SELECT log_id, session_id, page_url, page_title, visit_time, duration, referer, screen_res'
                    . $extra_ajax_columns
                    . $extra_cookie_columns
                    . $extra_cookie_debug_columns
                    . $extra_cursor_columns . '
                      FROM ' . $this->table_prefix . 'bastien59_stats
                      WHERE session_id IN (' . implode(',', $session_ids) . ')
                      ORDER BY visit_time ASC';
        $result_pages = $this->db->sql_query($sql_pages);
        while ($page = $this->db->sql_fetchrow($result_pages)) {
            $pages_by_session[$page['session_id']][] = $page;
        }
        $this->db->sql_freeresult($result_pages);

        // Prépare une vue globale multi-IP par hash cookie visiteur (fenêtre 24h).
        $session_cookie_hashes = [];
        foreach ($sessions as $row) {
            $hash = strtolower(trim((string)($row['visitor_cookie_hash'] ?? '')));
            if ($this->is_valid_visitor_cookie_hash($hash)) {
                $session_cookie_hashes[(string)$row['session_id']] = $hash;
            }
        }
        foreach ($pages_by_session as $sid => $rows) {
            if (isset($session_cookie_hashes[(string)$sid])) {
                continue;
            }
            foreach ($rows as $page_row) {
                $hash = strtolower(trim((string)($page_row['visitor_cookie_hash'] ?? '')));
                if ($this->is_valid_visitor_cookie_hash($hash)) {
                    $session_cookie_hashes[(string)$sid] = $hash;
                    break;
                }
            }
        }
        $cookie_overview = $this->get_visitor_cookie_cross_ip_overview(array_values(array_unique(array_values($session_cookie_hashes))), 86400);

        // Assigner les sessions au template
        foreach ($sessions as $row) {
            $session_id = $row['session_id'];
            $bot_source = $row['bot_source'] ?? '';
            $is_phpbb_bot = ($bot_source === 'phpbb') ? 1 : 0;

            $country_code_value = strtoupper(trim((string)($row['session_country_code'] ?? '')));
            if ($country_code_value === '') {
                $country_code_value = strtoupper(trim((string)($row['country_code'] ?? '')));
            }
            $country_name_value = trim((string)($row['session_country_name'] ?? ''));
            if ($country_name_value === '') {
                $country_name_value = trim((string)($row['country_name'] ?? ''));
            }

            // Formatage pays avec drapeau emoji
            $country_display = '';
            if ($country_code_value !== '') {
                $flag = $this->country_code_to_flag($country_code_value);
                $country_display = $flag . ' ' . htmlspecialchars($country_name_value !== '' ? $country_name_value : $country_code_value, ENT_COMPAT, 'UTF-8');
            }

            // Hostname depuis le cache (pas de DNS lookup temps réel)
            $hostname = trim((string)($row['session_hostname'] ?? ''));
            if ($hostname === '') {
                $hostname = trim((string)($row['hostname'] ?? ''));
            }

            // Forward DNS : résoudre le hostname vers IP(s) pour vérification
            $forward_dns_status = '';
            $forward_dns_ips = '';
            if (!empty($hostname) && $hostname !== '-') {
                $ip = $row['user_ip'];
                $forward_ips = @gethostbynamel($hostname);
                $ipv6_match = false;

                // Vérifier aussi les enregistrements AAAA pour IPv6
                if (strpos($ip, ':') !== false) {
                    $aaaa_records = @dns_get_record($hostname, DNS_AAAA);
                    $aaaa_ips = [];
                    if (!empty($aaaa_records)) {
                        foreach ($aaaa_records as $rec) {
                            if (isset($rec['ipv6'])) {
                                $aaaa_ips[] = $rec['ipv6'];
                                if ($rec['ipv6'] === $ip) {
                                    $ipv6_match = true;
                                }
                            }
                        }
                    }
                    if (!empty($aaaa_ips)) {
                        $forward_dns_ips = implode(', ', $aaaa_ips);
                        $forward_dns_status = $ipv6_match
                            ? '<span style="color:#27ae60;font-weight:bold;">✓ Correspond</span>'
                            : '<span style="color:#c0392b;font-weight:bold;">✗ Ne correspond pas</span>';
                    } else {
                        $forward_dns_status = '<span style="color:#999;">Indisponible (pas d\'enregistrement AAAA)</span>';
                    }
                } else {
                    // IPv4
                    if ($forward_ips !== false && !empty($forward_ips)) {
                        $forward_dns_ips = implode(', ', $forward_ips);
                        $forward_dns_status = in_array($ip, $forward_ips)
                            ? '<span style="color:#27ae60;font-weight:bold;">✓ Correspond</span>'
                            : '<span style="color:#c0392b;font-weight:bold;">✗ Ne correspond pas</span>';
                    } else {
                        $forward_dns_status = '<span style="color:#999;">Indisponible (résolution échouée)</span>';
                    }
                }
            } elseif (empty($hostname) || $hostname === '-') {
                $forward_dns_status = '<span style="color:#999;">N/A (pas de Reverse DNS)</span>';
            }

            $pages = $pages_by_session[$session_id] ?? [];
            $landing_log_id = (int)($row['log_id'] ?? 0);
            if ($landing_log_id > 0 && count($pages) > 1) {
                $landing_page = null;
                foreach ($pages as $page_row) {
                    if ($landing_page === null && (int)($page_row['log_id'] ?? 0) === $landing_log_id) {
                        $landing_page = $page_row;
                    }
                }
                if ($landing_page !== null) {
                    $landing_visit_time = (int)($landing_page['visit_time'] ?? 0);
                    $other_pages = [];
                    foreach ($pages as $page_row) {
                        if ((int)($page_row['log_id'] ?? 0) === $landing_log_id) {
                            continue;
                        }
                        $page_visit_time = (int)($page_row['visit_time'] ?? 0);
                        $page_url_lc = strtolower((string)($page_row['page_url'] ?? ''));
                        if (
                            $landing_visit_time > 0
                            && $page_visit_time > 0
                            && $page_visit_time < $landing_visit_time
                            && strpos($page_url_lc, 'search_id=active_topics') !== false
                        ) {
                            continue;
                        }
                        $other_pages[] = $page_row;
                    }
                    $pages = array_merge([$landing_page], $other_pages);
                }
            }
            $landing_title = trim((string)($row['page_title'] ?? ''));
            $landing_url = trim((string)($row['page_url'] ?? ''));
            if ($landing_title === '') {
                $landing_title = '-';
            }
            if ($landing_url === '') {
                $landing_url = '-';
            }

            // Agrégation session complète:
            // - scroll = vrai si au moins une page de la session a un signal
            // - résolution cookie = dernière non vide vue dans la session
            // - résolution ajax = dernière non vide par ajax_seen_time
            $scroll_done = !empty($row['scroll_down_ajax']) ? 1 : 0;
            $res_cookie = trim((string)($row['screen_res'] ?? ''));
            $res_ajax = trim((string)($row['screen_res_ajax'] ?? ''));
            $ajax_time = (int)($row['ajax_seen_time'] ?? 0);
            $visitor_cookie_hash = strtolower(trim((string)($row['visitor_cookie_hash'] ?? '')));
            $visitor_cookie_preexisting = (int)($row['visitor_cookie_preexisting'] ?? 0);
            $visitor_cookie_ajax_state = (int)($row['visitor_cookie_ajax_state'] ?? 0); // 0=none, 1=ok, 2=absent, 3=invalid, 4=mismatch
            $visitor_cookie_ajax_hash = strtolower(trim((string)($row['visitor_cookie_ajax_hash'] ?? '')));
            $visitor_cookie_ajax_state_time = max((int)($row['ajax_seen_time'] ?? 0), (int)($row['visit_time'] ?? 0));
            $visitor_cookie_ajax_hash_time = $visitor_cookie_ajax_state_time;
            if (!$this->is_valid_visitor_cookie_hash($visitor_cookie_ajax_hash)) {
                $visitor_cookie_ajax_hash = '';
                $visitor_cookie_ajax_hash_time = 0;
            }

            foreach ($pages as $page_row) {
                if (!$scroll_done && !empty($page_row['scroll_down_ajax'])) {
                    $scroll_done = 1;
                }

                $cookie_candidate = trim((string)($page_row['screen_res'] ?? ''));
                if ($cookie_candidate !== '') {
                    $res_cookie = $cookie_candidate;
                }

                $ajax_candidate = trim((string)($page_row['screen_res_ajax'] ?? ''));
                $ajax_candidate_time = (int)($page_row['ajax_seen_time'] ?? 0);
                if ($ajax_candidate !== '' && $ajax_candidate_time >= $ajax_time) {
                    $res_ajax = $ajax_candidate;
                    $ajax_time = $ajax_candidate_time;
                }

                $cookie_hash_candidate = strtolower(trim((string)($page_row['visitor_cookie_hash'] ?? '')));
                if ($this->is_valid_visitor_cookie_hash($cookie_hash_candidate)) {
                    $visitor_cookie_hash = $cookie_hash_candidate;
                }

                if ($has_cookie_debug_columns) {
                    if (!empty($page_row['visitor_cookie_preexisting'])) {
                        $visitor_cookie_preexisting = 1;
                    }
                    $ajax_state_candidate = (int)($page_row['visitor_cookie_ajax_state'] ?? 0);
                    $ajax_state_candidate_time = max((int)($page_row['ajax_seen_time'] ?? 0), (int)($page_row['visit_time'] ?? 0));
                    if ($ajax_state_candidate >= 0 && $ajax_state_candidate <= 4) {
                        // Conserver l'état AJAX le plus récent utile.
                        // Un état 0 récent ne doit pas écraser un état non-zéro plus ancien.
                        if (
                            $ajax_state_candidate > 0
                            && $ajax_state_candidate_time >= $visitor_cookie_ajax_state_time
                        ) {
                            $visitor_cookie_ajax_state = $ajax_state_candidate;
                            $visitor_cookie_ajax_state_time = $ajax_state_candidate_time;
                        } elseif (
                            $ajax_state_candidate === 0
                            && $visitor_cookie_ajax_state === 0
                            && $ajax_state_candidate_time >= $visitor_cookie_ajax_state_time
                        ) {
                            $visitor_cookie_ajax_state = 0;
                            $visitor_cookie_ajax_state_time = $ajax_state_candidate_time;
                        }
                    }
                    $ajax_hash_candidate = strtolower(trim((string)($page_row['visitor_cookie_ajax_hash'] ?? '')));
                    if (
                        $this->is_valid_visitor_cookie_hash($ajax_hash_candidate)
                        && $ajax_state_candidate_time >= $visitor_cookie_ajax_hash_time
                    ) {
                        $visitor_cookie_ajax_hash = $ajax_hash_candidate;
                        $visitor_cookie_ajax_hash_time = $ajax_state_candidate_time;
                    }
                }

            }

            if (isset($session_cookie_hashes[(string)$session_id]) && $this->is_valid_visitor_cookie_hash($session_cookie_hashes[(string)$session_id])) {
                $visitor_cookie_hash = $session_cookie_hashes[(string)$session_id];
            }

            $res_cookie_px = $this->format_resolution_px($res_cookie);
            $res_ajax_px = $this->format_resolution_px($res_ajax);
            $res_display = ($res_ajax !== '') ? $res_ajax_px : (($res_cookie !== '') ? $res_cookie_px : '-');
            $res_both_missing = ($res_cookie === '' && $res_ajax === '');
            $res_source_label = $this->user->lang('STATS_RES_SOURCE_UNKNOWN');
            if ($res_ajax !== '') {
                $res_source_label = $this->user->lang('STATS_RES_SOURCE_AJAX');
            } elseif ($res_cookie !== '') {
                $res_source_label = $this->user->lang('STATS_RES_SOURCE_COOKIE');
            }

            $res_compare_label = $this->user->lang('STATS_RES_COMPARE_NONE');
            $res_compare_class = 'res-compare-na';
            if ($res_cookie !== '' && $res_ajax !== '') {
                if ($res_cookie === $res_ajax) {
                    $res_compare_label = $this->user->lang('STATS_RES_COMPARE_MATCH');
                    $res_compare_class = 'res-compare-ok';
                } else {
                    $res_compare_label = $this->user->lang('STATS_RES_COMPARE_MISMATCH');
                    $res_compare_class = 'res-compare-bad';
                }
            } elseif ($res_cookie !== '') {
                $res_compare_label = $this->user->lang('STATS_RES_COMPARE_PARTIAL_COOKIE_ONLY');
                $res_compare_class = 'res-compare-mid';
            } elseif ($res_ajax !== '') {
                $res_compare_label = $this->user->lang('STATS_RES_COMPARE_PARTIAL_AJAX_ONLY');
                $res_compare_class = 'res-compare-mid';
            }

            $visitor_cookie_present = $has_cookie_column && $this->is_valid_visitor_cookie_hash($visitor_cookie_hash);
            $cookie_status_label = $visitor_cookie_present
                ? $this->user->lang('STATS_VISITOR_COOKIE_STATUS_SET')
                : $this->user->lang('STATS_VISITOR_COOKIE_STATUS_MISSING');
            $cookie_status_class = $visitor_cookie_present ? 'diag-cookie-ok' : 'diag-cookie-bad';

            if (!$has_cookie_debug_columns) {
                $cookie_preexisting_label = $this->user->lang('STATS_VISITOR_COOKIE_PREEXISTING_UNKNOWN');
                $cookie_preexisting_class = 'diag-cookie-na';
            } elseif (!$visitor_cookie_present) {
                $cookie_preexisting_label = '-';
                $cookie_preexisting_class = 'diag-cookie-na';
            } elseif ($visitor_cookie_preexisting === 1) {
                $cookie_preexisting_label = $this->user->lang('STATS_VISITOR_COOKIE_STATUS_PREEXISTING_YES');
                $cookie_preexisting_class = 'diag-cookie-mid';
            } else {
                $cookie_preexisting_label = $this->user->lang('STATS_VISITOR_COOKIE_STATUS_PREEXISTING_NO');
                $cookie_preexisting_class = 'diag-cookie-ok';
            }

            $cookie_ajax_fail = false;
            $cookie_ajax_mismatch = false;
            if (!$has_cookie_debug_columns) {
                $cookie_ajax_label = $this->user->lang('STATS_VISITOR_COOKIE_AJAX_UNAVAILABLE');
                $cookie_ajax_class = 'diag-cookie-na';
            } elseif ($visitor_cookie_ajax_state === 0) {
                $cookie_ajax_label = $this->user->lang('STATS_VISITOR_COOKIE_AJAX_NONE_HINT');
                $cookie_ajax_class = 'diag-cookie-na';
            } elseif ($visitor_cookie_ajax_state === 1) {
                if ($visitor_cookie_present && $this->is_valid_visitor_cookie_hash($visitor_cookie_ajax_hash) && !hash_equals($visitor_cookie_hash, $visitor_cookie_ajax_hash)) {
                    $cookie_ajax_mismatch = true;
                    $cookie_ajax_label = $this->user->lang('STATS_VISITOR_COOKIE_AJAX_MISMATCH');
                    $cookie_ajax_class = 'diag-cookie-bad';
                } else {
                    $cookie_ajax_label = $this->user->lang('STATS_VISITOR_COOKIE_AJAX_OK');
                    $cookie_ajax_class = 'diag-cookie-ok';
                }
            } elseif ($visitor_cookie_ajax_state === 2) {
                $cookie_ajax_fail = true;
                $cookie_ajax_label = $this->user->lang('STATS_VISITOR_COOKIE_AJAX_ABSENT');
                $cookie_ajax_class = 'diag-cookie-bad';
            } elseif ($visitor_cookie_ajax_state === 3) {
                $cookie_ajax_fail = true;
                $cookie_ajax_label = $this->user->lang('STATS_VISITOR_COOKIE_AJAX_INVALID');
                $cookie_ajax_class = 'diag-cookie-bad';
            } elseif ($visitor_cookie_ajax_state === 4) {
                $cookie_ajax_mismatch = true;
                $cookie_ajax_label = $this->user->lang('STATS_VISITOR_COOKIE_AJAX_MISMATCH');
                $cookie_ajax_class = 'diag-cookie-bad';
            } else {
                $cookie_ajax_fail = true;
                $cookie_ajax_label = $this->user->lang('STATS_VISITOR_COOKIE_AJAX_FAIL');
                $cookie_ajax_class = 'diag-cookie-bad';
            }

            $cookie_cross_label = $this->user->lang('STATS_VISITOR_COOKIE_CROSSIP_NONE');
            $cookie_cross_class = 'diag-cookie-ok';
            $cookie_other_ips = '';
            $cookie_other_ips_count = 0;
            if ($visitor_cookie_present && isset($cookie_overview[$visitor_cookie_hash])) {
                $ip_items = [];
                foreach ($cookie_overview[$visitor_cookie_hash]['ips'] as $seen_ip => $seen_time) {
                    if ((string)$seen_ip === (string)$row['user_ip']) {
                        continue;
                    }
                    $ip_items[] = $seen_ip . ' (' . $this->user->format_date((int)$seen_time, 'd M H:i') . ')';
                }
                $cookie_other_ips_count = count($ip_items);
                if ($cookie_other_ips_count > 0) {
                    $preview = implode(', ', array_slice($ip_items, 0, 3));
                    $cookie_other_ips = implode(', ', $ip_items);
                    $cookie_cross_label = sprintf($this->user->lang('STATS_VISITOR_COOKIE_CROSSIP_FOUND'), $cookie_other_ips_count, $preview);
                    $cookie_cross_class = ($cookie_other_ips_count >= 2) ? 'diag-cookie-bad' : 'diag-cookie-mid';
                }
            } elseif (!$visitor_cookie_present) {
                $cookie_cross_label = '-';
                $cookie_cross_class = 'diag-cookie-na';
            }

            $cookie_risk_label = $this->user->lang('STATS_VISITOR_COOKIE_RISK_LOW');
            $cookie_risk_class = 'diag-cookie-ok';
            if ($cookie_ajax_fail || $cookie_ajax_mismatch) {
                $cookie_risk_label = $this->user->lang('STATS_VISITOR_COOKIE_RISK_MEDIUM');
                $cookie_risk_class = 'diag-cookie-mid';
            }
            if (($cookie_ajax_fail || $cookie_ajax_mismatch) && $cookie_other_ips_count > 0) {
                $cookie_risk_label = $this->user->lang('STATS_VISITOR_COOKIE_RISK_HIGH');
                $cookie_risk_class = 'diag-cookie-bad';
            }

            $country_code_upper = $country_code_value;
            $cookie_fail2ban_label = $this->user->lang('STATS_VISITOR_COOKIE_FAIL2BAN_NONE');
            $cookie_fail2ban_class = 'diag-cookie-na';
            $country_unknown = ($country_code_upper === '' || $country_code_upper === '-' || $country_code_upper === 'ZZ');
            if ($country_code_upper === 'FR' || $country_code_upper === 'CO') {
                $cookie_fail2ban_label = $this->user->lang('STATS_VISITOR_COOKIE_FAIL2BAN_OBSERVE');
                $cookie_fail2ban_class = 'diag-cookie-mid';
            } elseif ($country_unknown) {
                $cookie_fail2ban_label = $this->user->lang('STATS_VISITOR_COOKIE_FAIL2BAN_GEO_PENDING');
                $cookie_fail2ban_class = 'diag-cookie-mid';
            } elseif ($cookie_ajax_fail || $cookie_ajax_mismatch) {
                $cookie_fail2ban_label = $this->user->lang('STATS_VISITOR_COOKIE_FAIL2BAN_ACTIVE');
                $cookie_fail2ban_class = 'diag-cookie-bad';
            }

            if (!$has_reactions_probe_columns) {
                $reactions_assets_label = $this->user->lang('STATS_REACTIONS_ASSETS_UNAVAILABLE');
                $reactions_assets_class = 'diag-cookie-na';
            } else {
                $reactions_expected = (int)($row['reactions_extension_expected'] ?? 0);
                $reactions_css_seen = (int)($row['reactions_css_seen'] ?? 0);
                $reactions_js_seen = (int)($row['reactions_js_seen'] ?? 0);
                $is_member_session = ((int)($row['user_id'] ?? 0) > 1);

                if ($reactions_expected !== 1) {
                    $reactions_assets_label = $this->user->lang('STATS_REACTIONS_ASSETS_NOT_EXPECTED');
                    $reactions_assets_class = 'diag-cookie-na';
                } elseif ($is_member_session) {
                    if ($reactions_css_seen === 1 && $reactions_js_seen === 1) {
                        $reactions_assets_label = $this->user->lang('STATS_REACTIONS_ASSETS_OK_MEMBER');
                        $reactions_assets_class = 'diag-cookie-ok';
                    } elseif ($reactions_css_seen === 1) {
                        $reactions_assets_label = $this->user->lang('STATS_REACTIONS_ASSETS_PARTIAL_MEMBER_NO_JS');
                        $reactions_assets_class = 'diag-cookie-mid';
                    } elseif ($reactions_js_seen === 1) {
                        $reactions_assets_label = $this->user->lang('STATS_REACTIONS_ASSETS_PARTIAL_MEMBER_NO_CSS');
                        $reactions_assets_class = 'diag-cookie-bad';
                    } else {
                        $reactions_assets_label = $this->user->lang('STATS_REACTIONS_ASSETS_MISSING_MEMBER');
                        $reactions_assets_class = 'diag-cookie-bad';
                    }
                } else {
                    if ($reactions_css_seen === 1) {
                        $reactions_assets_label = $this->user->lang('STATS_REACTIONS_ASSETS_OK_GUEST');
                        $reactions_assets_class = 'diag-cookie-ok';
                    } else {
                        $reactions_assets_label = $this->user->lang('STATS_REACTIONS_ASSETS_MISSING_GUEST');
                        $reactions_assets_class = 'diag-cookie-bad';
                    }
                }
            }

            $scroll_label = $scroll_done ? $this->user->lang('STATS_SCROLL_DONE') : $this->user->lang('STATS_SCROLL_NONE');
            $scroll_class = $scroll_done ? 'badge-scroll-yes' : 'badge-scroll-no';

            $cursor_modal_id = 'cursor_' . (int)($row['log_id'] ?? 0) . '_' . substr((string)$session_id, 0, 8);
            $cursor_has_data = 0;
            $cursor_graph_count = 0;
            $cursor_preview_svg = '';
            $cursor_preview_device_label = $this->user->lang('STATS_BEHAVIOR_CURSOR_DEVICE_UNKNOWN');
            $cursor_preview_viewport_label = '-';
            $cursor_page_blocks = [];
            if ($has_cursor_columns) {
                $cursor_candidates = $pages;
                if (empty($cursor_candidates)) {
                    $cursor_candidates = [$row];
                }

                foreach ($cursor_candidates as $cursor_row_src) {
                    $cursor_points = (int)($cursor_row_src['cursor_track_points'] ?? 0);

                    $cursor_row = [
                        'cursor_track_points' => $cursor_points,
                        'cursor_track_duration_ms' => (int)($cursor_row_src['cursor_track_duration_ms'] ?? 0),
                        'cursor_track_path' => (string)($cursor_row_src['cursor_track_path'] ?? ''),
                        'cursor_click_points' => (string)($cursor_row_src['cursor_click_points'] ?? ''),
                        'cursor_device_class' => (string)($cursor_row_src['cursor_device_class'] ?? ''),
                        'cursor_viewport' => (string)($cursor_row_src['cursor_viewport'] ?? ''),
                        'cursor_total_distance' => (int)($cursor_row_src['cursor_total_distance'] ?? 0),
                        'cursor_avg_speed' => (int)($cursor_row_src['cursor_avg_speed'] ?? 0),
                        'cursor_max_speed' => (int)($cursor_row_src['cursor_max_speed'] ?? 0),
                        'cursor_direction_changes' => (int)($cursor_row_src['cursor_direction_changes'] ?? 0),
                        'cursor_linearity' => (int)($cursor_row_src['cursor_linearity'] ?? 0),
                        'cursor_click_count' => (int)($cursor_row_src['cursor_click_count'] ?? 0),
                        'screen_res' => (string)($cursor_row_src['screen_res'] ?? ''),
                        'screen_res_ajax' => (string)($cursor_row_src['screen_res_ajax'] ?? ''),
                        'visit_time' => (int)($cursor_row_src['visit_time'] ?? 0),
                    ];

                    $svg_thumb = $this->build_cursor_trace_svg($cursor_row, 160, 0);
                    $svg_large = $this->build_cursor_trace_svg($cursor_row, 560, 0);
                    if ($svg_thumb === '' || $svg_large === '') {
                        $svg_thumb = $this->build_cursor_empty_svg($cursor_row, 160, 0);
                        $svg_large = $this->build_cursor_empty_svg($cursor_row, 560, 0);
                    }

                    $device_label = $this->format_cursor_device_label((string)($cursor_row['cursor_device_class'] ?? ''));
                    $summary_label = $this->format_cursor_summary($cursor_row);
                    $viewport_label = $this->format_resolution_px((string)($cursor_row['cursor_viewport'] ?? ''));
                    list($canvas_w, $canvas_h) = $this->resolve_cursor_canvas_size($cursor_row);
                    $canvas_label = $canvas_w . 'x' . $canvas_h . ' px';
                    $capture_time = (int)($cursor_row['visit_time'] ?? 0);
                    $capture_label = ($capture_time > 0)
                        ? $this->user->format_date($capture_time, 'd M Y H:i:s')
                        : '-';
                    $page_title = trim((string)($cursor_row_src['page_title'] ?? ''));
                    if ($page_title === '') {
                        $page_title = '-';
                    }
                    $page_url = trim((string)($cursor_row_src['page_url'] ?? ''));

                    $cursor_page_blocks[] = [
                        'PAGE_TITLE' => htmlspecialchars($page_title, ENT_COMPAT, 'UTF-8'),
                        'PAGE_URL' => htmlspecialchars($page_url, ENT_COMPAT, 'UTF-8'),
                        'CAPTURE_LABEL' => htmlspecialchars($capture_label, ENT_COMPAT, 'UTF-8'),
                        'DEVICE_LABEL' => htmlspecialchars($device_label, ENT_COMPAT, 'UTF-8'),
                        'VIEWPORT_LABEL' => htmlspecialchars($viewport_label, ENT_COMPAT, 'UTF-8'),
                        'CANVAS_LABEL' => htmlspecialchars($canvas_label, ENT_COMPAT, 'UTF-8'),
                        'POINTS' => max(0, (int)$cursor_row['cursor_track_points']),
                        'DURATION_MS' => max(0, (int)$cursor_row['cursor_track_duration_ms']),
                        'TOTAL_DISTANCE' => max(0, (int)$cursor_row['cursor_total_distance']),
                        'AVG_SPEED' => max(0, (int)$cursor_row['cursor_avg_speed']),
                        'MAX_SPEED' => max(0, (int)$cursor_row['cursor_max_speed']),
                        'DIRECTION_CHANGES' => max(0, (int)$cursor_row['cursor_direction_changes']),
                        'LINEARITY' => max(0, min(100, (int)$cursor_row['cursor_linearity'])),
                        'CLICK_COUNT' => max(0, (int)$cursor_row['cursor_click_count']),
                        'SVG_LARGE' => $svg_large,
                        'SUMMARY' => htmlspecialchars($summary_label, ENT_COMPAT, 'UTF-8'),
                    ];

                    // Aperçu session = dernière page capturée de la session.
                    $cursor_preview_svg = $svg_thumb;
                    $cursor_preview_device_label = $device_label;
                    $cursor_preview_viewport_label = $viewport_label;
                }
            }

            $cursor_graph_count = count($cursor_page_blocks);
            if ($cursor_graph_count > 0) {
                $cursor_has_data = 1;
            }

            $this->template->assign_block_vars('SESSIONS', [
                'SESSION_ID'        => substr($session_id, 0, 8) . '...',
                'IP'                => $row['user_ip'],
                'HOSTNAME'          => htmlspecialchars($hostname, ENT_COMPAT, 'UTF-8'),
                'FORWARD_DNS_STATUS'=> $forward_dns_status,
                'FORWARD_DNS_IPS'   => htmlspecialchars($forward_dns_ips, ENT_COMPAT, 'UTF-8'),
                'COUNTRY'           => $country_display,
                'COUNTRY_CODE'  => htmlspecialchars($country_code_value, ENT_COMPAT, 'UTF-8'),
                'OS'            => htmlspecialchars($row['user_os'], ENT_COMPAT, 'UTF-8'),
                'DEVICE'        => htmlspecialchars($row['user_device'], ENT_COMPAT, 'UTF-8'),
                'RES'           => htmlspecialchars($res_display, ENT_COMPAT, 'UTF-8'),
                'RES_COOKIE'    => htmlspecialchars($res_cookie ?: '-', ENT_COMPAT, 'UTF-8'),
                'RES_AJAX'      => htmlspecialchars($res_ajax ?: '-', ENT_COMPAT, 'UTF-8'),
                'RES_COOKIE_PX' => htmlspecialchars($res_cookie_px, ENT_COMPAT, 'UTF-8'),
                'RES_AJAX_PX'   => htmlspecialchars($res_ajax_px, ENT_COMPAT, 'UTF-8'),
                'RES_SCREEN_PX' => htmlspecialchars($res_display, ENT_COMPAT, 'UTF-8'),
                'RES_BOTH_MISSING' => $res_both_missing ? 1 : 0,
                'RES_SOURCE_LABEL' => htmlspecialchars($res_source_label, ENT_COMPAT, 'UTF-8'),
                'RES_COMPARE_LABEL' => htmlspecialchars($res_compare_label, ENT_COMPAT, 'UTF-8'),
                'RES_COMPARE_CLASS' => $res_compare_class,
                'VISITOR_COOKIE_STATUS_LABEL' => htmlspecialchars($cookie_status_label, ENT_COMPAT, 'UTF-8'),
                'VISITOR_COOKIE_STATUS_CLASS' => $cookie_status_class,
                'VISITOR_COOKIE_PREEXISTING_LABEL' => htmlspecialchars($cookie_preexisting_label, ENT_COMPAT, 'UTF-8'),
                'VISITOR_COOKIE_PREEXISTING_CLASS' => $cookie_preexisting_class,
                'VISITOR_COOKIE_AJAX_LABEL' => htmlspecialchars($cookie_ajax_label, ENT_COMPAT, 'UTF-8'),
                'VISITOR_COOKIE_AJAX_CLASS' => $cookie_ajax_class,
                'VISITOR_COOKIE_CROSSIP_LABEL' => htmlspecialchars($cookie_cross_label, ENT_COMPAT, 'UTF-8'),
                'VISITOR_COOKIE_CROSSIP_CLASS' => $cookie_cross_class,
                'VISITOR_COOKIE_CROSSIP_IPS' => htmlspecialchars($cookie_other_ips, ENT_COMPAT, 'UTF-8'),
                'VISITOR_COOKIE_RISK_LABEL' => htmlspecialchars($cookie_risk_label, ENT_COMPAT, 'UTF-8'),
                'VISITOR_COOKIE_RISK_CLASS' => $cookie_risk_class,
                'VISITOR_COOKIE_FAIL2BAN_LABEL' => htmlspecialchars($cookie_fail2ban_label, ENT_COMPAT, 'UTF-8'),
                'VISITOR_COOKIE_FAIL2BAN_CLASS' => $cookie_fail2ban_class,
                'REACTIONS_ASSETS_STATUS_LABEL' => htmlspecialchars($reactions_assets_label, ENT_COMPAT, 'UTF-8'),
                'REACTIONS_ASSETS_STATUS_CLASS' => $reactions_assets_class,
                'SCROLL_DONE'   => $scroll_done,
                'SCROLL_LABEL'  => htmlspecialchars($scroll_label, ENT_COMPAT, 'UTF-8'),
                'SCROLL_CLASS'  => $scroll_class,
                'USER_AGENT'    => htmlspecialchars($row['user_agent'], ENT_COMPAT, 'UTF-8'),
                'BOT_NAME'      => $this->extract_bot_name($row['user_agent']),
                'IS_PHPBB_BOT'  => $is_phpbb_bot,
                'USERNAME'      => ($row['user_id'] > 1) ? $this->get_username($row['user_id']) : $this->user->lang('STATS_GUEST'),
                'IS_GUEST'      => ($row['user_id'] <= 1 && !$row['is_bot']) ? 1 : 0,
                'IS_BOT'        => (int)$row['is_bot'],
                'BOT_CLASS'     => ($row['is_bot']) ? 'bot' : 'human',
                'BOT_SOURCE'    => htmlspecialchars($bot_source, ENT_COMPAT, 'UTF-8'),
                'SIGNALS'       => htmlspecialchars($row['signals'] ?? '', ENT_COMPAT, 'UTF-8'),
                'SIGNALS_DESC'  => $this->format_signals_description($row['signals'] ?? ''),
                'START_TIME'    => $this->user->format_date((int)($row['visit_time'] ?? 0)),
                'LANDING_PAGE'  => htmlspecialchars($landing_title, ENT_COMPAT, 'UTF-8'),
                'LANDING_URL'   => htmlspecialchars($landing_url, ENT_COMPAT, 'UTF-8'),
                'REFERER'       => $this->format_referer($row['referer']),
                'REFERER_TYPE'  => htmlspecialchars($row['referer_type'] ?? 'Direct', ENT_COMPAT, 'UTF-8'),
                'PAGE_COUNT'    => (int)$row['page_count'],
                'PAGES_COUNT_LABEL' => sprintf($this->user->lang('STATS_PAGES_COUNT'), (int)$row['page_count']),
                'LANDING_PAGE_INDEX' => 1,
                'CURSOR_HAS_DATA' => $cursor_has_data,
                'CURSOR_MODAL_ID' => htmlspecialchars($cursor_modal_id, ENT_COMPAT, 'UTF-8'),
                'CURSOR_GRAPH_COUNT' => $cursor_graph_count,
                'CURSOR_SVG_THUMB' => $cursor_preview_svg,
                'CURSOR_DEVICE_LABEL' => htmlspecialchars($cursor_preview_device_label, ENT_COMPAT, 'UTF-8'),
                'CURSOR_VIEWPORT_LABEL' => htmlspecialchars($cursor_preview_viewport_label, ENT_COMPAT, 'UTF-8'),
            ]);

            foreach ($cursor_page_blocks as $idx => $cursor_block) {
                $this->template->assign_block_vars('SESSIONS.CURSOR_PAGES', [
                    'PAGE_INDEX' => sprintf($this->user->lang('STATS_SESSION_CURSOR_PAGE_INDEX'), (int)$idx + 1),
                    'PAGE_TITLE' => $cursor_block['PAGE_TITLE'],
                    'PAGE_URL' => $cursor_block['PAGE_URL'],
                    'CAPTURE_LABEL' => $cursor_block['CAPTURE_LABEL'],
                    'DEVICE_LABEL' => $cursor_block['DEVICE_LABEL'],
                    'VIEWPORT_LABEL' => $cursor_block['VIEWPORT_LABEL'],
                    'CANVAS_LABEL' => $cursor_block['CANVAS_LABEL'],
                    'POINTS' => $cursor_block['POINTS'],
                    'DURATION_MS' => $cursor_block['DURATION_MS'],
                    'TOTAL_DISTANCE' => $cursor_block['TOTAL_DISTANCE'],
                    'AVG_SPEED' => $cursor_block['AVG_SPEED'],
                    'MAX_SPEED' => $cursor_block['MAX_SPEED'],
                    'DIRECTION_CHANGES' => $cursor_block['DIRECTION_CHANGES'],
                    'LINEARITY' => $cursor_block['LINEARITY'],
                    'CLICK_COUNT' => $cursor_block['CLICK_COUNT'],
                    'SVG_LARGE' => $cursor_block['SVG_LARGE'],
                    'SUMMARY' => $cursor_block['SUMMARY'],
                ]);
            }

            // Assigner les pages de la session (à partir de la 2ème)
            $first = true;
            $page_index = 1;
            foreach ($pages as $page) {
                if ($first) {
                    $first = false;
                    $page_index = 2;
                    continue; // Skip first page (already shown as landing)
                }
                $this->template->assign_block_vars('SESSIONS.PAGES', [
                    'PAGE_INDEX' => $page_index,
                    'TITLE'     => htmlspecialchars($page['page_title'], ENT_COMPAT, 'UTF-8'),
                    'URL'       => htmlspecialchars($page['page_url'], ENT_COMPAT, 'UTF-8'),
                    'TIME'      => $this->user->format_date($page['visit_time'], 'H:i:s'),
                    'DURATION'  => $this->format_duration($page['duration']),
                ]);
                $page_index++;
            }
        }
    }

    /**
     * Bloc de statistiques agrégées
     */
    private function assign_stats_block($column, $block_name, $start_time, $bot_filter, $limit = 10)
    {
        $sql = 'SELECT ' . $column . ', COUNT(*) as total
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time > ' . $start_time . $bot_filter . '
                AND ' . $column . ' <> ""
                GROUP BY ' . $column . '
                ORDER BY total DESC';

        $result = $this->db->sql_query_limit($sql, $limit);

        $rows = [];
        $max = 0;
        while ($row = $this->db->sql_fetchrow($result)) {
            if ($row['total'] > $max) $max = $row['total'];
            $rows[] = $row;
        }
        $this->db->sql_freeresult($result);

        foreach ($rows as $row) {
            $percent = ($max > 0) ? round(($row['total'] / $max) * 100) : 0;
            $this->template->assign_block_vars($block_name, [
                'NAME'    => htmlspecialchars($row[$column], ENT_COMPAT, 'UTF-8'),
                'COUNT'   => number_format($row['total'], 0, ',', ' '),
                'PERCENT' => $percent,
            ]);
        }
    }

    /**
     * Top des pages visitées
     */
    private function assign_top_pages($start_time, $bot_filter, $limit = 20)
    {
        $sql = 'SELECT page_title, page_url, COUNT(*) as visits, AVG(duration) as avg_time
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time > ' . $start_time . $bot_filter . '
                GROUP BY page_title, page_url
                ORDER BY visits DESC';

        $result = $this->db->sql_query_limit($sql, $limit);

        while ($row = $this->db->sql_fetchrow($result)) {
            $this->template->assign_block_vars('TOP_PAGES', [
                'TITLE'     => htmlspecialchars($row['page_title'], ENT_COMPAT, 'UTF-8'),
                'URL'       => htmlspecialchars($row['page_url'], ENT_COMPAT, 'UTF-8'),
                'VISITS'    => number_format($row['visits'], 0, ',', ' '),
                'AVG_TIME'  => $this->format_duration((int)$row['avg_time']),
            ]);
        }
        $this->db->sql_freeresult($result);
    }

    /**
     * Formate le referer pour affichage
     */
    private function format_referer($referer)
    {
        if (empty($referer)) {
            return '<span class="direct">' . $this->user->lang('STATS_DIRECT_ACCESS') . '</span>';
        }

        // Limiter la longueur
        $display = htmlspecialchars($referer, ENT_COMPAT, 'UTF-8');
        if (strlen($display) > 80) {
            $display = substr($display, 0, 77) . '...';
        }

        return '<a href="' . htmlspecialchars($referer, ENT_COMPAT, 'UTF-8') . '" target="_blank" rel="noopener">' . $display . '</a>';
    }

    /**
     * Récupère le nom d'utilisateur
     */
    private function get_username($user_id)
    {
        $sql = 'SELECT username FROM ' . USERS_TABLE . ' WHERE user_id = ' . (int)$user_id;
        $result = $this->db->sql_query($sql);
        $username = $this->db->sql_fetchfield('username');
        $this->db->sql_freeresult($result);

        return $username ?: sprintf($this->user->lang('STATS_USER_FALLBACK'), $user_id);
    }

    /**
     * Formate une durée en secondes
     */
    private function format_duration($seconds)
    {
        if ($seconds == 0 || $seconds === null) {
            return '-';
        }
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . 'm ' . ($seconds % 60) . 's';
        }
        return floor($seconds / 3600) . 'h ' . floor(($seconds % 3600) / 60) . 'm';
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
     * Détecte si les colonnes de métrique "assets reactions" sont disponibles.
     */
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

    /**
     * Détecte si la colonne visitor_cookie_hash (migration 1.7.0) existe.
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
     * Détecte si les colonnes debug cookie (migration 1.8.0) existent.
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
     * Retourne vrai si le hash cookie visiteur est valide.
     */
    private function is_valid_visitor_cookie_hash($hash)
    {
        return (bool)preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)$hash)));
    }

    /**
     * Construit un aperçu des IPs observées par hash cookie visiteur.
     * @return array<string,array{ips:array<string,int>}>
     */
    private function get_visitor_cookie_cross_ip_overview(array $hashes, $window_sec = 86400)
    {
        $overview = [];
        if (!$this->has_visitor_cookie_column()) {
            return $overview;
        }

        $valid_hashes = [];
        foreach ($hashes as $hash) {
            $hash = strtolower(trim((string)$hash));
            if ($this->is_valid_visitor_cookie_hash($hash)) {
                $valid_hashes[$hash] = true;
            }
        }
        if (empty($valid_hashes)) {
            return $overview;
        }

        $escaped_hashes = [];
        foreach (array_keys($valid_hashes) as $hash) {
            $escaped_hashes[] = '\'' . $this->db->sql_escape($hash) . '\'';
            $overview[$hash] = ['ips' => []];
        }

        $cutoff = time() - max(3600, (int)$window_sec);
        $sql = 'SELECT visitor_cookie_hash, user_ip, MAX(visit_time) AS last_seen
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visitor_cookie_hash IN (' . implode(',', $escaped_hashes) . ')
                AND visit_time >= ' . (int)$cutoff . '
                GROUP BY visitor_cookie_hash, user_ip
                ORDER BY last_seen DESC';
        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result)) {
            $hash = strtolower(trim((string)($row['visitor_cookie_hash'] ?? '')));
            $ip = trim((string)($row['user_ip'] ?? ''));
            if (!isset($overview[$hash]) || $ip === '') {
                continue;
            }
            if (!isset($overview[$hash]['ips'][$ip])) {
                $overview[$hash]['ips'][$ip] = (int)($row['last_seen'] ?? 0);
            }
        }
        $this->db->sql_freeresult($result);

        return $overview;
    }

    /**
     * Détecte la disponibilité des tables d'apprentissage comportemental.
     */
    private function has_behavior_learning_tables()
    {
        if ($this->has_behavior_learning_tables !== null) {
            return $this->has_behavior_learning_tables;
        }

        $sql_profile = 'SELECT profile_key FROM ' . $this->table_prefix . 'bastien59_stats_behavior_profile WHERE 1 = 0';
        $sql_seen = 'SELECT session_id FROM ' . $this->table_prefix . 'bastien59_stats_behavior_seen WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result_profile = $this->db->sql_query_limit($sql_profile, 1);
        $profile_error = (bool)$this->db->get_sql_error_triggered();
        if ($result_profile !== false) {
            $this->db->sql_freeresult($result_profile);
        }

        $result_seen = $this->db->sql_query_limit($sql_seen, 1);
        $seen_error = (bool)$this->db->get_sql_error_triggered();
        if ($result_seen !== false) {
            $this->db->sql_freeresult($result_seen);
        }
        $this->db->sql_return_on_error(false);

        $this->has_behavior_learning_tables = !$profile_error && !$seen_error;
        return $this->has_behavior_learning_tables;
    }

    private function assign_behavior_profiles($min_samples, $limit)
    {
        $sql = 'SELECT profile_key, profile_label, sample_count,
                       avg_first_scroll_ms, avg_scroll_events, avg_scroll_max_y,
                       avg_interact_score, no_interact_hits, fast_scroll_hits, jump_scroll_hits,
                       updated_time
                FROM ' . $this->table_prefix . 'bastien59_stats_behavior_profile
                WHERE sample_count >= ' . (int)$min_samples . '
                ORDER BY sample_count DESC, updated_time DESC';
        $result = $this->db->sql_query_limit($sql, $limit);

        while ($row = $this->db->sql_fetchrow($result)) {
            $samples = max(1, (int)$row['sample_count']);
            $no_interact_pct = round(((int)$row['no_interact_hits'] * 100) / $samples, 1);
            $fast_pct = round(((int)$row['fast_scroll_hits'] * 100) / $samples, 1);
            $jump_pct = round(((int)$row['jump_scroll_hits'] * 100) / $samples, 1);

            $this->template->assign_block_vars('BEHAVIOR_PROFILES', [
                'PROFILE_LABEL' => htmlspecialchars($row['profile_label'], ENT_COMPAT, 'UTF-8'),
                'PROFILE_KEY' => htmlspecialchars($row['profile_key'], ENT_COMPAT, 'UTF-8'),
                'SAMPLE_COUNT' => number_format((int)$row['sample_count'], 0, ',', ' '),
                'AVG_FIRST_SCROLL_MS' => (int)$row['avg_first_scroll_ms'],
                'AVG_SCROLL_EVENTS' => (int)$row['avg_scroll_events'],
                'AVG_SCROLL_MAX_Y' => (int)$row['avg_scroll_max_y'],
                'AVG_INTERACT_SCORE' => (int)$row['avg_interact_score'],
                'NO_INTERACT_PCT' => number_format($no_interact_pct, 1, ',', ' '),
                'FAST_SCROLL_PCT' => number_format($fast_pct, 1, ',', ' '),
                'JUMP_SCROLL_PCT' => number_format($jump_pct, 1, ',', ' '),
                'UPDATED_TIME' => $this->user->format_date((int)$row['updated_time']),
            ]);
        }
        $this->db->sql_freeresult($result);
    }

    private function assign_behavior_group_comparison($start_time)
    {
        if (!$this->has_ajax_telemetry_columns()) {
            return;
        }

        $advanced_fields = $this->has_ajax_advanced_columns()
            ? ',
                               AVG(CASE WHEN sess.ajax_first_scroll_ms > 0 THEN sess.ajax_first_scroll_ms END) AS avg_first_scroll_ms,
                               AVG(CASE WHEN sess.ajax_scroll_events > 0 THEN sess.ajax_scroll_events END) AS avg_scroll_events,
                               AVG(CASE WHEN sess.ajax_scroll_max_y > 0 THEN sess.ajax_scroll_max_y END) AS avg_scroll_max_y,
                               SUM(CASE WHEN sess.scroll_seen = 1 AND sess.ajax_interact_mask = 0 THEN 1 ELSE 0 END) AS zero_interact_scrolls'
            : ',
                               0 AS avg_first_scroll_ms,
                               0 AS avg_scroll_events,
                               0 AS avg_scroll_max_y,
                               0 AS zero_interact_scrolls';

        $metrics_sql = 'SELECT
                           sess.grp,
                           COUNT(*) AS sessions,
                           SUM(sess.ajax_seen) AS ajax_sessions,
                           SUM(sess.scroll_seen) AS scroll_sessions'
            . $advanced_fields
            . ' FROM (
                    SELECT
                        session_id,
                        CASE
                            WHEN MAX(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) = 1
                                 AND MAX(CASE WHEN user_id > 1 AND is_bot = 0 THEN 1 ELSE 0 END) = 0
                                 AND MAX(CASE WHEN user_id <= 1 AND is_bot = 0 THEN 1 ELSE 0 END) = 0 THEN \'bots\'
                            WHEN MAX(CASE WHEN user_id > 1 AND is_bot = 0 THEN 1 ELSE 0 END) = 1
                                 AND MAX(CASE WHEN user_id <= 1 AND is_bot = 0 THEN 1 ELSE 0 END) = 0
                                 AND MAX(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) = 0 THEN \'members\'
                            WHEN MAX(CASE WHEN user_id <= 1 AND is_bot = 0 THEN 1 ELSE 0 END) = 1
                                 AND MAX(CASE WHEN user_id > 1 AND is_bot = 0 THEN 1 ELSE 0 END) = 0
                                 AND MAX(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) = 0 THEN \'guests\'
                            ELSE \'mixed\'
                        END AS grp,
                        MAX(CASE WHEN ajax_seen_time > 0 THEN 1 ELSE 0 END) AS ajax_seen,
                        MAX(CASE WHEN scroll_down_ajax = 1 THEN 1 ELSE 0 END) AS scroll_seen,
                        MAX(CASE WHEN ajax_first_scroll_ms > 0 THEN ajax_first_scroll_ms ELSE 0 END) AS ajax_first_scroll_ms,
                        MAX(CASE WHEN ajax_scroll_events > 0 THEN ajax_scroll_events ELSE 0 END) AS ajax_scroll_events,
                        MAX(CASE WHEN ajax_scroll_max_y > 0 THEN ajax_scroll_max_y ELSE 0 END) AS ajax_scroll_max_y,
                        MAX(CASE WHEN ajax_interact_mask > 0 THEN ajax_interact_mask ELSE 0 END) AS ajax_interact_mask
                    FROM ' . $this->table_prefix . 'bastien59_stats
                    WHERE visit_time > ' . (int)$start_time . '
                    GROUP BY session_id
                ) AS sess
                GROUP BY sess.grp';

        $group_counts = [
            'members' => 0,
            'guests' => 0,
            'bots' => 0,
            'mixed' => 0,
        ];

        $result = $this->db->sql_query($metrics_sql);
        while ($row = $this->db->sql_fetchrow($result)) {
            $grp = (string)($row['grp'] ?? '');
            if (isset($group_counts[$grp])) {
                $group_counts[$grp] = (int)$row['sessions'];
            }

            if ($grp === 'mixed') {
                continue;
            }

            $sessions = max(1, (int)$row['sessions']);
            $scroll_sessions = max(1, (int)$row['scroll_sessions']);
            $ajax_pct = round(((int)$row['ajax_sessions'] * 100) / $sessions, 1);
            $scroll_pct = round(((int)$row['scroll_sessions'] * 100) / $sessions, 1);
            $zero_interact_pct = round(((int)$row['zero_interact_scrolls'] * 100) / $scroll_sessions, 1);

            $group_label = $grp;
            if ($grp === 'members') {
                $group_label = $this->user->lang('STATS_BEHAVIOR_GROUP_MEMBERS');
            } elseif ($grp === 'guests') {
                $group_label = $this->user->lang('STATS_BEHAVIOR_GROUP_GUESTS');
            } elseif ($grp === 'bots') {
                $group_label = $this->user->lang('STATS_BEHAVIOR_GROUP_BOTS');
            }

            $this->template->assign_block_vars('BEHAVIOR_GROUPS', [
                'GROUP_LABEL' => htmlspecialchars($group_label, ENT_COMPAT, 'UTF-8'),
                'SESSIONS' => number_format((int)$row['sessions'], 0, ',', ' '),
                'AJAX_RATE' => number_format($ajax_pct, 1, ',', ' '),
                'SCROLL_RATE' => number_format($scroll_pct, 1, ',', ' '),
                'AVG_FIRST_SCROLL_MS' => (int)$row['avg_first_scroll_ms'],
                'AVG_SCROLL_EVENTS' => number_format((float)$row['avg_scroll_events'], 1, ',', ' '),
                'AVG_SCROLL_MAX_Y' => (int)$row['avg_scroll_max_y'],
                'ZERO_INTERACT_RATE' => number_format($zero_interact_pct, 1, ',', ' '),
                'IS_BOTS' => ($grp === 'bots') ? 1 : 0,
            ]);
        }
        $this->db->sql_freeresult($result);

        $pure_total = (int)$group_counts['members'] + (int)$group_counts['guests'] + (int)$group_counts['bots'];
        $all_total = $pure_total + (int)$group_counts['mixed'];
        $this->template->assign_vars([
            'BEHAVIOR_GROUP_SESSIONS_TOTAL_PURE' => number_format($pure_total, 0, ',', ' '),
            'BEHAVIOR_GROUP_SESSIONS_TOTAL_ALL' => number_format($all_total, 0, ',', ' '),
            'BEHAVIOR_GROUP_SESSIONS_MIXED' => number_format((int)$group_counts['mixed'], 0, ',', ' '),
        ]);
    }

    /**
     * Compare la qualité de télémétrie entre membres et invités groupés par pays.
     */
    private function assign_behavior_telemetry_focus_comparison($start_time)
    {
        if (!$this->has_ajax_telemetry_columns()) {
            return;
        }

        $metrics_sql = 'SELECT grp_kind, grp_country_code, grp_country_name,
                               COUNT(*) AS sessions,
                               SUM(screen_res_cookie_seen) AS screen_res_cookie_sessions,
                               SUM(screen_res_ajax_seen) AS screen_res_ajax_sessions,
                               SUM(ajax_seen) AS ajax_sessions,
                               SUM(scroll_seen) AS scroll_sessions
                FROM (
                    SELECT
                        CASE
                            WHEN sess.has_member_human = 1 AND sess.has_bot_row = 0 AND sess.has_guest_human = 0 THEN \'members\'
                            WHEN sess.has_guest_human = 1 AND sess.has_member_human = 0 AND sess.has_bot_row = 0 AND sess.country_norm = \'\' THEN \'guests_pending_geo\'
                            WHEN sess.has_guest_human = 1 AND sess.has_member_human = 0 AND sess.has_bot_row = 0 THEN \'guests_country\'
                            ELSE \'other\'
                        END AS grp_kind,
                        CASE
                            WHEN sess.has_guest_human = 1 AND sess.has_member_human = 0 AND sess.has_bot_row = 0 THEN sess.country_norm
                            ELSE \'\'
                        END AS grp_country_code,
                        CASE
                            WHEN sess.has_guest_human = 1 AND sess.has_member_human = 0 AND sess.has_bot_row = 0 THEN sess.country_name_norm
                            ELSE \'\'
                        END AS grp_country_name,
                        sess.screen_res_cookie_seen,
                        sess.screen_res_ajax_seen,
                        sess.ajax_seen,
                        sess.scroll_seen
                    FROM (
                        SELECT
                            session_id,
                            MAX(CASE WHEN user_id > 1 AND is_bot = 0 THEN 1 ELSE 0 END) AS has_member_human,
                            MAX(CASE WHEN user_id <= 1 AND is_bot = 0 THEN 1 ELSE 0 END) AS has_guest_human,
                            MAX(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) AS has_bot_row,
                            CASE
                                WHEN MAX(CASE WHEN is_first_visit = 1 AND country_code <> \'\' THEN UPPER(country_code) ELSE \'\' END) <> \'\'
                                    THEN MAX(CASE WHEN is_first_visit = 1 AND country_code <> \'\' THEN UPPER(country_code) ELSE \'\' END)
                                ELSE MAX(CASE WHEN country_code <> \'\' THEN UPPER(country_code) ELSE \'\' END)
                            END AS country_norm,
                            CASE
                                WHEN MAX(CASE WHEN is_first_visit = 1 AND country_name <> \'\' THEN country_name ELSE \'\' END) <> \'\'
                                    THEN MAX(CASE WHEN is_first_visit = 1 AND country_name <> \'\' THEN country_name ELSE \'\' END)
                                ELSE MAX(CASE WHEN country_name <> \'\' THEN country_name ELSE \'\' END)
                            END AS country_name_norm,
                            MAX(CASE WHEN screen_res <> \'\' THEN 1 ELSE 0 END) AS screen_res_cookie_seen,
                            MAX(CASE WHEN screen_res_ajax <> \'\' THEN 1 ELSE 0 END) AS screen_res_ajax_seen,
                            MAX(CASE WHEN ajax_seen_time > 0 THEN 1 ELSE 0 END) AS ajax_seen,
                            MAX(CASE WHEN scroll_down_ajax = 1 THEN 1 ELSE 0 END) AS scroll_seen
                        FROM ' . $this->table_prefix . 'bastien59_stats
                        WHERE visit_time > ' . (int)$start_time . '
                        GROUP BY session_id
                    ) AS sess
                ) AS x
                WHERE grp_kind <> \'other\'
                GROUP BY grp_kind, grp_country_code, grp_country_name
                ORDER BY CASE
                            WHEN grp_kind = \'members\' THEN 0
                            WHEN grp_kind = \'guests_pending_geo\' THEN 1
                            ELSE 2
                         END ASC, sessions DESC';

        $result = $this->db->sql_query($metrics_sql);
        while ($row = $this->db->sql_fetchrow($result)) {
            $grp_kind = (string)($row['grp_kind'] ?? '');
            if ($grp_kind === '' || $grp_kind === 'other') {
                continue;
            }

            $sessions = max(1, (int)$row['sessions']);
            $res_cookie_pct = round(((int)$row['screen_res_cookie_sessions'] * 100) / $sessions, 1);
            $res_ajax_pct = round(((int)$row['screen_res_ajax_sessions'] * 100) / $sessions, 1);
            $ajax_pct = round(((int)$row['ajax_sessions'] * 100) / $sessions, 1);
            $scroll_pct = round(((int)$row['scroll_sessions'] * 100) / $sessions, 1);

            $group_label = $grp_kind;
            if ($grp_kind === 'members') {
                $group_label = $this->user->lang('STATS_BEHAVIOR_GROUP_MEMBERS');
            } elseif ($grp_kind === 'guests_pending_geo') {
                $group_label = $this->user->lang('STATS_BEHAVIOR_GROUP_GUESTS_PENDING_GEO');
            } elseif ($grp_kind === 'guests_country') {
                $cc = strtoupper(trim((string)($row['grp_country_code'] ?? '')));
                $cn = trim((string)($row['grp_country_name'] ?? ''));
                if ($cc === '') {
                    $country_label = $this->user->lang('STATS_BEHAVIOR_GROUP_GUESTS_COUNTRY_UNKNOWN');
                } else {
                    $country_label = trim($this->country_code_to_flag($cc) . ' ' . ($cn !== '' ? $cn : $cc));
                }
                $group_label = sprintf($this->user->lang('STATS_BEHAVIOR_GROUP_GUESTS_COUNTRY'), $country_label);
            }

            $this->template->assign_block_vars('BEHAVIOR_TELEMETRY_SEGMENTS', [
                'GROUP_LABEL' => htmlspecialchars($group_label, ENT_COMPAT, 'UTF-8'),
                'SESSIONS' => number_format((int)$row['sessions'], 0, ',', ' '),
                'RES_COOKIE_RATE' => number_format($res_cookie_pct, 1, ',', ' '),
                'RES_AJAX_RATE' => number_format($res_ajax_pct, 1, ',', ' '),
                'AJAX_RATE' => number_format($ajax_pct, 1, ',', ' '),
                'SCROLL_RATE' => number_format($scroll_pct, 1, ',', ' '),
            ]);
        }
        $this->db->sql_freeresult($result);
    }

    /**
     * Qualité de capture de trace curseur pour humains légitimes (membres + invités).
     */
    private function assign_behavior_cursor_capture_health($start_time)
    {
        if (!$this->has_cursor_columns()) {
            return;
        }

        $guest_actor_expr = $this->has_visitor_cookie_column()
            ? "COALESCE(NULLIF(CONCAT('g:', guest_cookie_hash), 'g:'), NULLIF(CONCAT('ip:', guest_ip), 'ip:'), CONCAT('sid:', session_id))"
            : "COALESCE(NULLIF(CONCAT('ip:', guest_ip), 'ip:'), CONCAT('sid:', session_id))";
        $guest_cookie_select = $this->has_visitor_cookie_column()
            ? "MAX(CASE WHEN user_id <= 1 AND is_bot = 0 AND visitor_cookie_hash <> '' THEN LOWER(visitor_cookie_hash) ELSE '' END) AS guest_cookie_hash,"
            : "'' AS guest_cookie_hash,";

        $sql = 'SELECT grp,
                       COUNT(*) AS sessions,
                       COUNT(DISTINCT actor_key) AS users,
                       SUM(has_trace) AS trace_ok_sessions
                FROM (
                    SELECT
                        session_id,
                        CASE
                            WHEN has_member_human = 1 AND has_guest_human = 0 AND has_bot_row = 0 THEN \'members\'
                            WHEN has_guest_human = 1 AND has_member_human = 0 AND has_bot_row = 0 THEN \'guests\'
                            ELSE \'other\'
                        END AS grp,
                        CASE
                            WHEN has_member_human = 1 AND has_guest_human = 0 AND has_bot_row = 0 THEN CONCAT(\'m:\', CAST(member_user_id AS CHAR))
                            WHEN has_guest_human = 1 AND has_member_human = 0 AND has_bot_row = 0 THEN ' . $guest_actor_expr . '
                            ELSE \'\'
                        END AS actor_key,
                        has_trace
                    FROM (
                        SELECT
                            session_id,
                            MAX(CASE WHEN user_id > 1 AND is_bot = 0 THEN 1 ELSE 0 END) AS has_member_human,
                            MAX(CASE WHEN user_id <= 1 AND is_bot = 0 THEN 1 ELSE 0 END) AS has_guest_human,
                            MAX(CASE WHEN is_bot = 1 THEN 1 ELSE 0 END) AS has_bot_row,
                            MAX(CASE WHEN user_id > 1 AND is_bot = 0 THEN user_id ELSE 0 END) AS member_user_id,
                            ' . $guest_cookie_select . '
                            MAX(CASE WHEN user_id <= 1 AND is_bot = 0 AND user_ip <> \'\' THEN user_ip ELSE \'\' END) AS guest_ip,
                            MAX(CASE WHEN cursor_track_points > 0 OR cursor_click_count > 0 THEN 1 ELSE 0 END) AS has_trace
                        FROM ' . $this->table_prefix . 'bastien59_stats
                        WHERE visit_time > ' . (int)$start_time . '
                        GROUP BY session_id
                    ) AS raw
                ) AS sess
                WHERE grp IN (\'members\', \'guests\')
                GROUP BY grp';

        $stats = [
            'members' => ['sessions' => 0, 'users' => 0, 'ok' => 0],
            'guests' => ['sessions' => 0, 'users' => 0, 'ok' => 0],
        ];

        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result)) {
            $grp = (string)($row['grp'] ?? '');
            if (!isset($stats[$grp])) {
                continue;
            }
            $stats[$grp]['sessions'] = (int)($row['sessions'] ?? 0);
            $stats[$grp]['users'] = (int)($row['users'] ?? 0);
            $stats[$grp]['ok'] = (int)($row['trace_ok_sessions'] ?? 0);
        }
        $this->db->sql_freeresult($result);

        $total_sessions = $stats['members']['sessions'] + $stats['guests']['sessions'];
        $total_users = $stats['members']['users'] + $stats['guests']['users'];
        $total_ok = $stats['members']['ok'] + $stats['guests']['ok'];

        $rows = [
            'members' => [
                'label' => $this->user->lang('STATS_BEHAVIOR_GROUP_MEMBERS'),
                'sessions' => $stats['members']['sessions'],
                'users' => $stats['members']['users'],
                'ok' => $stats['members']['ok'],
            ],
            'guests' => [
                'label' => $this->user->lang('STATS_BEHAVIOR_GROUP_GUESTS'),
                'sessions' => $stats['guests']['sessions'],
                'users' => $stats['guests']['users'],
                'ok' => $stats['guests']['ok'],
            ],
            'humans' => [
                'label' => $this->user->lang('STATS_BEHAVIOR_GROUP_HUMANS_LEGIT'),
                'sessions' => $total_sessions,
                'users' => $total_users,
                'ok' => $total_ok,
            ],
        ];

        foreach ($rows as $row) {
            if ((int)$row['sessions'] <= 0) {
                continue;
            }
            $fail = max(0, (int)$row['sessions'] - (int)$row['ok']);
            $fail_rate = round(($fail * 100) / max(1, (int)$row['sessions']), 1);
            $ok_rate = round((((int)$row['sessions'] - $fail) * 100) / max(1, (int)$row['sessions']), 1);

            $this->template->assign_block_vars('BEHAVIOR_CURSOR_CAPTURE', [
                'GROUP_LABEL' => htmlspecialchars((string)$row['label'], ENT_COMPAT, 'UTF-8'),
                'SESSIONS' => number_format((int)$row['sessions'], 0, ',', ' '),
                'USERS' => number_format((int)$row['users'], 0, ',', ' '),
                'TRACE_OK' => number_format((int)$row['ok'], 0, ',', ' '),
                'TRACE_OK_RATE' => number_format($ok_rate, 1, ',', ' '),
                'TRACE_FAIL' => number_format($fail, 0, ',', ' '),
                'TRACE_FAIL_RATE' => number_format($fail_rate, 1, ',', ' '),
            ]);
        }
    }

    private function assign_behavior_outlier_signals($start_time)
    {
        $signal_defs = [
            'learn_behavior_outlier' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_LEARN_BEHAVIOR'),
            'learn_speed_outlier' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_SPEED'),
            'learn_no_interact_outlier' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_NO_INTERACT'),
            'learn_sparse_scroll_outlier' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_SPARSE'),
            'learn_jump_outlier' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_JUMP'),
            'learn_reactions_assets_missing_outlier' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_REACTIONS_ASSETS'),
            'ajax_webdriver' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_WEBDRIVER'),
            'ajax_scroll_profile' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_AJAX_PROFILE'),
            'guest_fp_clone_multi_ip' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_GUEST_FP_CLONE'),
            'guest_fp_clone_multi_ip_shadow' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_GUEST_FP_CLONE_SHADOW'),
            'guest_cookie_clone_multi_ip' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_GUEST_COOKIE_CLONE'),
            'guest_cookie_clone_multi_ip_shadow' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_GUEST_COOKIE_CLONE_SHADOW'),
            'guest_cookie_ajax_fail' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_GUEST_COOKIE_AJAX_FAIL'),
            'guest_cookie_ajax_fail_shadow' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_GUEST_COOKIE_AJAX_FAIL_SHADOW'),
            'cursor_no_movement' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_CURSOR_NO_MOVE'),
            'cursor_no_clicks' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_CURSOR_NO_CLICKS'),
            'cursor_speed_outlier' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_CURSOR_SPEED'),
            'cursor_script_path' => $this->user->lang('STATS_BEHAVIOR_SIGNAL_CURSOR_SCRIPT'),
        ];

        $sql = 'SELECT COUNT(*) AS total_sessions
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time > ' . (int)$start_time . '
                AND is_first_visit = 1
                AND user_id <= 1';
        $result = $this->db->sql_query($sql);
        $total_sessions = (int)$this->db->sql_fetchfield('total_sessions');
        $this->db->sql_freeresult($result);

        foreach ($signal_defs as $signal => $label) {
            $sql = 'SELECT COUNT(*) AS total
                    FROM ' . $this->table_prefix . 'bastien59_stats
                    WHERE visit_time > ' . (int)$start_time . '
                    AND is_first_visit = 1
                    AND user_id <= 1
                    AND signals LIKE \'%' . $this->db->sql_escape($signal) . '%\'';
            $result = $this->db->sql_query($sql);
            $count = (int)$this->db->sql_fetchfield('total');
            $this->db->sql_freeresult($result);

            if ($count <= 0) {
                continue;
            }

            $rate = ($total_sessions > 0) ? round(($count * 100) / $total_sessions, 2) : 0;
            $this->template->assign_block_vars('BEHAVIOR_OUTLIERS', [
                'SIGNAL' => htmlspecialchars($signal, ENT_COMPAT, 'UTF-8'),
                'LABEL' => htmlspecialchars($label, ENT_COMPAT, 'UTF-8'),
                'COUNT' => number_format($count, 0, ',', ' '),
                'RATE' => number_format($rate, 2, ',', ' '),
            ]);
        }
    }

    private function assign_recent_behavior_cases($start_time, $limit)
    {
        $has_cursor_columns = $this->has_cursor_columns();
        $cursor_select = '';
        $interesting_condition = "signals <> ''";
        if ($has_cursor_columns) {
            $cursor_select = ', cursor_track_points, cursor_track_duration_ms, cursor_track_path, cursor_click_points,
                               cursor_device_class, cursor_viewport, cursor_total_distance, cursor_avg_speed,
                               cursor_max_speed, cursor_direction_changes, cursor_linearity, cursor_click_count';
            $interesting_condition = "(signals <> '' OR cursor_track_points > 0)";
        }

        $sql = 'SELECT visit_time, user_ip, country_code, country_name, page_title, page_url, signals, user_agent,
                       screen_res, screen_res_ajax' . $cursor_select . '
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time > ' . (int)$start_time . '
                AND is_first_visit = 1
                AND user_id <= 1
                AND ' . $interesting_condition . '
                ORDER BY visit_time DESC';
        $result = $this->db->sql_query_limit($sql, $limit);
        $case_count = 0;
        $svg_count = 0;

        while ($row = $this->db->sql_fetchrow($result)) {
            $country_display = '';
            if (!empty($row['country_code'])) {
                $country_display = $this->country_code_to_flag($row['country_code']) . ' ' . htmlspecialchars($row['country_name'] ?: $row['country_code'], ENT_COMPAT, 'UTF-8');
            }

            $cursor_svg = '';
            $cursor_summary = '-';
            $cursor_device = '';
            if ($has_cursor_columns) {
                $cursor_svg = $this->build_cursor_trace_svg($row);
                $cursor_device = $this->format_cursor_device_label((string)($row['cursor_device_class'] ?? ''));
                $cursor_summary = $this->format_cursor_summary($row);
                if ($cursor_svg !== '') {
                    $svg_count++;
                }
            }
            $case_count++;

            $this->template->assign_block_vars('BEHAVIOR_CASES', [
                'TIME' => $this->user->format_date((int)$row['visit_time']),
                'IP' => htmlspecialchars($row['user_ip'], ENT_COMPAT, 'UTF-8'),
                'COUNTRY' => $country_display,
                'PAGE_TITLE' => htmlspecialchars($row['page_title'], ENT_COMPAT, 'UTF-8'),
                'PAGE_URL' => htmlspecialchars($row['page_url'], ENT_COMPAT, 'UTF-8'),
                'SIGNALS' => htmlspecialchars($row['signals'] ?: '-', ENT_COMPAT, 'UTF-8'),
                'SIGNALS_DESC' => $this->format_signals_description($row['signals']),
                'USER_AGENT' => htmlspecialchars($row['user_agent'], ENT_COMPAT, 'UTF-8'),
                'CURSOR_DEVICE' => htmlspecialchars($cursor_device, ENT_COMPAT, 'UTF-8'),
                'CURSOR_SUMMARY' => htmlspecialchars($cursor_summary, ENT_COMPAT, 'UTF-8'),
                'CURSOR_SVG' => $cursor_svg,
            ]);
        }
        $this->db->sql_freeresult($result);

        $this->template->assign_vars([
            'BEHAVIOR_RECENT_CASES_COUNT' => $case_count,
            'BEHAVIOR_RECENT_CASES_SVG_COUNT' => $svg_count,
            'BEHAVIOR_RECENT_CURSOR_SVG_STATUS' => sprintf(
                $this->user->lang('STATS_BEHAVIOR_CURSOR_SVG_STATUS'),
                (int)$svg_count,
                (int)$case_count
            ),
        ]);
    }

    private function format_cursor_device_label($device_class)
    {
        $d = strtolower(trim((string)$device_class));
        if ($d === 'desktop') {
            return $this->user->lang('STATS_BEHAVIOR_CURSOR_DEVICE_DESKTOP');
        }
        if ($d === 'mobile') {
            return $this->user->lang('STATS_BEHAVIOR_CURSOR_DEVICE_MOBILE');
        }
        if ($d === 'tablet') {
            return $this->user->lang('STATS_BEHAVIOR_CURSOR_DEVICE_TABLET');
        }
        return $this->user->lang('STATS_BEHAVIOR_CURSOR_DEVICE_UNKNOWN');
    }

    private function format_cursor_summary(array $row)
    {
        $points = (int)($row['cursor_track_points'] ?? 0);
        if ($points <= 0) {
            return $this->user->lang('STATS_BEHAVIOR_CURSOR_NONE');
        }

        $duration = (int)($row['cursor_track_duration_ms'] ?? 0);
        $distance = (int)($row['cursor_total_distance'] ?? 0);
        $avg_speed = (int)($row['cursor_avg_speed'] ?? 0);
        $max_speed = (int)($row['cursor_max_speed'] ?? 0);
        $dir_changes = (int)($row['cursor_direction_changes'] ?? 0);
        $linearity = (int)($row['cursor_linearity'] ?? 0);
        $clicks = (int)($row['cursor_click_count'] ?? 0);

        return sprintf(
            $this->user->lang('STATS_BEHAVIOR_CURSOR_SUMMARY'),
            max(0, $points),
            max(0, $duration),
            max(0, $distance),
            max(0, $avg_speed),
            max(0, $max_speed),
            max(0, $dir_changes),
            max(0, min(100, $linearity)),
            max(0, $clicks)
        );
    }

    /**
     * @return array<int,array{0:int,1:int,2:int}>
     */
    private function decode_cursor_points_json($raw, $limit = 300)
    {
        $out = [];
        $text = trim((string)$raw);
        if ($text === '' || $text === '[]') {
            return $out;
        }

        $decoded = @json_decode($text, true);
        if (!is_array($decoded)) {
            return $out;
        }

        $max = max(1, min(400, (int)$limit));
        $last_t = -1;
        foreach ($decoded as $triplet) {
            if (count($out) >= $max) {
                break;
            }
            if (!is_array($triplet) || !isset($triplet[0], $triplet[1], $triplet[2])) {
                continue;
            }

            $t = max(0, min(120000, (int)$triplet[0]));
            $x = max(0, min(16384, (int)$triplet[1]));
            $y = max(0, min(16384, (int)$triplet[2]));
            if ($t < $last_t) {
                continue;
            }
            $last_t = $t;
            $out[] = [$t, $x, $y];
        }

        return $out;
    }

    /**
     * @return array{0:int,1:int}
     */
    private function resolve_cursor_canvas_size(array $row)
    {
        $candidates = [
            (string)($row['screen_res_ajax'] ?? ''),
            (string)($row['screen_res'] ?? ''),
            (string)($row['cursor_viewport'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            $res = trim($candidate);
            if (!preg_match('/^([1-9][0-9]{1,4})x([1-9][0-9]{1,4})$/', $res, $m)) {
                continue;
            }
            $w = (int)$m[1];
            $h = (int)$m[2];
            if ($w >= 120 && $h >= 120 && $w <= 16384 && $h <= 16384) {
                return [$w, $h];
            }
        }

        return [1366, 768];
    }

    private function build_cursor_trace_svg(array $row, $display_width = 220, $display_height = 140)
    {
        $points = $this->decode_cursor_points_json((string)($row['cursor_track_path'] ?? ''), 300);
        if (count($points) <= 0) {
            return '';
        }

        $clicks = $this->decode_cursor_points_json((string)($row['cursor_click_points'] ?? ''), 120);
        list($canvas_w, $canvas_h) = $this->resolve_cursor_canvas_size($row);
        $render_w = max(96, min(1280, (int)$display_width));
        $requested_h = (int)$display_height;
        if ($requested_h <= 0) {
            $requested_h = (int)round(($render_w * (float)$canvas_h) / max(1.0, (float)$canvas_w));
        }
        $render_h = max(64, min(960, $requested_h));

        $plot = [];
        foreach ($points as $p) {
            $x = max(0, min($canvas_w, (int)$p[1]));
            $y = max(0, min($canvas_h, (int)$p[2]));
            $plot[] = $x . ',' . $y;
        }
        if (empty($plot)) {
            return '';
        }

        $base_viewbox = $this->format_cursor_viewbox(0, 0, $canvas_w, $canvas_h);
        list($fit_x, $fit_y, $fit_w, $fit_h) = $this->compute_cursor_fit_viewbox($points, $clicks, $canvas_w, $canvas_h);
        $fit_viewbox = $this->format_cursor_viewbox($fit_x, $fit_y, $fit_w, $fit_h);

        $svg = '<svg class="behavior-cursor-svg" viewBox="' . $fit_viewbox . '" data-cursor-base-viewbox="' . $base_viewbox . '" data-cursor-fit-viewbox="' . $fit_viewbox . '" width="' . $render_w . '" height="' . $render_h . '" preserveAspectRatio="xMidYMid meet">';
        $svg .= '<rect x="0" y="0" width="' . (int)$canvas_w . '" height="' . (int)$canvas_h . '" fill="#f7fbff" stroke="#d8e4f2" />';
        $svg .= '<polyline points="' . implode(' ', $plot) . '" fill="none" stroke="#0b74c7" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />';

        $step = max(8, (int)floor(count($points) / 12));
        for ($i = $step; $i < count($points); $i += $step) {
            $p0 = $points[$i - $step];
            $p1 = $points[$i];
            $x0 = (float)$p0[1];
            $y0 = (float)$p0[2];
            $x1 = (float)$p1[1];
            $y1 = (float)$p1[2];
            $dx = $x1 - $x0;
            $dy = $y1 - $y0;
            $len = sqrt(($dx * $dx) + ($dy * $dy));
            if ($len < 10.0) {
                continue;
            }

            $ux = $dx / $len;
            $uy = $dy / $len;
            $arrow = min(12.0, max(6.0, $len * 0.18));
            $ax = $x1 - ($ux * $arrow);
            $ay = $y1 - ($uy * $arrow);
            $nx = -$uy;
            $ny = $ux;
            $hx1 = $ax + ($nx * ($arrow * 0.45));
            $hy1 = $ay + ($ny * ($arrow * 0.45));
            $hx2 = $ax - ($nx * ($arrow * 0.45));
            $hy2 = $ay - ($ny * ($arrow * 0.45));

            $svg .= '<line x1="' . (int)round($x0) . '" y1="' . (int)round($y0) . '" x2="' . (int)round($x1) . '" y2="' . (int)round($y1) . '" stroke="#6aa8d8" stroke-width="1" />';
            $svg .= '<line x1="' . (int)round($x1) . '" y1="' . (int)round($y1) . '" x2="' . (int)round($hx1) . '" y2="' . (int)round($hy1) . '" stroke="#2f6f9f" stroke-width="1" />';
            $svg .= '<line x1="' . (int)round($x1) . '" y1="' . (int)round($y1) . '" x2="' . (int)round($hx2) . '" y2="' . (int)round($hy2) . '" stroke="#2f6f9f" stroke-width="1" />';
        }

        foreach ($clicks as $click) {
            $cx = max(0, min($canvas_w, (int)$click[1]));
            $cy = max(0, min($canvas_h, (int)$click[2]));
            $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="5" fill="none" stroke="#d43d3d" stroke-width="1.4" />';
            $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="10" fill="none" stroke="#d43d3d" stroke-width="1" opacity="0.55" />';
            $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="15" fill="none" stroke="#d43d3d" stroke-width="0.8" opacity="0.3" />';
        }

        $svg .= '</svg>';
        return $svg;
    }

    private function build_cursor_empty_svg(array $row, $display_width = 220, $display_height = 140)
    {
        list($canvas_w, $canvas_h) = $this->resolve_cursor_canvas_size($row);
        $render_w = max(96, min(1280, (int)$display_width));
        $requested_h = (int)$display_height;
        if ($requested_h <= 0) {
            $requested_h = (int)round(($render_w * (float)$canvas_h) / max(1.0, (float)$canvas_w));
        }
        $render_h = max(64, min(960, $requested_h));
        $label = htmlspecialchars((string)$this->user->lang('STATS_SESSION_CURSOR_NONE'), ENT_COMPAT, 'UTF-8');
        $base_viewbox = $this->format_cursor_viewbox(0, 0, $canvas_w, $canvas_h);
        $pad = (int)max(12, min(180, round((float)min($canvas_w, $canvas_h) * 0.14)));
        $stroke = (int)max(7, min(96, round((float)min($canvas_w, $canvas_h) * 0.10)));
        $x1 = $pad;
        $y1 = $pad;
        $x2 = max($x1 + 1, $canvas_w - $pad);
        $y2 = max($y1 + 1, $canvas_h - $pad);
        $cx = (int)round($canvas_w / 2);
        $cy = (int)round($canvas_h / 2);
        $ring_r = (int)max(10, min(220, round((float)min($canvas_w, $canvas_h) * 0.22)));

        $svg = '<svg class="behavior-cursor-svg" viewBox="' . $base_viewbox . '" data-cursor-base-viewbox="' . $base_viewbox . '" width="' . $render_w . '" height="' . $render_h . '" preserveAspectRatio="xMidYMid meet">';
        $svg .= '<rect x="0" y="0" width="' . (int)$canvas_w . '" height="' . (int)$canvas_h . '" fill="#f7fbff" stroke="#d8e4f2" />';
        $svg .= '<circle cx="' . $cx . '" cy="' . $cy . '" r="' . $ring_r . '" fill="none" stroke="#d43d3d" stroke-width="' . max(2, (int)round($stroke * 0.22)) . '" opacity="0.28" />';
        $svg .= '<line x1="' . $x1 . '" y1="' . $y1 . '" x2="' . $x2 . '" y2="' . $y2 . '" stroke="#d43d3d" stroke-width="' . $stroke . '" stroke-linecap="round" opacity="0.92" />';
        $svg .= '<line x1="' . $x2 . '" y1="' . $y1 . '" x2="' . $x1 . '" y2="' . $y2 . '" stroke="#d43d3d" stroke-width="' . $stroke . '" stroke-linecap="round" opacity="0.92" />';
        $svg .= '<text x="' . $cx . '" y="' . (int)max(16, min($canvas_h - 10, $canvas_h - (int)round($canvas_h * 0.06))) . '" text-anchor="middle" dominant-baseline="middle" font-size="' . max(14, min(42, (int)round($canvas_h * 0.12))) . '" fill="#7a8ca2">' . $label . '</text>';
        $svg .= '</svg>';
        return $svg;
    }

    /**
     * Calcule une viewBox "fit-to-trace" autour des points curseur/clics.
     *
     * @param array<int,array{0:int,1:int,2:int}> $points
     * @param array<int,array{0:int,1:int,2:int}> $clicks
     * @return array{0:int,1:int,2:int,3:int}
     */
    private function compute_cursor_fit_viewbox(array $points, array $clicks, $canvas_w, $canvas_h)
    {
        $cw = max(1, (int)$canvas_w);
        $ch = max(1, (int)$canvas_h);
        $min_x = $cw;
        $min_y = $ch;
        $max_x = 0;
        $max_y = 0;
        $has_any = false;

        $collector = function (array $items) use (&$min_x, &$min_y, &$max_x, &$max_y, &$has_any, $cw, $ch) {
            foreach ($items as $p) {
                $x = max(0, min($cw, (int)($p[1] ?? 0)));
                $y = max(0, min($ch, (int)($p[2] ?? 0)));
                if (!$has_any) {
                    $min_x = $x;
                    $max_x = $x;
                    $min_y = $y;
                    $max_y = $y;
                    $has_any = true;
                    continue;
                }
                if ($x < $min_x) {
                    $min_x = $x;
                }
                if ($x > $max_x) {
                    $max_x = $x;
                }
                if ($y < $min_y) {
                    $min_y = $y;
                }
                if ($y > $max_y) {
                    $max_y = $y;
                }
            }
        };

        $collector($points);
        $collector($clicks);

        if (!$has_any) {
            return [0, 0, $cw, $ch];
        }

        $pad = (int)max(14, min(220, round((float)max($cw, $ch) * 0.045)));
        $x1 = max(0, $min_x - $pad);
        $y1 = max(0, $min_y - $pad);
        $x2 = min($cw, $max_x + $pad);
        $y2 = min($ch, $max_y + $pad);

        if ($x2 <= $x1) {
            $x2 = min($cw, $x1 + 1);
        }
        if ($y2 <= $y1) {
            $y2 = min($ch, $y1 + 1);
        }

        $box_w = max(1, $x2 - $x1);
        $box_h = max(1, $y2 - $y1);

        $target_ratio = (float)$cw / (float)$ch;
        $box_ratio = (float)$box_w / (float)$box_h;

        if ($box_ratio > $target_ratio) {
            $wanted_h = (int)ceil((float)$box_w / max(0.0001, $target_ratio));
            $wanted_h = max(1, min($ch, $wanted_h));
            $cy = ((float)$y1 + (float)$y2) / 2.0;
            $y1 = (int)floor($cy - ((float)$wanted_h / 2.0));
            $y2 = $y1 + $wanted_h;
            if ($y1 < 0) {
                $y2 += -$y1;
                $y1 = 0;
            }
            if ($y2 > $ch) {
                $y1 -= ($y2 - $ch);
                $y2 = $ch;
                if ($y1 < 0) {
                    $y1 = 0;
                }
            }
        } else {
            $wanted_w = (int)ceil((float)$box_h * $target_ratio);
            $wanted_w = max(1, min($cw, $wanted_w));
            $cx = ((float)$x1 + (float)$x2) / 2.0;
            $x1 = (int)floor($cx - ((float)$wanted_w / 2.0));
            $x2 = $x1 + $wanted_w;
            if ($x1 < 0) {
                $x2 += -$x1;
                $x1 = 0;
            }
            if ($x2 > $cw) {
                $x1 -= ($x2 - $cw);
                $x2 = $cw;
                if ($x1 < 0) {
                    $x1 = 0;
                }
            }
        }

        $fit_w = max(1, $x2 - $x1);
        $fit_h = max(1, $y2 - $y1);

        if ($fit_w >= ($cw - 2) && $fit_h >= ($ch - 2)) {
            return [0, 0, $cw, $ch];
        }

        return [(int)$x1, (int)$y1, (int)$fit_w, (int)$fit_h];
    }

    private function format_cursor_viewbox($x, $y, $w, $h)
    {
        return (int)$x . ' ' . (int)$y . ' ' . max(1, (int)$w) . ' ' . max(1, (int)$h);
    }

    /**
     * Formate une résolution brute (ex: 1366x768) en pixels lisibles.
     */
    private function format_resolution_px($resolution)
    {
        $res = trim((string)$resolution);
        if ($res === '') {
            return '-';
        }

        if (preg_match('/^([1-9][0-9]{1,4})x([1-9][0-9]{1,4})$/', $res, $m)) {
            return $m[1] . 'x' . $m[2] . ' px';
        }

        return '-';
    }

    /**
     * Extrait le nom du bot depuis le User-Agent
     */
    /**
     * Génère une description HTML des signaux de détection
     */
    private function format_signals_description($signals_str)
    {
        if (empty($signals_str)) {
            return '';
        }

        $descriptions = [
            'empty_ua'             => 'User-Agent vide (80 pts)',
            'ua_pattern'           => 'Pattern de bot dans le UA : bot/, crawler, headlesschrome... (70 pts)',
            'no_browser_signature' => 'Aucun navigateur reconnu dans le UA — renforcement uniquement (25 pts)',
            'fake_chrome_build'    => 'Numéro de build Chrome impossible pour une version récente (55 pts)',
            'old_firefox'          => 'Firefox < 30, version de 2014 (55 pts)',
            'bad_gecko_date'       => 'Date Gecko invalide dans le UA (50 pts)',
            'fake_safari_build'    => 'Numéro de build Safari < 400, impossible en réel (50 pts)',
            'template_literal'     => 'Template non résolu dans le UA, ex: Firefox/{version} (70 pts)',
            'iphone_13_2_3'       => 'iPhone OS 13_2_3 figé — botnet Tencent Cloud (60 pts)',
            'fake_legit_bot'       => 'UA prétend être un bot légitime mais le reverse DNS ne correspond pas (90 pts)',
            'posting_first_visit'  => 'Accès direct à posting.php dès la 1ère visite (65 pts)',
            'posting_get_loop'     => 'Requêtes GET en boucle sur posting.php sans jamais poster (65 pts)',
            'viewprofile_first_visit_no_res' => 'Accès direct à viewprofile en 1ère visite, sans referer interne et sans résolution (cookie/AJAX) (strict)',
            'no_screen_res'        => 'Pas de résolution d\'écran après 3+ pages — pas de JavaScript (35 pts)',
            'html_entities_in_url' => 'Entités HTML dans l\'URL — bot qui parse le HTML source (45 pts)',
            'ajax_webdriver'       => 'navigator.webdriver = true détecté via télémétrie AJAX (95 pts)',
            'ajax_scroll_no_interact' => 'Scroll AJAX sans aucune interaction utilisateur préalable (25 pts)',
            'ajax_scroll_too_fast' => 'Premier scroll anormalement rapide après chargement (30 pts)',
            'ajax_scroll_jump'     => 'Scroll en saut brusque (grande distance avec 1-2 événements) (30 pts)',
            'ajax_scroll_profile'  => 'Profil de scroll automatisé (combinaison de signaux AJAX) (70 pts)',
            'guest_fp_clone_multi_ip' => 'Fingerprint invité cloné sur plusieurs IPs en fenêtre courte (hors FR/CO) (strict)',
            'guest_fp_clone_multi_ip_shadow' => 'Fingerprint invité cloné multi-IP avec pays non encore résolu — signal différé par le cron (observation)',
            'guest_cookie_clone_multi_ip' => 'Cookie visiteur invité réutilisé sur plusieurs IPs en fenêtre courte (hors FR/CO) (strict)',
            'guest_cookie_clone_multi_ip_shadow' => 'Cookie visiteur invité réutilisé multi-IP avec pays non encore résolu — signal différé par le cron (observation)',
            'guest_cookie_ajax_fail' => 'Cookie visiteur signé non relu (ou incohérent) lors de l\'AJAX malgré JS actif (hors FR/CO) (strict)',
            'guest_cookie_ajax_fail_shadow' => 'Cookie visiteur signé non relu (ou incohérent) en AJAX — mode observation FR/CO ou géolocalisation en attente (non signalé fail2ban)',
            'cursor_no_movement' => 'Aucun déplacement curseur/touch significatif pendant la fenêtre de capture (observation)',
            'cursor_no_clicks' => 'Trajet détecté sans clic pendant la fenêtre de capture (observation)',
            'cursor_speed_outlier' => 'Trajet très rapide et peu varié (outlier curseur)',
            'cursor_script_path' => 'Trajectoire quasi-linéaire et mécanique (signature d\'automation) (strict)',
            'learn_no_interact_outlier' => 'Écart au profil appris: absence d\'interaction inhabituel pour ce profil (25 pts)',
            'learn_speed_outlier'   => 'Écart au profil appris: scroll initial anormalement rapide (25 pts)',
            'learn_sparse_scroll_outlier' => 'Écart au profil appris: trop peu d\'événements de scroll (20 pts)',
            'learn_jump_outlier'    => 'Écart au profil appris: saut de scroll atypique (20 pts)',
            'learn_reactions_assets_missing_outlier' => 'Écart au profil appris: assets réactions absents malgré extension active (25 pts)',
            'learn_behavior_outlier'=> 'Écart global au profil appris des membres connectés (20 pts)',
        ];

        $parts = [];
        foreach (explode(',', $signals_str) as $sig) {
            $sig = trim($sig);
            if (empty($sig)) continue;
            if (isset($descriptions[$sig])) {
                $parts[] = '<li><code>' . htmlspecialchars($sig) . '</code> — ' . $descriptions[$sig] . '</li>';
            } elseif (preg_match('/^ua_pattern:(.+)$/', $sig, $m)) {
                $parts[] = '<li><code>' . htmlspecialchars($sig) . '</code> — Pattern UA bot détecté : <strong>' . htmlspecialchars($m[1]) . '</strong> (70 pts)</li>';
            } elseif (preg_match('/^legit_ua_pattern:(.+)$/', $sig, $m)) {
                $parts[] = '<li><code>' . htmlspecialchars($sig) . '</code> — Pattern UA bot légitime détecté : <strong>' . htmlspecialchars($m[1]) . '</strong> (info)</li>';
            } elseif (preg_match('/^old_chrome_(\d+)$/', $sig, $m)) {
                $parts[] = '<li><code>' . htmlspecialchars($sig) . '</code> — Chrome ' . $m[1] . ', version obsolète (50 pts)</li>';
            } else {
                $parts[] = '<li><code>' . htmlspecialchars($sig) . '</code></li>';
            }
        }

        return !empty($parts) ? '<ul style="margin:2px 0 0 15px;padding:0;list-style:square;">' . implode('', $parts) . '</ul>' : '';
    }

    private function extract_bot_name($user_agent)
    {
        $ua_lower = strtolower($user_agent);

        // Liste des bots connus avec leur nom d'affichage
        $known_bots = [
            'googlebot'         => 'Googlebot',
            'googlebot-image'   => 'Googlebot Image',
            'bingbot'           => 'Bingbot (Microsoft)',
            'yandexbot'         => 'Yandex Bot',
            'baiduspider'       => 'Baidu Spider',
            'duckduckbot'       => 'DuckDuckBot',
            'bytespider'        => 'Bytespider (TikTok/ByteDance)',
            'petalbot'          => 'PetalBot (Huawei)',
            'applebot'          => 'Applebot',
            'facebookexternalhit' => 'Facebook Crawler',
            'twitterbot'        => 'Twitter Bot',
            'linkedinbot'       => 'LinkedIn Bot',
            'slackbot'          => 'Slack Bot',
            'discordbot'        => 'Discord Bot',
            'telegrambot'       => 'Telegram Bot',
            'whatsapp'          => 'WhatsApp',
            'pinterest'         => 'Pinterest Bot',
            'semrush'           => 'SEMrush Bot',
            'ahrefs'            => 'Ahrefs Bot',
            'mj12bot'           => 'Majestic Bot',
            'dotbot'            => 'DotBot (Moz)',
            'rogerbot'          => 'Rogerbot (Moz)',
            'screaming'         => 'Screaming Frog',
            'uptimerobot'       => 'Uptime Robot',
            'pingdom'           => 'Pingdom',
            'gptbot'            => 'GPTBot (OpenAI)',
            'claudebot'         => 'ClaudeBot (Anthropic)',
            'amazonbot'         => 'Amazonbot (Amazon)',
            'amzn-searchbot'    => 'Amazonbot (Amazon)',
            'chatgpt'           => 'ChatGPT-User (OpenAI)',
            'ccbot'             => 'Common Crawl Bot',
            'sogou'             => 'Sogou Spider',
            'exabot'            => 'Exabot',
            'ia_archiver'       => 'Alexa Crawler',
            'archive.org_bot'   => 'Internet Archive Bot',
            'seznambot'         => 'Seznam Bot',
            'yacybot'           => 'YaCy Bot',
            'crawler'           => 'Generic Crawler',
            'spider'            => 'Generic Spider',
            'bot/'              => 'Generic Bot',
            'wget'              => 'Wget',
            'curl/'             => 'cURL',
            'python-requests'   => 'Python Requests',
            'python-urllib'     => 'Python urllib',
            'scrapy'            => 'Scrapy',
            'headlesschrome'    => 'Headless Chrome',
            'phantomjs'         => 'PhantomJS',
            'selenium'          => 'Selenium',
            'puppeteer'         => 'Puppeteer',
        ];

        foreach ($known_bots as $pattern => $name) {
            if (strpos($ua_lower, $pattern) !== false) {
                return htmlspecialchars($name, ENT_COMPAT, 'UTF-8');
            }
        }

        // Si pas reconnu, essayer d'extraire un nom
        if (preg_match('/([a-z]+bot|[a-z]+spider|[a-z]+crawler)/i', $user_agent, $matches)) {
            return htmlspecialchars(ucfirst($matches[1]), ENT_COMPAT, 'UTF-8');
        }

        return $this->user->lang('STATS_BOT_UNKNOWN');
    }

    /**
     * Convertit un code pays en emoji drapeau
     */
    private function country_code_to_flag($country_code)
    {
        if (empty($country_code) || strlen($country_code) !== 2) {
            return '';
        }

        $country_code = strtoupper($country_code);

        // Convertir les lettres en Regional Indicator Symbols
        $flag = '';
        for ($i = 0; $i < 2; $i++) {
            $flag .= mb_chr(ord($country_code[$i]) - ord('A') + 0x1F1E6);
        }

        return $flag;
    }

    /**
     * Referers complets cliquables (externes uniquement)
     */
    private function assign_full_referers($start_time, $limit = 5000)
    {
        $server_name = $this->request->server('SERVER_NAME', '');

        // Domaines internes à exclure dans le SQL
        $internal_domains = [];
        if (!empty($server_name)) {
            $internal_domains[] = $server_name;
        }
        $board_url = $this->config['server_name'] ?? '';
        if (!empty($board_url) && $board_url !== $server_name) {
            $internal_domains[] = $board_url;
        }
        $internal_domains[] = 'bernard.debucquoi.com';

        // Construire l'exclusion SQL
        $exclude_sql = '';
        foreach ($internal_domains as $domain) {
            $exclude_sql .= ' AND referer NOT LIKE \'%' . $this->db->sql_escape($domain) . '%\'';
        }

        // Referers externes avec page de destination
        $sql = 'SELECT referer, referer_type, page_url, page_title, is_bot, COUNT(*) as total
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time > ' . $start_time . '
                AND referer <> ""
                AND referer_type <> \'Interne\'
                AND referer_type <> \'Direct\'
                ' . $exclude_sql . '
                GROUP BY referer, referer_type, page_url, page_title, is_bot
                ORDER BY total DESC';

        $result = $this->db->sql_query_limit($sql, $limit);

        while ($row = $this->db->sql_fetchrow($result)) {
            $ref = $row['referer'];
            $display = $ref;
            if (strlen($display) > 80) {
                $display = substr($display, 0, 77) . '...';
            }

            $dest = $row['page_url'];
            $dest_display = $row['page_title'];
            if (empty($dest_display)) {
                $dest_display = $dest;
            }
            if (strlen($dest_display) > 60) {
                $dest_display = substr($dest_display, 0, 57) . '...';
            }

            $this->template->assign_block_vars('FULL_REFERERS', [
                'URL'           => htmlspecialchars($ref, ENT_COMPAT, 'UTF-8'),
                'DISPLAY'       => htmlspecialchars($display, ENT_COMPAT, 'UTF-8'),
                'TYPE'          => htmlspecialchars($row['referer_type'], ENT_COMPAT, 'UTF-8'),
                'COUNT'         => (int)$row['total'],
                'IS_BOT'        => (int)$row['is_bot'],
                'DEST_URL'      => htmlspecialchars($dest, ENT_COMPAT, 'UTF-8'),
                'DEST_TITLE'    => htmlspecialchars($dest_display, ENT_COMPAT, 'UTF-8'),
            ]);
        }
        $this->db->sql_freeresult($result);
    }

    /**
     * Statistiques par pays pour la carte (séparées humains/bots)
     */
    private function assign_country_stats($start_time, $bot_filter)
    {
        // Carte humains
        $this->assign_country_stats_for('humans', $start_time, ' AND is_bot = 0', 'STATS_COUNTRY_HUMANS', 'MAP_DATA_HUMANS_JSON');

        // Carte bots
        $this->assign_country_stats_for('bots', $start_time, ' AND is_bot = 1', 'STATS_COUNTRY_BOTS', 'MAP_DATA_BOTS_JSON');

        // Données combinées (pour compatibilité)
        $this->assign_country_stats_for('all', $start_time, $bot_filter, 'STATS_COUNTRY', 'MAP_DATA_JSON');
    }

    /**
     * Statistiques par pays pour un filtre donné
     */
    private function assign_country_stats_for($type, $start_time, $filter, $block_name, $json_var)
    {
        $sql = 'SELECT country_code, country_name, COUNT(DISTINCT session_id) as visitors
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time > ' . $start_time . $filter . '
                AND country_code <> ""
                AND is_first_visit = 1
                GROUP BY country_code, country_name
                ORDER BY visitors DESC';

        $result = $this->db->sql_query($sql);

        $countries = [];
        $max_visitors = 0;
        while ($row = $this->db->sql_fetchrow($result)) {
            if ($row['visitors'] > $max_visitors) {
                $max_visitors = $row['visitors'];
            }
            $countries[] = $row;
        }
        $this->db->sql_freeresult($result);

        $map_data = [];
        foreach ($countries as $country) {
            $percent = ($max_visitors > 0) ? round(($country['visitors'] / $max_visitors) * 100) : 0;
            $flag = $this->country_code_to_flag($country['country_code']);

            $this->template->assign_block_vars($block_name, [
                'CODE'      => htmlspecialchars($country['country_code'], ENT_COMPAT, 'UTF-8'),
                'NAME'      => htmlspecialchars($country['country_name'], ENT_COMPAT, 'UTF-8'),
                'FLAG'      => $flag,
                'VISITORS'  => (int)$country['visitors'],
                'PERCENT'   => $percent,
            ]);

            $map_data[strtolower($country['country_code'])] = (int)$country['visitors'];
        }

        $this->template->assign_var($json_var, json_encode($map_data));
    }
}
