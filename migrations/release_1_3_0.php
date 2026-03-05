<?php
/**
 * Stats Extension for phpBB - Migration 1.3.0
 * Adds advanced AJAX telemetry columns for stronger bot behavior detection.
 *
 * @package bastien59960/stats
 * @version 1.3.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_3_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->db_tools->sql_column_exists($this->table_prefix . 'bastien59_stats', 'ajax_interact_mask')
            && $this->db_tools->sql_column_exists($this->table_prefix . 'bastien59_stats', 'ajax_first_scroll_ms')
            && $this->db_tools->sql_column_exists($this->table_prefix . 'bastien59_stats', 'ajax_scroll_events')
            && $this->db_tools->sql_column_exists($this->table_prefix . 'bastien59_stats', 'ajax_scroll_max_y')
            && $this->db_tools->sql_column_exists($this->table_prefix . 'bastien59_stats', 'ajax_webdriver')
            && $this->db_tools->sql_column_exists($this->table_prefix . 'bastien59_stats', 'ajax_telemetry_ver');
    }

    static public function depends_on()
    {
        return ['\bastien59960\stats\migrations\release_1_2_0'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'ajax_interact_mask' => ['UINT:3', 0],
                    'ajax_first_scroll_ms' => ['UINT:11', 0],
                    'ajax_scroll_events' => ['UINT:11', 0],
                    'ajax_scroll_max_y' => ['UINT:11', 0],
                    'ajax_webdriver' => ['BOOL', 0],
                    'ajax_telemetry_ver' => ['UINT:2', 0],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'ajax_interact_mask',
                    'ajax_first_scroll_ms',
                    'ajax_scroll_events',
                    'ajax_scroll_max_y',
                    'ajax_webdriver',
                    'ajax_telemetry_ver',
                ],
            ],
        ];
    }
}
