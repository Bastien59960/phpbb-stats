<?php
/**
 * Stats Extension for phpBB - Migration 1.10.0
 * Adds reactions-assets telemetry columns used by learning profiles.
 *
 * @package bastien59960/stats
 * @version 1.10.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_10_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        $stats_table = $this->table_prefix . 'bastien59_stats';
        $profile_table = $this->table_prefix . 'bastien59_stats_behavior_profile';
        $seen_table = $this->table_prefix . 'bastien59_stats_behavior_seen';

        return $this->db_tools->sql_column_exists($stats_table, 'reactions_extension_expected')
            && $this->db_tools->sql_column_exists($stats_table, 'reactions_css_seen')
            && $this->db_tools->sql_column_exists($stats_table, 'reactions_js_seen')
            && $this->db_tools->sql_column_exists($profile_table, 'reactions_missing_hits')
            && $this->db_tools->sql_column_exists($seen_table, 'reactions_extension_expected')
            && $this->db_tools->sql_column_exists($seen_table, 'reactions_css_seen')
            && $this->db_tools->sql_column_exists($seen_table, 'reactions_js_seen');
    }

    static public function depends_on()
    {
        return ['\\bastien59960\\stats\\migrations\\release_1_9_0'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'reactions_extension_expected' => ['UINT:1', 0],
                    'reactions_css_seen' => ['UINT:1', 0],
                    'reactions_js_seen' => ['UINT:1', 0],
                ],
                $this->table_prefix . 'bastien59_stats_behavior_profile' => [
                    'reactions_missing_hits' => ['UINT:11', 0],
                ],
                $this->table_prefix . 'bastien59_stats_behavior_seen' => [
                    'reactions_extension_expected' => ['UINT:1', 0],
                    'reactions_css_seen' => ['UINT:1', 0],
                    'reactions_js_seen' => ['UINT:1', 0],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'reactions_extension_expected',
                    'reactions_css_seen',
                    'reactions_js_seen',
                ],
                $this->table_prefix . 'bastien59_stats_behavior_profile' => [
                    'reactions_missing_hits',
                ],
                $this->table_prefix . 'bastien59_stats_behavior_seen' => [
                    'reactions_extension_expected',
                    'reactions_css_seen',
                    'reactions_js_seen',
                ],
            ],
        ];
    }
}
