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

        $this->tpl_name = 'acp_stats_body';
        $this->page_title = $user->lang('ACP_STATS');

        $controller = $phpbb_container->get('bastien59960.stats.acp.controller');
        $controller->display($this->u_action);
    }
}