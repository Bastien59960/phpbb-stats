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
        $referer = $this->request->header('Referer', '');
        $page_url = $this->request->server('REQUEST_URI');

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
        $is_extension_bot = $this->detect_bot($user_agent);
        $is_bot = ($is_phpbb_bot || $is_extension_bot) ? 1 : 0;

        // Source de détection du bot
        $bot_source = '';
        if ($is_bot) {
            $bot_source = $is_phpbb_bot ? 'phpbb' : 'extension';
        }

        // Détection comportementale (bots avec UA valide mais comportement impossible)
        if (!$is_bot) {
            if ($this->detect_bot_behavior($page_url, $referer, $is_first_visit, $screen_res, $session_id)) {
                $is_bot = 1;
                $bot_source = 'behavior';
            }
        }

        // Classification du referer
        $referer_type = $this->classify_referer($referer);

        // Géolocalisation de l'IP (seulement pour la première visite de session)
        $geo_data = ['country_code' => '', 'country_name' => '', 'hostname' => ''];
        if ($is_first_visit) {
            $geo_data = $this->geolocate_ip($this->user->ip);
        }

        // 4. Enregistrement
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
     * Détection avancée des bots
     */
    private function detect_bot($user_agent)
    {
        // phpBB a déjà détecté un bot
        if (!empty($this->user->data['is_bot'])) {
            return 1;
        }

        // User-Agent vide = très suspect
        if (empty($user_agent)) {
            return 1;
        }

        $ua_lower = strtolower($user_agent);

        // Vérifier contre notre liste de patterns
        foreach (self::$bot_patterns as $pattern) {
            if (strpos($ua_lower, $pattern) !== false) {
                return 1;
            }
        }

        // Heuristiques supplémentaires
        // - Pas de navigateur reconnu dans le UA
        $browsers = ['mozilla', 'chrome', 'safari', 'firefox', 'edge', 'opera', 'msie', 'trident'];
        $has_browser = false;
        foreach ($browsers as $browser) {
            if (strpos($ua_lower, $browser) !== false) {
                $has_browser = true;
                break;
            }
        }

        if (!$has_browser) {
            return 1;
        }

        // Detect fake Chrome build numbers (botnet pattern)
        // Real Chrome 120+ builds are in the 6000-7999 range
        // Fake bots use random build numbers (e.g. Chrome/122.0.4877.833)
        // Skip reduced/frozen UA (Chrome/XXX.0.0.0) which is legitimate
        if (preg_match('/Chrome\/(\d+)\.0\.(\d+)\.(\d+)/', $user_agent, $matches)) {
            $chrome_major = (int)$matches[1];
            $chrome_build = (int)$matches[2];
            $chrome_patch = (int)$matches[3];

            if (!($chrome_build === 0 && $chrome_patch === 0)) {
                if ($chrome_major >= 120 && ($chrome_build < 6000 || $chrome_build > 7999)) {
                    return 1;
                }
            }
        }

        // Detect ancient/fabricated browser versions
        // Firefox < 30 (2014), impossible Gecko dates
        if (preg_match('/Firefox\/(\d+)\./', $user_agent, $matches)) {
            if ((int)$matches[1] < 30) {
                return 1;
            }
        }
        if (preg_match('/Gecko\/(\d{4})-/', $user_agent, $matches)) {
            $gecko_year = (int)$matches[1];
            if ($gecko_year > 2030 || $gecko_year < 2000) {
                return 1;
            }
        }

        // Chrome < 30 (2013) = trop ancien pour être réel
        if (preg_match('/Chrome\/(\d+)\./', $user_agent, $matches)) {
            if ((int)$matches[1] < 30 && strpos($ua_lower, 'headlesschrome') === false) {
                return 1;
            }
        }

        // Safari build number fake (réel = 604.x ou 605.x, pas 184.x)
        if (preg_match('/Safari\/(\d+)\./', $user_agent, $matches)) {
            $safari_build = (int)$matches[1];
            // Les vrais Safari builds sont >= 400 (Safari 3+)
            if ($safari_build < 400 && $safari_build > 0) {
                return 1;
            }
        }

        // Template literal non résolu dans le UA (ex: Firefox/{version})
        if (strpos($user_agent, '{') !== false && strpos($user_agent, '}') !== false) {
            return 1;
        }

        // iPhone OS 13_2_3 figé = botnet de scraping (Tencent Cloud et similaires)
        // Ce UA exact est utilisé massivement par des bots cloud
        if (strpos($user_agent, 'iPhone OS 13_2_3') !== false) {
            return 1;
        }

        return 0;
    }

    /**
     * Détection comportementale des bots (UA valide mais comportement impossible)
     */
    private function detect_bot_behavior($page_url, $referer, $is_first_visit, $screen_res, $session_id)
    {
        $user_id = (int)$this->user->data['user_id'];
        if ($user_id > 1) {
            return false; // Membres connectés = jamais flag
        }

        $page_lower = strtolower($page_url);

        // Signal 1 : Invité atterrit sur posting.php en première visite
        if ($is_first_visit && strpos($page_lower, 'posting.php') !== false) {
            return true;
        }

        // Signal 2 : Referer auto-référent sur posting.php (GET)
        if (strpos($page_lower, 'posting.php') !== false && !empty($referer)) {
            if (strpos(strtolower($referer), 'posting.php') !== false) {
                preg_match('/[?&]p=(\d+)/', $page_url, $page_m);
                preg_match('/[?&]p=(\d+)/', $referer, $ref_m);
                if (!empty($page_m[1]) && !empty($ref_m[1]) && $page_m[1] === $ref_m[1]) {
                    return true;
                }
            }
        }

        // Signal 3 : Invité sans screen resolution après 5+ pages dans la session
        if (!$is_first_visit && empty($screen_res)) {
            $sql = 'SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . 'bastien59_stats
                    WHERE session_id = \'' . $this->db->sql_escape($session_id) . '\'';
            $result = $this->db->sql_query($sql);
            $page_count = (int)$this->db->sql_fetchfield('cnt');
            $this->db->sql_freeresult($result);
            if ($page_count >= 5) {
                return true;
            }
        }

        return false;
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
