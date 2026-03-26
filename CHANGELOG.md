# Changelog

Toutes les modifications notables de cette extension sont documentées dans ce fichier.

Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.1.0/).

## [Unreleased]

### Changed
- Le cookie visiteur signé `b59_vid` devient l'ancre principale de session quand il est présent: les pages forum et téléchargements restent regroupés dans la même session même si l'IP change avant le timeout
- L'ACP **Sessions** affiche désormais une vraie ligne chronologique `#1` pour la page d'entrée, puis chaque ligne de timeline au format `IP - durée` pour rendre les bascules d'IP immédiatement lisibles
- Les sessions composées uniquement de téléchargements directs affichent des états `N/A` explicites pour AJAX, assets réactions et assets Apache, au lieu d'un diagnostic trompeur "absent"
- Les cartes de session ACP sont maintenant encadrées visuellement selon le verdict: vert (OK / bot phpBB légitime), orange (suspicion), rouge (signal strict)

## [1.16.0] - 2026-03-26

### Added
- Journalisation des téléchargements de pièces jointes `download/file.php` dans la timeline **Sessions** via le hook phpBB `core.download_file_send_to_browser_before`
- Affichage ACP du referer pour chaque entrée de timeline et conservation de l'ordre chronologique stable `visit_time, log_id`
- Backfill informatif par session dans `geo_async` à partir des logs Apache récents pour compter les chargements de bannière forum, images de rangs et avatars
- Nouveau signal `direct_resource_page_fallback_no_bootstrap` dérivé des logs Apache pour le motif `ressource opaque directe -> fallback HTML -> aucun bootstrap`
- Migration `release_1_16_0` avec colonnes `apache_banner_hits`, `apache_rank_hits`, `apache_avatar_hits`, `apache_asset_scan_time`

### Changed
- L'ACP distingue désormais clairement `Reverse DNS en attente` (cron pas encore passé) de `Aucun nom d'hôte trouvé` (PTR absent après passage du cron)
- Le bouton **Effacer les statistiques** et ses messages indiquent explicitement que le cache GeoIP / Reverse DNS est conservé
- Les signaux `cursor_no_movement` et `cursor_no_clicks` ne s'appliquent plus aux terminaux non desktop
- Les cas `direct_resource_page_fallback_no_bootstrap` restent en `_shadow` sur PTR résidentiel, puis passent en strict sur `view=print`, récidive IP, absence de PTR ou PTR non résidentiel

### Fixed
- Le cron `geo_async` persiste correctement le hostname dans le cache GeoIP et retraitera aussi les lignes dont le pays est déjà connu mais dont le reverse DNS manque encore
- Les téléchargements de pièces jointes sont visibles dans **Sessions** sans fausser le scoring "page HTML + JS" (`page_count`, règles d'absence d'interaction, durée de la page précédente)

## [1.10.1] - 2026-03-15

### Added
- Sessions ACP : troisième filtre "Bots légitimes" (`phpbb-bot`) séparé de "Bots détectés" — les crawlers reconnus (Googlebot, etc.) ont maintenant leur propre checkbox indépendante

### Changed
- **Règle `cn_no_interaction_5m` révisée** : le critère d'émission du signal passe de l'absence d'interaction télémétrique (6 conditions `NOT EXISTS`) à `page_count = 1` (une seule page visitée en 5 minutes) **ET** première visite de l'IP dans les 24h précédentes.
  - Si le visiteur navigue vers d'autres pages (`page_count > 1`), le signal n'est pas émis.
  - Si l'IP a déjà visité dans les 24h (humain qui revient dans la journée), la règle est ignorée.
  - Suppression de la dépendance aux colonnes de télémétrie JS (`has_ajax_telemetry_columns`, `has_cursor_columns`) : la règle fonctionne même sur les anciens schémas.

### Security
- **Évasion corrigée — Botnet CN "wheel sans curseur" (observé et corrigé 2026-03-15)** :
  Fingerprint : `ajax_interact_mask=16` (événement `wheel` seul, sans `mousemove`),
  `ajax_scroll_events=2` exact, `cursor_track_points=0`, UA Chrome/139 Windows 10/11, résolution 3840×2160.
  Le bot dispatche 2 événements `wheel` synthétiques → `scroll_down_ajax=1` → ancienne condition `NOT EXISTS`
  satisfaite → signal jamais émis.
  **Correction** : la nouvelle règle `page_count=1` est impossible à falsifier sans réellement charger
  d'autres pages du forum. Le bot qui ne fait qu'un seul hit est désormais banni après 5 minutes.

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
