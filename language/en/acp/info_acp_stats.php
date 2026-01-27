<?php
/**
 * Visitor Statistics Extension for phpBB
 *
 * @package bastien59960/stats
 * @license GPL-2.0-only
 */

if (!defined('IN_PHPBB'))
{
	exit;
}

if (empty($lang) || !is_array($lang))
{
	$lang = [];
}

$lang = array_merge($lang, [
	// Main category (tab)
	'ACP_CAT_STATS'					=> 'Statistics',

	// Module titles (ACP menu)
	'ACP_STATS'						=> 'Visitor Statistics',
	'ACP_STATS_EXPLAIN'				=> 'Analytics Dashboard: Overview of visitor configurations and detailed navigation log.',
	'ACP_STATS_SETTINGS'			=> 'Statistics Settings',
	'ACP_STATS_SETTINGS_EXPLAIN'	=> 'Configure collection and retention settings for statistics.',

	// Main module
	'ACP_BASTIEN59_STATS_TITLE'		=> 'Visitor Statistics',
	'ACP_BASTIEN59_STATS_EXPLAIN'	=> 'Analytics Dashboard: Overview of visitor configurations and detailed navigation log.',
	'ACP_BASTIEN59_STATS_CLEAR'		=> 'Clear Statistics',
	'ACP_BASTIEN59_STATS_CONFIRM'	=> 'Are you sure you want to delete all statistics data?',
	'STATS_CLEARED'					=> 'Statistics have been successfully cleared.',

	// Settings
	'STATS_ENABLED'					=> 'Enable tracking',
	'STATS_ENABLED_EXPLAIN'			=> 'Enable or disable visitor statistics collection.',
	'STATS_RETENTION_DAYS'			=> 'Human retention (days)',
	'STATS_RETENTION_DAYS_EXPLAIN'	=> 'Number of days to keep human visitor data (1-365).',
	'STATS_RETENTION_BOTS'			=> 'Bot retention (days)',
	'STATS_RETENTION_BOTS_EXPLAIN'	=> 'Number of days to keep bot data (1-30).',
	'STATS_SESSION_TIMEOUT'			=> 'Session timeout (minutes)',
	'STATS_SESSION_TIMEOUT_EXPLAIN'	=> 'Inactivity duration before a new visit is considered a new session (5-120 minutes).',
]);
