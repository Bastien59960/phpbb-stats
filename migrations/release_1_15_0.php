<?php
/**
 * Stats Extension for phpBB - Migration 1.15.0
 * Adds ACP setting for max member session window (hours).
 *
 * @package bastien59960/stats
 * @version 1.15.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_15_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['bastien59_stats_member_session_max_hours']);
    }

    static public function depends_on()
    {
        return ['\\bastien59960\\stats\\migrations\\release_1_14_0'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['bastien59_stats_member_session_max_hours', 24]],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['bastien59_stats_member_session_max_hours']],
        ];
    }
}
