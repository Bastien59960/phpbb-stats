<?php
namespace bastien59960\stats\acp;

class main_module
{
    public $u_action;
    public $tpl_name;
    public $page_title;

    public function main($id, $mode)
    {
        global $phpbb_container, $user;

        // Charger les fichiers de langue
        $user->add_lang_ext('bastien59960/stats', 'acp/info_acp_stats');

        $controller = $phpbb_container->get('bastien59960.stats.acp.controller');

        if ($mode === 'behavior')
        {
            $this->tpl_name = 'acp_stats_behavior';
            $this->page_title = $user->lang('ACP_STATS_BEHAVIOR');
            $controller->display_behavior($this->u_action);
            return;
        }

        $this->tpl_name = 'acp_stats_body';
        $this->page_title = $user->lang('ACP_STATS');
        $controller->display($this->u_action);
    }
}
