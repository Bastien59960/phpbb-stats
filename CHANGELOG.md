# Changelog

Toutes les modifications notables de cette extension sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## [1.10.0] - 2026-03-06

### Added
- Télémétrie assets Reactions (`reactions_extension_expected`, `reactions_css_seen`, `reactions_js_seen`) dans `bastien59_stats` et `bastien59_stats_behavior_seen`
- Colonne `reactions_missing_hits` dans `bastien59_stats_behavior_profile`
- Diagnostics ACP détaillés sur état des assets Reactions dans la vue Sessions
- Tableau ACP de santé de capture des traces curseur (membres / invités / humains légitimes)
- Numérotation des pages de session et correspondance explicite avec les graphiques curseur

### Changed
- Zoom/pan des SVG de traces curseur (molette, double-clic, glisser) dans les vues ACP concernées
- Libellés ACP/FR/EN clarifiés pour distinguer **cookie de résolution écran** et **cookie visiteur signé**
- Traitement géolocalisation asynchrone renforcé: cache IPv4 sur préfixe configurable (défaut `/24`, format `v4:a.b.c.n/24`), throttling avec marge de sécurité, pause inter-batch fixe et progression CLI globale plus lisible

### Fixed
- En cas de retour HTTP 429 du service géoloc, reprise au prochain run sans marquer l'IP comme traitée
- Promotion des signaux `_shadow` pays-dépendants après résolution géoloc et émission audit correspondante

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
