<?php
/**
 * Stats Extension for phpBB - Migration 1.1.0
 * Adds signals column to track detection signals per visit.
 *
 * @package bastien59960/stats
 * @version 1.1.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_1_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->db_tools->sql_column_exists($this->table_prefix . 'bastien59_stats', 'signals');
    }

    static public function depends_on()
    {
        return ['\bastien59960\stats\migrations\release_1_0_0'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'signals' => ['VCHAR:255', ''],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'bastien59_stats' => ['signals'],
            ],
        ];
    }
}
