<?php
/**
 * Stats Extension - Event Listener
 *
 * @package bastien59960/stats
 * @license GPL-2.0-only
 */

namespace bastien59960\stats\event;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class listener implements EventSubscriberInterface
{
    protected $db;
    protected $request;
    protected $user;
    protected $config;
    protected $template;
    protected $table_prefix;

    // Domaines reverse DNS légitimes pour vérification des bots prétendus
    // Source : https://developers.google.com/search/docs/crawling-indexing/verifying-googlebot
    protected static $bot_rdns_domains = [
        'googlebot'        => ['.googlebot.com', '.google.com'],
        'google-extended'  => ['.googlebot.com', '.google.com'],
        'googleother'      => ['.googlebot.com', '.google.com'],
        'bingbot'          => ['.search.msn.com'],
        'applebot'         => ['.applebot.apple.com'],
        'yandexbot'        => ['.yandex.com', '.yandex.ru', '.yandex.net'],
        'baiduspider'      => ['.baidu.com', '.baidu.jp'],
        'duckduckbot'      => ['.duckduckgo.com'],
        'facebookexternalhit' => ['.facebook.com', '.fbsv.net', '.fbcdn.net'],
        'linkedinbot'      => ['.linkedin.com'],
        'twitterbot'       => ['.twttr.com', '.twitter.com'],
        'petalbot'         => ['.petalsearch.com', '.aspiegel.com'],
        'qwant'            => ['.qwant.com'],
        // OpenAI — https://platform.openai.com/docs/bots
        'chatgpt-user'     => ['.openai.com'],
        'oai-searchbot'    => ['.openai.com'],
        'gptbot'           => ['.openai.com'],
    ];

    // Bots légitimes : stats uniquement, PAS de log sécurité, PAS de ban.
    // Centralisé ici pour éviter la duplication entre legit_ua_overrides et legit_bots.
    // Inclut les bots NON reconnus par phpBB natif (table phpbb_bots trop ancienne).
    // Pour les bots avec entrée dans $bot_rdns_domains : vérification rDNS obligatoire.
    // Sans rDNS valide → signal fake_legit_bot → ban par fail2ban.
    protected static $legit_bot_uas = [
        // Moteurs de recherche non reconnus par phpBB
        'googleother', 'google-extended', 'qwant',
        // Apple system requests (iOS prefetch, favicon, app-icon)
        'cfnetwork',
        // OpenAI bots (rDNS vérifié via $bot_rdns_domains → .openai.com)
        'chatgpt-user', 'oai-searchbot', 'gptbot',
        // Anthropic
        'claudebot',
    ];

    // Classification des referers
    protected static $referer_types = [
        // Moteurs de recherche
        'google.'       => 'Google',
        'bing.com'      => 'Bing',
        'yahoo.com'     => 'Yahoo',
        'duckduckgo'    => 'DuckDuckGo',
        'qwant.com'     => 'Qwant',
        'ecosia.org'    => 'Ecosia',
        'yandex.'       => 'Yandex',
        'baidu.com'     => 'Baidu',
        // Réseaux sociaux
        'facebook.com'  => 'Facebook',
        'fb.com'        => 'Facebook',
        'instagram.com' => 'Instagram',
        'twitter.com'   => 'Twitter',
        'x.com'         => 'Twitter/X',
        't.co'          => 'Twitter/X',
        'linkedin.com'  => 'LinkedIn',
        'pinterest.'    => 'Pinterest',
        'reddit.com'    => 'Reddit',
        'tiktok.com'    => 'TikTok',
        'youtube.com'   => 'YouTube',
        'youtu.be'      => 'YouTube',
        'discord.com'   => 'Discord',
        'telegram.'     => 'Telegram',
        'whatsapp.'     => 'WhatsApp',
        // Forums/Communautés
        'forum'         => 'Forum externe',
        // Email
        'mail.google'   => 'Gmail',
        'outlook.'      => 'Outlook',
        'mail.yahoo'    => 'Yahoo Mail',
    ];

    public function __construct(
        \phpbb\db\driver\driver_interface $db,
        \phpbb\request\request $request,
        \phpbb\user $user,
        \phpbb\config\config $config,
        \phpbb\template\template $template,
        $table_prefix
    ) {
        $this->db = $db;
        $this->request = $request;
        $this->user = $user;
        $this->config = $config;
        $this->template = $template;
        $this->table_prefix = $table_prefix;
    }

    public static function getSubscribedEvents()
    {
        return [
            'core.page_header_after' => 'log_visit',
            'core.page_footer'       => 'inject_resolution_script',
            'core.obtain_users_online_string_modify' => 'split_online_users',
        ];
    }

    public function log_visit($event)
    {
        // Ne pas logger les requêtes AJAX
        if ($this->request->is_ajax()) {
            return;
        }

        // Vérifier si le tracking est activé
        if (empty($this->config['bastien59_stats_enabled'])) {
            return;
        }

        $time_now = time();
        $session_id = $this->user->session_id;
        $user_agent = $this->user->browser ?? '';
        $user_ip = $this->user->ip;

        // Timeout de session configurable (15 min par défaut)
        $session_timeout = (int)($this->config['bastien59_stats_session_timeout'] ?? 900);

        // 1. Vérifier si c'est la première visite de ce visiteur (basé sur IP + timeout)
        // Un visiteur est "nouveau" s'il n'a pas de visite dans les X dernières minutes
        $sql = 'SELECT log_id, visit_time, session_id as last_session
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE user_ip = \'' . $this->db->sql_escape($user_ip) . '\'
                ORDER BY visit_time DESC';

        $result = $this->db->sql_query_limit($sql, 1);
        $last_row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        // Nouvelle session si : pas de visite OU dernière visite > timeout
        $is_first_visit = 0;
        if ($last_row === false) {
            $is_first_visit = 1;
        } else {
            $time_since_last = $time_now - (int)$last_row['visit_time'];
            if ($time_since_last > $session_timeout) {
                $is_first_visit = 1; // Nouvelle session après timeout
            } else {
                // Même session : utiliser le session_id de la dernière visite pour cohérence
                $session_id = $last_row['last_session'];
            }
        }

        // 2. Mise à jour de la durée de la page PRÉCÉDENTE (même IP, même session)
        if ($last_row && isset($last_row['log_id']) && isset($last_row['visit_time'])) {
            $duration = $time_now - (int)$last_row['visit_time'];
            // Durée raisonnable (max = timeout + marge)
            if ($duration >= 0 && $duration <= $session_timeout) {
                $sql_update = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                               SET duration = ' . (int)$duration . '
                               WHERE log_id = ' . (int)$last_row['log_id'];
                $this->db->sql_query($sql_update);
            }
        }

        // 3. Collecter les données
        // raw_variable() retourne la valeur sans htmlspecialchars()
        // (request->server() applique htmlspecialchars, transformant & en &amp;
        //  ce qui causait des faux positifs sur html_entities_in_url)
        $page_url = $this->request->raw_variable('REQUEST_URI', '', \phpbb\request\request_interface::SERVER);
        $referer = $this->request->raw_variable('HTTP_REFERER', '', \phpbb\request\request_interface::SERVER);

        // Récupérer le vrai referer original passé via _r (redirect cross-domain)
        $original_ref = $this->request->variable('_r', '', true);
        if (!empty($original_ref)) {
            $referer = $original_ref;
            // Nettoyer _r de l'URL stockée
            $page_url = preg_replace('/[?&]_r=[^&]*/', '', $page_url);
            $page_url = preg_replace('/\?&/', '?', $page_url);
            $page_url = rtrim($page_url, '?');
        }

        // Récupérer le vrai titre de la page depuis l'événement
        // (core.page_header_after fournit page_title dans $event)
        $page_title = isset($event['page_title']) ? $event['page_title'] : '';
        if (empty($page_title)) {
            // Fallback : déduire le titre depuis l'URL
            $page_name = $this->user->page['page_name'] ?? '';
            $page_title = $this->get_page_title_from_url($page_name, $page_url);
        }

        // Résolution via cookie
        $screen_res = $this->request->variable('bastien59_stats_res', '', true, \phpbb\request\request_interface::COOKIE);

        // === DÉTECTION DES BOTS (2 couches) ===
        // Couche 1 : Protection bots légitimes (phpBB natif + whitelist + vérif rDNS)
        // Couche 2 : Détection comportementale (signaux impossibles à voir via Apache)

        // 1. Vérifier si bot légitime connu (phpBB natif + notre whitelist)
        $is_legit_bot = !empty($this->user->data['is_bot']);
        $claimed_bot = '';
        if (!$is_legit_bot) {
            $ua_check = strtolower($user_agent);
            foreach (self::$legit_bot_uas as $lp) {
                if (strpos($ua_check, $lp) !== false) {
                    $is_legit_bot = true;
                    $claimed_bot = $lp;
                    break;
                }
            }
        }

        // 2. Si UA prétend être un bot légitime (whitelist) mais PAS reconnu par phpBB natif
        //    → vérifier reverse DNS pour détecter les imposteurs en temps réel
        $fake_bot_signals = [];
        $rdns_fail_reason = '';
        if ($is_legit_bot && empty($this->user->data['is_bot'])) {
            $hostname_check = $this->get_cached_hostname($this->user->ip);
            if (!$this->verify_bot_rdns($claimed_bot, $hostname_check, $rdns_fail_reason)) {
                // IMPOSTEUR ! UA prétend être un bot légitime mais rDNS ne correspond pas
                $fake_bot_signals = ['fake_legit_bot'];
                $is_legit_bot = false; // Pas un vrai bot légitime → continuer détection
            }
        }

        // 3. Détection UA + comportementale (seulement pour visiteurs non-bots-légitimes)
        $is_bot = $is_legit_bot ? 1 : 0;
        $bot_source = $is_legit_bot ? 'phpbb' : '';
        $all_signals = [];

        if (!$is_legit_bot) {
            // Couche 2a : Détection par User-Agent (versions anciennes, patterns bots, anomalies)
            $ua_signals = $this->detect_bot($user_agent);
            // no_browser_signature seul ne suffit pas — protège navigateurs exotiques (Lynx, w3m)
            $strong_ua_signals = array_filter($ua_signals, function($s) { return $s !== 'no_browser_signature'; });

            // Couche 2b : Détection comportementale (signaux impossibles à voir via Apache)
            $behavior_signals = $this->detect_bot_behavior($page_url, $referer, $is_first_visit, $screen_res, $session_id, $user_agent);

            // Combiner : faux bot légitime + UA + comportementaux
            $all_signals = array_merge($fake_bot_signals, $ua_signals, $behavior_signals);

            if (!empty($all_signals)) {
                $is_bot = 1;
                $bot_source = !empty($strong_ua_signals) ? 'extension' : 'behavior';
            }
        }

        // Classification du referer
        $referer_type = $this->classify_referer($referer);

        // Géolocalisation de l'IP (seulement pour la première visite de session)
        $geo_data = ['country_code' => '', 'country_name' => '', 'hostname' => ''];
        if ($is_first_visit) {
            $geo_data = $this->geolocate_ip($this->user->ip);
        }

        // 4. Écriture security_audit.log (bridge vers fail2ban)
        // Ne log que les bots avec signaux détectés (pas phpBB natifs légitimes)
        if (!empty($all_signals)) {
            // Compter les pages dans la session pour le log
            $page_count = 0;
            if (!$is_first_visit) {
                $sql_cnt = 'SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . 'bastien59_stats
                            WHERE session_id = \'' . $this->db->sql_escape($session_id) . '\'';
                $result_cnt = $this->db->sql_query($sql_cnt);
                $page_count = (int)$this->db->sql_fetchfield('cnt');
                $this->db->sql_freeresult($result_cnt);
            }

            $this->write_security_audit(
                $this->user->ip, $session_id,
                (int)$this->user->data['user_id'],
                $all_signals, $user_agent, $page_url,
                $screen_res, $page_count,
                $hostname_check ?? ($geo_data['hostname'] ?? ''),
                $claimed_bot, $rdns_fail_reason
            );
        }

        // 5. Enregistrement
        $sql_ary = [
            'session_id'     => $session_id,
            'user_id'        => (int)$this->user->data['user_id'],
            'user_ip'        => $this->user->ip,
            'user_agent'     => substr($user_agent, 0, 254),
            'user_os'        => $this->get_os($user_agent, $screen_res),
            'user_device'    => $this->get_device($user_agent, $screen_res),
            'screen_res'     => substr($screen_res, 0, 20),
            'is_bot'         => $is_bot,
            'bot_source'     => $bot_source,
            'country_code'   => $geo_data['country_code'],
            'country_name'   => $geo_data['country_name'],
            'hostname'       => $geo_data['hostname'] ?? '',
            'visit_time'     => $time_now,
            'page_url'       => substr($page_url, 0, 65000),
            'page_title'     => substr($page_title, 0, 254),
            'referer'        => substr($referer, 0, 65000),
            'referer_type'   => $referer_type,
            'duration'       => 0,
            'is_first_visit' => $is_first_visit,
            'signals'        => substr(implode(',', $all_signals), 0, 255),
        ];

        $sql = 'INSERT INTO ' . $this->table_prefix . 'bastien59_stats ' . $this->db->sql_build_array('INSERT', $sql_ary);
        $this->db->sql_query($sql);

        // 5. Nettoyage automatique (1 chance sur 100)
        // Rétention différenciée : 5 jours pour les bots, 30 jours pour les humains
        if (mt_rand(1, 100) === 1) {
            $retention_humans = (int)($this->config['bastien59_stats_retention'] ?? 30);
            $retention_bots = (int)($this->config['bastien59_stats_retention_bots'] ?? 5);

            // Supprimer les vieux bots (5 jours)
            if ($retention_bots > 0) {
                $cutoff_bots = time() - ($retention_bots * 86400);
                $sql_clean = 'DELETE FROM ' . $this->table_prefix . 'bastien59_stats
                              WHERE is_bot = 1 AND visit_time < ' . $cutoff_bots;
                $this->db->sql_query($sql_clean);
            }

            // Supprimer les vieux humains (30 jours)
            if ($retention_humans > 0) {
                $cutoff_humans = time() - ($retention_humans * 86400);
                $sql_clean = 'DELETE FROM ' . $this->table_prefix . 'bastien59_stats
                              WHERE is_bot = 0 AND visit_time < ' . $cutoff_humans;
                $this->db->sql_query($sql_clean);
            }
        }
    }

    /**
     * Sépare la liste "Qui est en ligne" en humains et robots sur deux lignes
     */
    public function split_online_users($event)
    {
        $rowset = $event['rowset'];
        $user_online_link = $event['user_online_link'];

        if (empty($rowset) || empty($user_online_link)) {
            return;
        }

        // Construire un index par user_id (rowset est indexé numériquement)
        $users_by_id = [];
        foreach ($rowset as $row) {
            $users_by_id[$row['user_id']] = $row;
        }

        $humans = [];
        $bots = [];

        foreach ($user_online_link as $user_id => $link) {
            if (isset($users_by_id[$user_id]) && $users_by_id[$user_id]['user_type'] == USER_IGNORE) {
                $bots[] = $link;
            } else {
                $humans[] = $link;
            }
        }

        $parts = [];
        if (!empty($humans)) {
            $parts[] = 'Membres : ' . implode(', ', $humans);
        }
        if (!empty($bots)) {
            $parts[] = 'Robots : ' . implode(', ', $bots);
        }

        if (!empty($parts)) {
            $event['online_userlist'] = implode('<br />', $parts);
        }
    }

    public function inject_resolution_script($event)
    {
        // Script léger pour capturer la résolution d'écran
        $script = '<script>
        (function(){
            if(!document.cookie.match(/bastien59_stats_res/)){
                var d = new Date();
                d.setTime(d.getTime() + (30*24*60*60*1000));
                var res = window.screen.width + "x" + window.screen.height;
                document.cookie = "bastien59_stats_res=" + res + ";path=/;expires="+d.toUTCString()+";SameSite=Lax";
            }
            // Nettoyer le parametre _r de l URL (referer original du redirect)
            if(window.history&&window.history.replaceState&&location.search.indexOf("_r=")>-1){
                var u=new URL(location.href);u.searchParams.delete("_r");
                window.history.replaceState(null,"",u.toString());
            }
        })();
        </script>';

        $this->template->append_var('RUN_CRON_TASK', $script);
    }

    /**
     * Déduit un titre de page depuis le nom de fichier et l'URL
     */
    private function get_page_title_from_url($page_name, $page_url)
    {
        $titles = [
            'index.php'       => 'Index du forum',
            'viewforum.php'   => 'Forum',
            'viewtopic.php'   => 'Sujet',
            'posting.php'     => 'Publication',
            'memberlist.php'  => 'Liste des membres',
            'ucp.php'         => 'Panneau utilisateur',
            'mcp.php'         => 'Panneau modérateur',
            'search.php'      => 'Recherche',
            'faq.php'         => 'FAQ',
            'feed.php'        => 'Flux RSS',
        ];

        foreach ($titles as $file => $title) {
            if (strpos($page_name, $file) !== false) {
                return $title;
            }
        }

        return $page_name ?: 'Index';
    }

    /**
     * Détection des bots par User-Agent (versions anciennes, patterns, anomalies)
     * @return array Liste des signaux UA détectés (vide = UA semble légitime)
     */
    private function detect_bot($user_agent)
    {
        $signals = [];

        // User-Agent vide = très suspect
        if (empty($user_agent)) {
            return ['empty_ua'];
        }

        $ua_lower = strtolower($user_agent);

        // Patterns de bots connus dans le UA
        $bot_keywords = [
            'bot/', 'bot;', 'crawler', 'spider', 'scraper', 'headlesschrome',
            'phantomjs', 'selenium', 'puppeteer', 'scrapy', 'nutch',
            'python-requests', 'python-urllib', 'java/', 'httpclient',
            'okhttp', 'axios', 'node-fetch', 'wget', 'curl/',
            'go-http-client', 'mozlila/', 'bulid/',
        ];
        foreach ($bot_keywords as $pattern) {
            if (strpos($ua_lower, $pattern) !== false) {
                $signals[] = 'ua_pattern';
                break;
            }
        }

        // Pas de navigateur reconnu dans le UA
        $browsers = ['mozilla', 'chrome', 'safari', 'firefox', 'edge', 'opera', 'msie', 'trident'];
        $has_browser = false;
        foreach ($browsers as $browser) {
            if (strpos($ua_lower, $browser) !== false) {
                $has_browser = true;
                break;
            }
        }
        if (!$has_browser) {
            $signals[] = 'no_browser_signature';
        }

        // Fake Chrome build numbers (botnet pattern)
        // Real Chrome 120+ builds are in the 6000-7999 range
        if (preg_match('/Chrome\/(\d+)\.0\.(\d+)\.(\d+)/', $user_agent, $matches)) {
            $chrome_major = (int)$matches[1];
            $chrome_build = (int)$matches[2];
            $chrome_patch = (int)$matches[3];
            // Chrome/XXX.0.0.0 = PAS exploitable comme signal bot.
            // Brave Browser masque volontairement le build Chrome (.0.0.0) pour l'anti-fingerprinting.
            if ($chrome_build !== 0 || $chrome_patch !== 0) {
                if ($chrome_major >= 120 && ($chrome_build < 6000 || $chrome_build > 7999)) {
                    $signals[] = 'fake_chrome_build';
                }
            }
        }

        // Firefox < 30 (2014)
        if (preg_match('/Firefox\/(\d+)\./', $user_agent, $matches)) {
            if ((int)$matches[1] < 30) {
                $signals[] = 'old_firefox';
            }
        }

        // Impossible Gecko dates
        if (preg_match('/Gecko\/(\d{4})-/', $user_agent, $matches)) {
            $gecko_year = (int)$matches[1];
            if ($gecko_year > 2030 || $gecko_year < 2000) {
                $signals[] = 'bad_gecko_date';
            }
        }

        // Chrome < 130 = trop ancien pour être réel (Chrome 130 = oct 2024)
        if (preg_match('/Chrome\/(\d+)\./', $user_agent, $matches)) {
            $chromeVer = (int)$matches[1];
            if ($chromeVer < 130 && $chromeVer > 0 && strpos($ua_lower, 'headlesschrome') === false) {
                $signals[] = 'old_chrome_' . $chromeVer;
            }
        }

        // Safari build number fake (réel >= 400)
        if (preg_match('/Safari\/(\d+)\./', $user_agent, $matches)) {
            $safari_build = (int)$matches[1];
            if ($safari_build < 400 && $safari_build > 0) {
                $signals[] = 'fake_safari_build';
            }
        }

        // Template literal non résolu dans le UA (ex: Firefox/{version})
        if (strpos($user_agent, '{') !== false && strpos($user_agent, '}') !== false) {
            $signals[] = 'template_literal';
        }

        // iPhone OS 13_2_3 figé = botnet Tencent Cloud
        if (strpos($user_agent, 'iPhone OS 13_2_3') !== false) {
            $signals[] = 'iphone_13_2_3';
        }

        return $signals;
    }

    /**
     * Détection comportementale des bots (UA valide mais comportement impossible)
     * @return array Liste des signaux comportementaux détectés (vide = pas un bot)
     */
    private function detect_bot_behavior($page_url, $referer, $is_first_visit, $screen_res, $session_id, $user_agent = '')
    {
        $signals = [];
        $user_id = (int)$this->user->data['user_id'];
        if ($user_id > 1) {
            return $signals; // Membres connectés = jamais flag
        }

        // Bots légitimes connus — ils n'exécutent pas JS (pas de screen_res) et c'est NORMAL.
        // Ne pas les flagger en comportemental même si phpBB ne les reconnaît pas nativement.
        // Inclut $legit_bot_uas + bots phpBB natifs courants (filet de sécurité)
        $ua_lower = strtolower($user_agent);
        $behavior_safe_bots = array_merge(self::$legit_bot_uas, [
            'googlebot', 'bingbot', 'applebot', 'yandexbot', 'duckduckbot',
            'baiduspider', 'petalbot', 'facebookexternalhit', 'linkedinbot',
            'twitterbot', 'claudebot', 'gptbot', 'amazonbot', 'bytespider',
            'seznambot', 'archive.org_bot', 'ccbot',
        ]);
        foreach ($behavior_safe_bots as $lb) {
            if (strpos($ua_lower, $lb) !== false) {
                return $signals; // Bot légitime = pas de détection comportementale
            }
        }

        $page_lower = strtolower($page_url);

        // Signal 1 : Invité atterrit sur posting.php en première visite
        if ($is_first_visit && strpos($page_lower, 'posting.php') !== false) {
            $signals[] = 'posting_first_visit';
        }

        // Signal 2 : Referer auto-référent sur posting.php (boucle GET)
        if (strpos($page_lower, 'posting.php') !== false && !empty($referer)) {
            if (strpos(strtolower($referer), 'posting.php') !== false) {
                preg_match('/[?&]p=(\d+)/', $page_url, $page_m);
                preg_match('/[?&]p=(\d+)/', $referer, $ref_m);
                if (!empty($page_m[1]) && !empty($ref_m[1]) && $page_m[1] === $ref_m[1]) {
                    $signals[] = 'posting_get_loop';
                }
            }
        }

        // Signal 3 : Invité sans screen resolution après 3+ pages (pas d'exécution JS)
        if (!$is_first_visit && empty($screen_res)) {
            $sql = 'SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . 'bastien59_stats
                    WHERE session_id = \'' . $this->db->sql_escape($session_id) . '\'';
            $result = $this->db->sql_query($sql);
            $page_count = (int)$this->db->sql_fetchfield('cnt');
            $this->db->sql_freeresult($result);
            if ($page_count >= 3) {
                $signals[] = 'no_screen_res';
            }
        }

        // Signal 4 : Entités HTML dans l'URL (scraper qui parse le HTML source)
        // $page_url et $referer sont déjà raw (via raw_variable), pas d'htmlspecialchars.
        // Un vrai navigateur ne met JAMAIS &amp; ou amp%3B dans l'URL HTTP réelle.
        if (preg_match('/&amp;|amp%3[Bb]/', $page_url) || preg_match('/&amp;|amp%3[Bb]/', $referer)) {
            $signals[] = 'html_entities_in_url';
        }

        return $signals;
    }

    /**
     * Écriture dans /var/log/security_audit.log pour le bridge fail2ban
     * Format PHPBB-SIGNAL : signaux bruts, pas de score (scoring externe dans collect.php)
     */
    private function write_security_audit($ip, $session_id, $user_id, $all_signals, $user_agent, $page_url, $screen_res, $page_count, $hostname, $claimed_bot, $rdns_fail_reason = '')
    {
        $log_file = '/var/log/security_audit.log';

        // Déduplication : max 1 log par session+signaux par heure
        // Empêche qu'un utilisateur qui navigue N pages avec le même faux signal
        // accumule N hits et déclenche un ban (phpbb-badbot-suspicious maxretry=3)
        $signals_str = implode(',', $all_signals);
        $dedup_key = md5($session_id . '|' . $signals_str);
        $dedup_file = sys_get_temp_dir() . '/sec_audit_' . $dedup_key;
        if (@file_exists($dedup_file) && (time() - @filemtime($dedup_file)) < 3600) {
            return; // Déjà loggé pour cette session + ces signaux dans la dernière heure
        }
        @touch($dedup_file);

        // Construire la ligne de log (paires clé=valeur)
        $ts = date('Y-m-d H:i:s');

        // Échapper les guillemets dans les valeurs quotées
        $ua_safe = str_replace('"', '\\"', substr($user_agent, 0, 500));
        $page_safe = str_replace('"', '\\"', substr($page_url, 0, 500));

        $line = sprintf(
            '%s PHPBB-SIGNAL ip=%s session=%s user_id=%d signals="%s" page="%s" ua="%s" screen_res=%s page_count=%d',
            $ts, $ip, $session_id, (int)$user_id,
            $signals_str, $page_safe, $ua_safe,
            $screen_res ?: '-', (int)$page_count
        );

        // Ajouter hostname, claimed_bot et raison d'échec pour fake_legit_bot
        if (in_array('fake_legit_bot', $all_signals)) {
            $line .= sprintf(' hostname=%s claimed_bot=%s rdns_reason=%s',
                $hostname ?: '-', $claimed_bot ?: '-', $rdns_fail_reason ?: '-');
        }

        // Écriture avec verrouillage (échec silencieux)
        @file_put_contents($log_file, $line . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Vérifie le reverse DNS d'un bot prétendu légitime
     * @return bool true si le hostname correspond au bot, false si imposteur
     */
    private function verify_bot_rdns($claimed_bot, $hostname, &$fail_reason = '')
    {
        if (empty($hostname) || $hostname === '-') {
            $fail_reason = 'no_rdns';
            return false;
        }
        $domains = self::$bot_rdns_domains[strtolower($claimed_bot)] ?? null;
        if ($domains === null) {
            return true; // Bot non listé dans rdns_domains = on ne vérifie pas
        }
        // Étape 1 : rDNS doit correspondre à un domaine attendu
        $hn_lower = strtolower($hostname);
        $rdns_match = false;
        foreach ($domains as $domain) {
            if (substr($hn_lower, -strlen($domain)) === $domain) {
                $rdns_match = true;
                break;
            }
        }
        if (!$rdns_match) {
            $fail_reason = 'rdns_mismatch';
            return false;
        }
        // Étape 2 : Forward DNS validation (le hostname doit résoudre vers l'IP originale)
        $ip = $this->user->ip;
        $forward_ips = @gethostbynamel($hostname);
        if ($forward_ips === false) {
            // Forward DNS a échoué — vérifier aussi les AAAA pour IPv6
            $aaaa = @dns_get_record($hostname, DNS_AAAA);
            if (!empty($aaaa)) {
                foreach ($aaaa as $rec) {
                    if (isset($rec['ipv6']) && $rec['ipv6'] === $ip) {
                        return true;
                    }
                }
            }
            $fail_reason = 'fdns_failed';
            return false;
        }
        if (!in_array($ip, $forward_ips)) {
            $fail_reason = 'fdns_mismatch';
            return false;
        }
        return true;
    }

    /**
     * Récupère le hostname depuis le geo_cache ou via gethostbyaddr()
     */
    private function get_cached_hostname($ip)
    {
        // Essayer le cache géo en premier (rapide, pas de réseau)
        $geo = $this->get_geo_cache($ip);
        if ($geo && !empty($geo['hostname'])) {
            return $geo['hostname'];
        }
        // Résolution DNS rapide (timeout par défaut du système)
        $hostname = @gethostbyaddr($ip);
        return ($hostname && $hostname !== $ip) ? $hostname : '-';
    }

    /**
     * Classification du referer
     */
    private function classify_referer($referer)
    {
        if (empty($referer)) {
            return 'Direct';
        }

        $referer_lower = strtolower($referer);

        // Vérifier si c'est une navigation interne (domaine actuel + anciens domaines)
        $server_name = $this->request->server('SERVER_NAME', '');
        $internal_domains = [$server_name, 'bernard.debucquoi.com'];
        foreach ($internal_domains as $domain) {
            if (!empty($domain) && strpos($referer_lower, strtolower($domain)) !== false) {
                return 'Interne';
            }
        }

        // Classifier selon les patterns connus
        foreach (self::$referer_types as $pattern => $type) {
            if (strpos($referer_lower, $pattern) !== false) {
                return $type;
            }
        }

        // Extraire le domaine pour les autres
        $parsed = @parse_url($referer);
        if (isset($parsed['host'])) {
            return 'Externe: ' . $parsed['host'];
        }

        return 'Autre';
    }

    /**
     * Détection de l'OS
     * Utilise le User-Agent ET la résolution d'écran pour plus de précision
     */
    private function get_os($ua, $screen_res = '')
    {
        if (empty($ua)) {
            return 'Inconnu';
        }

        $ua_lower = strtolower($ua);

        $os_patterns = [
            'windows nt 10'   => 'Windows 10/11',
            'windows nt 6.3'  => 'Windows 8.1',
            'windows nt 6.2'  => 'Windows 8',
            'windows nt 6.1'  => 'Windows 7',
            'windows nt 6.0'  => 'Windows Vista',
            'windows nt 5.1'  => 'Windows XP',
            'windows phone'   => 'Windows Phone',
            'macintosh'       => 'macOS',
            'mac os x'        => 'macOS',
            'cros'            => 'Chrome OS',
            'android'         => 'Android',
            'iphone'          => 'iOS',
            'ipad'            => 'iPadOS',
            'linux'           => 'Linux',
            'ubuntu'          => 'Ubuntu',
            'fedora'          => 'Fedora',
            'freebsd'         => 'FreeBSD',
        ];

        foreach ($os_patterns as $pattern => $os_name) {
            if (strpos($ua_lower, $pattern) !== false) {
                // Cas spécial : Linux avec résolution mobile = probablement Android en mode desktop
                if ($os_name === 'Linux' && !empty($screen_res)) {
                    if (preg_match('/^(\d+)x(\d+)$/', $screen_res, $matches)) {
                        $width = (int)$matches[1];
                        if ($width < 600) {
                            return 'Android (mode desktop)';
                        }
                    }
                }
                return $os_name;
            }
        }

        return 'Autre';
    }

    /**
     * Détection du type d'appareil
     * Utilise le User-Agent ET la résolution d'écran pour plus de précision
     */
    private function get_device($ua, $screen_res = '')
    {
        if (empty($ua)) {
            return 'Inconnu';
        }

        $ua_lower = strtolower($ua);

        // Mobile d'abord (ordre important)
        $mobile_patterns = ['iphone', 'android', 'mobile', 'phone', 'ipod', 'blackberry', 'opera mini', 'iemobile'];
        foreach ($mobile_patterns as $pattern) {
            if (strpos($ua_lower, $pattern) !== false) {
                // Vérifier si c'est pas une tablette Android
                if ($pattern === 'android' && strpos($ua_lower, 'mobile') === false) {
                    continue; // C'est probablement une tablette
                }
                return 'Mobile';
            }
        }

        // Tablettes
        $tablet_patterns = ['ipad', 'tablet', 'kindle', 'silk', 'playbook'];
        foreach ($tablet_patterns as $pattern) {
            if (strpos($ua_lower, $pattern) !== false) {
                return 'Tablette';
            }
        }

        // Android sans "mobile" = tablette
        if (strpos($ua_lower, 'android') !== false && strpos($ua_lower, 'mobile') === false) {
            return 'Tablette';
        }

        // Détection par résolution d'écran (pour les navigateurs en mode desktop)
        // Les mobiles ont généralement une largeur < 500px
        if (!empty($screen_res) && preg_match('/^(\d+)x(\d+)$/', $screen_res, $matches)) {
            $width = (int)$matches[1];
            $height = (int)$matches[2];

            // Portrait mobile (largeur < 500)
            if ($width < 500) {
                return 'Mobile';
            }
            // Tablette portrait ou mobile paysage
            if ($width >= 500 && $width < 800 && $height > $width) {
                return 'Mobile';
            }
            // Tablette
            if ($width >= 600 && $width < 1024 && $height >= 800) {
                return 'Tablette';
            }
        }

        return 'Desktop';
    }

    /**
     * Géolocalise une adresse IP via ip-api.com avec cache + DNS reverse
     */
    private function geolocate_ip($ip)
    {
        $default = ['country_code' => '', 'country_name' => '', 'hostname' => ''];

        // Ignorer les IPs locales
        if ($this->is_local_ip($ip)) {
            return ['country_code' => 'LO', 'country_name' => 'Local', 'hostname' => 'localhost'];
        }

        // Vérifier le cache d'abord
        $cached = $this->get_geo_cache($ip);
        if ($cached !== false) {
            return $cached;
        }

        // DNS Reverse Lookup (avec timeout court)
        $hostname = @gethostbyaddr($ip);
        if ($hostname === $ip) {
            $hostname = ''; // Pas de résolution
        }

        // Appel API ip-api.com (gratuit, 45 req/min)
        $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,city';

        $context = stream_context_create([
            'http' => [
                'timeout' => 2,
                'ignore_errors' => true,
            ]
        ]);

        $response = @file_get_contents($url, false, $context);

        $result = [
            'country_code' => '',
            'country_name' => '',
            'city'         => '',
            'hostname'     => substr($hostname, 0, 255),
        ];

        if ($response !== false) {
            $data = @json_decode($response, true);

            if ($data && $data['status'] === 'success') {
                $result['country_code'] = substr($data['countryCode'] ?? '', 0, 5);
                $result['country_name'] = substr($data['country'] ?? '', 0, 100);
                $result['city']         = substr($data['city'] ?? '', 0, 100);
            }
        }

        // Mettre en cache (expire après 30 jours)
        $this->set_geo_cache($ip, $result);

        return $result;
    }

    /**
     * Vérifie si l'IP est locale/privée
     */
    private function is_local_ip($ip)
    {
        // IPv4 privées
        if (preg_match('/^(10\.|172\.(1[6-9]|2[0-9]|3[01])\.|192\.168\.|127\.)/', $ip)) {
            return true;
        }
        // IPv6 locales
        if (preg_match('/^(::1|fe80:|fc00:|fd00:)/i', $ip)) {
            return true;
        }
        return false;
    }

    /**
     * Récupère les données géo depuis le cache
     */
    private function get_geo_cache($ip)
    {
        $sql = 'SELECT country_code, country_name, city, hostname FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache
                WHERE ip_address = \'' . $this->db->sql_escape($ip) . '\'
                AND cached_time > ' . (time() - 30 * 86400);

        $result = $this->db->sql_query($sql);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($row) {
            return [
                'country_code' => $row['country_code'],
                'country_name' => $row['country_name'],
                'city'         => $row['city'] ?? '',
                'hostname'     => $row['hostname'] ?? '',
            ];
        }

        return false;
    }

    /**
     * Stocke les données géo en cache
     */
    private function set_geo_cache($ip, $data)
    {
        // Supprimer l'ancienne entrée si elle existe
        $sql = 'DELETE FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache
                WHERE ip_address = \'' . $this->db->sql_escape($ip) . '\'';
        $this->db->sql_query($sql);

        // Insérer la nouvelle
        $sql_ary = [
            'ip_address'   => $ip,
            'country_code' => $data['country_code'],
            'country_name' => $data['country_name'],
            'city'         => $data['city'] ?? '',
            'hostname'     => $data['hostname'] ?? '',
            'cached_time'  => time(),
        ];

        $sql = 'INSERT INTO ' . $this->table_prefix . 'bastien59_stats_geo_cache ' .
               $this->db->sql_build_array('INSERT', $sql_ary);
        $this->db->sql_query($sql);
    }
}
