<?php
/**
 * Stats Extension for phpBB - Migration 1.9.0
 * Adds cursor telemetry columns and async geo lookup settings.
 *
 * @package bastien59960/stats
 * @version 1.9.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_9_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        $table = $this->table_prefix . 'bastien59_stats';

        return $this->db_tools->sql_column_exists($table, 'cursor_track_points')
            && $this->db_tools->sql_column_exists($table, 'cursor_track_duration_ms')
            && $this->db_tools->sql_column_exists($table, 'cursor_track_path')
            && $this->db_tools->sql_column_exists($table, 'cursor_click_points')
            && $this->db_tools->sql_column_exists($table, 'cursor_device_class')
            && $this->db_tools->sql_column_exists($table, 'cursor_viewport')
            && $this->db_tools->sql_column_exists($table, 'cursor_total_distance')
            && $this->db_tools->sql_column_exists($table, 'cursor_avg_speed')
            && $this->db_tools->sql_column_exists($table, 'cursor_max_speed')
            && $this->db_tools->sql_column_exists($table, 'cursor_direction_changes')
            && $this->db_tools->sql_column_exists($table, 'cursor_linearity')
            && $this->db_tools->sql_column_exists($table, 'cursor_click_count')
            && isset($this->config['bastien59_stats_geo_cache_ttl_days'])
            && isset($this->config['bastien59_stats_geo_async_interval'])
            && isset($this->config['bastien59_stats_geo_async_batch'])
            && isset($this->config['bastien59_stats_geo_async_last_run'])
            && isset($this->config['bastien59_stats_cursor_capture_ms']);
    }

    static public function depends_on()
    {
        return ['\\bastien59960\\stats\\migrations\\release_1_8_0'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'cursor_track_points' => ['UINT:11', 0],
                    'cursor_track_duration_ms' => ['UINT:11', 0],
                    'cursor_track_path' => ['TEXT', ''],
                    'cursor_click_points' => ['TEXT', ''],
                    'cursor_device_class' => ['VCHAR:16', ''],
                    'cursor_viewport' => ['VCHAR:20', ''],
                    'cursor_total_distance' => ['UINT:11', 0],
                    'cursor_avg_speed' => ['UINT:11', 0],
                    'cursor_max_speed' => ['UINT:11', 0],
                    'cursor_direction_changes' => ['UINT:11', 0],
                    'cursor_linearity' => ['UINT:3', 0],
                    'cursor_click_count' => ['UINT:11', 0],
                ],
            ],
            'add_index' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'cursor_points_time' => ['cursor_track_points', 'visit_time'],
                    'first_visit_country_time' => ['is_first_visit', 'country_code', 'visit_time'],
                ],
                $this->table_prefix . 'bastien59_stats_geo_cache' => [
                    'cached_time' => ['cached_time'],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_keys' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'cursor_points_time',
                    'first_visit_country_time',
                ],
                $this->table_prefix . 'bastien59_stats_geo_cache' => [
                    'cached_time',
                ],
            ],
            'drop_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'cursor_track_points',
                    'cursor_track_duration_ms',
                    'cursor_track_path',
                    'cursor_click_points',
                    'cursor_device_class',
                    'cursor_viewport',
                    'cursor_total_distance',
                    'cursor_avg_speed',
                    'cursor_max_speed',
                    'cursor_direction_changes',
                    'cursor_linearity',
                    'cursor_click_count',
                ],
            ],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['bastien59_stats_geo_cache_ttl_days', 45]],
            ['config.add', ['bastien59_stats_geo_async_interval', 300]],
            ['config.add', ['bastien59_stats_geo_async_batch', 30]],
            ['config.add', ['bastien59_stats_geo_async_last_run', 0]],
            ['config.add', ['bastien59_stats_cursor_capture_ms', 3500]],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['bastien59_stats_geo_cache_ttl_days']],
            ['config.remove', ['bastien59_stats_geo_async_interval']],
            ['config.remove', ['bastien59_stats_geo_async_batch']],
            ['config.remove', ['bastien59_stats_geo_async_last_run']],
            ['config.remove', ['bastien59_stats_cursor_capture_ms']],
        ];
    }
}
