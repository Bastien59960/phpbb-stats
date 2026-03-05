<?php
/**
 * Stats Extension for phpBB - Migration 1.7.0
 * Adds visitor cookie hash storage for multi-IP clone detection.
 *
 * @package bastien59960/stats
 * @version 1.7.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_7_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->db_tools->sql_column_exists($this->table_prefix . 'bastien59_stats', 'visitor_cookie_hash');
    }

    static public function depends_on()
    {
        return ['\bastien59960\stats\migrations\release_1_6_0'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'visitor_cookie_hash' => ['VCHAR:64', ''],
                ],
            ],
            'add_index' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'visitor_cookie_hash_time' => ['visitor_cookie_hash', 'visit_time'],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_keys' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'visitor_cookie_hash_time',
                ],
            ],
            'drop_columns' => [
                $this->table_prefix . 'bastien59_stats' => ['visitor_cookie_hash'],
            ],
        ];
    }
}

