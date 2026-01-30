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

	// Filters
	'STATS_PERIOD'					=> 'Period:',
	'STATS_1_HOUR'					=> '1 hour',
	'STATS_6_HOURS'					=> '6 hours',
	'STATS_12_HOURS'				=> '12 hours',
	'STATS_24_HOURS'				=> '24 hours',
	'STATS_48_HOURS'				=> '48 hours',
	'STATS_7_DAYS'					=> '7 days',
	'STATS_30_DAYS'					=> '30 days',
	'STATS_SHOW_BOTS'				=> 'Show bots',
	'STATS_LIMIT'					=> 'Limit:',

	// Stat cards
	'STATS_PAGEVIEWS_HUMANS'		=> 'Page views (humans)',
	'STATS_UNIQUE_VISITORS'			=> 'Unique visitors',
	'STATS_AVG_DURATION_HUMANS'		=> 'Avg. duration humans',
	'STATS_UNIQUE_BOTS'				=> 'Unique bots',
	'STATS_BOT_PAGEVIEWS'			=> 'Bot pages (%s%%)',
	'STATS_AVG_DURATION_BOTS'		=> 'Avg. duration bots',

	// Tabs
	'STATS_TAB_OVERVIEW'			=> 'Overview',
	'STATS_TAB_SESSIONS'			=> 'Sessions',
	'STATS_TAB_PAGES'				=> 'Pages',
	'STATS_TAB_MAP'					=> 'Map',

	// Overview
	'STATS_TRAFFIC_HUMANS'			=> 'Traffic sources (Humans)',
	'STATS_TRAFFIC_BOTS'			=> 'Traffic sources (Bots)',
	'STATS_OS_TITLE'				=> 'Operating systems',
	'STATS_DEVICES_TITLE'			=> 'Device types',
	'STATS_RES_TITLE'				=> 'Screen resolutions',
	'STATS_NO_DATA'					=> 'No data',
	'STATS_FULL_REFERERS'			=> 'Full external referers (clickable)',
	'STATS_COL_SOURCE_REFERER'		=> 'Source (referer)',
	'STATS_COL_DESTINATION'			=> 'Landing page',
	'STATS_COL_TYPE'				=> 'Type',
	'STATS_COL_VISITS'				=> 'Visits',
	'STATS_COL_SOURCE'				=> 'Source',
	'STATS_LABEL_BOT'				=> 'Bot',
	'STATS_LABEL_HUMAN'				=> 'Human',
	'STATS_NO_EXTERNAL_REFERER'		=> 'No external referer',

	// Sessions
	'STATS_FILTER_HUMANS'			=> 'Humans',
	'STATS_FILTER_ROBOTS'			=> 'Robots',
	'STATS_COL_VISITOR'				=> 'Visitor',
	'STATS_COL_DEVICE'				=> 'Device',
	'STATS_COL_LANDING_PAGE'		=> 'Landing page',
	'STATS_COL_PAGES'				=> 'Pages',
	'STATS_BADGE_BOT'				=> 'BOT',
	'STATS_BADGE_PHPBB'				=> 'phpBB DB',
	'STATS_BADGE_BEHAVIOR'			=> 'Behavior',
	'STATS_BADGE_BEHAVIOR_TITLE'	=> 'Suspicious behavior',
	'STATS_BADGE_UA'				=> 'Suspicious UA',
	'STATS_BADGE_UA_TITLE'			=> 'Suspicious User-Agent',
	'STATS_BADGE_GUEST'				=> 'Guest',
	'STATS_PAGES_COUNT'				=> '%d pages',
	'STATS_NO_SESSION'				=> 'No sessions recorded',
	'STATS_NO_SESSION_EXPLAIN'		=> 'Data will appear after the first visits.',

	// Pages
	'STATS_TOP_PAGES'				=> 'Most visited pages',
	'STATS_COL_PAGE'				=> 'Page',
	'STATS_COL_AVG_TIME'			=> 'Avg. time',
	'STATS_NO_PAGE_DATA'			=> 'No data available.',

	// Map
	'STATS_MAP_HUMANS'				=> 'Human visitors map',
	'STATS_MAP_BOTS'				=> 'Bots map',
	'STATS_LEGEND'					=> 'Legend:',
	'STATS_LEGEND_LOW'				=> 'Low',
	'STATS_LEGEND_MEDIUM'			=> 'Medium',
	'STATS_LEGEND_HIGH'				=> 'High',
	'STATS_TOP_COUNTRIES_HUMANS'	=> 'Top countries (Humans)',
	'STATS_TOP_COUNTRIES_BOTS'		=> 'Top countries (Bots)',
	'STATS_DISTRIBUTION_HUMANS'		=> 'Human distribution',
	'STATS_DISTRIBUTION_BOTS'		=> 'Bot distribution',
	'STATS_TOTAL_COUNTRIES'			=> 'Total countries:',
	'STATS_GEOLOCATED_VISITORS'		=> 'Geolocated visitors:',
	'STATS_GEOLOCATED_BOTS'		=> 'Geolocated bots:',
	'STATS_NO_GEO_DATA'				=> 'No geolocation data',
	'STATS_MAP_LOADING'				=> 'Loading...',
	'STATS_MAP_ERROR'				=> 'Map loading error.',
	'STATS_MAP_UNAVAILABLE'			=> 'jVectorMap unavailable.',
	'STATS_MAP_VISITOR'				=> 'visitor',
	'STATS_MAP_VISITORS'			=> 'visitors',
	'STATS_MAP_BOT_LABEL'			=> 'bot',
	'STATS_MAP_BOTS_LABEL'			=> 'bots',

	// Controller
	'STATS_GUEST'					=> 'Guest',
	'STATS_DIRECT_ACCESS'			=> 'Direct access',
	'STATS_USER_FALLBACK'			=> 'User #%d',
	'STATS_BOT_UNKNOWN'				=> 'Suspect bot (UA/behavior)',
]);
