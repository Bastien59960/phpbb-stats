<?php
namespace bastien59960\stats\acp;

class main_info
{
    public function __construct()
    {
        global $phpbb_container;
        
        if (isset($phpbb_container)) {
            $user = $phpbb_container->get('user');
            $user->add_lang_ext('bastien59960/stats', 'acp/info_acp_stats');
        }
    }

    public function module()
    {
        // Forcer le chargement des langues pour le menu
        global $user;
        if (!isset($user->lang['ACP_BASTIEN59_STATS_TITLE'])) {
            $user->add_lang_ext('bastien59960/stats', 'acp/info_acp_stats');
        }
        
        return [
            'filename'  => '\bastien59960\stats\acp\main_module',
            'title'     => 'ACP_STATS',
            'modes'     => [
                'index' => [
                    'title' => 'ACP_STATS',
                    'auth'  => 'ext_bastien59960/stats && acl_a_board',
                    'cat'   => ['ACP_STATS']
                ],
            ],
        ];
    }
}
