<?php
namespace bastien59960\stats\acp;

class settings_info
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
        if (!isset($user->lang['ACP_STATS_SETTINGS'])) {
            $user->add_lang_ext('bastien59960/stats', 'acp/info_acp_stats');
        }
        
        return [
            'filename'  => '\bastien59960\stats\acp\settings_module',
            'title'     => 'ACP_STATS_SETTINGS',
            'modes'     => [
                'settings' => [
                    'title' => 'ACP_STATS_SETTINGS',
                    'auth'  => 'ext_bastien59960/stats && acl_a_board',
                    'cat'   => ['ACP_STATS_SETTINGS']
                ],
            ],
        ];
    }
}
