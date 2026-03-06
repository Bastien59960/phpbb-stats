<?php
/**
 * Stats Extension for phpBB - Migration 1.11.0
 * Adds configurable IPv4 subnet prefix length for geolocation cache.
 *
 * @package bastien59960/stats
 * @version 1.11.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_11_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return isset($this->config['bastien59_stats_geo_ipv4_prefix_len']);
    }

    static public function depends_on()
    {
        return ['\\bastien59960\\stats\\migrations\\release_1_10_0'];
    }

    public function update_data()
    {
        return [
            ['config.add', ['bastien59_stats_geo_ipv4_prefix_len', 24]],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['bastien59_stats_geo_ipv4_prefix_len']],
        ];
    }
}
