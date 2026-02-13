<?php
namespace bastien59960\stats\acp;

class settings_module
{
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

            // Extension activée
            $enabled = $request->variable('stats_enabled', 1);
            $config->set('bastien59_stats_enabled', $enabled ? 1 : 0);

            // Chemin du log de sécurité
            $audit_log_path = $request->variable('stats_audit_log_path', '/var/log/security_audit.log');
            $audit_log_path = trim($audit_log_path);
            if (!empty($audit_log_path)) {
                $config->set('bastien59_stats_audit_log_path', $audit_log_path);
            }

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

            trigger_error($user->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
        }

        $template->assign_vars([
            'U_ACTION'               => $this->u_action,
            'STATS_ENABLED'          => $config['bastien59_stats_enabled'] ?? 1,
            'STATS_RETENTION_DAYS'   => $config['bastien59_stats_retention'] ?? 30,
            'STATS_RETENTION_BOTS'   => $config['bastien59_stats_retention_bots'] ?? 5,
            'STATS_SESSION_TIMEOUT'  => (int)(($config['bastien59_stats_session_timeout'] ?? 900) / 60),
            'STATS_CHROME_THRESHOLD' => $config['bastien59_stats_chrome_threshold'] ?? 130,
            'STATS_FIREFOX_THRESHOLD'=> $config['bastien59_stats_firefox_threshold'] ?? 30,
            'STATS_NOSCREENRES_PAGES'=> $config['bastien59_stats_noscreenres_pages'] ?? 3,
            'STATS_AUDIT_LOG_PATH'   => $config['bastien59_stats_audit_log_path'] ?? '/var/log/security_audit.log',
            'STATS_AUDIT_LOG_STATUS' => $this->check_log_status($config['bastien59_stats_audit_log_path'] ?? '/var/log/security_audit.log'),
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
