<?php
/**
 * Stats Extension for phpBB - Migration 1.14.0
 * Adds ACP toggle for CN deferred no-interaction signal.
 *
 * @package bastien59960/stats
 * @version 1.14.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_14_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['bastien59_stats_cn_no_interaction_enabled']);
    }

    static public function depends_on()
    {
        return ['\\bastien59960\\stats\\migrations\\release_1_13_0'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['bastien59_stats_cn_no_interaction_enabled', 1]],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['bastien59_stats_cn_no_interaction_enabled']],
        ];
    }
}
