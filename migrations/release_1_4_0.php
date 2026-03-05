<?php
/**
 * Stats Extension for phpBB - Migration 1.4.0
 * Adds behavior-learning tables based on registered users metrics.
 *
 * @package bastien59960/stats
 * @version 1.4.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_4_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'bastien59_stats_behavior_profile')
            && $this->db_tools->sql_table_exists($this->table_prefix . 'bastien59_stats_behavior_seen')
            && isset($this->config['bastien59_stats_learning_enabled'])
            && isset($this->config['bastien59_stats_learning_min_samples']);
    }

    static public function depends_on()
    {
        return ['\bastien59960\stats\migrations\release_1_3_0'];
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'bastien59_stats_behavior_profile' => [
                    'COLUMNS' => [
                        'profile_key'         => ['VCHAR:64', ''],
                        'profile_label'       => ['VCHAR:120', ''],
                        'sample_count'        => ['UINT:11', 0],
                        'avg_first_scroll_ms' => ['UINT:11', 0],
                        'avg_scroll_events'   => ['UINT:11', 0],
                        'avg_scroll_max_y'    => ['UINT:11', 0],
                        'avg_interact_score'  => ['UINT:3', 0],
                        'no_interact_hits'    => ['UINT:11', 0],
                        'fast_scroll_hits'    => ['UINT:11', 0],
                        'jump_scroll_hits'    => ['UINT:11', 0],
                        'updated_time'        => ['UINT:11', 0],
                        'created_time'        => ['UINT:11', 0],
                    ],
                    'PRIMARY_KEY' => 'profile_key',
                    'KEYS' => [
                        'sample_count' => ['INDEX', 'sample_count'],
                        'updated_time' => ['INDEX', 'updated_time'],
                    ],
                ],
                $this->table_prefix . 'bastien59_stats_behavior_seen' => [
                    'COLUMNS' => [
                        'session_id'  => ['VCHAR:32', ''],
                        'profile_key' => ['VCHAR:64', ''],
                        'learned_time'=> ['UINT:11', 0],
                    ],
                    'PRIMARY_KEY' => 'session_id',
                    'KEYS' => [
                        'profile_key' => ['INDEX', 'profile_key'],
                        'learned_time' => ['INDEX', 'learned_time'],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'bastien59_stats_behavior_profile',
                $this->table_prefix . 'bastien59_stats_behavior_seen',
            ],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['bastien59_stats_learning_enabled', 1]],
            ['config.add', ['bastien59_stats_learning_min_samples', 25]],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['bastien59_stats_learning_enabled']],
            ['config.remove', ['bastien59_stats_learning_min_samples']],
        ];
    }
}

