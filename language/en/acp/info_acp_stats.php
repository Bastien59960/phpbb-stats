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
	'ACP_STATS_BEHAVIOR'			=> 'Behaviors',
	'ACP_STATS_BEHAVIOR_EXPLAIN'	=> 'Compare learned profiles (registered users) against guest/bot sessions to refine detection.',
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

	// Behavior ACP module
	'STATS_BEHAVIOR_MIN_SAMPLES'	=> 'Min. samples:',
	'STATS_BEHAVIOR_PROFILE_LIMIT'	=> 'Profile limit:',
	'STATS_BEHAVIOR_LEARNING_STATUS'=> 'Learning',
	'STATS_BEHAVIOR_TABLES_STATUS'	=> 'Learning tables',
		'STATS_BEHAVIOR_NO_LEARNING_TABLES' => 'Learning tables unavailable. Run extension migrations.',
		'STATS_BEHAVIOR_GROUP_COMPARISON' => 'Members / Guests / Bots comparison',
		'STATS_BEHAVIOR_TELEMETRY_FOCUS' => 'Screen resolution & AJAX (members vs CN/ambiguous guests)',
		'STATS_BEHAVIOR_PROFILES'		=> 'Learned profiles (registered users baseline)',
		'STATS_BEHAVIOR_OUTLIERS'		=> 'Detected outlier signals (guests)',
		'STATS_BEHAVIOR_RECENT_CASES'	=> 'Recent sessions with behavior signals',
		'STATS_BEHAVIOR_GROUP_MEMBERS'	=> 'Members',
		'STATS_BEHAVIOR_GROUP_GUESTS'	=> 'Guests',
		'STATS_BEHAVIOR_GROUP_GUESTS_CN' => 'CN guests',
		'STATS_BEHAVIOR_GROUP_GUESTS_AMBIGUOUS' => 'Ambiguous guests (non FR/CO/CN)',
		'STATS_BEHAVIOR_GROUP_BOTS'		=> 'Bots',
		'STATS_BEHAVIOR_COL_GROUP'		=> 'Group',
		'STATS_BEHAVIOR_COL_PROFILE'	=> 'Profile',
		'STATS_BEHAVIOR_COL_SAMPLES'	=> 'Samples',
		'STATS_BEHAVIOR_COL_SESSIONS'	=> 'Sessions',
		'STATS_BEHAVIOR_COL_RES_ANY_RATE' => 'Resolution (any channel)',
		'STATS_BEHAVIOR_COL_RES_COOKIE_RATE' => 'Cookie resolution',
		'STATS_BEHAVIOR_COL_RES_AJAX_RATE' => 'AJAX resolution',
		'STATS_BEHAVIOR_COL_AJAX_RATE'	=> 'AJAX rate',
		'STATS_BEHAVIOR_COL_SCROLL_RATE'=> 'Scroll rate',
	'STATS_BEHAVIOR_COL_AVG_FIRST_SCROLL' => 'Avg first scroll',
	'STATS_BEHAVIOR_COL_AVG_EVENTS'	=> 'Avg scroll events',
	'STATS_BEHAVIOR_COL_AVG_MAXY'	=> 'Avg max scroll',
	'STATS_BEHAVIOR_COL_INTERACT_SCORE' => 'Interact score',
	'STATS_BEHAVIOR_COL_NO_INTERACT' => '% no interaction',
	'STATS_BEHAVIOR_COL_FAST'		=> '% fast scroll',
	'STATS_BEHAVIOR_COL_JUMP'		=> '% jump scroll',
	'STATS_BEHAVIOR_COL_UPDATED'	=> 'Updated',
	'STATS_BEHAVIOR_COL_SIGNAL'		=> 'Signal',
	'STATS_BEHAVIOR_COL_RATE'		=> 'Rate',
	'STATS_BEHAVIOR_COL_ZERO_INTERACT' => '% no-interact scroll',
		'STATS_BEHAVIOR_HELP_COL_GROUP' => 'Compared session type: registered members, guests, or bots.',
		'STATS_BEHAVIOR_HELP_COL_PROFILE' => 'Learned profile (OS / device / browser) built from registered members.',
		'STATS_BEHAVIOR_HELP_COL_SAMPLES' => 'Number of member samples used to build this profile.',
		'STATS_BEHAVIOR_HELP_COL_SESSIONS' => 'Number of sessions (first visit only) observed in the filtered period.',
		'STATS_BEHAVIOR_HELP_COL_RES_ANY_RATE' => 'Share of sessions with a screen resolution available via cookie or AJAX.',
		'STATS_BEHAVIOR_HELP_COL_RES_COOKIE_RATE' => 'Share of sessions with screen resolution from the initial cookie.',
		'STATS_BEHAVIOR_HELP_COL_RES_AJAX_RATE' => 'Share of sessions with screen resolution received via AJAX payload.',
		'STATS_BEHAVIOR_HELP_COL_AJAX_RATE' => 'Share of sessions that returned valid AJAX telemetry.',
		'STATS_BEHAVIOR_HELP_COL_SCROLL_RATE' => 'Share of sessions with down-scroll detected via AJAX.',
	'STATS_BEHAVIOR_HELP_COL_AVG_FIRST_SCROLL' => 'Average time before first scroll (milliseconds).',
	'STATS_BEHAVIOR_HELP_COL_AVG_EVENTS' => 'Average number of scroll events captured per session.',
	'STATS_BEHAVIOR_HELP_COL_AVG_MAXY' => 'Average maximum vertical scroll position reached (pixels).',
	'STATS_BEHAVIOR_HELP_COL_INTERACT_SCORE' => 'Average user interaction score (higher = more human-like profile).',
	'STATS_BEHAVIOR_HELP_COL_NO_INTERACT' => 'Share of sessions with no keyboard/mouse/touch interaction detected.',
	'STATS_BEHAVIOR_HELP_COL_FAST' => 'Share of sessions with abnormally fast first scroll.',
	'STATS_BEHAVIOR_HELP_COL_JUMP' => 'Share of sessions with atypical jump-scroll behavior.',
	'STATS_BEHAVIOR_HELP_COL_UPDATED' => 'Last update timestamp for this learned profile.',
	'STATS_BEHAVIOR_HELP_COL_SIGNAL' => 'Behavior signal name detected by the engine.',
	'STATS_BEHAVIOR_HELP_COL_VISITS' => 'Number of sessions containing this signal in the period.',
	'STATS_BEHAVIOR_HELP_COL_RATE' => 'Percentage of sessions affected by this signal.',
	'STATS_BEHAVIOR_HELP_COL_ZERO_INTERACT' => 'Share of scrolled sessions with no prior user interaction.',
	'STATS_BEHAVIOR_HELP_COL_DATE' => 'Timestamp of the analyzed session.',
	'STATS_BEHAVIOR_HELP_COL_VISITOR' => 'IP address and country of the observed session.',
	'STATS_BEHAVIOR_HELP_COL_LANDING_PAGE' => 'First page visited by the session.',
	'STATS_BEHAVIOR_HELP_COL_USER_AGENT' => 'Raw User-Agent string sent by the client.',
	'STATS_BEHAVIOR_SIGNAL_LEARN_BEHAVIOR' => 'Global learned-profile deviation',
	'STATS_BEHAVIOR_SIGNAL_SPEED'	=> 'Too-fast first scroll vs baseline',
	'STATS_BEHAVIOR_SIGNAL_NO_INTERACT' => 'No interaction vs baseline',
	'STATS_BEHAVIOR_SIGNAL_SPARSE'	=> 'Too few scroll events',
	'STATS_BEHAVIOR_SIGNAL_JUMP'	=> 'Atypical jump scroll',
	'STATS_BEHAVIOR_SIGNAL_WEBDRIVER' => 'Automation webdriver detected',
	'STATS_BEHAVIOR_SIGNAL_AJAX_PROFILE' => 'Automated AJAX scroll profile',
	'STATS_BEHAVIOR_SIGNAL_GUEST_FP_CLONE' => 'Guest fingerprint clone multi-IP (FR/CO excluded)',

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
	'STATS_SCROLL_LABEL'			=> 'Down scroll:',
	'STATS_SCROLL_DONE'				=> 'Scroll detected',
	'STATS_SCROLL_NONE'				=> 'No scroll',
	'STATS_RES_SOURCE_LABEL'		=> 'Resolution source:',
	'STATS_RES_SOURCE_AJAX'			=> 'Immediate AJAX',
	'STATS_RES_SOURCE_COOKIE'		=> 'Cookie',
	'STATS_RES_SOURCE_UNKNOWN'		=> 'Unknown',
	'STATS_RES_COOKIE_LABEL'		=> 'Cookie resolution:',
	'STATS_RES_AJAX_LABEL'			=> 'AJAX resolution:',
	'STATS_RES_COMPARE_LABEL'		=> 'Comparison:',
	'STATS_RES_COMPARE_MATCH'		=> 'OK (cookie = AJAX)',
	'STATS_RES_COMPARE_MISMATCH'	=> 'Mismatch detected',
	'STATS_RES_COMPARE_PARTIAL'		=> 'Partial (single source)',
	'STATS_RES_COMPARE_NONE'		=> 'No data',
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
