<?php
/**
 * Extension Statistiques de Visiteurs pour phpBB
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
	// Catégorie principale (onglet)
	'ACP_CAT_STATS'					=> 'Statistiques',

	// Titres des modules (menu ACP)
	'ACP_STATS'						=> 'Statistiques de Visiteurs',
	'ACP_STATS_EXPLAIN'				=> 'Tableau de bord analytique : Vue d\'ensemble des configurations des visiteurs et journal détaillé de navigation.',
	'ACP_STATS_SETTINGS'			=> 'Réglages des Statistiques',
	'ACP_STATS_SETTINGS_EXPLAIN'	=> 'Configurez les paramètres de collecte et de rétention des statistiques.',

	// Module principal
	'ACP_BASTIEN59_STATS_TITLE'		=> 'Statistiques de Visiteurs',
	'ACP_BASTIEN59_STATS_EXPLAIN'	=> 'Tableau de bord analytique : Vue d\'ensemble des configurations des visiteurs et journal détaillé de navigation.',
	'ACP_BASTIEN59_STATS_CLEAR'		=> 'Effacer les statistiques',
	'ACP_BASTIEN59_STATS_CONFIRM'	=> 'Êtes-vous sûr de vouloir supprimer toutes les données de statistiques ?',
	'STATS_CLEARED'					=> 'Les statistiques ont été effacées avec succès.',

	// Réglages
	'STATS_ENABLED'					=> 'Activer le tracking',
	'STATS_ENABLED_EXPLAIN'			=> 'Active ou désactive la collecte des statistiques de visiteurs.',
	'STATS_RETENTION_DAYS'			=> 'Rétention humains (jours)',
	'STATS_RETENTION_DAYS_EXPLAIN'	=> 'Nombre de jours de conservation des données des visiteurs humains (1-365).',
	'STATS_RETENTION_BOTS'			=> 'Rétention bots (jours)',
	'STATS_RETENTION_BOTS_EXPLAIN'	=> 'Nombre de jours de conservation des données des bots (1-30).',
	'STATS_SESSION_TIMEOUT'			=> 'Timeout de session (minutes)',
	'STATS_SESSION_TIMEOUT_EXPLAIN'	=> 'Durée d\'inactivité avant qu\'une nouvelle visite soit considérée comme une nouvelle session (5-120 minutes).',

	// Filtres
	'STATS_PERIOD'					=> 'Période :',
	'STATS_1_HOUR'					=> '1 heure',
	'STATS_6_HOURS'					=> '6 heures',
	'STATS_12_HOURS'				=> '12 heures',
	'STATS_24_HOURS'				=> '24 heures',
	'STATS_48_HOURS'				=> '48 heures',
	'STATS_7_DAYS'					=> '7 jours',
	'STATS_30_DAYS'					=> '30 jours',
	'STATS_SHOW_BOTS'				=> 'Afficher les bots',
	'STATS_LIMIT'					=> 'Limite :',

	// Cartes statistiques
	'STATS_PAGEVIEWS_HUMANS'		=> 'Pages vues (humains)',
	'STATS_UNIQUE_VISITORS'			=> 'Visiteurs uniques',
	'STATS_AVG_DURATION_HUMANS'		=> 'Durée moy. humains',
	'STATS_UNIQUE_BOTS'				=> 'Bots uniques',
	'STATS_BOT_PAGEVIEWS'			=> 'Pages bots (%s%%)',
	'STATS_AVG_DURATION_BOTS'		=> 'Durée moy. bots',

	// Onglets
	'STATS_TAB_OVERVIEW'			=> 'Vue d\'ensemble',
	'STATS_TAB_SESSIONS'			=> 'Sessions',
	'STATS_TAB_PAGES'				=> 'Pages',
	'STATS_TAB_MAP'					=> 'Carte',

	// Vue d'ensemble
	'STATS_TRAFFIC_HUMANS'			=> 'Sources de trafic (Humains)',
	'STATS_TRAFFIC_BOTS'			=> 'Sources de trafic (Bots)',
	'STATS_OS_TITLE'				=> 'Systèmes d\'exploitation',
	'STATS_DEVICES_TITLE'			=> 'Types d\'appareils',
	'STATS_RES_TITLE'				=> 'Résolutions d\'écran',
	'STATS_NO_DATA'					=> 'Aucune donnée',
	'STATS_FULL_REFERERS'			=> 'Referers externes complets (cliquables)',
	'STATS_COL_SOURCE_REFERER'		=> 'Source (referer)',
	'STATS_COL_DESTINATION'			=> 'Page de destination',
	'STATS_COL_TYPE'				=> 'Type',
	'STATS_COL_VISITS'				=> 'Visites',
	'STATS_COL_SOURCE'				=> 'Source',
	'STATS_LABEL_BOT'				=> 'Bot',
	'STATS_LABEL_HUMAN'				=> 'Humain',
	'STATS_NO_EXTERNAL_REFERER'		=> 'Aucun referer externe',

	// Sessions
	'STATS_FILTER_HUMANS'			=> 'Humains',
	'STATS_FILTER_ROBOTS'			=> 'Robots',
	'STATS_COL_VISITOR'				=> 'Visiteur',
	'STATS_COL_DEVICE'				=> 'Appareil',
	'STATS_COL_LANDING_PAGE'		=> 'Page d\'entrée',
	'STATS_COL_PAGES'				=> 'Pages',
	'STATS_BADGE_BOT'				=> 'BOT',
	'STATS_BADGE_PHPBB'				=> 'DB phpBB',
	'STATS_BADGE_BEHAVIOR'			=> 'Comportement',
	'STATS_BADGE_BEHAVIOR_TITLE'	=> 'Comportement suspect',
	'STATS_BADGE_UA'				=> 'UA suspect',
	'STATS_BADGE_UA_TITLE'			=> 'User-Agent suspect',
	'STATS_BADGE_GUEST'				=> 'Invité',
	'STATS_PAGES_COUNT'				=> '%d pages',
	'STATS_NO_SESSION'				=> 'Aucune session enregistrée',
	'STATS_NO_SESSION_EXPLAIN'		=> 'Les données apparaîtront après les premières visites.',

	// Pages
	'STATS_TOP_PAGES'				=> 'Pages les plus visitées',
	'STATS_COL_PAGE'				=> 'Page',
	'STATS_COL_AVG_TIME'			=> 'Temps moyen',
	'STATS_NO_PAGE_DATA'			=> 'Aucune donnée disponible.',

	// Carte
	'STATS_MAP_HUMANS'				=> 'Carte des visiteurs humains',
	'STATS_MAP_BOTS'				=> 'Carte des bots',
	'STATS_LEGEND'					=> 'Légende :',
	'STATS_LEGEND_LOW'				=> 'Peu',
	'STATS_LEGEND_MEDIUM'			=> 'Moyen',
	'STATS_LEGEND_HIGH'				=> 'Beaucoup',
	'STATS_TOP_COUNTRIES_HUMANS'	=> 'Top pays (Humains)',
	'STATS_TOP_COUNTRIES_BOTS'		=> 'Top pays (Bots)',
	'STATS_DISTRIBUTION_HUMANS'		=> 'Répartition humains',
	'STATS_DISTRIBUTION_BOTS'		=> 'Répartition bots',
	'STATS_TOTAL_COUNTRIES'			=> 'Total pays :',
	'STATS_GEOLOCATED_VISITORS'		=> 'Visiteurs géolocalisés :',
	'STATS_GEOLOCATED_BOTS'		=> 'Bots géolocalisés :',
	'STATS_NO_GEO_DATA'				=> 'Aucune donnée de géolocalisation',
	'STATS_MAP_LOADING'				=> 'Chargement...',
	'STATS_MAP_ERROR'				=> 'Erreur de chargement de la carte.',
	'STATS_MAP_UNAVAILABLE'			=> 'jVectorMap non disponible.',
	'STATS_MAP_VISITOR'				=> 'visiteur',
	'STATS_MAP_VISITORS'			=> 'visiteurs',
	'STATS_MAP_BOT_LABEL'			=> 'bot',
	'STATS_MAP_BOTS_LABEL'			=> 'bots',

	// Controller
	'STATS_GUEST'					=> 'Invité',
	'STATS_DIRECT_ACCESS'			=> 'Accès direct',
	'STATS_USER_FALLBACK'			=> 'Utilisateur #%d',
	'STATS_BOT_UNKNOWN'				=> 'Bot suspect (UA/comportement)',
]);
