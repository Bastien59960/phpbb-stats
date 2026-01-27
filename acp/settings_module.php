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

            trigger_error($user->lang('CONFIG_UPDATED') . adm_back_link($this->u_action));
        }

        $template->assign_vars([
            'U_ACTION'               => $this->u_action,
            'STATS_ENABLED'          => $config['bastien59_stats_enabled'] ?? 1,
            'STATS_RETENTION_DAYS'   => $config['bastien59_stats_retention'] ?? 30,
            'STATS_RETENTION_BOTS'   => $config['bastien59_stats_retention_bots'] ?? 5,
            'STATS_SESSION_TIMEOUT'  => (int)(($config['bastien59_stats_session_timeout'] ?? 900) / 60),
        ]);
    }
}
