# PRD - lot courant bastien59960/stats

## Contexte

L'extension `bastien59960/stats` sert de tour de controle ACP pour differencier navigation humaine, automatisation, scraping, et signaux faibles exploitables par fail2ban.

Le lot courant consolide plusieurs evolutions deja presentes dans le depot, puis documente une decision produit importante prise pendant l'analyse d'un faux positif ACP sur un cluster de session.

## Objectifs du lot

1. Rendre l'ACP plus operationnel pour l'analyse de sessions et de clusters.
2. Stabiliser la collecte et la correlation autour du cookie visiteur signe.
3. Simplifier l'exploitation fail2ban avec un chemin de log fixe.
4. Corriger un bug d'affichage ACP qui pouvait faire croire a tort qu'aucune image attendue n'avait ete chargee.
5. Conserver pour l'instant la logique actuelle de clustering, meme si certains cas limites humains restent plus complexes.

## Livrables fonctionnels du lot

### 1. ACP et diagnostic session/cluster

- L'ACP continue a afficher les sessions corrigees / correlees a partir des liens entre cookie, IP et telemetrie.
- Le libelle "Pages" evolue vers "Requetes" quand il s'agit en pratique d'un volume de hits HTTP, pas seulement de pages HTML.
- L'UI distingue mieux les IP vues directement et les IP correlees au cluster.

### 2. Continuite de session autour du cookie visiteur

- Le cookie visiteur signe reste l'ancre principale de correlation quand il est disponible.
- Les traces anonymes autour d'un login peuvent etre rattachees a la session membre resolue au lieu de casser artificiellement le parcours.
- Cette logique vise a mieux raconter la navigation reelle sans dependre uniquement de l'IP source.

### 3. Journal de securite fixe

- Le chemin du journal de securite est fixe a `/var/log/security_audit.log`.
- L'ACP n'expose plus ce chemin comme un parametre libre.
- La migration `release_1_19_0` force la valeur de configuration pour rester alignee avec fail2ban et avec l'exploitation systeme.

### 4. Garde-fou cron geo

- Le cron `geo_async` verifie desormais plus tot s'il doit vraiment s'executer.
- Objectif: eviter des runs inutiles et garder un comportement plus previsible en production.

### 5. Correctif UI sur les medias attendus / charges

Probleme observe:

- Dans certains clusters ACP, l'ecran affichait par exemple `98 images attendues, 0 chargee` alors que les acces et la logique metier montraient clairement que les images avaient bien ete chargees.

Cause produit:

- Le panneau de probabilite pouvait afficher des compteurs de medias non coherents avec le cluster reellement affiche dans l'ACP.

Decision prise:

- Sans toucher pour l'instant au moteur global de scoring, l'ACP recalcule explicitement les medias attendus et les medias charges a partir des pages du cluster actuellement affiche.
- L'affichage des facteurs relies aux medias s'aligne sur ce recalcul au moment du rendu.

Resultat attendu:

- Si le cluster affiche des images attendues et effectivement chargees, l'ACP ne doit plus presenter ce cas comme "0 chargee".
- On reduit ainsi un faux positif visuel tres trompeur, sans changer encore les autres heuristiques du modele probabiliste.

## Non-objectifs de ce lot

Les points suivants sont volontairement laisses en l'etat pour cette iteration:

- Pas de refonte du moteur de probabilite.
- Pas de changement de ponderation globale du `P(bot)`.
- Pas de changement du mecanisme de fusion de cluster base sur:
  - meme cookie sur plusieurs IP
  - meme IP avec plusieurs cookies
- Pas de sous-regroupement "par machine" a l'interieur d'un cluster dans cette iteration.

## Contraintes metier confirmees pendant l'analyse

### 1. Une meme IP ne designe pas forcement une seule machine

Cas frequents:

- NAT domestique
- CGNAT mobile
- wifi partage
- proxy entreprise
- webview ou ouverture externe depuis une application de messagerie ou de webmail

Conclusion:

- Le signal "meme IP, cookies multiples" peut correspondre a un humain legitime ou a plusieurs machines derriere une meme IP publique.

### 2. Une meme machine peut changer d'IP pendant la navigation

Cas frequents:

- bascule IPv6 / IPv4
- changement de reseau
- variations operateur mobile

Conclusion:

- Le signal "meme cookie, IP multiples" n'est pas un indicateur suffisant de bot a lui seul.

### 3. Disponibilite de la resolution d'ecran

Etat actuel:

- `screen_res` peut etre disponible sur plusieurs requetes une fois le cookie present, y compris sur des hits non HTML comme certains `download/file.php`.
- `screen_res_ajax` n'existe que lorsque la page HTML execute le JS et remonte la telemetrie via `/app.php/stats/px`.

Conclusion:

- La resolution n'est pas "seulement la premiere requete".
- En revanche, le niveau de preuve n'est pas le meme entre la valeur issue du cookie et la valeur issue de l'AJAX.

## Piste produit suivante recommandee

### Regrouper un cluster par "machine"

Direction recommandee:

- Conserver le cluster large pour l'investigation.
- Introduire a l'interieur de ce cluster une vue ou un regroupement par machine probable.

Base de regroupement proposee:

1. `visitor_cookie_hash` comme ancre principale quand il existe.
2. En repli, un fingerprint souple du type:
   - famille UA
   - OS / appareil
   - resolution ecran
   - presence ou absence de telemetrie AJAX
3. Autoriser des changements ponctuels d'IP a l'interieur de cette machine probable tant que la fenetre temporelle reste courte et que le cookie est stable.

Benefices attendus:

- Moins de faux positifs sur des clusters humains composites.
- ACP plus lisible.
- Meilleure explication du score probabiliste.
- Base plus saine pour une future evolution du modele `P(bot)`.

## Decision produit a retenir

Pour l'instant:

- on corrige l'erreur d'affichage ACP sur les medias,
- on conserve la logique actuelle de clustering et de score,
- on documente clairement que la suite logique est un regroupement intra-cluster par machine probable.

## Validation minimale de ce lot

- Les fichiers PHP modifies passent `php -l`.
- Le correctif ACP n'introduit pas de changement de schema.
- Le commit de ce lot embarque aussi la migration forçant le chemin du journal de securite.

## Dépendances inter-extensions

### Utilise (optionnel)

- **`bastien59960/reactions`** : les colonnes `reactions_extension_expected`, `reactions_css_seen`, `reactions_js_seen` dans `bastien59_stats` et `bastien59_stats_behavior_seen` (migration `release_1_10_0`) tracent la présence et le chargement des assets de l'extension reactions par session visiteur. La migration vérifie l'existence de ces colonnes (`sql_column_exists`) avant usage. Si reactions est absent ou non migré, ces colonnes restent à `0` sans impact fonctionnel sur les autres features de stats.

### Exposée à (consommateurs)

Aucun autre module du projet ne lit directement les tables de stats.

### Résumé

`stats` n'a aucune dépendance forte. Son intégration avec `reactions` est un enrichissement optionnel : stats peut fonctionner entièrement sans reactions.
