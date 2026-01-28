# Bastien59960 - Stats - Extension phpBB

Extension de statistiques avancées pour phpBB 3.3+. Collecte et affiche les données de navigation des visiteurs directement dans le panneau d'administration.

## Fonctionnalités

- **Tableau de bord analytique** : Vue d'ensemble des visites avec graphiques et compteurs
- **Détection des bots** : Identification automatique des robots (User-Agent + liste phpBB)
- **Géolocalisation** : Carte du monde interactive (jVectorMap) avec cache IP
- **Journal de navigation** : Log détaillé de chaque page visitée avec durée, referer, OS, navigateur
- **Sessions** : Regroupement des pages vues par session utilisateur
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

## Structure

```
stats/
├── acp/                    # Modules ACP (info + modules)
├── adm/style/              # Templates HTML du panneau admin
├── config/                 # Définitions de services (services.yml)
├── controller/             # Contrôleur ACP principal
├── event/                  # Event listener (collecte des données)
├── language/{en,fr}/       # Fichiers de langue
├── migrations/             # Migration de base de données
├── composer.json           # Métadonnées de l'extension
└── ext.php                 # Classe de base de l'extension
```

## Tables créées

- `bastien59_stats` : Log principal des visites (IP, OS, navigateur, page, durée, etc.)
- `bastien59_stats_geo_cache` : Cache de géolocalisation IP

## Licence

[GPL-2.0-only](LICENSE)

## Auteur

**Bastien** (bastien59960)
