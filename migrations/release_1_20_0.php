<?php
/**
 * Stats Extension for phpBB - Migration 1.20.0
 * Makes the security audit log path configurable via ACP.
 * On existing installs the current value (set by release_1_19_0) is preserved as-is.
 *
 * @package bastien59960/stats
 * @version 1.20.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_20_0 extends \phpbb\db\migration\container_aware_migration
{
    const DEFAULT_AUDIT_LOG_PATH = '/var/log/security_audit.log';

    public function effectively_installed()
    {
        // Consider done as long as the config key exists (whatever its current value).
        // On fresh installs update_data() will set the default; on upgraded installs
        // the admin-configured value is kept untouched.
        return isset($this->config['bastien59_stats_audit_log_path']);
    }

    public static function depends_on()
    {
        return ['\\bastien59960\\stats\\migrations\\release_1_19_0'];
    }

    public function update_schema()
    {
        return [];
    }

    public function update_data()
    {
        return [
            // config.add is a no-op when the key already exists (safe for upgrades).
            ['config.add', ['bastien59_stats_audit_log_path', self::DEFAULT_AUDIT_LOG_PATH]],
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
}
