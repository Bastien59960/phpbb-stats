# Bastien59 Stats - Extension phpBB 3.3+

[English](README.md)

**Transformez votre ACP en tour de contrôle anti-robots.**

À mesure que la demande en données d'entraînement IA augmente, les contenus réellement rédigés par des humains prennent de plus en plus de valeur. Cette valeur attire aussi un scraping massif par des robots automatisés. Bastien59 Stats aide les administrateurs phpBB à détecter et contenir ces abus grâce à des métriques exploitables, des signaux comportementaux et des événements sécurité compatibles Fail2ban.

## Pourquoi l'installer

- Voir rapidement qui consomme vos ressources (humains vs bots).
- Détecter des comportements automatisés qui passent sous les radars User-Agent.
- Corréler session, AJAX, cookie visiteur signé, IP, pays et traces curseur.
- Alimenter Fail2ban avec des signaux exploitables sans exposer de secrets.

## Fonctionnalités clés

### ACP orienté exploitation

- Vue d'ensemble avec compteurs humains/bots, sources, OS, appareils, résolutions.
- Onglet **Sessions** avec timeline, pages, diagnostics cookie/AJAX, signaux et aperçu pays/drapeau.
- Onglet **Pages** (top pages + referers complets).
- Onglet **Carte** (jVectorMap) pour la répartition géographique.
- Onglet **Comportements** avec comparaison membres/invités/bots, profils appris, outliers invités, santé capture curseur et cas récents avec SVG.

### Détection anti-bot multi-signaux

Signaux stricts ou d'observation selon contexte géographique:

- `old_chrome_*`, `old_firefox`, `no_screen_res`.
- `ajax_webdriver`, `ajax_scroll_profile`.
- `guest_fp_clone_multi_ip` et `_shadow`.
- `guest_cookie_clone_multi_ip` et `_shadow`.
- `guest_cookie_ajax_fail` et `_shadow`.
- `cursor_no_movement`, `cursor_no_clicks`, `cursor_speed_outlier`, `cursor_script_path`.
- `learn_*_outlier` basés sur profils appris (scroll/interactions/réactions).

### Télémétrie AJAX + cookie visiteur signé

- Endpoint sécurisé `POST /stats/px` (token de lien + contrôle same-origin + session).
- Collecte résolution, scroll, interactions, webdriver, traces curseur/touch.
- Cookie visiteur signé `b59_vid` (hashé en base, jamais stocké en clair).
- États AJAX cookie distingués: absent, invalide, mismatch.

### Géolocalisation asynchrone robuste (cron `geo_async`)

- Résolution IP via `ip-api.com` avec cache DB.
- Cache IPv4 par IP **et par préfixe `/16`** (`v4:a.b`) pour éviter les appels redondants.
- TTL cache configurable (défaut 45 jours).
- Throttling avec marge de sécurité: cible 40 requêtes/min, limite service 45/min, pause inter-batch fixe 5s, pauses quota selon headers.
- En cas de HTTP 429: arrêt anticipé du run live, IP laissées non traitées pour le prochain lancement.
- Progression CLI batch + globale.

### Bridge sécurité / Fail2ban

- Écrit des lignes `PHPBB-SIGNAL` (signaux comportementaux) dans `security_audit.log`.
- Script CLI `bin/cross_ip_audit.php` pour détecter le téléchargement distribué cross-IP (`PHPBB-XIP`).
- Snippets Fail2ban inclus: `fail2ban/phpbb-guest-cookie-clone.conf`, `fail2ban/phpbb-crossip-soft.conf`, `fail2ban/phpbb-crossip-hard.conf`, `fail2ban/jail.guest-cookie-clone.local.example`, `fail2ban/jail.crossip.local.example`.

## Pré-requis

- PHP `>= 7.1.3`
- phpBB `>= 3.3.0`

## Installation

1. Copier le dossier `bastien59960/stats` dans `ext/`.
2. Activer l'extension:

```bash
php bin/phpbbcli.php extension:enable bastien59960/stats
```

## Mise à jour

Après mise à jour des fichiers, exécuter:

```bash
php bin/phpbbcli.php db:migrate
php bin/phpbbcli.php cache:purge
```

## Désinstallation

```bash
php bin/phpbbcli.php extension:disable bastien59960/stats
php bin/phpbbcli.php extension:purge bastien59960/stats
```

## Configuration rapide (ACP)

Dans **Extensions > Réglages des Statistiques**:

- Activer/désactiver le tracking.
- Rétention humains et bots.
- Timeout session.
- Chemin du log sécurité (défaut `/var/log/security_audit.log`).
- Seuils de détection navigateurs / absence JS.

Checklist production:

- Vérifier que le process PHP peut écrire dans le log sécurité.
- Activer vos jails Fail2ban associées.
- Vérifier que vos tâches cron phpBB tournent régulièrement.

## Cron et commandes utiles

### Cron phpBB global

```bash
php /var/www/forum/bin/phpbbcli.php cron:run
```

### Lancer uniquement la géolocalisation asynchrone

```bash
php /var/www/forum/bin/phpbbcli.php cron:run cron.task.bastien59960.stats.geo_async
```

### Audit cross-IP (dry-run)

```bash
php ext/bastien59960/stats/bin/cross_ip_audit.php --target=86400 --context=172800 --verbose
```

### Audit cross-IP (émission dans `security_audit.log`)

```bash
php ext/bastien59960/stats/bin/cross_ip_audit.php --emit --target=10800 --context=86400
```

Exemple cron (toutes les 30 minutes):

```cron
*/30 * * * * php /var/www/forum/ext/bastien59960/stats/bin/cross_ip_audit.php --emit --target=10800 --context=86400 >> /var/log/phpbb_crossip_audit.log 2>&1
```

### Backfill assets Reactions (optionnel)

Si l'extension `bastien59960/reactions` est utilisée:

```bash
php ext/bastien59960/stats/bin/backfill_reactions_assets.php --window=120 --verbose
php ext/bastien59960/stats/bin/backfill_reactions_assets.php --apply --window=120
```

## Données stockées (résumé)

Tables principales:

- `bastien59_stats`: sessions/pages, signaux, AJAX, cookie hash, curseur, diagnostics.
- `bastien59_stats_geo_cache`: cache géolocalisation IP + clé `/16` IPv4.
- `bastien59_stats_behavior_profile`: profils appris (membres).
- `bastien59_stats_behavior_seen`: sessions déjà intégrées à l'apprentissage.

## Sécurité et confidentialité

- Pas de mot de passe, token API ou secret serveur versionné.
- Cookie visiteur stocké sous forme hashée en base.
- Endpoint AJAX protégé (méthode, token, session, same-origin).
- Signaux pays sensibles FR/CO gérés en mode observation quand prévu.

## Limites connues

- La carte géographique dépend des assets jVectorMap chargés depuis CDN.
- La géolocalisation dépend de la disponibilité de `ip-api.com`.
- Le blocage réseau n'est pas fait par l'extension elle-même: il est délégué à Fail2ban.

## Licence

[GPL-2.0-only](LICENSE)

## Auteur

**Bastien** (`bastien59960`)
