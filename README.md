# Bastien59960 - Stats - Extension phpBB

Extension de statistiques avancées pour phpBB 3.3+. Collecte et affiche les données de navigation des visiteurs directement dans le panneau d'administration.

## Fonctionnalités

- **Tableau de bord analytique** : Vue d'ensemble des visites avec graphiques et compteurs
- **Détection des bots** : Identification automatique des robots (User-Agent + liste phpBB)
- **Apprentissage comportemental** : Profilage des métriques scroll/interactions des membres connectés pour affiner la détection des invités
- **Signal strict viewprofile** : Détection des accès directs à `memberlist.php?mode=viewprofile` sans navigation préalable et sans résolution écran (cookie/AJAX)
- **Signal clone multi-IP invité** : Détection d’un fingerprint invité cloné (UA + télémétrie AJAX scroll) observé sur plusieurs IPs en fenêtre courte, avec exclusion stricte des IP FR/CO
- **Signal clone cookie visiteur multi-IP** : Détection d’un cookie visiteur invité signé (`b59_vid`) réutilisé sur plusieurs IPs en fenêtre courte, avec exclusion stricte des IP FR/CO
- **Signal strict cookie AJAX** : Détection d’un invité JS-actif dont le cookie signé n’est pas relu (ou incohérent) sur la requête AJAX
- **Mode observation FR/CO** : Les mêmes contrôles cookie/AJAX tournent aussi sur FR/CO, mais avec signal `_shadow` (analyse/ACP uniquement, pas de ban fail2ban)
- **Signal cross-IP distribué (CLI)** : Détection comportementale anti-spoof sur téléchargements PJ distribués via IP différentes (source Apache + jointures phpBB), avec exclusion stricte des IP FR
- **Géolocalisation** : Carte du monde interactive (jVectorMap) avec cache IP
- **Journal de navigation** : Log détaillé de chaque page visitée avec durée, referer, OS, navigateur
- **Sessions** : Regroupement des pages vues par session utilisateur
- **Onglet ACP Comportements** : Visualisation des profils appris (membres) et des écarts détectés sur invités/bots
- **Filtres** : Période personnalisable (1h, 6h, 24h, 7j, 30j) et filtre bots
- **Rétention configurable** : Durées de conservation séparées pour humains et bots
- **Nettoyage automatique** : Tâche cron intégrée pour purger les anciennes données
- **Bilingue** : Interface disponible en français et anglais

## Pré-requis

- PHP >= 7.1.3
- phpBB >= 3.3.0

## Installation

1. Téléchargez et décompressez l'archive
2. Copiez le dossier `bastien59960/stats` dans `ext/` de votre forum phpBB
3. Dans le PCA, allez dans **Personnalisation** > **Extensions** > **Gérer les extensions**
4. Cliquez sur **Activer** en face de « Bastien59 Stats »

Ou via CLI :

```bash
php bin/phpbbcli.php extension:enable bastien59960/stats
```

## Désinstallation

Via CLI :

```bash
php bin/phpbbcli.php extension:disable bastien59960/stats
php bin/phpbbcli.php extension:purge bastien59960/stats
```

Ou via le PCA dans **Personnalisation** > **Extensions** > **Gérer les extensions**.

## Configuration

Après activation, un onglet **Statistiques** apparaît dans le PCA (après Extensions). Les réglages sont accessibles dans **Extensions** > **Réglages des Statistiques** :

| Paramètre | Description | Défaut |
|---|---|---|
| Activer le tracking | Active/désactive la collecte | Oui |
| Rétention humains | Jours de conservation des données visiteurs | 30 |
| Rétention bots | Jours de conservation des données bots | 5 |

## Signal Cross-IP (audit + fail2ban)

Le script CLI `bin/cross_ip_audit.php` génère des lignes dédiées dans `security_audit.log` avec le préfixe `PHPBB-XIP` (séparé de `PHPBB-SIGNAL`), pour faciliter le debug des jails.

Exemple de ligne :

```text
2026-03-05 17:43:37 PHPBB-XIP ip=188.61.125.156 cc=CH method=xip_dl_soft_v1 severity=soft score=65 downloads=13 ...
```

Règles clés anti faux-positifs :

- scoring multi-critères (download-only, ratio cross-IP distribué, burst, etc.)
- vérification inverse/forward DNS des crawlers légitimes connus avant signal
- exclusion stricte des IP `FR`
- déduplication temporelle par IP + méthode

Mode test (n'écrit rien dans le log) :

```bash
php ext/bastien59960/stats/bin/cross_ip_audit.php --target=86400 --context=172800 --verbose
```

Mode émission vers `security_audit.log` :

```bash
php ext/bastien59960/stats/bin/cross_ip_audit.php --emit --target=10800 --context=86400
```

Le process CLI doit avoir le droit d'écriture sur le fichier de log (ex: exécution en `www-data` ou via cron root).

Exemple cron (toutes les 30 min) :

```cron
*/30 * * * * php /var/www/forum/ext/bastien59960/stats/bin/cross_ip_audit.php --emit --target=10800 --context=86400 >> /var/log/phpbb_crossip_audit.log 2>&1
```

Snippets fail2ban fournis :

- `fail2ban/phpbb-crossip-soft.conf`
- `fail2ban/phpbb-crossip-hard.conf`
- `fail2ban/jail.crossip.local.example`
- `fail2ban/phpbb-guest-cookie-clone.conf`
- `fail2ban/jail.guest-cookie-clone.local.example`

La jail `phpbb-guest-cookie-clone` couvre les signaux stricts cookie :

- `guest_cookie_clone_multi_ip`
- `guest_cookie_ajax_fail`

Le signal `guest_cookie_ajax_fail_shadow` (FR/CO) est conservé en base pour l’analyse comportementale, mais n’est pas matché par la jail fail2ban.

Les états de relecture cookie via AJAX sont distingués :

- `visitor_cookie_ajax_state=2` : cookie absent
- `visitor_cookie_ajax_state=3` : cookie invalide (format/signature)
- `visitor_cookie_ajax_state=4` : cookie incohérent (mismatch cookie page vs cookie AJAX)

## Structure

```
stats/
├── acp/                    # Modules ACP (info + modules)
├── adm/style/              # Templates HTML du panneau admin
├── config/                 # Définitions de services (services.yml)
├── controller/             # Contrôleur ACP principal
├── event/                  # Event listener (collecte des données)
├── bin/                    # Outils CLI (cross-IP audit)
├── fail2ban/               # Filtres/jails fail2ban (snippets)
├── language/{en,fr}/       # Fichiers de langue
├── migrations/             # Migration de base de données
├── composer.json           # Métadonnées de l'extension
└── ext.php                 # Classe de base de l'extension
```

## Tables créées

- `bastien59_stats` : Log principal des visites (IP, OS, navigateur, page, durée, hash cookie visiteur, etc.)
- `bastien59_stats_geo_cache` : Cache de géolocalisation IP
- `bastien59_stats_behavior_profile` : Profils agrégés d'apprentissage comportemental
- `bastien59_stats_behavior_seen` : Déduplication des sessions déjà apprises

## Licence

[GPL-2.0-only](LICENSE)

## Auteur

**Bastien** (bastien59960)
