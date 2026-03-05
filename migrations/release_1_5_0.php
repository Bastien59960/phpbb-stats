<?php
/**
 * Stats Extension for phpBB - Migration 1.5.0
 * Adds ACP module mode for behavior-learning dashboard.
 *
 * @package bastien59960/stats
 * @version 1.5.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_5_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        $sql = 'SELECT module_id
                FROM ' . $this->table_prefix . 'modules
                WHERE module_class = \'acp\'
                AND module_basename = \'\\bastien59960\\stats\\acp\\main_module\'
                AND module_mode = \'behavior\'';
        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        return (bool) $row;
    }

    static public function depends_on()
    {
        return ['\bastien59960\stats\migrations\release_1_4_0'];
    }

    public function update_data()
    {
        return [
            ['module.add', ['acp', 'ACP_STATS', [
                'module_basename' => '\bastien59960\stats\acp\main_module',
                'modes' => ['behavior'],
            ]]],
        ];
    }

    public function revert_data()
    {
        return [
            ['module.remove', ['acp', 'ACP_STATS', [
                'module_basename' => '\bastien59960\stats\acp\main_module',
                'modes' => ['behavior'],
            ]]],
        ];
    }
}

