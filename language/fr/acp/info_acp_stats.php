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
	'ACP_STATS_BEHAVIOR'			=> 'Comportements',
	'ACP_STATS_BEHAVIOR_EXPLAIN'	=> 'Comparaison des profils appris (membres connectés) avec les sessions invitées et bots pour affiner la détection.',
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

	// Onglet comportements (ACP dédié)
	'STATS_BEHAVIOR_MIN_SAMPLES'	=> 'Min. échantillons :',
	'STATS_BEHAVIOR_PROFILE_LIMIT'	=> 'Limite profils :',
	'STATS_BEHAVIOR_LEARNING_STATUS'=> 'Apprentissage',
	'STATS_BEHAVIOR_TABLES_STATUS'	=> 'Tables learning',
	'STATS_BEHAVIOR_NO_LEARNING_TABLES' => 'Tables d\'apprentissage indisponibles. Lancez les migrations de l\'extension.',
	'STATS_BEHAVIOR_GROUP_COMPARISON' => 'Comparaison membres / invités / bots',
	'STATS_BEHAVIOR_PROFILES'		=> 'Profils appris (base membres connectés)',
	'STATS_BEHAVIOR_OUTLIERS'		=> 'Signaux d\'écart détectés (invités)',
	'STATS_BEHAVIOR_RECENT_CASES'	=> 'Cas récents avec signaux comportementaux',
	'STATS_BEHAVIOR_GROUP_MEMBERS'	=> 'Membres',
	'STATS_BEHAVIOR_GROUP_GUESTS'	=> 'Invités',
	'STATS_BEHAVIOR_GROUP_BOTS'		=> 'Bots',
	'STATS_BEHAVIOR_COL_GROUP'		=> 'Groupe',
	'STATS_BEHAVIOR_COL_PROFILE'	=> 'Profil',
	'STATS_BEHAVIOR_COL_SAMPLES'	=> 'Échantillons',
	'STATS_BEHAVIOR_COL_SESSIONS'	=> 'Sessions',
	'STATS_BEHAVIOR_COL_AJAX_RATE'	=> 'Taux AJAX',
	'STATS_BEHAVIOR_COL_SCROLL_RATE'=> 'Taux scroll',
	'STATS_BEHAVIOR_COL_AVG_FIRST_SCROLL' => '1er scroll moyen',
	'STATS_BEHAVIOR_COL_AVG_EVENTS'	=> 'Évts scroll moy.',
	'STATS_BEHAVIOR_COL_AVG_MAXY'	=> 'Amplitude moy.',
	'STATS_BEHAVIOR_COL_INTERACT_SCORE' => 'Score interactions',
	'STATS_BEHAVIOR_COL_NO_INTERACT' => '% sans interaction',
	'STATS_BEHAVIOR_COL_FAST'		=> '% scroll rapide',
	'STATS_BEHAVIOR_COL_JUMP'		=> '% scroll jump',
	'STATS_BEHAVIOR_COL_UPDATED'	=> 'Maj',
	'STATS_BEHAVIOR_COL_SIGNAL'		=> 'Signal',
	'STATS_BEHAVIOR_COL_RATE'		=> 'Taux',
	'STATS_BEHAVIOR_COL_ZERO_INTERACT' => '% scroll sans interaction',
	'STATS_BEHAVIOR_HELP_COL_GROUP' => 'Type de session comparé : membres connectés, invités ou bots.',
	'STATS_BEHAVIOR_HELP_COL_PROFILE' => 'Profil appris (OS / appareil / navigateur) basé sur les membres connectés.',
	'STATS_BEHAVIOR_HELP_COL_SAMPLES' => 'Nombre d\'échantillons membres utilisés pour construire ce profil.',
	'STATS_BEHAVIOR_HELP_COL_SESSIONS' => 'Nombre de sessions (première visite) observées sur la période filtrée.',
	'STATS_BEHAVIOR_HELP_COL_AJAX_RATE' => 'Part des sessions ayant renvoyé une télémétrie AJAX valide.',
	'STATS_BEHAVIOR_HELP_COL_SCROLL_RATE' => 'Part des sessions avec un scroll bas détecté via AJAX.',
	'STATS_BEHAVIOR_HELP_COL_AVG_FIRST_SCROLL' => 'Temps moyen avant le premier scroll (en millisecondes).',
	'STATS_BEHAVIOR_HELP_COL_AVG_EVENTS' => 'Nombre moyen d\'événements de scroll captés par session.',
	'STATS_BEHAVIOR_HELP_COL_AVG_MAXY' => 'Position verticale maximale moyenne atteinte pendant le scroll (en pixels).',
	'STATS_BEHAVIOR_HELP_COL_INTERACT_SCORE' => 'Score moyen d\'interactions utilisateur (plus élevé = profil plus humain).',
	'STATS_BEHAVIOR_HELP_COL_NO_INTERACT' => 'Part des sessions sans interaction clavier/souris/touch détectée.',
	'STATS_BEHAVIOR_HELP_COL_FAST' => 'Part des sessions avec premier scroll anormalement rapide.',
	'STATS_BEHAVIOR_HELP_COL_JUMP' => 'Part des sessions avec saut de scroll atypique.',
	'STATS_BEHAVIOR_HELP_COL_UPDATED' => 'Date de dernière mise à jour du profil appris.',
	'STATS_BEHAVIOR_HELP_COL_SIGNAL' => 'Nom du signal comportemental détecté par le moteur.',
	'STATS_BEHAVIOR_HELP_COL_VISITS' => 'Nombre de sessions contenant ce signal sur la période.',
	'STATS_BEHAVIOR_HELP_COL_RATE' => 'Pourcentage des sessions concernées par ce signal.',
	'STATS_BEHAVIOR_HELP_COL_ZERO_INTERACT' => 'Part des sessions scrollées sans interaction utilisateur préalable.',
	'STATS_BEHAVIOR_HELP_COL_DATE' => 'Horodatage de la session analysée.',
	'STATS_BEHAVIOR_HELP_COL_VISITOR' => 'Adresse IP et pays de la session observée.',
	'STATS_BEHAVIOR_HELP_COL_LANDING_PAGE' => 'Première page visitée par la session.',
	'STATS_BEHAVIOR_HELP_COL_USER_AGENT' => 'Chaîne User-Agent brute transmise par le client.',
	'STATS_BEHAVIOR_SIGNAL_LEARN_BEHAVIOR' => 'Écart global au profil appris',
	'STATS_BEHAVIOR_SIGNAL_SPEED'	=> 'Scroll initial trop rapide vs profils',
	'STATS_BEHAVIOR_SIGNAL_NO_INTERACT' => 'Aucune interaction vs profils',
	'STATS_BEHAVIOR_SIGNAL_SPARSE'	=> 'Scroll trop pauvre en événements',
	'STATS_BEHAVIOR_SIGNAL_JUMP'	=> 'Saut de scroll atypique',
	'STATS_BEHAVIOR_SIGNAL_WEBDRIVER' => 'Automation webdriver détectée',
	'STATS_BEHAVIOR_SIGNAL_AJAX_PROFILE' => 'Profil AJAX automatisé',
	'STATS_BEHAVIOR_SIGNAL_GUEST_FP_CLONE' => 'Fingerprint invité cloné multi-IP (hors FR/CO)',

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
	'STATS_SCROLL_LABEL'			=> 'Scroll bas :',
	'STATS_SCROLL_DONE'				=> 'Scroll détecté',
	'STATS_SCROLL_NONE'				=> 'Aucun scroll',
	'STATS_RES_SOURCE_LABEL'		=> 'Source résolution :',
	'STATS_RES_SOURCE_AJAX'			=> 'AJAX immédiat',
	'STATS_RES_SOURCE_COOKIE'		=> 'Cookie',
	'STATS_RES_SOURCE_UNKNOWN'		=> 'Inconnue',
	'STATS_RES_COOKIE_LABEL'		=> 'Résolution cookie :',
	'STATS_RES_AJAX_LABEL'			=> 'Résolution AJAX :',
	'STATS_RES_COMPARE_LABEL'		=> 'Comparaison :',
	'STATS_RES_COMPARE_MATCH'		=> 'OK (cookie = AJAX)',
	'STATS_RES_COMPARE_MISMATCH'	=> 'Différence détectée',
	'STATS_RES_COMPARE_PARTIAL'		=> 'Partiel (une seule source)',
	'STATS_RES_COMPARE_NONE'		=> 'Aucune donnée',
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
