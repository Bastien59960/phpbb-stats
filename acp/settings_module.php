<?php
namespace bastien59960\stats\acp;

class settings_module
{
    const AUDIT_LOG_PATH = '/var/log/security_audit.log';

    public $u_action;
    public $tpl_name;
    public $page_title;

    public function main($id, $mode)
    {
        global $phpbb_container, $user, $request, $config, $template;

        // Charger les fichiers de langue
        $user->add_lang_ext('bastien59960/stats', 'acp/info_acp_stats');

        $this->tpl_name = 'acp_stats_settings';
        $this->page_title = $user->lang('ACP_STATS_SETTINGS');

        $form_key = 'acp_stats_settings';
        add_form_key($form_key);

        // Purge immédiate (bouton "Purger maintenant")
        if ($request->is_set_post('submit_purge'))
        {
            if (!check_form_key($form_key))
            {
                trigger_error($user->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
            }

            global $db, $table_prefix;
            $retention_humans = (int)($config['bastien59_stats_retention'] ?? 30);
            $retention_bots   = (int)($config['bastien59_stats_retention_bots'] ?? 5);

            if ($retention_bots > 0) {
                $cutoff_bots = time() - ($retention_bots * 86400);
                $db->sql_query('DELETE FROM ' . $table_prefix . 'bastien59_stats WHERE is_bot = 1 AND visit_time < ' . (int)$cutoff_bots);
                $deleted_bots = (int)$db->sql_affectedrows();
            } else {
                $deleted_bots = 0;
            }
            if ($retention_humans > 0) {
                $cutoff_humans = time() - ($retention_humans * 86400);
                $db->sql_query('DELETE FROM ' . $table_prefix . 'bastien59_stats WHERE is_bot = 0 AND visit_time < ' . (int)$cutoff_humans);
                $deleted_humans = (int)$db->sql_affectedrows();
            } else {
                $deleted_humans = 0;
            }

            trigger_error('Purge terminée : ' . number_format($deleted_bots) . ' lignes bot et ' . number_format($deleted_humans) . ' lignes humain supprimées.' . adm_back_link($this->u_action));
        }

        if ($request->is_set_post('submit'))
        {
            if (!check_form_key($form_key))
            {
                trigger_error($user->lang('FORM_INVALID') . adm_back_link($this->u_action), E_USER_WARNING);
            }

            // Rétention humains (1-365 jours)
            $retention_days = $request->variable('stats_retention_days', 30);
            $retention_days = max(1, min(365, (int)$retention_days));
            $config->set('bastien59_stats_retention', $retention_days);

            // Rétention bots (1-30 jours)
            $retention_bots = $request->variable('stats_retention_bots', 5);
            $retention_bots = max(1, min(30, (int)$retention_bots));
            $config->set('bastien59_stats_retention_bots', $retention_bots);

            // Timeout session (5-120 minutes)
            $session_timeout = $request->variable('stats_session_timeout', 15);
            $session_timeout = max(5, min(120, (int)$session_timeout));
            $config->set('bastien59_stats_session_timeout', $session_timeout * 60); // Stocké en secondes

            // Duree max de regroupement des sessions membres (1-168 heures)
            $member_session_max_hours = $request->variable('stats_member_session_max_hours', 24);
            $member_session_max_hours = max(1, min(168, (int)$member_session_max_hours));
            $config->set('bastien59_stats_member_session_max_hours', $member_session_max_hours);

            // Extension activée
            $enabled = $request->variable('stats_enabled', 1);
            $config->set('bastien59_stats_enabled', $enabled ? 1 : 0);

            // Signal différé CN: pas d'interaction après 5 minutes
            $cn_no_interaction_enabled = $request->variable('stats_cn_no_interaction_enabled', 1);
            $config->set('bastien59_stats_cn_no_interaction_enabled', $cn_no_interaction_enabled ? 1 : 0);

            // Chemin du log de sécurité (fixe pour rester aligné avec fail2ban)
            $config->set('bastien59_stats_audit_log_path', self::AUDIT_LOG_PATH);

            // Seuil Chrome (50-200)
            $chrome_threshold = $request->variable('stats_chrome_threshold', 130);
            $chrome_threshold = max(50, min(200, (int)$chrome_threshold));
            $config->set('bastien59_stats_chrome_threshold', $chrome_threshold);

            // Seuil Firefox (10-200)
            $firefox_threshold = $request->variable('stats_firefox_threshold', 30);
            $firefox_threshold = max(10, min(200, (int)$firefox_threshold));
            $config->set('bastien59_stats_firefox_threshold', $firefox_threshold);

            // Pages sans screen_res (2-20)
            $noscreenres_pages = $request->variable('stats_noscreenres_pages', 3);
            $noscreenres_pages = max(2, min(20, (int)$noscreenres_pages));
            $config->set('bastien59_stats_noscreenres_pages', $noscreenres_pages);

            // Préfixe IPv4 du cache géoloc (/16 à /32)
            $geo_ipv4_prefix_len = $request->variable('stats_geo_ipv4_prefix_len', 24);
            $geo_ipv4_prefix_len = max(16, min(32, (int)$geo_ipv4_prefix_len));
            $config->set('bastien59_stats_geo_ipv4_prefix_len', $geo_ipv4_prefix_len);

            // Préfixe IPv6 du cache géoloc (/32 à /64, défaut /48)
            $geo_ipv6_prefix_len = $request->variable('stats_geo_ipv6_prefix_len', 48);
            $geo_ipv6_prefix_len = max(32, min(64, (int)$geo_ipv6_prefix_len));
            $config->set('bastien59_stats_geo_ipv6_prefix_len', $geo_ipv6_prefix_len);

            trigger_error($user->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
        }

        // Taille de la table pour affichage dans l'ACP
        global $db, $table_prefix;
        $result_size = $db->sql_query(
            'SELECT TABLE_ROWS, ROUND((DATA_LENGTH + INDEX_LENGTH)/1024/1024, 1) AS size_mb
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'' . $table_prefix . 'bastien59_stats\''
        );
        $size_row = $db->sql_fetchrow($result_size);
        $db->sql_freeresult($result_size);

        $template->assign_vars([
            'U_ACTION'               => $this->u_action,
            'STATS_ENABLED'          => $config['bastien59_stats_enabled'] ?? 1,
            'STATS_TABLE_ROWS'       => number_format((int)($size_row['TABLE_ROWS'] ?? 0), 0, ',', ' '),
            'STATS_TABLE_SIZE_MB'    => $size_row['size_mb'] ?? '?',
            'STATS_RETENTION_BOTS_WARNING' => ((int)($config['bastien59_stats_retention_bots'] ?? 5)) > 7 ? 1 : 0,
            'STATS_CN_NO_INTERACTION_ENABLED' => isset($config['bastien59_stats_cn_no_interaction_enabled'])
                ? (int)$config['bastien59_stats_cn_no_interaction_enabled']
                : 1,
            'STATS_CN_NO_INTERACTION_COUNTRIES' => 'CN',
            'STATS_RETENTION_DAYS'   => $config['bastien59_stats_retention'] ?? 30,
            'STATS_RETENTION_BOTS'   => $config['bastien59_stats_retention_bots'] ?? 5,
            'STATS_SESSION_TIMEOUT'  => (int)(($config['bastien59_stats_session_timeout'] ?? 900) / 60),
            'STATS_MEMBER_SESSION_MAX_HOURS' => max(
                1,
                min(168, (int)($config['bastien59_stats_member_session_max_hours'] ?? 24))
            ),
            'STATS_CHROME_THRESHOLD' => $config['bastien59_stats_chrome_threshold'] ?? 130,
            'STATS_FIREFOX_THRESHOLD'=> $config['bastien59_stats_firefox_threshold'] ?? 30,
            'STATS_NOSCREENRES_PAGES'=> $config['bastien59_stats_noscreenres_pages'] ?? 3,
            'STATS_GEO_IPV4_PREFIX_LEN' => $config['bastien59_stats_geo_ipv4_prefix_len'] ?? 24,
            'STATS_GEO_IPV6_PREFIX_LEN' => $config['bastien59_stats_geo_ipv6_prefix_len'] ?? 48,
            'STATS_AUDIT_LOG_PATH'   => self::AUDIT_LOG_PATH,
            'STATS_AUDIT_LOG_STATUS' => $this->check_log_status(self::AUDIT_LOG_PATH),
        ]);
    }

    private function check_log_status($path)
    {
        if (file_exists($path)) {
            return is_writable($path) ? 'ok' : 'not_writable';
        }
        $dir = dirname($path);
        if (is_dir($dir) && is_writable($dir)) {
            return 'not_exists'; // File doesn't exist but directory is writable
        }
        return 'dir_not_writable';
    }
}
