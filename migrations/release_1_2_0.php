<?php
/**
 * Stats Extension for phpBB - Migration 1.2.0
 * Adds AJAX telemetry columns for scroll and screen resolution comparison.
 *
 * @package bastien59960/stats
 * @version 1.2.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_2_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->db_tools->sql_column_exists($this->table_prefix . 'bastien59_stats', 'screen_res_ajax')
            && $this->db_tools->sql_column_exists($this->table_prefix . 'bastien59_stats', 'scroll_down_ajax')
            && $this->db_tools->sql_column_exists($this->table_prefix . 'bastien59_stats', 'ajax_seen_time');
    }

    static public function depends_on()
    {
        return ['\bastien59960\stats\migrations\release_1_1_0'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'screen_res_ajax' => ['VCHAR:20', ''],
                    'scroll_down_ajax' => ['BOOL', 0],
                    'ajax_seen_time' => ['UINT:11', 0],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'bastien59_stats' => ['screen_res_ajax', 'scroll_down_ajax', 'ajax_seen_time'],
            ],
        ];
    }
}
