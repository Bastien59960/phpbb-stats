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
]);
