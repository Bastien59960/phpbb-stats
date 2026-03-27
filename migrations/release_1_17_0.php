<?php
/**
 * Stats Extension for phpBB - Migration 1.17.0
 * Adds failed login attempt diagnostics captured server-side.
 *
 * @package bastien59960/stats
 * @version 1.17.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_17_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        $stats_table = $this->table_prefix . 'bastien59_stats';

        return $this->db_tools->sql_column_exists($stats_table, 'login_attempt_failed')
            && $this->db_tools->sql_column_exists($stats_table, 'login_attempt_username')
            && $this->db_tools->sql_column_exists($stats_table, 'login_attempt_error');
    }

    static public function depends_on()
    {
        return ['\\bastien59960\\stats\\migrations\\release_1_16_0'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'login_attempt_failed' => ['BOOL', 0],
                    'login_attempt_username' => ['VCHAR:255', ''],
                    'login_attempt_error' => ['VCHAR:64', ''],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'login_attempt_failed',
                    'login_attempt_username',
                    'login_attempt_error',
                ],
            ],
        ];
    }
}
