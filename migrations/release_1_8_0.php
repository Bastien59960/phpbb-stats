<?php
/**
 * Stats Extension for phpBB - Migration 1.8.0
 * Adds visitor cookie debug columns (preexisting + AJAX readback state).
 *
 * @package bastien59960/stats
 * @version 1.8.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_8_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        $table = $this->table_prefix . 'bastien59_stats';
        return $this->db_tools->sql_column_exists($table, 'visitor_cookie_preexisting')
            && $this->db_tools->sql_column_exists($table, 'visitor_cookie_ajax_state')
            && $this->db_tools->sql_column_exists($table, 'visitor_cookie_ajax_hash');
    }

    static public function depends_on()
    {
        return ['\bastien59960\stats\migrations\release_1_7_0'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'visitor_cookie_preexisting' => ['BOOL', 0],
                    'visitor_cookie_ajax_state' => ['UINT:1', 0],
                    'visitor_cookie_ajax_hash' => ['VCHAR:64', ''],
                ],
            ],
            'add_index' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'visitor_cookie_ajax_state_time' => ['visitor_cookie_ajax_state', 'visit_time'],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_keys' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'visitor_cookie_ajax_state_time',
                ],
            ],
            'drop_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'visitor_cookie_preexisting',
                    'visitor_cookie_ajax_state',
                    'visitor_cookie_ajax_hash',
                ],
            ],
        ];
    }
}

