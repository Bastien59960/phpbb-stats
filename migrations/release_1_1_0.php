<?php
/**
 * Stats Extension for phpBB - Migration 1.1.0
 * Adds signals column + configurable detection thresholds.
 *
 * @package bastien59960/stats
 * @version 1.1.0
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\migrations;

class release_1_1_0 extends \phpbb\db\migration\migration
{
    public function effectively_installed()
    {
        return $this->db_tools->sql_column_exists($this->table_prefix . 'bastien59_stats', 'signals');
    }

    static public function depends_on()
    {
        return ['\bastien59960\stats\migrations\release_1_0_0'];
    }

    public function update_schema()
    {
        return [
            'add_columns' => [
                $this->table_prefix . 'bastien59_stats' => [
                    'signals' => ['VCHAR:255', ''],
                ],
            ],
        ];
    }

    public function revert_schema()
    {
        return [
            'drop_columns' => [
                $this->table_prefix . 'bastien59_stats' => ['signals'],
            ],
        ];
    }

    public function update_data()
    {
        return [
            // Seuil Chrome : versions < cette valeur = signal old_chrome (défaut: 130 = oct 2024)
            ['config.add', ['bastien59_stats_chrome_threshold', 130]],
            // Seuil Firefox : versions < cette valeur = signal old_firefox (défaut: 30 = 2014)
            ['config.add', ['bastien59_stats_firefox_threshold', 30]],
            // Pages sans screen_res avant signal no_screen_res (défaut: 3)
            ['config.add', ['bastien59_stats_noscreenres_pages', 3]],
            // Chemin du fichier de log sécurité (bridge fail2ban)
            ['config.add', ['bastien59_stats_audit_log_path', '/var/log/security_audit.log']],
        ];
    }

    public function revert_data()
    {
        return [
            ['config.remove', ['bastien59_stats_chrome_threshold']],
            ['config.remove', ['bastien59_stats_firefox_threshold']],
            ['config.remove', ['bastien59_stats_noscreenres_pages']],
            ['config.remove', ['bastien59_stats_audit_log_path']],
        ];
    }
}
