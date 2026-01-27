<?php
/**
 * Visitor Statistics Extension for phpBB
 *
 * @package bastien59960/stats
 * @license GPL-2.0-only
 */

namespace bastien59960\stats;

class ext extends \phpbb\extension\base
{
    /**
     * Get extension version
     *
     * @return string
     */
    public function get_version()
    {
        return '1.1.0';
    }
}