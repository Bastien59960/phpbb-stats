<?php
/**
 * Stats Extension for phpBB - Unified Migration
 * Creates all schema, config, and ACP modules in a single migration.
 *
 * @package bastien59960/stats
 * @version 1.1.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_0_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'bastien59_stats');
    }

    static public function depends_on()
    {
        return ['\phpbb\db\migration\data\v330\v330'];
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'COLUMNS' => [
                        'log_id'        => ['UINT', null, 'auto_increment'],
                        'session_id'    => ['VCHAR:32', ''],
                        'user_id'       => ['UINT', 0],
                        'user_ip'       => ['VCHAR:40', ''],
                        'user_agent'    => ['VCHAR:255', ''],
                        'user_os'       => ['VCHAR:50', 'Unknown'],
                        'user_device'   => ['VCHAR:50', 'Desktop'],
                        'screen_res'    => ['VCHAR:20', ''],
                        'is_bot'        => ['BOOL', 0],
                        'bot_source'    => ['VCHAR:20', ''],
                        'country_code'  => ['VCHAR:5', ''],
                        'country_name'  => ['VCHAR:100', ''],
                        'hostname'      => ['VCHAR:255', ''],
                        'visit_time'    => ['UINT:11', 0],
                        'page_url'      => ['TEXT', ''],
                        'page_title'    => ['VCHAR:255', ''],
                        'referer'       => ['TEXT', ''],
                        'referer_type'  => ['VCHAR:50', ''],
                        'duration'      => ['UINT', 0],
                        'is_first_visit'=> ['BOOL', 0],
                    ],
                    'PRIMARY_KEY' => 'log_id',
                    'KEYS' => [
                        'session_id'  => ['INDEX', 'session_id'],
                        'visit_time'  => ['INDEX', 'visit_time'],
                        'user_id'     => ['INDEX', 'user_id'],
                        'user_ip'     => ['INDEX', 'user_ip'],
                        'is_bot'      => ['INDEX', 'is_bot'],
                    ],
                ],
                $this->table_prefix . 'bastien59_stats_geo_cache' => [
                    'COLUMNS' => [
                        'ip_address'    => ['VCHAR:45', ''],
                        'country_code'  => ['VCHAR:5', ''],
                        'country_name'  => ['VCHAR:100', ''],
                        'city'          => ['VCHAR:100', ''],
                        'hostname'      => ['VCHAR:255', ''],
                        'cached_time'   => ['UINT:11', 0],
                    ],
                    'PRIMARY_KEY' => 'ip_address',
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'bastien59_stats',
                $this->table_prefix . 'bastien59_stats_geo_cache',
            ],
        ];
    }

    public function update_data()
    {
        return [
            // Config
            ['config.add', ['bastien59_stats_retention', 30]],
            ['config.add', ['bastien59_stats_enabled', 1]],
            ['config.add', ['bastien59_stats_retention_bots', 5]],

            // Créer la catégorie principale APRES Extensions (ACP_CAT_DOT_MODS)
            ['custom', [[$this, 'create_stats_category']]],

            // Module principal dans la nouvelle catégorie
            ['module.add', ['acp', 'ACP_CAT_STATS', 'ACP_STATS']],
            ['module.add', ['acp', 'ACP_STATS', [
                'module_basename' => '\bastien59960\stats\acp\main_module',
                'modes' => ['index'],
            ]]],

            // Module de réglages dans Extensions (sous ACP_CAT_DOT_MODS)
            ['module.add', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_STATS_SETTINGS']],
            ['module.add', ['acp', 'ACP_STATS_SETTINGS', [
                'module_basename' => '\bastien59960\stats\acp\settings_module',
                'modes' => ['settings'],
            ]]],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['bastien59_stats_retention']],
            ['config.remove', ['bastien59_stats_enabled']],
            ['config.remove', ['bastien59_stats_retention_bots']],

            ['module.remove', ['acp', 'ACP_STATS_SETTINGS', [
                'module_basename' => '\bastien59960\stats\acp\settings_module',
                'modes' => ['settings'],
            ]]],
            ['module.remove', ['acp', 'ACP_CAT_DOT_MODS', 'ACP_STATS_SETTINGS']],

            ['module.remove', ['acp', 'ACP_STATS', [
                'module_basename' => '\bastien59960\stats\acp\main_module',
                'modes' => ['index'],
            ]]],
            ['module.remove', ['acp', 'ACP_CAT_STATS', 'ACP_STATS']],

            ['custom', [[$this, 'remove_stats_category']]],
        ];
    }

    public function create_stats_category()
    {
        // Vérifier si la catégorie existe déjà
        $sql = 'SELECT module_id FROM ' . $this->table_prefix . "modules
                WHERE module_langname = 'ACP_CAT_STATS'
                AND module_class = 'acp'
                AND module_basename = ''";
        $result = $this->db->sql_query($sql);
        $exists = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($exists) {
            return;
        }

        // Récupérer la position de ACP_CAT_DOT_MODS (Extensions)
        $sql = 'SELECT module_id, right_id FROM ' . $this->table_prefix . "modules
                WHERE module_langname = 'ACP_CAT_DOT_MODS'
                AND module_class = 'acp'
                AND parent_id = 0";
        $result = $this->db->sql_query($sql);
        $extensions_cat = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($extensions_cat) {
            $ref_right = (int)$extensions_cat['right_id'];
        } else {
            // Fallback: trouver le max right_id
            $sql = 'SELECT MAX(right_id) as max_right FROM ' . $this->table_prefix . "modules
                    WHERE module_class = 'acp'";
            $result = $this->db->sql_query($sql);
            $max = $this->db->sql_fetchrow($result);
            $this->db->sql_freeresult($result);
            $ref_right = (int)($max['max_right'] ?? 0);
        }

        $new_left = $ref_right + 1;
        $new_right = $ref_right + 2;

        // Décaler tous les modules avec left_id/right_id > ref_right
        $sql = 'UPDATE ' . $this->table_prefix . "modules
                SET left_id = CASE WHEN left_id > {$ref_right} THEN left_id + 2 ELSE left_id END,
                    right_id = CASE WHEN right_id > {$ref_right} THEN right_id + 2 ELSE right_id END
                WHERE module_class = 'acp'";
        $this->db->sql_query($sql);

        // Insérer la nouvelle catégorie
        $sql_ary = [
            'module_class'    => 'acp',
            'module_langname' => 'ACP_CAT_STATS',
            'module_mode'     => '',
            'module_basename' => '',
            'module_auth'     => '',
            'left_id'         => $new_left,
            'right_id'        => $new_right,
            'module_enabled'  => 1,
            'module_display'  => 1,
        ];
        $sql = 'INSERT INTO ' . $this->table_prefix . 'modules ' . $this->db->sql_build_array('INSERT', $sql_ary);
        $this->db->sql_query($sql);
    }

    public function remove_stats_category()
    {
        $sql = 'SELECT module_id, left_id, right_id FROM ' . $this->table_prefix . "modules
                WHERE module_langname = 'ACP_CAT_STATS'
                AND module_class = 'acp'
                AND module_basename = ''";
        $result = $this->db->sql_query($sql);
        $category = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$category) {
            return;
        }

        $left_id = (int)$category['left_id'];
        $right_id = (int)$category['right_id'];
        $width = $right_id - $left_id + 1;

        $sql = 'DELETE FROM ' . $this->table_prefix . "modules
                WHERE module_class = 'acp'
                AND left_id >= {$left_id}
                AND right_id <= {$right_id}";
        $this->db->sql_query($sql);

        $sql = 'UPDATE ' . $this->table_prefix . "modules
                SET left_id = CASE WHEN left_id > {$right_id} THEN left_id - {$width} ELSE left_id END,
                    right_id = CASE WHEN right_id > {$right_id} THEN right_id - {$width} ELSE right_id END
                WHERE module_class = 'acp'";
        $this->db->sql_query($sql);
    }
}
