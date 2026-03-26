<?php
/**
 * Stats Extension for phpBB - Migration 1.16.0
 * Adds Apache asset counters for session diagnostics.
 *
 * @package bastien59960/stats
 * @version 1.16.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_16_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        $stats_table = $this->table_prefix . 'bastien59_stats';

        return $this->db_tools->sql_column_exists($stats_table, 'apache_banner_hits')
            && $this->db_tools->sql_column_exists($stats_table, 'apache_rank_hits')
            && $this->db_tools->sql_column_exists($stats_table, 'apache_avatar_hits')
            && $this->db_tools->sql_column_exists($stats_table, 'apache_asset_scan_time');
    }

    static public function depends_on()
    {
        return ['\\bastien59960\\stats\\migrations\\release_1_15_0'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'apache_banner_hits' => ['UINT:11', 0],
                    'apache_rank_hits' => ['UINT:11', 0],
                    'apache_avatar_hits' => ['UINT:11', 0],
                    'apache_asset_scan_time' => ['UINT:11', 0],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'apache_banner_hits',
                    'apache_rank_hits',
                    'apache_avatar_hits',
                    'apache_asset_scan_time',
                ],
            ],
        ];
    }
}
