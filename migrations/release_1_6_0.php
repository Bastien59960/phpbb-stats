<?php
/**
 * Stats Extension for phpBB - Migration 1.6.0
 * Adds composite indexes to reduce hot-path query latency.
 *
 * @package bastien59960/stats
 * @version 1.6.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_6_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        $found = [];
        $sql = 'SHOW INDEX FROM ' . $this->table_prefix . "bastien59_stats
                WHERE Key_name IN ('user_ip_visit_time', 'is_first_visit_time')";
        $result = $this->db->sql_query($sql);
        while ($row = $this->db->sql_fetchrow($result)) {
            if (!empty($row['Key_name'])) {
                $found[(string) $row['Key_name']] = true;
            }
        }
        $this->db->sql_freeresult($result);

        return !empty($found['user_ip_visit_time']) && !empty($found['is_first_visit_time']);
    }

    static public function depends_on()
    {
        return ['\bastien59960\stats\migrations\release_1_5_0'];
    }

    public function update_schema()
    {
        return [
            'add_index' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'user_ip_visit_time' => ['user_ip', 'visit_time'],
                    'is_first_visit_time' => ['is_first_visit', 'visit_time'],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_keys' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'user_ip_visit_time',
                    'is_first_visit_time',
                ],
            ],
        ];
    }
}

