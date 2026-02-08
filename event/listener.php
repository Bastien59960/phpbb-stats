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

    // Liste des bots connus (User-Agent patterns)
    protected static $bot_patterns = [
        // Moteurs de recherche
        'googlebot', 'bingbot', 'yandexbot', 'baiduspider', 'duckduckbot',
        'slurp', 'sogou', 'exabot', 'facebot', 'ia_archiver',
        // Crawlers
        'crawler', 'spider', 'bot/', 'bot;', 'bot ',
        'crawl', 'slurp', 'mediapartners', 'adsbot',
        // Outils SEO
        'semrush', 'ahrefs', 'moz.com', 'majestic', 'dotbot',
        'rogerbot', 'screaming', 'seokicks', 'sistrix',
        // Réseaux sociaux
        'facebookexternalhit', 'twitterbot', 'linkedinbot', 'pinterest',
        'whatsapp', 'telegrambot', 'slackbot', 'discordbot',
        // Monitoring
        'uptimerobot', 'pingdom', 'statuscake', 'newrelicpinger',
        'jetmon', 'site24x7', 'monitis',
        // Autres
        'wget', 'curl/', 'python-requests', 'python-urllib',
        'java/', 'httpclient', 'okhttp', 'axios', 'node-fetch',
        'headlesschrome', 'phantomjs', 'selenium', 'puppeteer',
        'scrapy', 'nutch', 'archive.org_bot', 'ccbot',
        'applebot', 'petalbot', 'bytespider', 'gptbot', 'claudebot',
        'chatgpt', 'amazonbot', 'dataprovider', 'megaindex', 'blexbot',
        'mj12bot', 'ahrefsbot', 'seznambot', 'yacybot',
        // Google services
        'googledocs', 'google-read-aloud', 'storebot-google', 'google-inspectiontool',
        'feedfetcher-google', 'apis-google', 'mediapartners-google',
        // Patterns de bots avec typos/malformations
        'mozlila/', 'bulid/',
        // Outils divers
        'sitesucker', 'expo-research', 'trendictionbot', 'amzn-searchbot',
        'wpmu dev', 'broken link checker',
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

        // Détection avancée des bots
        // Vérifier d'abord si phpBB l'a détecté comme bot (présent dans la table bots)
        $is_phpbb_bot = !empty($this->user->data['is_bot']);
        $ua_signals = $is_phpbb_bot ? [] : $this->detect_bot($user_agent);
        // no_browser_signature seul ne suffit pas — protège navigateurs exotiques (Lynx, w3m, UA custom vie privée)
        $strong_ua_signals = array_filter($ua_signals, function($s) { return $s !== 'no_browser_signature'; });
        $is_bot = ($is_phpbb_bot || !empty($strong_ua_signals)) ? 1 : 0;

        // Source de détection et signaux collectés
        $bot_source = '';
        $all_signals = [];
        if ($is_bot) {
            if ($is_phpbb_bot) {
                $bot_source = 'phpbb';
            } else {
                $bot_source = 'extension';
                $all_signals = $ua_signals; // inclut no_browser_signature pour le scoring
            }
        }

        // Détection comportementale (bots avec UA valide mais comportement impossible)
        if (!$is_bot) {
            $behavior_signals = $this->detect_bot_behavior($page_url, $referer, $is_first_visit, $screen_res, $session_id, $user_agent);
            if (!empty($behavior_signals)) {
                $is_bot = 1;
                $bot_source = 'behavior';
                // Ajouter no_browser_signature comme renfort si présent dans l'UA
                $all_signals = !empty($ua_signals) ? array_merge($behavior_signals, array_intersect($ua_signals, ['no_browser_signature'])) : $behavior_signals;
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
        // Ne log que les bots détectés par l'extension ou le comportement (pas phpBB natifs)
        if ($is_bot && $bot_source !== 'phpbb') {
            $this->write_security_audit(
                $this->user->ip, $session_id, $bot_source, $all_signals,
                $user_agent, $page_url, $referer,
                $geo_data['country_code'], $geo_data['hostname'] ?? ''
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
     * Détection avancée des bots par analyse du User-Agent
     * @return array Liste des signaux détectés (vide = pas un bot)
     */
    private function detect_bot($user_agent)
    {
        $signals = [];

        // User-Agent vide = très suspect
        if (empty($user_agent)) {
            return ['empty_ua'];
        }

        $ua_lower = strtolower($user_agent);

        // Vérifier contre notre liste de patterns
        foreach (self::$bot_patterns as $pattern) {
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
            if (!($chrome_build === 0 && $chrome_patch === 0)) {
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
        // Les botnets (Tencent Cloud etc.) utilisent des versions 103-129
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
        $ua_lower = strtolower($user_agent);
        $legit_bots = ['googlebot', 'bingbot', 'applebot', 'yandexbot', 'duckduckbot',
                        'baiduspider', 'qwant', 'petalbot', 'facebookexternalhit', 'linkedinbot',
                        'twitterbot', 'claudebot', 'gptbot', 'amazonbot', 'bytespider',
                        'seznambot', 'archive.org_bot', 'ccbot'];
        foreach ($legit_bots as $lb) {
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
     * Calcul du score de danger (0-100) basé sur les signaux détectés
     */
    private function compute_danger_score($all_signals, $hostname)
    {
        $signal_scores = [
            'empty_ua'              => 80,
            'ua_pattern'            => 70,
            'no_browser_signature'  => 25,
            'template_literal'      => 70,
            'posting_first_visit'   => 65,
            'posting_get_loop'      => 65,
            'iphone_13_2_3'         => 60,
            'fake_chrome_build'     => 55,
            'old_firefox'           => 55,
            'bad_gecko_date'        => 50,
            'fake_safari_build'     => 50,
            'html_entities_in_url'  => 45,
            'no_screen_res'         => 35,
        ];

        $score = 0;
        foreach ($all_signals as $signal) {
            if (isset($signal_scores[$signal])) {
                $score += $signal_scores[$signal];
            } elseif (strpos($signal, 'old_chrome_') === 0) {
                $score += 50;
            }
        }

        // Bonus datacenter hostname
        if (!empty($hostname) && $hostname !== '' && $hostname !== '-') {
            $dc_patterns = ['amazonaws', 'googleusercontent', 'azure', 'ovh.net', 'hetzner',
                            'digitalocean', 'linode', 'vultr', 'contabo', 'cloudfront',
                            'tencent', 'alicloud', 'aliyun', 'scaleway'];
            $hn_lower = strtolower($hostname);
            foreach ($dc_patterns as $dc) {
                if (strpos($hn_lower, $dc) !== false) {
                    $score += 15;
                    break;
                }
            }
        }

        return min(100, $score);
    }

    /**
     * Écriture dans /var/log/security_audit.log pour le bridge fail2ban
     * Format: clé=valeur parseable par regex fail2ban ET PHP collect.php
     */
    private function write_security_audit($ip, $session_id, $bot_source, $all_signals, $user_agent, $page_url, $referer, $country_code, $hostname)
    {
        $log_file = '/var/log/security_audit.log';

        $score = $this->compute_danger_score($all_signals, $hostname);
        $level = ($score >= 50) ? 'confirmed' : 'suspicious';

        // Déterminer le type de détection
        $detection = $bot_source; // 'extension' ou 'behavior'

        // Construire la ligne de log (paires clé=valeur)
        $signals_str = implode(',', $all_signals);
        $ts = date('Y-m-d H:i:s');

        // Échapper les guillemets dans les valeurs quotées
        $ua_safe = str_replace('"', '\\"', substr($user_agent, 0, 500));
        $page_safe = str_replace('"', '\\"', substr($page_url, 0, 500));
        $ref_safe = str_replace('"', '\\"', substr($referer ?: '-', 0, 500));

        $line = sprintf(
            '%s BOT-DETECT level=%s ip=%s session=%s detection=%s score=%d ua="%s" page="%s" referer="%s" signals="%s" country=%s hostname=%s',
            $ts, $level, $ip, $session_id, $detection, $score,
            $ua_safe, $page_safe, $ref_safe, $signals_str,
            $country_code ?: '-', $hostname ?: '-'
        );

        // Écriture avec verrouillage (échec silencieux)
        @file_put_contents($log_file, $line . "\n", FILE_APPEND | LOCK_EX);
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
