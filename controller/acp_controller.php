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

        // ================================================================
        // 1. STATISTIQUES GLOBALES
        // ================================================================
        $this->assign_global_stats($start_time, $bot_filter);

        // ================================================================
        // 2. LISTE DES SESSIONS (visiteurs uniques)
        // ================================================================
        $this->assign_sessions($start_time, $bot_filter);

        // ================================================================
        // 3. GRAPHIQUES / STATISTIQUES AGRÉGÉES
        // ================================================================
        $this->assign_stats_block('user_os', 'STATS_OS', $start_time, $bot_filter, 10);
        $this->assign_stats_block('user_device', 'STATS_DEVICE', $start_time, $bot_filter, 5);
        $this->assign_stats_block('screen_res', 'STATS_RES', $start_time, $bot_filter, 10);
        $this->assign_stats_block('referer_type', 'STATS_REFERER', $start_time, $bot_filter, 15);

        // Sources de trafic séparées humains/bots
        $this->assign_stats_block('referer_type', 'STATS_REFERER_HUMANS', $start_time, ' AND is_bot = 0', 30);
        $this->assign_stats_block('referer_type', 'STATS_REFERER_BOTS', $start_time, ' AND is_bot = 1', 30);

        // Referers complets cliquables (humains seulement, externes uniquement)
        $this->assign_full_referers($start_time);

        // Top pages visitées
        $this->assign_top_pages($start_time, $bot_filter, 20);

        // Statistiques par pays (pour la carte)
        $this->assign_country_stats($start_time, $bot_filter);

        // Variables template
        $this->template->assign_vars([
            'U_ACTION'        => append_sid($u_action),
            'FILTER_HOURS'    => $hours,
            'SHOW_BOTS'       => $show_bots,
        ]);
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
        ]);
    }

    /**
     * Liste des sessions avec pages visitées
     */
    private function assign_sessions($start_time, $bot_filter)
    {
        // Récupérer les sessions uniques avec leur première entrée
        $sql = 'SELECT s.*,
                       (SELECT COUNT(*) FROM ' . $this->table_prefix . 'bastien59_stats s2
                        WHERE s2.session_id = s.session_id) as page_count
                FROM ' . $this->table_prefix . 'bastien59_stats s
                WHERE s.visit_time > ' . $start_time . $bot_filter . '
                AND s.is_first_visit = 1
                ORDER BY s.visit_time DESC';

        $result = $this->db->sql_query_limit($sql, 100);

        while ($row = $this->db->sql_fetchrow($result)) {
            $session_id = $row['session_id'];

            // Récupérer les pages de cette session
            $sql_pages = 'SELECT page_url, page_title, visit_time, duration, referer
                          FROM ' . $this->table_prefix . 'bastien59_stats
                          WHERE session_id = \'' . $this->db->sql_escape($session_id) . '\'
                          ORDER BY visit_time ASC';
            $result_pages = $this->db->sql_query($sql_pages);

            $pages = [];
            while ($page = $this->db->sql_fetchrow($result_pages)) {
                $pages[] = $page;
            }
            $this->db->sql_freeresult($result_pages);

            // Assigner la session
            $bot_source = $row['bot_source'] ?? '';
            $is_phpbb_bot = ($bot_source === 'phpbb') ? 1 : 0;

            // Formatage pays avec drapeau emoji
            $country_display = '';
            if (!empty($row['country_code'])) {
                $flag = $this->country_code_to_flag($row['country_code']);
                $country_display = $flag . ' ' . htmlspecialchars($row['country_name'] ?? $row['country_code'], ENT_COMPAT, 'UTF-8');
            }

            $this->template->assign_block_vars('SESSIONS', [
                'SESSION_ID'    => substr($session_id, 0, 8) . '...',
                'IP'            => $row['user_ip'],
                'HOSTNAME'      => htmlspecialchars($row['hostname'] ?? '', ENT_COMPAT, 'UTF-8'),
                'COUNTRY'       => $country_display,
                'COUNTRY_CODE'  => htmlspecialchars($row['country_code'] ?? '', ENT_COMPAT, 'UTF-8'),
                'OS'            => htmlspecialchars($row['user_os'], ENT_COMPAT, 'UTF-8'),
                'DEVICE'        => htmlspecialchars($row['user_device'], ENT_COMPAT, 'UTF-8'),
                'RES'           => htmlspecialchars($row['screen_res'] ?: '-', ENT_COMPAT, 'UTF-8'),
                'USER_AGENT'    => htmlspecialchars($row['user_agent'], ENT_COMPAT, 'UTF-8'),
                'BOT_NAME'      => $this->extract_bot_name($row['user_agent']),
                'IS_PHPBB_BOT'  => $is_phpbb_bot,
                'USERNAME'      => ($row['user_id'] > 1) ? $this->get_username($row['user_id']) : 'Invité',
                'IS_BOT'        => (int)$row['is_bot'],
                'BOT_CLASS'     => ($row['is_bot']) ? 'bot' : 'human',
                'START_TIME'    => $this->user->format_date($row['visit_time']),
                'LANDING_PAGE'  => htmlspecialchars($row['page_title'], ENT_COMPAT, 'UTF-8'),
                'LANDING_URL'   => htmlspecialchars($row['page_url'], ENT_COMPAT, 'UTF-8'),
                'REFERER'       => $this->format_referer($row['referer']),
                'REFERER_TYPE'  => htmlspecialchars($row['referer_type'] ?? 'Direct', ENT_COMPAT, 'UTF-8'),
                'PAGE_COUNT'    => (int)$row['page_count'],
            ]);

            // Assigner les pages de la session (à partir de la 2ème)
            $first = true;
            foreach ($pages as $page) {
                if ($first) {
                    $first = false;
                    continue; // Skip first page (already shown as landing)
                }
                $this->template->assign_block_vars('SESSIONS.PAGES', [
                    'TITLE'     => htmlspecialchars($page['page_title'], ENT_COMPAT, 'UTF-8'),
                    'URL'       => htmlspecialchars($page['page_url'], ENT_COMPAT, 'UTF-8'),
                    'TIME'      => $this->user->format_date($page['visit_time'], 'H:i:s'),
                    'DURATION'  => $this->format_duration($page['duration']),
                ]);
            }
        }
        $this->db->sql_freeresult($result);
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
            return '<span class="direct">Accès direct</span>';
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

        return $username ?: 'Utilisateur #' . $user_id;
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
     * Extrait le nom du bot depuis le User-Agent
     */
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
            'amazonbot'         => 'Amazonbot',
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

        return 'Bot inconnu';
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
    private function assign_full_referers($start_time)
    {
        $server_name = $this->request->server('SERVER_NAME', '');

        // Domaines internes à exclure (domaine actuel + anciens domaines / alias)
        $internal_domains = [$server_name];
        $board_url = $this->config['server_name'] ?? '';
        if (!empty($board_url) && $board_url !== $server_name) {
            $internal_domains[] = $board_url;
        }
        // Ancien domaine du forum (redirection vhost)
        $internal_domains[] = 'bernard.debucquoi.com';

        $sql = 'SELECT referer, referer_type, COUNT(*) as total, is_bot
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time > ' . $start_time . '
                AND referer <> ""
                GROUP BY referer, referer_type, is_bot
                ORDER BY total DESC';

        $result = $this->db->sql_query_limit($sql, 50);

        while ($row = $this->db->sql_fetchrow($result)) {
            $ref = $row['referer'];
            // Exclure les referers internes (tous les domaines connus)
            $is_internal = false;
            foreach ($internal_domains as $domain) {
                if (!empty($domain) && stripos($ref, $domain) !== false) {
                    $is_internal = true;
                    break;
                }
            }
            if ($is_internal) {
                continue;
            }

            $display = $ref;
            if (strlen($display) > 80) {
                $display = substr($display, 0, 77) . '...';
            }

            $this->template->assign_block_vars('FULL_REFERERS', [
                'URL'       => htmlspecialchars($ref, ENT_COMPAT, 'UTF-8'),
                'DISPLAY'   => htmlspecialchars($display, ENT_COMPAT, 'UTF-8'),
                'TYPE'      => htmlspecialchars($row['referer_type'], ENT_COMPAT, 'UTF-8'),
                'COUNT'     => (int)$row['total'],
                'IS_BOT'    => (int)$row['is_bot'],
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
