<?php
/**
 * Stats Extension for phpBB - Migration 1.19.0
 * Forces the security audit log path to /var/log/security_audit.log.
 *
 * @package bastien59960/stats
 * @version 1.19.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_19_0 extends \phpbb\db\migration\container_aware_migration
{
    const AUDIT_LOG_PATH = '/var/log/security_audit.log';

    public function effectively_installed()
    {
        return isset($this->config['bastien59_stats_audit_log_path'])
            && trim((string) $this->config['bastien59_stats_audit_log_path']) === self::AUDIT_LOG_PATH;
    }

    public static function depends_on()
    {
        return ['\\bastien59960\\stats\\migrations\\release_1_18_0'];
    }

    public function update_schema()
    {
        return [];
    }

    public function update_data()
    {
        return [
            ['custom', [[$this, 'force_audit_log_path']]],
        ];
    }

    public function revert_schema()
    {
        return [];
    }

    public function revert_data()
    {
        return [];
    }

    public function force_audit_log_path()
    {
        $this->config->set('bastien59_stats_audit_log_path', self::AUDIT_LOG_PATH);
        return true;
    }
}
