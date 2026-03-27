<?php
/**
 * Stats Extension for phpBB - Migration 1.18.0
 * Persists the probabilistic bot/human model used by ACP session scoring.
 *
 * @package bastien59960/stats
 * @version 1.18.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_18_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->db_tools->sql_table_exists($this->table_prefix . 'bastien59_stats_probability_model');
    }

    static public function depends_on()
    {
        return ['\\bastien59960\\stats\\migrations\\release_1_17_0'];
    }

    public function update_schema()
    {
        return [
            'add_tables' => [
                $this->table_prefix . 'bastien59_stats_probability_model' => [
                    'COLUMNS' => [
                        'scope' => ['VCHAR:16', ''],
                        'actor_class' => ['VCHAR:16', ''],
                        'profile_hash' => ['VCHAR:32', ''],
                        'profile_key' => ['VCHAR:120', ''],
                        'feature_key' => ['VCHAR:64', ''],
                        'sample_count' => ['UINT:11', 0],
                        'hit_count' => ['UINT:11', 0],
                        'updated_time' => ['UINT:11', 0],
                    ],
                    'PRIMARY_KEY' => ['scope', 'actor_class', 'profile_hash', 'feature_key'],
                    'KEYS' => [
                        'updated_time' => ['INDEX', 'updated_time'],
                        'actor_scope' => ['INDEX', ['actor_class', 'scope']],
                    ],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_tables' => [
                $this->table_prefix . 'bastien59_stats_probability_model',
            ],
        ];
    }

    public function update_data()
    {
        return [
            ['config.add', ['bastien59_stats_probability_model_ttl', 300]],
            ['config.add', ['bastien59_stats_probability_model_days', 30]],
        ];
    }
}
