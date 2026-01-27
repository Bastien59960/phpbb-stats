# Changelog

Toutes les modifications notables de cette extension sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## [1.1.0] - 2026-01-27

### Changed
- Migration unifiée : toutes les migrations fusionnées en un seul fichier `release_1_0_0.php`
- L'onglet Statistiques est maintenant correctement placé après Extensions dans le PCA

### Fixed
- Correction de la carte de géolocalisation qui ne s'affichait pas (chargement jQuery/jVectorMap)
- Correction du menu déroulant de période qui éjectait du PCA
- Correction de la capture du titre de page dans le listener (utilisation de `$event['page_title']`)
- Correction du positionnement de la catégorie ACP (après Extensions, plus entre Personnalisation et Maintenance)

## [1.0.0] - 2025-01-01

### Added
- Tableau de bord analytique avec compteurs et graphiques
- Détection automatique des bots (User-Agent + liste phpBB)
- Géolocalisation par IP avec carte du monde (jVectorMap)
- Journal de navigation détaillé (pages, durées, referer)
- Vue par sessions utilisateur
- Filtres par période (1h, 6h, 12h, 24h, 7j, 30j) et filtre bots
- Rétention configurable (humains et bots séparés)
- Nettoyage automatique via tâche cron
- Support français et anglais
- Cache de géolocalisation IP
