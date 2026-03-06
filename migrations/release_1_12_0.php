<?php
/**
 * Stats Extension for phpBB - Migration 1.12.0
 * Ensures session timeout config key exists in fresh installs.
 *
 * @package bastien59960/stats
 * @version 1.12.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_12_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['bastien59_stats_session_timeout']);
    }

    static public function depends_on()
    {
        return ['\\bastien59960\\stats\\migrations\\release_1_11_0'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['bastien59_stats_session_timeout', 900]],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['bastien59_stats_session_timeout']],
        ];
    }
}
