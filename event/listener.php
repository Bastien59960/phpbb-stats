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
    protected $helper;
    protected $table_prefix;
    protected $current_log_id = 0;
    protected $has_ajax_telemetry_columns = null;
    protected $has_ajax_advanced_columns = null;
    protected $has_cursor_columns = null;
    protected $has_reactions_probe_columns = null;
    protected $has_visitor_cookie_column = null;
    protected $has_visitor_cookie_debug_columns = null;
    protected $has_login_attempt_columns = null;
    protected $has_behavior_learning_tables = null;
    protected $has_reactions_learning_columns = null;
    protected $reactions_extension_active = null;
    protected $behavior_profile_cache = [];
    protected $visitor_cookie_preexisting = false;
    protected $pending_login_attempt = null;

    const AJAX_LINK_NAME = 'b59_stats_px';
    const VISITOR_COOKIE_NAME = 'b59_vid';
    const VISITOR_COOKIE_TTL = 15552000; // 180 days
    const CN_NO_INTERACTION_SIGNAL = 'cn_no_interaction_5m';
    const CN_NO_INTERACTION_DELAY_SEC = 300; // 5 minutes
    const CN_NO_INTERACTION_BATCH_LIMIT = 5; // Max sessions processed par déclenchement (throttle 60s)
    const CN_NO_INTERACTION_COUNTRY_CODES = ['CN']; // Extendable list (ACP info panel)

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
        \phpbb\controller\helper $helper,
        $table_prefix
    ) {
        $this->db = $db;
        $this->request = $request;
        $this->user = $user;
        $this->config = $config;
        $this->template = $template;
        $this->helper = $helper;
        $this->table_prefix = $table_prefix;
    }

    public static function getSubscribedEvents()
    {
        return [
            'core.page_header_after' => 'log_visit',
            'core.download_file_send_to_browser_before' => 'log_attachment_download',
            'core.login_box_failed' => 'capture_failed_login_attempt',
            'core.page_footer'       => 'inject_resolution_script',
            'core.obtain_users_online_string_modify' => 'split_online_users',
        ];
    }

    public function log_visit($event)
    {
        $this->log_request_entry([
            'page_title' => isset($event['page_title']) ? (string)$event['page_title'] : '',
            'page_name' => (string)($this->user->page['page_name'] ?? ''),
            'allow_behavior_detection' => true,
        ]);
    }

    public function log_attachment_download($event)
    {
        $attachment = (isset($event['attachment']) && is_array($event['attachment'])) ? $event['attachment'] : [];
        $attach_id = (int)($event['attach_id'] ?? ($attachment['attach_id'] ?? 0));
        $thumbnail = !empty($event['thumbnail']);
        $mode = trim((string)($event['mode'] ?? ''));

        $this->log_request_entry([
            'page_title' => $this->build_attachment_page_title($attach_id, $attachment, $thumbnail, $mode),
            'page_name' => 'download/file.php',
            'allow_behavior_detection' => false,
            'is_download' => true,
        ]);
    }

    public function capture_failed_login_attempt($event)
    {
        if (empty($this->config['bastien59_stats_enabled'])) {
            return;
        }

        $username = trim((string)($event['username'] ?? ''));
        $result = (isset($event['result']) && is_array($event['result'])) ? $event['result'] : [];
        $error_code = trim((string)($result['error_msg'] ?? ''));

        $this->pending_login_attempt = [
            'failed' => 1,
            'username' => substr($username, 0, 254),
            'error' => substr($error_code, 0, 63),
        ];
    }

    private function log_request_entry(array $context = [])
    {
        // Ne pas logger les requêtes AJAX
        if ($this->request->is_ajax()) {
            return;
        }

        // Vérifier si le tracking est activé
        if (empty($this->config['bastien59_stats_enabled'])) {
            return;
        }

        $allow_behavior_detection = !empty($context['allow_behavior_detection']);
        $is_download = !empty($context['is_download']);
        $time_now = time();
        $user_ip = (string)$this->user->ip;
        $native_session_id = (string)($this->user->session_id ?? '');
        $user_id = (int)$this->user->data['user_id'];
        $user_agent = $this->user->browser ?? '';

        // Timeout de session configurable (15 min par défaut)
        $session_timeout = (int)($this->config['bastien59_stats_session_timeout'] ?? 900);

        // 2. Collecter les données
        $page_url = isset($context['page_url'])
            ? (string)$context['page_url']
            : $this->request->raw_variable('REQUEST_URI', '', \phpbb\request\request_interface::SERVER);
        $referer = isset($context['referer'])
            ? (string)$context['referer']
            : $this->request->raw_variable('HTTP_REFERER', '', \phpbb\request\request_interface::SERVER);

        // Récupérer le vrai referer original passé via _r (redirect cross-domain)
        $original_ref = $this->request->variable('_r', '', true);
        if (!empty($original_ref)) {
            $referer = $original_ref;
            $page_url = preg_replace('/[?&]_r=[^&]*/', '', $page_url);
            $page_url = preg_replace('/\?&/', '?', $page_url);
            $page_url = rtrim($page_url, '?');
        }

        $page_title = trim((string)($context['page_title'] ?? ''));
        if ($page_title === '') {
            $page_name = trim((string)($context['page_name'] ?? ''));
            $page_title = $this->get_page_title_from_url($page_name, $page_url);
        }

        $login_attempt_meta = null;
        if ($this->has_login_attempt_columns()) {
            $login_attempt_meta = $this->consume_pending_login_attempt_for_page($page_url);
        }

        // Résolution via cookie
        $screen_res = $this->request->variable('bastien59_stats_res', '', true, \phpbb\request\request_interface::COOKIE);
        $visitor_cookie_id = $this->get_or_init_visitor_cookie_id();
        $visitor_cookie_hash = ($visitor_cookie_id !== '') ? hash('sha256', $visitor_cookie_id) : '';
        $tracking_session = $this->resolve_tracking_session(
            $native_session_id,
            $user_ip,
            $user_id,
            $visitor_cookie_hash,
            $time_now,
            $session_timeout
        );
        $session_id = (string)($tracking_session['session_id'] ?? '');
        $is_first_visit = !empty($tracking_session['is_first_visit']) ? 1 : 0;

        // 3. Mise à jour de la durée de la dernière page HTML précédente.
        // Les téléchargements de PJ ne doivent pas écraser la durée de consultation
        // de la page qui les a déclenchés.
        if (!$is_download) {
            $sql_duration = 'SELECT log_id, visit_time
                             FROM ' . $this->table_prefix . 'bastien59_stats
                             WHERE session_id = \'' . $this->db->sql_escape($session_id) . '\'
                             AND LOWER(page_url) NOT LIKE \'%download/file.php%\'
                             ORDER BY visit_time DESC, log_id DESC';
            $result_duration = $this->db->sql_query_limit($sql_duration, 1);
            $duration_row = $this->db->sql_fetchrow($result_duration);
            $this->db->sql_freeresult($result_duration);

            if ($duration_row && isset($duration_row['log_id']) && isset($duration_row['visit_time'])) {
                $duration = $time_now - (int)$duration_row['visit_time'];
                if ($duration >= 0 && $duration <= $session_timeout) {
                    $sql_update = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                                   SET duration = ' . (int)$duration . '
                                   WHERE log_id = ' . (int)$duration_row['log_id'];
                    $this->db->sql_query($sql_update);
                }
            }
        }

        // === DÉTECTION DES BOTS (2 couches) ===
        // Couche 1 : Protection bots légitimes (phpBB natif + whitelist + vérif rDNS)
        // Couche 2 : Détection comportementale (signaux impossibles à voir via Apache)

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

        $fake_bot_signals = [];
        $rdns_fail_reason = '';
        $hostname_check = null;
        if ($is_legit_bot && empty($this->user->data['is_bot'])) {
            $hostname_check = $this->get_cached_hostname($this->user->ip);
            if (!$this->verify_bot_rdns($claimed_bot, $hostname_check, $rdns_fail_reason)) {
                $fake_bot_signals = ['fake_legit_bot'];
                $is_legit_bot = false;
            }
        }

        $geo_data = ['country_code' => '', 'country_name' => '', 'hostname' => ''];
        if ($is_first_visit) {
            $geo_data = $this->geolocate_ip($this->user->ip, false);
        }

        $is_bot = $is_legit_bot ? 1 : 0;
        $bot_source = $is_legit_bot ? 'phpbb' : '';
        $all_signals = [];
        $actionable_signals = [];

        if (!$is_legit_bot) {
            $ua_signals = $this->detect_bot($user_agent);
            $strong_ua_signals = array_filter($ua_signals, function ($s) {
                return $s !== 'no_browser_signature';
            });

            $behavior_signals = [];
            if ($allow_behavior_detection) {
                $behavior_signals = $this->detect_bot_behavior(
                    $page_url,
                    $referer,
                    $is_first_visit,
                    $screen_res,
                    $session_id,
                    $user_agent,
                    (string)($geo_data['country_code'] ?? ''),
                    $visitor_cookie_hash
                );
            }

            $all_signals = array_merge($fake_bot_signals, $ua_signals, $behavior_signals);
            $actionable_signals = array_values(array_filter($all_signals, function ($sig) {
                return !preg_match('/_shadow$/', (string)$sig);
            }));

            if (!empty($actionable_signals)) {
                $is_bot = 1;
                $bot_source = !empty($strong_ua_signals) ? 'extension' : 'behavior';
            }
        }

        $referer_type = $this->classify_referer($referer);

        if ($is_bot === 1 && !empty($actionable_signals) && !$is_first_visit) {
            $sql_reclass = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                            SET is_bot = 1,
                                bot_source = CASE
                                    WHEN bot_source = \'\' THEN \'' . $this->db->sql_escape($bot_source) . '\'
                                    ELSE bot_source
                                END
                            WHERE session_id = \'' . $this->db->sql_escape($session_id) . '\'
                            AND is_bot = 0';
            $this->db->sql_query($sql_reclass);
        }

        $audit_signals = $this->filter_security_audit_signals_by_country(
            $actionable_signals,
            (string)($geo_data['country_code'] ?? '')
        );
        if (!empty($audit_signals)) {
            $page_count = 0;
            if (!$is_first_visit) {
                $sql_cnt = 'SELECT COUNT(*) as cnt FROM ' . $this->table_prefix . 'bastien59_stats
                            WHERE session_id = \'' . $this->db->sql_escape($session_id) . '\'';
                $result_cnt = $this->db->sql_query($sql_cnt);
                $page_count = (int)$this->db->sql_fetchfield('cnt');
                $this->db->sql_freeresult($result_cnt);
            }

            $this->write_security_audit(
                $this->user->ip,
                $session_id,
                (int)$this->user->data['user_id'],
                $audit_signals,
                $user_agent,
                $page_url,
                $screen_res,
                $page_count,
                $hostname_check ?? ($geo_data['hostname'] ?? ''),
                $claimed_bot,
                $rdns_fail_reason
            );
        }

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
        if ($this->has_visitor_cookie_column()) {
            $sql_ary['visitor_cookie_hash'] = substr(strtolower($visitor_cookie_hash), 0, 64);
        }
        if ($this->has_visitor_cookie_debug_columns()) {
            $sql_ary['visitor_cookie_preexisting'] = $this->visitor_cookie_preexisting ? 1 : 0;
            $sql_ary['visitor_cookie_ajax_state'] = 0;
            $sql_ary['visitor_cookie_ajax_hash'] = '';
        }
        if ($this->has_cursor_columns()) {
            $sql_ary['cursor_track_points'] = 0;
            $sql_ary['cursor_track_duration_ms'] = 0;
            $sql_ary['cursor_track_path'] = '[]';
            $sql_ary['cursor_click_points'] = '[]';
            $sql_ary['cursor_device_class'] = '';
            $sql_ary['cursor_viewport'] = '';
            $sql_ary['cursor_total_distance'] = 0;
            $sql_ary['cursor_avg_speed'] = 0;
            $sql_ary['cursor_max_speed'] = 0;
            $sql_ary['cursor_direction_changes'] = 0;
            $sql_ary['cursor_linearity'] = 0;
            $sql_ary['cursor_click_count'] = 0;
        }
        if ($this->has_reactions_probe_columns()) {
            $sql_ary['reactions_extension_expected'] = $this->is_reactions_extension_active() ? 1 : 0;
            $sql_ary['reactions_css_seen'] = 0;
            $sql_ary['reactions_js_seen'] = 0;
        }
        if ($this->has_login_attempt_columns()) {
            $sql_ary['login_attempt_failed'] = !empty($login_attempt_meta['failed']) ? 1 : 0;
            $sql_ary['login_attempt_username'] = substr((string)($login_attempt_meta['username'] ?? ''), 0, 254);
            $sql_ary['login_attempt_error'] = substr((string)($login_attempt_meta['error'] ?? ''), 0, 63);
        }

        $sql = 'INSERT INTO ' . $this->table_prefix . 'bastien59_stats ' . $this->db->sql_build_array('INSERT', $sql_ary);
        $this->db->sql_query($sql);
        $this->current_log_id = (int)$this->db->sql_nextid();

        if ((int)$this->user->data['user_id'] > 1 && (int)$is_bot === 0) {
            $this->learn_registered_behavior($session_id, $user_agent, $screen_res);
        }

        $this->emit_cn_no_interaction_signals($time_now);

        if (mt_rand(1, 100) === 1) {
            $retention_humans = (int)($this->config['bastien59_stats_retention'] ?? 30);
            $retention_bots = (int)($this->config['bastien59_stats_retention_bots'] ?? 5);

            if ($retention_bots > 0) {
                $cutoff_bots = time() - ($retention_bots * 86400);
                $sql_clean = 'DELETE FROM ' . $this->table_prefix . 'bastien59_stats
                              WHERE is_bot = 1 AND visit_time < ' . $cutoff_bots;
                $this->db->sql_query($sql_clean);
            }

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
        $ajax_payload = [
            'u' => '',
            't' => '',
            's' => (string)($this->user->session_id ?? ''),
            'i' => (int)$this->current_log_id,
            'x' => (int)($this->config['bastien59_stats_session_timeout'] ?? 900),
            'cm' => max(800, min(6000, (int)($this->config['bastien59_stats_cursor_capture_ms'] ?? 3500))),
            're' => ($this->is_reactions_extension_active() ? 1 : 0),
        ];

        if ($ajax_payload['i'] > 0 && $ajax_payload['s'] !== '') {
            try {
                $ajax_payload['u'] = $this->helper->route('bastien59960_stats_collect');
                $ajax_payload['t'] = generate_link_hash(self::AJAX_LINK_NAME);
            } catch (\Throwable $e) {
                $ajax_payload['u'] = '';
                $ajax_payload['t'] = '';
            }
        }

        $ajax_json = json_encode($ajax_payload, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        if ($ajax_json === false) {
            $ajax_json = '{"u":"","t":"","s":"","i":0,"x":900,"cm":3500,"re":0}';
        }

        // Script client:
        // - conserve la logique cookie résolution
        // - envoie la résolution AJAX immédiatement (critique 1re page)
        // - capture les mouvements curseur pendant 3.5s de focus/onglet actif, à chaque visite
        $script = <<<HTML
<script>
(function(w,d,c){
    if(!w||!d){return;}

    var n='bastien59_stats_res';
    function g(){
        var sw=(w.screen&&w.screen.width)?parseInt(w.screen.width,10):0;
        var sh=(w.screen&&w.screen.height)?parseInt(w.screen.height,10):0;
        if(sw>9&&sh>9&&sw<=16384&&sh<=16384){return String(sw)+'x'+String(sh);}
        return '';
    }
    function gc(){
        var m=d.cookie.match(new RegExp('(?:^|;\\\\s*)'+n+'=([^;]*)'));
        return m?decodeURIComponent(m[1]):'';
    }
    function sc(v){
        var dt=new Date();
        dt.setTime(dt.getTime()+(30*24*60*60*1000));
        d.cookie=n+'='+encodeURIComponent(v)+';path=/;expires='+dt.toUTCString()+';SameSite=Lax';
    }
    function dc(){
        d.cookie=n+'=;path=/;expires=Thu, 01 Jan 1970 00:00:00 GMT;SameSite=Lax';
    }

    // Cookie historique conservé (comparaison cookie vs AJAX côté serveur)
    var v=g();
    var cv=gc();
    if(v){
        if(!cv){
            sc(v);
        }else if(cv!==v){
            dc();
            sc(v);
        }
    }else if(cv){
        dc();
    }

    // Nettoyer le paramètre _r de l'URL (referer original du redirect)
    if(w.history&&w.history.replaceState&&w.location&&w.location.search.indexOf('_r=')>-1){
        try{
            var u=new URL(w.location.href);
            u.searchParams.delete('_r');
            w.history.replaceState(null,'',u.toString());
        }catch(e){}
    }

    // Endpoint AJAX (noms de clés volontairement opaques côté client)
    if(!c||!c.u||!c.t||!c.s||!c.i||!w.fetch||!w.FormData){return;}

    var kCookie='b59x3c_'+String(c.s||'')+'_'+String(c.i||0);
    var kScroll='b59x3s_'+String(c.s||'')+'_'+String(c.i||0);
    var kCursor='b59x3m_'+String(c.s||'')+'_'+String(c.i||0);
    var ttl=Math.max(300,parseInt(c.x||900,10))*1000;
    var cookieSent=false;
    var cookieInflight=false;
    var scrollSent=false;
    var scrollInflight=false;
    var cursorSent=false;
    var cursorInflight=false;
    var t0=Date.now();
    var captureMs=Math.max(800,Math.min(6000,parseInt(c.cm||3500,10)||3500));
    var bm=0;
    var se=0;
    var my=0;
    var mwd=(w.navigator&&w.navigator.webdriver)?1:0;
    var points=[];
    var clicks=[];
    var maxPoints=260;
    var maxClicks=80;
    var lastPointTs=0;
    var moveStepMs=14;
    var listenersBound=false;
    var captureStarted=false;
    var captureFinalized=false;
    var captureTick=0;
    var activeStartTs=0;
    var activeAccumMs=0;
    function oy(){
        return w.pageYOffset||d.documentElement.scrollTop||d.body.scrollTop||0;
    }
    function mk(bit){
        if((bm&bit)===0){bm|=bit;}
    }
    function e1(){mk(1);}
    function e2(){mk(2);}
    function e3(){mk(4);}
    function e4(){mk(8);}
    function e5(){mk(16);}
    function e6(){mk(32);}
    function clamp(v,min,max){
        var x=parseInt(v,10);
        if(isNaN(x)){x=0;}
        if(x<min){x=min;}
        if(x>max){x=max;}
        return x;
    }
    function readXY(ev){
        if(!ev){return null;}
        if(typeof ev.clientX==='number'&&typeof ev.clientY==='number'){
            return {x:ev.clientX,y:ev.clientY};
        }
        if(ev.touches&&ev.touches[0]){
            return {x:ev.touches[0].clientX,y:ev.touches[0].clientY};
        }
        if(ev.changedTouches&&ev.changedTouches[0]){
            return {x:ev.changedTouches[0].clientX,y:ev.changedTouches[0].clientY};
        }
        return null;
    }
    function getViewport(){
        var vw=clamp(w.innerWidth||0,0,16384);
        var vh=clamp(w.innerHeight||0,0,16384);
        if(vw<32||vh<32){return '';}
        return String(vw)+'x'+String(vh);
    }
    function getDeviceClass(){
        var ua=String((w.navigator&&w.navigator.userAgent)||'').toLowerCase();
        var touchPts=parseInt((w.navigator&&w.navigator.maxTouchPoints)||0,10)||0;
        var coarse=false;
        try{
            coarse=!!(w.matchMedia&&w.matchMedia('(pointer:coarse)').matches);
        }catch(e){}
        if(ua.indexOf('ipad')>-1||ua.indexOf('tablet')>-1){return 'tablet';}
        if(ua.indexOf('android')>-1&&ua.indexOf('mobile')===-1){return 'tablet';}
        if(ua.indexOf('iphone')>-1||ua.indexOf('ipod')>-1||ua.indexOf('mobile')>-1){return 'mobile';}
        if(coarse&&touchPts>0){
            var vp=getViewport();
            if(vp){
                var p=vp.split('x');
                var wv=parseInt(p[0]||0,10)||0;
                if(wv>0&&wv<900){return 'mobile';}
                if(wv>=900&&wv<1366){return 'tablet';}
            }
            return 'mobile';
        }
        return 'desktop';
    }
    function isReactionsCssUrl(u){
        var s=String(u||'').toLowerCase();
        if(!s){return false;}
        if(s.indexOf('/ext/bastien59960/reactions/')===-1){return false;}
        return (s.indexOf('/reactions.css')!==-1);
    }
    function isReactionsJsUrl(u){
        var s=String(u||'').toLowerCase();
        if(!s){return false;}
        if(s.indexOf('/ext/bastien59960/reactions/')===-1){return false;}
        return (s.indexOf('/reactions.js')!==-1);
    }
    function detectReactionsAssets(){
        var out={c:0,j:0};
        try{
            var links=d.getElementsByTagName('link');
            for(var i=0;i<links.length;i++){
                var href=String((links[i]&&links[i].getAttribute&&links[i].getAttribute('href'))||'');
                if(isReactionsCssUrl(href)){ out.c=1; break; }
            }
            var scripts=d.getElementsByTagName('script');
            for(var k=0;k<scripts.length;k++){
                var src=String((scripts[k]&&scripts[k].getAttribute&&scripts[k].getAttribute('src'))||'');
                if(isReactionsJsUrl(src)){ out.j=1; break; }
            }
        }catch(e){}
        return out;
    }
    function hasActiveTab(){
        var visible=true;
        if(d&&typeof d.visibilityState==='string'){
            visible=(d.visibilityState==='visible');
        }
        var focused=true;
        try{
            if(d&&typeof d.hasFocus==='function'){
                focused=!!d.hasFocus();
            }
        }catch(e){}
        return visible&&focused;
    }
    function captureElapsed(nowTs){
        var now=parseInt(nowTs||Date.now(),10);
        if(isNaN(now)||now<0){ now=Date.now(); }
        var total=activeAccumMs;
        if(captureStarted && activeStartTs>0){
            total+=Math.max(0,now-activeStartTs);
        }
        return clamp(total,0,120000);
    }
    function pauseCaptureClock(){
        if(!captureStarted || activeStartTs<=0){return;}
        activeAccumMs=clamp(activeAccumMs + (Date.now()-activeStartTs),0,120000);
        activeStartTs=0;
    }
    function resumeCaptureClock(){
        if(!captureStarted){return;}
        if(activeStartTs>0){return;}
        activeStartTs=Date.now();
    }
    function pushPoint(x,y,ts){
        if(points.length>=maxPoints){return;}
        var px=clamp(x,0,16384);
        var py=clamp(y,0,16384);
        var dt=captureElapsed(ts);
        points.push([dt,px,py]);
    }
    function onMove(ev){
        e1();
        var now=Date.now();
        if((now-lastPointTs)<moveStepMs){return;}
        var xy=readXY(ev);
        if(!xy){return;}
        lastPointTs=now;
        pushPoint(xy.x,xy.y,now);
    }
    function onClick(ev){
        e6();
        if(clicks.length>=maxClicks){return;}
        var xy=readXY(ev);
        if(!xy){return;}
        var now=Date.now();
        clicks.push([captureElapsed(now),clamp(xy.x,0,16384),clamp(xy.y,0,16384)]);
    }
    function serializePts(items,maxLen){
        if(!items||!items.length){return '';}
        var out=[];
        for(var i=0;i<items.length;i++){
            var p=items[i];
            if(!p||p.length<3){continue;}
            out.push(String(clamp(p[0],0,120000))+':'+String(clamp(p[1],0,16384))+':'+String(clamp(p[2],0,16384)));
        }
        var txt=out.join(';');
        if(txt.length>maxLen){txt=txt.substring(0,maxLen);}
        return txt;
    }
    function e7(){
        var y=oy();
        se++;
        if(y>my){my=y;}
    }
    function wasSent(k){
        try{
            if(w.sessionStorage){
                var raw=w.sessionStorage.getItem(k)||'';
                var ts=parseInt(raw,10)||0;
                if(ts>0&&(Date.now()-ts)<ttl){ return true; }
                if(ts>0){ w.sessionStorage.removeItem(k); }
            }
        }catch(e){}
        return false;
    }
    function markSent(k){
        try{ if(w.sessionStorage){ w.sessionStorage.setItem(k,String(Date.now())); } }catch(e){}
    }
    function postAjax(f,onOk,onDone){
        w.fetch(String(c.u),{
            method:'POST',
            body:f,
            credentials:'same-origin',
            keepalive:true,
            headers:{'X-Requested-With':'XMLHttpRequest'}
        }).then(function(resp){
            if(!resp||!resp.ok){throw 0;}
            return resp.json().catch(function(){ return {ok:1}; });
        }).then(function(data){
            if(data&&String(data.ok)==='1'&&typeof onOk==='function'){
                onOk();
            }
        }).catch(function(){}).finally(function(){
            if(typeof onDone==='function'){ onDone(); }
        });
    }
    function basePayload(mode){
        var f=new FormData();
        f.append('k',String(c.t||''));
        f.append('s',String(c.s||''));
        f.append('i',String(c.i||0));
        f.append('a',String(clamp(mode,0,2)));
        var r=g();
        if(r){f.append('r',r);}
        if(parseInt(c.re||0,10)===1){
            var ra=detectReactionsAssets();
            f.append('re','1');
            f.append('rc',String((ra&&ra.c)?1:0));
            f.append('rj',String((ra&&ra.j)?1:0));
        }
        f.append('v','3');
        return f;
    }
    function sendCookieProbe(){
        if(cookieSent||cookieInflight||wasSent(kCookie)){ cookieSent=true; return; }
        cookieInflight=true;
        var f=basePayload(0);
        postAjax(f,function(){
            cookieSent=true;
            markSent(kCookie);
        },function(){ cookieInflight=false; });
    }
    function sendOnFirstScroll(){
        if(scrollSent||scrollInflight||wasSent(kScroll)){ scrollSent=true; return; }
        scrollInflight=true;
        var f=basePayload(1);
        var dt=clamp(Date.now()-t0,0,120000);
        var sn=clamp(se,0,10000);
        var sy=clamp(my,0,500000);
        f.append('b',String(bm&255));
        f.append('d',String(dt));
        f.append('n',String(sn));
        f.append('y',String(sy));
        f.append('w',String(mwd?1:0));
        postAjax(f,function(){
            scrollSent=true;
            markSent(kScroll);
        },function(){ scrollInflight=false; });
    }
    function sendCursorTelemetry(){
        if(!captureStarted){ return; }
        if(cursorSent||cursorInflight||wasSent(kCursor)){ cursorSent=true; return; }
        pauseCaptureClock();
        cursorInflight=true;
        var f=basePayload(2);
        var dt=captureElapsed(Date.now());
        var sn=clamp(se,0,10000);
        var sy=clamp(my,0,500000);
        var viewport=getViewport();
        var path=serializePts(points,12000);
        var clickPath=serializePts(clicks,5000);
        f.append('b',String(bm&255));
        f.append('d',String(dt));
        f.append('n',String(sn));
        f.append('y',String(sy));
        f.append('w',String(mwd?1:0));
        f.append('z',String(getDeviceClass()));
        if(viewport){f.append('l',viewport);}
        if(path){f.append('m',path);}
        if(clickPath){f.append('q',clickPath);}
        postAjax(f,function(){
            cursorSent=true;
            markSent(kCursor);
        },function(){ cursorInflight=false; });
    }
    function unbindCaptureListeners(){
        if(!listenersBound||!w.removeEventListener){return;}
        listenersBound=false;
        w.removeEventListener('mousemove',onMove,true);
        w.removeEventListener('pointermove',onMove,true);
        w.removeEventListener('touchmove',onMove,true);
        w.removeEventListener('click',onClick,true);
        w.removeEventListener('pointerdown',onClick,true);
    }
    function bindCaptureListeners(){
        if(listenersBound||!w.addEventListener){return;}
        listenersBound=true;
        w.addEventListener('mousemove',onMove,{passive:true,capture:true});
        w.addEventListener('pointermove',onMove,{passive:true,capture:true});
        w.addEventListener('touchmove',onMove,{passive:true,capture:true});
        w.addEventListener('click',onClick,{passive:true,capture:true});
        w.addEventListener('pointerdown',onClick,{passive:true,capture:true});
    }
    function stopCaptureTicker(){
        if(captureTick&&w.clearInterval){
            w.clearInterval(captureTick);
        }
        captureTick=0;
    }
    function ensureCaptureTicker(){
        if(captureTick||!w.setInterval){return;}
        captureTick=w.setInterval(function(){
            if(captureFinalized||cursorSent||wasSent(kCursor)){
                stopCaptureTicker();
                return;
            }
            if(!captureStarted){return;}
            if(!hasActiveTab()){
                pauseCaptureClock();
                return;
            }
            resumeCaptureClock();
            if(captureElapsed(Date.now())>=captureMs){
                finalizeCapture();
            }
        },120);
    }
    function maybeStartCapture(){
        if(captureFinalized||cursorSent||wasSent(kCursor)){return;}
        if(!hasActiveTab()){return;}
        if(!captureStarted){
            captureStarted=true;
            activeAccumMs=0;
            activeStartTs=Date.now();
            bindCaptureListeners();
        }else{
            resumeCaptureClock();
        }
        ensureCaptureTicker();
    }
    function pauseCapture(){
        if(!captureStarted||captureFinalized){return;}
        pauseCaptureClock();
    }
    function finalizeCapture(){
        if(captureFinalized||!captureStarted){return;}
        captureFinalized=true;
        pauseCaptureClock();
        sendCursorTelemetry();
        unbindCaptureListeners();
        stopCaptureTicker();
    }

    if(wasSent(kCookie)&&wasSent(kScroll)&&wasSent(kCursor)){ return; }

    var done=false;
    function h(){
        if(done||wasSent(kScroll)){ done=true; return; }
        e7();
        var y=oy();
        if(y>12){
            done=true;
            sendOnFirstScroll();
            if(w.removeEventListener){w.removeEventListener('scroll',h,true);}
        }
    }
    if(w.addEventListener){
        w.addEventListener('mousemove',e1,{passive:true,capture:true});
        w.addEventListener('keydown',e2,{passive:true,capture:true});
        w.addEventListener('touchstart',e3,{passive:true,capture:true});
        w.addEventListener('pointerdown',e4,{passive:true,capture:true});
        w.addEventListener('wheel',e5,{passive:true,capture:true});
        w.addEventListener('click',e6,{passive:true,capture:true});
        if(!wasSent(kScroll)){ w.addEventListener('scroll',h,{passive:true,capture:true}); }
        h();
    }
    if(!wasSent(kCookie)){ sendCookieProbe(); }
    if(!wasSent(kCursor)){ maybeStartCapture(); }
    if(w.addEventListener){
        w.addEventListener('focus',function(){ maybeStartCapture(); },{capture:true});
        w.addEventListener('blur',function(){ pauseCapture(); },{capture:true});
        w.addEventListener('pagehide',function(){
            if(!wasSent(kCookie)){ sendCookieProbe(); }
            if(!wasSent(kCursor)){ finalizeCapture(); }
        },{capture:true});
    }
    if(d&&d.addEventListener){
        d.addEventListener('visibilitychange',function(){
            if(d.visibilityState==='visible'){
                maybeStartCapture();
            }else{
                pauseCapture();
                if(!wasSent(kCookie)){ sendCookieProbe(); }
            }
        },{capture:true});
    }
})(window,document,$ajax_json);
</script>
HTML;

        $this->template->append_var('RUN_CRON_TASK', $script);
    }

    /**
     * Construit une clé de session de tracking stable et cloisonnée.
     * Évite les collisions entre visiteurs partageant IP/NAT/proxy.
     */
    /**
     * Résout la session de tracking courante.
     *
     * Quand le cookie visiteur stats est disponible, il devient la clé d'ancrage principale
     * pour regrouper les pages et téléchargements même si l'IP change en cours de navigation.
     * On conserve néanmoins un vrai timeout de session : au-delà, une nouvelle session_id est émise.
     *
     * Fallback historique : si le cookie visiteur n'est pas exploitable, on garde le couplage
     * phpBB session + IP pour éviter les mélanges sur des clients sans cookie.
     */
    private function resolve_tracking_session($native_session_id, $user_ip, $user_id, $visitor_cookie_hash, $time_now, $session_timeout)
    {
        $sid = trim((string)$native_session_id);
        $ip = trim((string)$user_ip);
        $uid = (int)$user_id;
        $timeout = max(300, (int)$session_timeout);
        $time_now = max(0, (int)$time_now);
        $cookie_hash = strtolower(trim((string)$visitor_cookie_hash));

        if ($this->has_visitor_cookie_column() && $this->is_valid_visitor_cookie_hash($cookie_hash)) {
            $member_session = ($uid > 1)
                ? $this->find_recent_cookie_tracking_session($cookie_hash, $uid, $time_now, $timeout)
                : null;

            if ($uid > 1) {
                $guest_login_session = $this->find_recent_anonymous_login_tracking_session($cookie_hash, $time_now, $timeout);
                if (is_array($guest_login_session) && !empty($guest_login_session['session_id'])) {
                    $resolved_session_id = !empty($member_session['session_id'])
                        ? (string)$member_session['session_id']
                        : (string)$guest_login_session['session_id'];

                    if ($resolved_session_id !== '') {
                        $this->promote_anonymous_login_rows_to_member_session(
                            (string)$guest_login_session['session_id'],
                            $uid,
                            $resolved_session_id,
                            $time_now,
                            $timeout
                        );

                        return [
                            'session_id' => $resolved_session_id,
                            'is_first_visit' => 0,
                        ];
                    }
                }
            }

            $last_row = ($uid > 1)
                ? $member_session
                : $this->find_recent_cookie_tracking_session($cookie_hash, $uid, $time_now, $timeout);

            if (is_array($last_row) && !empty($last_row['session_id'])) {
                return [
                    'session_id' => (string)$last_row['session_id'],
                    'is_first_visit' => 0,
                ];
            }

            return [
                'session_id' => $this->build_cookie_tracking_session_id($cookie_hash, $uid, $time_now),
                'is_first_visit' => 1,
            ];
        }

        $session_id = $this->build_tracking_session_id($sid, $ip, $uid);
        $sql = 'SELECT log_id, visit_time, session_id AS last_session
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE session_id = \'' . $this->db->sql_escape($session_id) . '\'
                AND user_ip = \'' . $this->db->sql_escape($ip) . '\'
                ORDER BY visit_time DESC, log_id DESC';

        $result = $this->db->sql_query_limit($sql, 1);
        $last_row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($last_row === false) {
            return [
                'session_id' => $session_id,
                'is_first_visit' => 1,
            ];
        }

        $time_since_last = $time_now - (int)$last_row['visit_time'];
        if ($time_since_last > $timeout) {
            return [
                'session_id' => $session_id,
                'is_first_visit' => 1,
            ];
        }

        return [
            'session_id' => (string)$last_row['last_session'],
            'is_first_visit' => 0,
        ];
    }

    /**
     * Retrouve la dernière session stats récente d'un membre/invité pour un cookie visiteur donné.
     *
     * @return array{session_id:string,visit_time:int}|null
     */
    private function find_recent_cookie_tracking_session($visitor_cookie_hash, $user_id, $time_now, $session_timeout)
    {
        $hash = strtolower(trim((string)$visitor_cookie_hash));
        $uid = (int)$user_id;
        $timeout = max(300, (int)$session_timeout);
        $time_now = max(0, (int)$time_now);

        if ($uid < 0 || !$this->is_valid_visitor_cookie_hash($hash)) {
            return null;
        }

        $sql = 'SELECT log_id, visit_time, session_id
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE user_id = ' . (int)$uid . '
                AND visitor_cookie_hash = \'' . $this->db->sql_escape($hash) . '\'
                ORDER BY visit_time DESC, log_id DESC';

        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row || empty($row['session_id'])) {
            return null;
        }

        $time_since_last = $time_now - (int)$row['visit_time'];
        if ($time_since_last < 0 || $time_since_last > $timeout) {
            return null;
        }

        return [
            'session_id' => (string)$row['session_id'],
            'visit_time' => (int)$row['visit_time'],
        ];
    }

    /**
     * Retrouve une session invitée récente contenant une activité de login.
     * On s'appuie sur le cookie stats, pas sur l'IP, pour couvrir les bascules IPv4/IPv6.
     *
     * @return array{session_id:string,last_login_related_time:int}|null
     */
    private function find_recent_anonymous_login_tracking_session($visitor_cookie_hash, $time_now, $session_timeout)
    {
        $hash = strtolower(trim((string)$visitor_cookie_hash));
        $time_now = max(0, (int)$time_now);
        $lookback = max(900, max(300, (int)$session_timeout) * 2);

        if (!$this->is_valid_visitor_cookie_hash($hash)) {
            return null;
        }

        $select_login_columns = $this->has_login_attempt_columns()
            ? ', login_attempt_failed'
            : '';
        $sql = 'SELECT log_id, visit_time, session_id, page_url' . $select_login_columns . '
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE user_id <= 1
                AND is_bot = 0
                AND visitor_cookie_hash = \'' . $this->db->sql_escape($hash) . '\'
                AND visit_time >= ' . (int) max(0, $time_now - $lookback) . '
                ORDER BY visit_time DESC, log_id DESC';

        $result = $this->db->sql_query_limit($sql, 60);
        $sessions = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $session_id = trim((string)($row['session_id'] ?? ''));
            if ($session_id === '') {
                continue;
            }

            if (!isset($sessions[$session_id])) {
                $sessions[$session_id] = [
                    'session_id' => $session_id,
                    'last_login_related_time' => 0,
                ];
            }

            if ($this->is_login_related_tracking_row($row)) {
                $sessions[$session_id]['last_login_related_time'] = max(
                    (int)$sessions[$session_id]['last_login_related_time'],
                    (int)($row['visit_time'] ?? 0)
                );
            }
        }
        $this->db->sql_freeresult($result);

        if (empty($sessions)) {
            return null;
        }

        uasort($sessions, function ($a, $b) {
            return ((int)($b['last_login_related_time'] ?? 0) <=> (int)($a['last_login_related_time'] ?? 0));
        });

        foreach ($sessions as $candidate) {
            if ((int)($candidate['last_login_related_time'] ?? 0) > 0) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Promeut les lignes anonymes liées au login vers la session membre résolue.
     */
    private function promote_anonymous_login_rows_to_member_session($source_session_id, $member_user_id, $target_session_id, $time_now, $session_timeout)
    {
        $source = trim((string)$source_session_id);
        $target = trim((string)$target_session_id);
        $uid = (int)$member_user_id;
        $time_now = max(0, (int)$time_now);
        $lookback = max(900, max(300, (int)$session_timeout) * 2);

        if ($source === '' || $uid <= 1) {
            return;
        }

        if ($target === '') {
            $target = $source;
        }

        $update_fields = [];
        if ($target !== $source) {
            $update_fields[] = 'session_id = \'' . $this->db->sql_escape($target) . '\'';
        }
        $update_fields[] = 'user_id = ' . (int)$uid;

        $login_where = '(LOWER(page_url) LIKE \'%ucp.php%\' AND LOWER(page_url) LIKE \'%mode=login%\')';
        if ($this->has_login_attempt_columns()) {
            $login_where = '(login_attempt_failed = 1 OR ' . $login_where . ')';
        }

        $sql = 'UPDATE ' . $this->table_prefix . 'bastien59_stats
                SET ' . implode(', ', $update_fields) . '
                WHERE session_id = \'' . $this->db->sql_escape($source) . '\'
                AND user_id <= 1
                AND is_bot = 0
                AND visit_time >= ' . (int) max(0, $time_now - $lookback) . '
                AND ' . $login_where;
        $this->db->sql_query($sql);
    }

    /**
     * Ligne stats liée à la page de login phpBB ou à un login raté capturé côté serveur.
     */
    private function is_login_related_tracking_row(array $row)
    {
        if ((int)($row['login_attempt_failed'] ?? 0) === 1) {
            return true;
        }

        return $this->is_login_page_url((string)($row['page_url'] ?? ''));
    }

    /**
     * Génère une session_id dédiée aux sessions regroupées par cookie visiteur stats.
     * L'ID reste opaque mais change à chaque nouvelle fenêtre de session.
     */
    private function build_cookie_tracking_session_id($visitor_cookie_hash, $user_id, $time_now)
    {
        $hash = strtolower(trim((string)$visitor_cookie_hash));
        $uid = (int)$user_id;
        $time_now = max(0, (int)$time_now);

        return md5('vid|' . $hash . '|' . $uid . '|' . $time_now);
    }

    /**
     * Fallback historique sans cookie stats exploitable.
     */
    private function build_tracking_session_id($native_session_id, $user_ip, $user_id)
    {
        $sid = trim((string)$native_session_id);
        $ip = trim((string)$user_ip);
        $uid = (int)$user_id;

        if ($sid !== '' && $ip !== '') {
            return md5($sid . '|' . $ip . '|' . $uid);
        }
        if ($sid !== '') {
            return md5('sid|' . $sid . '|' . $uid);
        }
        if ($ip !== '') {
            return md5('ip|' . $ip . '|' . $uid);
        }

        return md5('fallback|' . microtime(true) . '|' . mt_rand());
    }

    private function is_valid_visitor_cookie_hash($hash)
    {
        return (bool)preg_match('/^[a-f0-9]{64}$/', strtolower(trim((string)$hash)));
    }

    /**
     * Récupère un identifiant visiteur signé via cookie, ou en émet un nouveau.
     */
    private function get_or_init_visitor_cookie_id()
    {
        $raw = trim((string)$this->request->variable(self::VISITOR_COOKIE_NAME, '', true, \phpbb\request\request_interface::COOKIE));
        $id = $this->parse_signed_visitor_cookie($raw);
        if ($id !== '') {
            $this->visitor_cookie_preexisting = true;
            return $id;
        }
        $this->visitor_cookie_preexisting = false;

        $id = $this->generate_visitor_cookie_id();
        if ($id === '') {
            return '';
        }

        $signed = $this->build_signed_visitor_cookie($id);
        if ($signed !== '') {
            $this->issue_visitor_cookie($signed);
        }

        return $id;
    }

    /**
     * Valide et extrait l'identifiant d'un cookie signé format v1.<id>.<sig>.
     */
    private function parse_signed_visitor_cookie($raw)
    {
        if (!preg_match('/^v1\.([a-f0-9]{32})\.([a-f0-9]{24})$/i', (string)$raw, $m)) {
            return '';
        }

        $id = strtolower((string)$m[1]);
        $sig = strtolower((string)$m[2]);
        foreach ($this->get_visitor_cookie_secrets() as $secret) {
            $expected = substr(hash_hmac('sha256', 'v1|' . $id, $secret), 0, 24);
            if (hash_equals($expected, $sig)) {
                return $id;
            }
        }

        return '';
    }

    /**
     * Construit la valeur signée d'un cookie visiteur.
     */
    private function build_signed_visitor_cookie($id)
    {
        $cookie_id = strtolower(trim((string)$id));
        if (!preg_match('/^[a-f0-9]{32}$/', $cookie_id)) {
            return '';
        }

        $sig = substr(hash_hmac('sha256', 'v1|' . $cookie_id, $this->get_visitor_cookie_secret()), 0, 24);
        return 'v1.' . $cookie_id . '.' . $sig;
    }

    /**
     * Émet le cookie visiteur (HttpOnly) avec paramètres de cookie phpBB.
     */
    private function issue_visitor_cookie($value)
    {
        $val = trim((string)$value);
        if ($val === '' || headers_sent()) {
            return;
        }

        $expires = time() + self::VISITOR_COOKIE_TTL;
        $path = (string)($this->config['cookie_path'] ?? '/');
        if ($path === '') {
            $path = '/';
        }
        $domain = (string)($this->config['cookie_domain'] ?? '');
        $secure = !empty($this->config['cookie_secure']);

        if (PHP_VERSION_ID >= 70300) {
            @setcookie(self::VISITOR_COOKIE_NAME, $val, [
                'expires' => $expires,
                'path' => $path,
                'domain' => $domain,
                'secure' => $secure,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            return;
        }

        @setcookie(
            self::VISITOR_COOKIE_NAME,
            $val,
            $expires,
            $path . '; samesite=lax',
            $domain,
            $secure,
            true
        );
    }

    /**
     * Génère un identifiant visiteur 128 bits en hex.
     */
    private function generate_visitor_cookie_id()
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            // Fallbacks pour environnements restreints.
        }

        if (function_exists('openssl_random_pseudo_bytes')) {
            $bytes = @openssl_random_pseudo_bytes(16);
            if (is_string($bytes) && strlen($bytes) === 16) {
                return bin2hex($bytes);
            }
        }

        return substr(sha1(uniqid('', true) . '|' . mt_rand()), 0, 32);
    }

    /**
     * Secret local pour signature du cookie visiteur.
     */
    private function get_visitor_cookie_secret()
    {
        $secrets = $this->get_visitor_cookie_secrets();
        return $secrets[0];
    }

    /**
     * Secrets de validation du cookie visiteur.
     * Le premier est utilisé pour signer les nouveaux cookies.
     * Les suivants sont acceptés en lecture pour compatibilité.
     *
     * @return string[]
     */
    private function get_visitor_cookie_secrets()
    {
        $secrets = [];

        $seed_primary = (string)($this->config['bastien59_stats_cookie_secret'] ?? '');
        if ($seed_primary === '') {
            $seed_primary = (string)($this->config['cookie_name'] ?? '') . '|' . (string)($this->config['server_name'] ?? '');
        }
        if ($seed_primary === '') {
            $seed_primary = 'b59-fallback-seed';
        }
        $secrets[] = hash('sha256', 'b59-visitor-cookie|' . $seed_primary);

        // Compat ancienne signature (listener historique basé sur rand_seed).
        $seed_legacy = (string)($this->config['rand_seed'] ?? '');
        if ($seed_legacy !== '') {
            $legacy = hash('sha256', 'b59-visitor-cookie|' . $seed_legacy);
            if (!in_array($legacy, $secrets, true)) {
                $secrets[] = $legacy;
            }
        }

        return $secrets;
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

    private function build_attachment_page_title($attach_id, array $attachment, $thumbnail = false, $mode = '')
    {
        $attach_id = max(0, (int)$attach_id);
        $filename = trim((string)($attachment['real_filename'] ?? ''));

        if ($filename !== '') {
            return $thumbnail ? ($filename . ' [thumb]') : $filename;
        }

        if ($attach_id > 0) {
            return 'download/file.php?id=' . $attach_id . ($thumbnail ? '&t=1' : '');
        }

        return ($mode !== '') ? ('download/file.php?mode=' . $mode) : 'download/file.php';
    }

    private function is_attachment_download_page_url($page_url)
    {
        $raw = trim((string)$page_url);
        if ($raw === '') {
            return false;
        }

        $path = parse_url($raw, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $raw;
        }

        $path = strtolower($path);
        return (substr($path, -18) === '/download/file.php' || $path === 'download/file.php');
    }

    /**
     * Détection des bots par User-Agent (versions anciennes, patterns, anomalies)
     * @return array Liste des signaux UA détectés (vide = UA semble légitime)
     */
    private function detect_bot($user_agent)
    {



        $signals = [];
        $ua_raw = trim((string)$user_agent);
        if ($ua_raw === '' || $ua_raw === '-') {
            // Compat: conserver empty_ua (ban direct confirmé) + no_browser_signature.
            return ['empty_ua', 'no_browser_signature'];
        }

        $ua_lower = strtolower($ua_raw);

        // 1. On définit qui est "Ami"
        $is_friend = false;
        $behavior_safe_bots = array_merge(self::$legit_bot_uas, [
            'googlebot', 'bingbot', 'applebot', 'yandexbot', 'duckduckbot',
            'baiduspider', 'petalbot', 'facebookexternalhit', 'linkedinbot',
            'twitterbot', 'claudebot', 'gptbot', 'amazonbot', 'bytespider',
        ]);

        foreach ($behavior_safe_bots as $lb) {
            if (strpos($ua_lower, $lb) !== false) {
                $is_friend = true;
                break;
            }
        }

        // 2. DETECTION DES PATTERNS
        $bot_keywords = [
            'bot/', 'bot;', 'crawler', 'spider', 'scraper', 'headlesschrome',
            'phantomjs', 'selenium', 'puppeteer', 'scrapy', 'nutch',
            'python-requests', 'python-urllib', 'java/', 'httpclient',
            'okhttp', 'axios', 'node-fetch', 'wget', 'curl/',
            'go-http-client', 'mozlila/', 'bulid/',
        ];

        foreach ($bot_keywords as $pattern) {
            if (strpos($ua_lower, $pattern) !== false) {
                $clean_pattern = trim($pattern, '/;');

                // SI c'est un ami, on change le nom du signal
                if ($is_friend) {
                    $signals[] = 'legit_ua_pattern:' . $clean_pattern;
                } else {
                    $signals[] = 'ua_pattern:' . $clean_pattern;
                }
                break;
            }
        }
        // Fake Chrome build numbers (botnet pattern)
        // Real Chrome 120+ builds are in the 6000-7999 range
        if (preg_match('/Chrome\/(\d+)\.0\.(\d+)\.(\d+)/', $ua_raw, $matches)) {
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

        // Firefox < seuil configurable (défaut: 30 = 2014)
        $firefox_threshold = (int)($this->config['bastien59_stats_firefox_threshold'] ?? 30);
        if (preg_match('/Firefox\/(\d+)\./', $ua_raw, $matches)) {
            if ((int)$matches[1] < $firefox_threshold) {
                $signals[] = 'old_firefox';
            }
        }

        // Impossible Gecko dates
        if (preg_match('/Gecko\/(\d{4})-/', $ua_raw, $matches)) {
            $gecko_year = (int)$matches[1];
            if ($gecko_year > 2030 || $gecko_year < 2000) {
                $signals[] = 'bad_gecko_date';
            }
        }

        // Chrome < seuil configurable = trop ancien (défaut: 130 = oct 2024)
        $chrome_threshold = (int)($this->config['bastien59_stats_chrome_threshold'] ?? 130);
        if (preg_match('/Chrome\/(\d+)\./', $ua_raw, $matches)) {
            $chromeVer = (int)$matches[1];
            if ($chromeVer < $chrome_threshold && $chromeVer > 0 && strpos($ua_lower, 'headlesschrome') === false) {
                $signals[] = 'old_chrome_' . $chromeVer;
            }
        }

        // Safari build number fake (réel >= 400)
        if (preg_match('/Safari\/(\d+)\./', $ua_raw, $matches)) {
            $safari_build = (int)$matches[1];
            if ($safari_build < 400 && $safari_build > 0) {
                $signals[] = 'fake_safari_build';
            }
        }

        // Template literal non résolu dans le UA (ex: Firefox/{version})
        if (strpos($ua_raw, '{') !== false && strpos($ua_raw, '}') !== false) {
            $signals[] = 'template_literal';
        }

        // iPhone OS 13_2_3 figé = botnet Tencent Cloud
        if (strpos($ua_raw, 'iPhone OS 13_2_3') !== false) {
            $signals[] = 'iphone_13_2_3';
        }

        return $signals;
    }

    /**
     * Détection comportementale des bots (UA valide mais comportement impossible)
     * @return array Liste des signaux comportementaux détectés (vide = pas un bot)
     */
    private function detect_bot_behavior($page_url, $referer, $is_first_visit, $screen_res, $session_id, $user_agent, $country_code_hint = '', $visitor_cookie_hash = '')
    {
        $signals = [];
        $hard_signals = [];
        $score_signals = [];
        $observation_signals = [];
        $behavior_score = 0;

        $add_hard_signal = function ($signal) use (&$hard_signals) {
            if ($signal !== '' && !in_array($signal, $hard_signals, true)) {
                $hard_signals[] = $signal;
            }
        };
        $add_score_signal = function ($signal, $points) use (&$score_signals, &$behavior_score) {
            if ($signal === '') {
                return;
            }
            if (!in_array($signal, $score_signals, true)) {
                $score_signals[] = $signal;
                $behavior_score += (int)$points;
            }
        };
        $add_observe_signal = function ($signal) use (&$observation_signals) {
            if ($signal !== '' && !in_array($signal, $observation_signals, true)) {
                $observation_signals[] = $signal;
            }
        };

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
        if ($this->is_attachment_download_page_url($page_lower)) {
            return $signals;
        }
        $snapshot = $this->get_session_behavior_snapshot($session_id);
        $page_count = (int)$snapshot['page_count'];
        $has_res_cookie = !empty($screen_res) || (int)$snapshot['has_screen_res'] === 1;
        $has_res_ajax = (int)$snapshot['has_screen_res_ajax'] === 1;
        $has_any_resolution = $has_res_cookie || $has_res_ajax;
        $profile_screen_res = !empty($screen_res) ? (string)$screen_res : (string)($snapshot['screen_res_any'] ?? '');
        $profile_os = $this->get_os($user_agent, $profile_screen_res);
        $profile_device = $this->get_device($user_agent, $profile_screen_res);
        $profile_browser = $this->get_browser_family($user_agent);
        $profile_key = $this->build_behavior_profile_key($profile_os, $profile_device, $profile_browser);
        $learning_enabled = !empty($this->config['bastien59_stats_learning_enabled']);
        $learning_min_samples = max(10, (int)($this->config['bastien59_stats_learning_min_samples'] ?? 25));
        $country_code = strtoupper(trim((string)$country_code_hint));
        if ($country_code === '') {
            $country_code = strtoupper(trim((string)($snapshot['country_code_any'] ?? '')));
        }
        $country_pending = $this->is_country_code_pending($country_code);
        $country_excluded = $this->is_guest_clone_country_excluded($country_code);

        $referer_trimmed = trim((string)$referer);
        $is_direct_entry = ($referer_trimmed === '' || $referer_trimmed === '-');

        // Signal 1 : accès direct invité à posting.php en première visite.
        // On limite strictement aux modes de publication (post/reply/quote)
        // et aux entrées directes pour éviter les faux positifs sur:
        // - mode=edit (session expirée / onglet ancien)
        // - navigation normale intra-forum avec referer.
        if ($is_first_visit && strpos($page_lower, 'posting.php') !== false) {
            $posting_mode = '';
            if (preg_match('/[?&]mode=([a-z_]+)/', $page_lower, $mode_match)) {
                $posting_mode = strtolower((string)$mode_match[1]);
            }
            $is_publish_mode = in_array($posting_mode, ['post', 'reply', 'quote'], true);
            if ($is_publish_mode && $is_direct_entry && $page_count <= 1) {
                $add_hard_signal('posting_first_visit');
            }
        }

        // Signal 1b (strict) : accès direct à une fiche membre en première visite,
        // sans navigation préalable et sans résolution (cookie + AJAX).
        $is_viewprofile_page = (
            strpos($page_lower, 'memberlist.php') !== false
            && strpos($page_lower, 'mode=viewprofile') !== false
        );
        if (
            $is_first_visit
            && $is_viewprofile_page
            && $is_direct_entry
            && !$has_res_cookie
            && !$has_res_ajax
            && $page_count <= 1
        ) {
            $add_hard_signal('viewprofile_first_visit_no_res');
        }

        // Signal 2 : Referer auto-référent sur posting.php (boucle GET)
        if (strpos($page_lower, 'posting.php') !== false && !empty($referer)) {
            if (strpos(strtolower($referer), 'posting.php') !== false) {
                preg_match('/[?&]p=(\d+)/', $page_url, $page_m);
                preg_match('/[?&]p=(\d+)/', $referer, $ref_m);
                if (!empty($page_m[1]) && !empty($ref_m[1]) && $page_m[1] === $ref_m[1]) {
                    $add_hard_signal('posting_get_loop');
                }
            }
        }

        // Signal 3 : Invité sans screen resolution après N+ pages (configurable, défaut: 3)
        $noscreenres_pages = (int)($this->config['bastien59_stats_noscreenres_pages'] ?? 3);
        if (!$is_first_visit && !$has_any_resolution && $page_count >= $noscreenres_pages) {
            if ($page_count >= max(7, $noscreenres_pages + 3)) {
                $add_hard_signal('no_screen_res');
            } else {
                $add_score_signal('no_screen_res', 35);
            }
        }

        // Signal 4 : Entités HTML dans l'URL (scraper qui parse le HTML source)
        // $page_url et $referer sont déjà raw (via raw_variable), pas d'htmlspecialchars.
        // Un vrai navigateur ne met JAMAIS &amp; ou amp%3B dans l'URL HTTP réelle.
        if (preg_match('/&amp;|amp%3[Bb]/', $page_url) || preg_match('/&amp;|amp%3[Bb]/', $referer)) {
            $add_hard_signal('html_entities_in_url');
        }

        // Signal 5 : Télémétrie AJAX avancée (scroll simulé / automation)
        if ($this->has_ajax_advanced_columns()) {
            $scroll_seen = (int)$snapshot['scroll_down'] === 1;
            $webdriver_seen = (int)$snapshot['ajax_webdriver'] === 1;
            $interact_mask = (int)$snapshot['ajax_interact_mask'];
            $first_scroll_ms = (int)$snapshot['ajax_first_scroll_ms'];
            $scroll_events = (int)$snapshot['ajax_scroll_events'];
            $scroll_max_y = (int)$snapshot['ajax_scroll_max_y'];
            $learning_hits = 0;

            if ($webdriver_seen) {
                $add_hard_signal('ajax_webdriver');
            }

            if ($scroll_seen) {
                $no_interact = ($interact_mask === 0);
                $too_fast = ($first_scroll_ms > 0 && $first_scroll_ms <= 250);
                $jump_scroll = ($scroll_max_y >= 1400 && $scroll_events > 0 && $scroll_events <= 2);
                $single_long_scroll = ($scroll_max_y >= 900 && $scroll_events > 0 && $scroll_events <= 1);

                if ($no_interact) {
                    $add_score_signal('ajax_scroll_no_interact', 25);
                }
                if ($too_fast) {
                    $add_score_signal('ajax_scroll_too_fast', 30);
                }
                if ($jump_scroll) {
                    $add_score_signal('ajax_scroll_jump', 30);
                }

                if ($no_interact && $too_fast && $jump_scroll) {
                    $add_hard_signal('ajax_scroll_profile');
                } elseif (
                    ($no_interact && $too_fast) ||
                    ($no_interact && $jump_scroll && $first_scroll_ms > 0 && $first_scroll_ms <= 1800) ||
                    ($no_interact && $single_long_scroll && $first_scroll_ms > 0 && $first_scroll_ms <= 1200)
                ) {
                    $add_score_signal('ajax_scroll_profile', 20);
                }

                // Signal 6 : comparaison avec profils appris des membres connectés.
                $has_reactions_learning = $this->has_reactions_learning_columns();
                $profile_row = $learning_enabled ? $this->get_behavior_profile_row($profile_key) : [];
                if (!empty($profile_row) && (int)$profile_row['sample_count'] >= $learning_min_samples) {
                    $sample_count = (int)$profile_row['sample_count'];
                    $avg_ms = (int)$profile_row['avg_first_scroll_ms'];
                    $avg_events = (int)$profile_row['avg_scroll_events'];
                    $avg_max_y = (int)$profile_row['avg_scroll_max_y'];
                    $no_interact_rate = ((int)$profile_row['no_interact_hits'] / max(1, $sample_count));
                    $fast_rate = ((int)$profile_row['fast_scroll_hits'] / max(1, $sample_count));
                    $jump_rate = ((int)$profile_row['jump_scroll_hits'] / max(1, $sample_count));
                    $reactions_missing_rate = $has_reactions_learning
                        ? ((int)($profile_row['reactions_missing_hits'] ?? 0) / max(1, $sample_count))
                        : 1.0;

                    if ($interact_mask === 0 && $no_interact_rate <= 0.10) {
                        $learning_hits++;
                        $add_score_signal('learn_no_interact_outlier', 25);
                    }

                    // Durci: le "speed outlier" doit representer un scroll reel
                    // (pas un micro-scroll de quelques pixels).
                    $fast_threshold = max(120, (int)floor($avg_ms * 0.18));
                    $fast_cap_ms = 350;
                    if (
                        $first_scroll_ms > 0
                        && $avg_ms > 0
                        && $scroll_events >= 2
                        && $scroll_max_y >= 300
                        && $first_scroll_ms <= min($fast_threshold, $fast_cap_ms)
                        && $fast_rate <= 0.15
                    ) {
                        $learning_hits++;
                        $add_score_signal('learn_speed_outlier', 25);
                    }

                    // Outlier "sparse" uniquement si la distance scrollee est significative.
                    if ($scroll_events > 0 && $avg_events >= 4 && $scroll_events <= 1 && $scroll_max_y >= 600) {
                        $learning_hits++;
                        $add_score_signal('learn_sparse_scroll_outlier', 20);
                    }

                    $jump_threshold = max(1400, (int)floor($avg_max_y * 2.2));
                    if ($scroll_max_y > 0 && $avg_max_y > 0 && $scroll_max_y >= $jump_threshold && $scroll_events > 0 && $scroll_events <= 2 && $jump_rate <= 0.20) {
                        $learning_hits++;
                        $add_score_signal('learn_jump_outlier', 20);
                    }

                    $reactions_expected = (int)($snapshot['reactions_extension_expected'] ?? 0);
                    $reactions_css_seen = (int)($snapshot['reactions_css_seen'] ?? 0);
                    $reactions_js_seen = (int)($snapshot['reactions_js_seen'] ?? 0);
                    // L'extension Reactions charge le CSS pour tous, mais le JS uniquement pour les membres connectés.
                    // Ici (détection comportementale invités), on ne considère donc "manquant" que le CSS.
                    $reactions_missing = ($reactions_css_seen === 0);
                    if (
                        $has_reactions_learning
                        && $has_res_ajax
                        && $reactions_expected === 1
                        && $reactions_missing
                        && $reactions_missing_rate <= 0.05
                        && $page_count >= 2
                    ) {
                        $learning_hits++;
                        $add_score_signal('learn_reactions_assets_missing_outlier', 25);
                    }

                    if ($learning_hits >= 2) {
                        $add_score_signal('learn_behavior_outlier', 20);
                    }
                }

                // Signal 7/8 : signaux pays-dépendants.
                // Si pays inconnu, on stocke en mode "shadow" (différé) et le cron décidera
                // après géolocalisation si on doit les promouvoir en strict.
                if ($country_pending) {
                    if ($this->detect_guest_fingerprint_clone_multi_ip($session_id, $user_agent, $snapshot, $country_code, true)) {
                        $add_observe_signal('guest_fp_clone_multi_ip_shadow');
                    }
                    if ($this->detect_guest_cookie_clone_multi_ip($visitor_cookie_hash, $country_code, true)) {
                        $add_observe_signal('guest_cookie_clone_multi_ip_shadow');
                    }
                } elseif (!$country_excluded) {
                    if ($this->detect_guest_fingerprint_clone_multi_ip($session_id, $user_agent, $snapshot, $country_code, false)) {
                        $add_hard_signal('guest_fp_clone_multi_ip');
                    }
                    if ($this->detect_guest_cookie_clone_multi_ip($visitor_cookie_hash, $country_code, false)) {
                        $add_hard_signal('guest_cookie_clone_multi_ip');
                    }
                }

                // Signal 9 (strict) : AJAX reçu mais cookie signé non relu/invalide/incohérent.
                if ($this->has_visitor_cookie_debug_columns()) {
                    $cookie_hash = strtolower(trim((string)$visitor_cookie_hash));
                    $ajax_cookie_hash = strtolower(trim((string)($snapshot['visitor_cookie_ajax_hash_any'] ?? '')));
                    $ajax_cookie_state = (int)($snapshot['visitor_cookie_ajax_state'] ?? 0); // 0=none, 1=ok, 2=absent, 3=invalid, 4=mismatch
                    $cookie_preexisting = (int)($snapshot['visitor_cookie_preexisting'] ?? 0) === 1;
                    $cookie_hash_valid = preg_match('/^[a-f0-9]{64}$/', $cookie_hash);
                    $ajax_hash_valid = preg_match('/^[a-f0-9]{64}$/', $ajax_cookie_hash);
                    $ajax_cookie_mismatch = (
                        $ajax_cookie_state === 4
                        || ($ajax_cookie_state === 1 && $cookie_hash_valid && $ajax_hash_valid && !hash_equals($cookie_hash, $ajax_cookie_hash))
                    );
                    $ajax_cookie_absent = ($ajax_cookie_state === 2);
                    $ajax_cookie_invalid = ($ajax_cookie_state === 3);

                    // Conditions strictes: invité JS actif + scroll réel + cookie créé pendant la visite.
                    if ($cookie_hash_valid && !$cookie_preexisting && $page_count <= 2 && ($ajax_cookie_absent || $ajax_cookie_invalid || $ajax_cookie_mismatch)) {
                        if ($country_excluded) {
                            $add_observe_signal('guest_cookie_ajax_fail_shadow');
                        } else {
                            $add_hard_signal('guest_cookie_ajax_fail');
                        }
                    }
                }
            }
        }

        $signals = $hard_signals;
        $min_behavior_score = 65;
        if (!empty($hard_signals)) {
            $signals = array_merge($signals, $score_signals);
        } elseif ($behavior_score >= $min_behavior_score) {
            $signals = array_merge($signals, $score_signals);
        }
        if (!empty($observation_signals)) {
            $signals = array_merge($signals, $observation_signals);
        }

        return array_values(array_unique($signals));
    }

    /**
     * Détecte si les colonnes AJAX (migration 1.2.0) sont disponibles.
     */
    private function has_ajax_telemetry_columns()
    {
        if ($this->has_ajax_telemetry_columns !== null) {
            return $this->has_ajax_telemetry_columns;
        }

        $sql = 'SELECT screen_res_ajax, scroll_down_ajax, ajax_seen_time
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_ajax_telemetry_columns = !$has_error;
        return $this->has_ajax_telemetry_columns;
    }

    /**
     * Détecte si les colonnes AJAX avancées (migration 1.3.0) sont disponibles.
     */
    private function has_ajax_advanced_columns()
    {
        if ($this->has_ajax_advanced_columns !== null) {
            return $this->has_ajax_advanced_columns;
        }

        $sql = 'SELECT ajax_interact_mask, ajax_first_scroll_ms, ajax_scroll_events,
                       ajax_scroll_max_y, ajax_webdriver, ajax_telemetry_ver
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_ajax_advanced_columns = !$has_error;
        return $this->has_ajax_advanced_columns;
    }

    /**
     * Détecte si les colonnes de tracking curseur (migration 1.9.0) sont disponibles.
     */
    private function has_cursor_columns()
    {
        if ($this->has_cursor_columns !== null) {
            return $this->has_cursor_columns;
        }

        $sql = 'SELECT cursor_track_points, cursor_track_duration_ms, cursor_track_path, cursor_click_points,
                       cursor_device_class, cursor_viewport, cursor_total_distance, cursor_avg_speed,
                       cursor_max_speed, cursor_direction_changes, cursor_linearity, cursor_click_count
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_cursor_columns = !$has_error;
        return $this->has_cursor_columns;
    }

    /**
     * Détecte si les colonnes de métrique "assets reactions" sont disponibles.
     */
    private function has_reactions_probe_columns()
    {
        if ($this->has_reactions_probe_columns !== null) {
            return $this->has_reactions_probe_columns;
        }

        $sql = 'SELECT reactions_extension_expected, reactions_css_seen, reactions_js_seen
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_reactions_probe_columns = !$has_error;
        return $this->has_reactions_probe_columns;
    }

    /**
     * Détecte si la colonne visitor_cookie_hash est disponible (migration 1.7.0).
     */
    private function has_visitor_cookie_column()
    {
        if ($this->has_visitor_cookie_column !== null) {
            return $this->has_visitor_cookie_column;
        }

        $sql = 'SELECT visitor_cookie_hash
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_visitor_cookie_column = !$has_error;
        return $this->has_visitor_cookie_column;
    }

    /**
     * Détecte si les colonnes debug cookie (migration 1.8.0) sont disponibles.
     */
    private function has_visitor_cookie_debug_columns()
    {
        if ($this->has_visitor_cookie_debug_columns !== null) {
            return $this->has_visitor_cookie_debug_columns;
        }

        $sql = 'SELECT visitor_cookie_preexisting, visitor_cookie_ajax_state, visitor_cookie_ajax_hash
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_visitor_cookie_debug_columns = !$has_error;
        return $this->has_visitor_cookie_debug_columns;
    }

    /**
     * Détecte si les colonnes de tentative de login ratée (migration 1.17.0) existent.
     */
    private function has_login_attempt_columns()
    {
        if ($this->has_login_attempt_columns !== null) {
            return $this->has_login_attempt_columns;
        }

        $sql = 'SELECT login_attempt_failed, login_attempt_username, login_attempt_error
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->has_login_attempt_columns = !$has_error;
        return $this->has_login_attempt_columns;
    }

    /**
     * Associe la tentative de login ratée au rendu de la page de connexion du même POST.
     * On la consomme une seule fois pour éviter de polluer une requête suivante.
     */
    private function consume_pending_login_attempt_for_page($page_url)
    {
        if (!is_array($this->pending_login_attempt) || empty($this->pending_login_attempt['failed'])) {
            return null;
        }

        if (!$this->is_login_page_url($page_url)) {
            return null;
        }

        $payload = $this->pending_login_attempt;
        $this->pending_login_attempt = null;

        return $payload;
    }

    /**
     * Détecte la page de connexion phpBB classique.
     */
    private function is_login_page_url($page_url)
    {
        $raw_url = trim((string)$page_url);
        if ($raw_url === '') {
            return false;
        }

        $path = parse_url($raw_url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $raw_url;
        }

        $path = strtolower($path);
        if (substr($path, -7) !== 'ucp.php' && $path !== '/ucp.php') {
            return false;
        }

        parse_str((string)parse_url($raw_url, PHP_URL_QUERY), $query);
        return strtolower(trim((string)($query['mode'] ?? ''))) === 'login';
    }

    /**
     * Snapshot agrégé de session pour la détection comportementale.
     * Fallback automatique si migrations AJAX absentes.
     */
    private function get_session_behavior_snapshot($session_id)
    {
        $snapshot = [
            'page_count' => 0,
            'has_screen_res' => 0,
            'has_screen_res_ajax' => 0,
            'screen_res_any' => '',
            'screen_res_ajax_any' => '',
            'country_code_any' => '',
            'scroll_down' => 0,
            'ajax_interact_mask' => 0,
            'ajax_first_scroll_ms' => 0,
            'ajax_scroll_events' => 0,
            'ajax_scroll_max_y' => 0,
            'ajax_webdriver' => 0,
            'ajax_telemetry_ver' => 0,
            'reactions_extension_expected' => 0,
            'reactions_css_seen' => 0,
            'reactions_js_seen' => 0,
            'visitor_cookie_preexisting' => 0,
            'visitor_cookie_ajax_state' => 0,
            'visitor_cookie_ajax_hash_any' => '',
        ];

        if (!preg_match('/^[A-Za-z0-9]{32}$/', (string)$session_id)) {
            return $snapshot;
        }

        $fields = [
            "SUM(CASE WHEN LOWER(page_url) LIKE '%download/file.php%' THEN 0 ELSE 1 END) AS page_count",
            "MAX(CASE WHEN screen_res <> '' THEN 1 ELSE 0 END) AS has_screen_res",
            "MAX(CASE WHEN country_code <> '' THEN UPPER(country_code) ELSE '' END) AS country_code_any",
        ];

        if ($this->has_ajax_telemetry_columns()) {
            $fields[] = "MAX(CASE WHEN screen_res_ajax <> '' THEN 1 ELSE 0 END) AS has_screen_res_ajax";
            $fields[] = 'MAX(scroll_down_ajax) AS scroll_down';
            $fields[] = "MAX(CASE WHEN screen_res_ajax <> '' THEN screen_res_ajax ELSE screen_res END) AS screen_res_any";
            $fields[] = "MAX(CASE WHEN screen_res_ajax <> '' THEN screen_res_ajax ELSE '' END) AS screen_res_ajax_any";
        } else {
            $fields[] = "MAX(CASE WHEN screen_res <> '' THEN screen_res ELSE '' END) AS screen_res_any";
        }

        if ($this->has_ajax_advanced_columns()) {
            $fields[] = 'MAX(ajax_interact_mask) AS ajax_interact_mask';
            $fields[] = 'MAX(ajax_first_scroll_ms) AS ajax_first_scroll_ms';
            $fields[] = 'MAX(ajax_scroll_events) AS ajax_scroll_events';
            $fields[] = 'MAX(ajax_scroll_max_y) AS ajax_scroll_max_y';
            $fields[] = 'MAX(ajax_webdriver) AS ajax_webdriver';
            $fields[] = 'MAX(ajax_telemetry_ver) AS ajax_telemetry_ver';
        }
        if ($this->has_reactions_probe_columns()) {
            $fields[] = 'MAX(reactions_extension_expected) AS reactions_extension_expected';
            $fields[] = 'MAX(reactions_css_seen) AS reactions_css_seen';
            $fields[] = 'MAX(reactions_js_seen) AS reactions_js_seen';
        }
        if ($this->has_visitor_cookie_debug_columns()) {
            $fields[] = 'MAX(visitor_cookie_preexisting) AS visitor_cookie_preexisting';
            $fields[] = 'MAX(visitor_cookie_ajax_state) AS visitor_cookie_ajax_state';
            $fields[] = "MAX(CASE WHEN visitor_cookie_ajax_hash <> '' THEN visitor_cookie_ajax_hash ELSE '' END) AS visitor_cookie_ajax_hash_any";
        }

        $sql = 'SELECT ' . implode(', ', $fields) . '
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE session_id = \'' . $this->db->sql_escape($session_id) . '\'';

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($has_error || $result === false) {
            $this->db->sql_return_on_error(false);
            return $snapshot;
        }

        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        $this->db->sql_return_on_error(false);

        if (!$row) {
            return $snapshot;
        }

        $snapshot['page_count'] = (int)($row['page_count'] ?? 0);
        $snapshot['has_screen_res'] = (int)($row['has_screen_res'] ?? 0);
        $snapshot['has_screen_res_ajax'] = (int)($row['has_screen_res_ajax'] ?? 0);
        $snapshot['screen_res_any'] = (string)($row['screen_res_any'] ?? '');
        $snapshot['screen_res_ajax_any'] = (string)($row['screen_res_ajax_any'] ?? '');
        $snapshot['country_code_any'] = (string)($row['country_code_any'] ?? '');
        $snapshot['scroll_down'] = (int)($row['scroll_down'] ?? 0);
        $snapshot['ajax_interact_mask'] = (int)($row['ajax_interact_mask'] ?? 0);
        $snapshot['ajax_first_scroll_ms'] = (int)($row['ajax_first_scroll_ms'] ?? 0);
        $snapshot['ajax_scroll_events'] = (int)($row['ajax_scroll_events'] ?? 0);
        $snapshot['ajax_scroll_max_y'] = (int)($row['ajax_scroll_max_y'] ?? 0);
        $snapshot['ajax_webdriver'] = (int)($row['ajax_webdriver'] ?? 0);
        $snapshot['ajax_telemetry_ver'] = (int)($row['ajax_telemetry_ver'] ?? 0);
        $snapshot['reactions_extension_expected'] = (int)($row['reactions_extension_expected'] ?? 0);
        $snapshot['reactions_css_seen'] = (int)($row['reactions_css_seen'] ?? 0);
        $snapshot['reactions_js_seen'] = (int)($row['reactions_js_seen'] ?? 0);
        $snapshot['visitor_cookie_preexisting'] = (int)($row['visitor_cookie_preexisting'] ?? 0);
        $snapshot['visitor_cookie_ajax_state'] = (int)($row['visitor_cookie_ajax_state'] ?? 0);
        $snapshot['visitor_cookie_ajax_hash_any'] = (string)($row['visitor_cookie_ajax_hash_any'] ?? '');

        return $snapshot;
    }

    /**
     * Exclusion géographique stricte pour le signal clone invité multi-IP.
     * Les IP FR/CO ne déclenchent jamais ce signal.
     */
    private function is_guest_clone_country_excluded($country_code)
    {
        $cc = strtoupper(trim((string)$country_code));
        // Pays inconnu => mode observation (pas de strict) pour éviter les faux positifs.
        if ($cc === '' || $cc === '-' || $cc === 'ZZ') {
            return true;
        }
        return ($cc === 'FR' || $cc === 'CO');
    }

    private function is_country_code_pending($country_code)
    {
        $cc = strtoupper(trim((string)$country_code));
        return ($cc === '' || $cc === '-' || $cc === 'ZZ');
    }

    /**
     * Détecte un fingerprint invité cloné sur plusieurs IPs en peu de temps.
     * Règles strictes pour limiter les faux positifs:
     * - invité uniquement
     * - télémétrie AJAX complète et scroll réel
     * - même tuple (UA + résolution AJAX + mask + events + maxY)
     * - diffusion multi-IP dans une fenêtre courte
     * - exclusion FR/CO
     */
    private function detect_guest_fingerprint_clone_multi_ip($session_id, $user_agent, array $snapshot, $country_code, $allow_pending_country = false)
    {
        $cc = strtoupper(trim((string)$country_code));
        if ($cc === 'FR' || $cc === 'CO') {
            return false;
        }
        if (!$allow_pending_country && $this->is_country_code_pending($cc)) {
            return false;
        }

        if (!$this->has_ajax_telemetry_columns() || !$this->has_ajax_advanced_columns()) {
            return false;
        }

        if (!preg_match('/^[A-Za-z0-9]{32}$/', (string)$session_id)) {
            return false;
        }

        $page_count = (int)($snapshot['page_count'] ?? 0);
        if ($page_count < 1 || $page_count > 3) {
            return false;
        }

        if ((int)($snapshot['has_screen_res_ajax'] ?? 0) !== 1) {
            return false;
        }
        if ((int)($snapshot['scroll_down'] ?? 0) !== 1) {
            return false;
        }

        $screen_res_ajax = trim((string)($snapshot['screen_res_ajax_any'] ?? ''));
        if (!preg_match('/^[1-9][0-9]{1,4}x[1-9][0-9]{1,4}$/', $screen_res_ajax)) {
            return false;
        }

        $interact_mask = (int)($snapshot['ajax_interact_mask'] ?? 0);
        $scroll_events = (int)($snapshot['ajax_scroll_events'] ?? 0);
        $scroll_max_y = (int)($snapshot['ajax_scroll_max_y'] ?? 0);

        if ($interact_mask < 0 || $interact_mask > 255) {
            return false;
        }
        if ($scroll_events <= 0 || $scroll_events > 20) {
            return false;
        }
        if ($scroll_max_y < 900 || $scroll_max_y > 500000) {
            return false;
        }

        $ua = trim(substr((string)$user_agent, 0, 254));
        if ($ua === '') {
            return false;
        }

        // Seuils stricts (évite les faux positifs):
        // 4 IP distinctes + 6 sessions similaires en 30 min.
        $window_sec = 1800;
        $min_unique_ips = 4;
        $min_hits = 6;
        $cutoff = time() - $window_sec;

        $sql = 'SELECT COUNT(*) AS total_hits, COUNT(DISTINCT user_ip) AS unique_ips
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time >= ' . (int)$cutoff . '
                AND user_id <= 1
                AND is_first_visit = 1
                AND user_agent = \'' . $this->db->sql_escape($ua) . '\'
                AND screen_res_ajax = \'' . $this->db->sql_escape($screen_res_ajax) . '\'
                AND ajax_interact_mask = ' . (int)$interact_mask . '
                AND ajax_scroll_events = ' . (int)$scroll_events . '
                AND ajax_scroll_max_y = ' . (int)$scroll_max_y;
        if (!$allow_pending_country) {
            $sql .= " AND UPPER(country_code) NOT IN ('FR','CO')";
        }

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($has_error || $result === false) {
            $this->db->sql_return_on_error(false);
            return false;
        }

        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        $this->db->sql_return_on_error(false);

        if (!$row) {
            return false;
        }

        $hits = (int)($row['total_hits'] ?? 0);
        $uniq = (int)($row['unique_ips'] ?? 0);
        return ($uniq >= $min_unique_ips && $hits >= $min_hits);
    }

    /**
     * Détecte un cookie visiteur invité cloné sur plusieurs IPs en fenêtre courte.
     * Strict: exclut FR/CO + seuils élevés pour limiter les faux positifs.
     */
    private function detect_guest_cookie_clone_multi_ip($visitor_cookie_hash, $country_code, $allow_pending_country = false)
    {
        $cc = strtoupper(trim((string)$country_code));
        if ($cc === 'FR' || $cc === 'CO') {
            return false;
        }
        if (!$allow_pending_country && $this->is_country_code_pending($cc)) {
            return false;
        }

        if (!$this->has_visitor_cookie_column()) {
            return false;
        }

        $hash = strtolower(trim((string)$visitor_cookie_hash));
        if (!preg_match('/^[a-f0-9]{64}$/', $hash)) {
            return false;
        }

        $window_sec = 1800;
        $min_unique_ips = 4;
        $min_hits = 6;
        $cutoff = time() - $window_sec;

        $sql = 'SELECT COUNT(*) AS total_hits,
                       COUNT(DISTINCT user_ip) AS unique_ips,
                       COUNT(DISTINCT session_id) AS unique_sessions
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE visit_time >= ' . (int)$cutoff . '
                AND user_id <= 1
                AND is_first_visit = 1
                AND visitor_cookie_hash = \'' . $this->db->sql_escape($hash) . '\'';
        if (!$allow_pending_country) {
            $sql .= ' AND UPPER(country_code) NOT IN (\'FR\',\'CO\')';
        }

        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        if ($has_error || $result === false) {
            $this->db->sql_return_on_error(false);
            return false;
        }

        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        $this->db->sql_return_on_error(false);

        if (!$row) {
            return false;
        }

        $hits = (int)($row['total_hits'] ?? 0);
        $uniq_ips = (int)($row['unique_ips'] ?? 0);
        $uniq_sessions = (int)($row['unique_sessions'] ?? 0);
        return ($uniq_ips >= $min_unique_ips && $hits >= $min_hits && $uniq_sessions >= $min_hits);
    }

    /**
     * Les signaux pays-dépendants ne doivent pas alimenter fail2ban tant que
     * la géolocalisation IP n'est pas terminée par le cron async.
     *
     * @param string[] $signals
     * @return string[]
     */
    private function filter_security_audit_signals_by_country(array $signals, $country_code)
    {
        if (empty($signals)) {
            return [];
        }

        if (!$this->is_country_code_pending($country_code)) {
            return $signals;
        }

        $country_bound = [
            'guest_fp_clone_multi_ip' => true,
            'guest_cookie_clone_multi_ip' => true,
            'guest_cookie_ajax_fail' => true,
        ];

        $filtered = [];
        foreach ($signals as $sig) {
            $key = trim((string)$sig);
            if ($key === '') {
                continue;
            }
            if (isset($country_bound[$key])) {
                continue;
            }
            $filtered[] = $key;
        }

        return array_values(array_unique($filtered));
    }

    /**
     * Émet un signal différé pour sessions invitées CN sans interaction après 5 min.
     * Règle:
     * - pays dans la liste CN_NO_INTERACTION_COUNTRY_CODES (actuellement CN)
     * - session invitée (user_id <= 1), non-bot
     * - 5 minutes écoulées depuis la page d'entrée de session
     * - aucune interaction observée (scroll/interactions/cursor) depuis cette entrée
     *
     * Sans cron: le signal est émis au prochain hit du site.
     */
    private function emit_cn_no_interaction_signals($time_now)
    {
        if (!$this->is_cn_no_interaction_enabled()) {
            return;
        }

        // Throttle : un seul worker toutes les 60 secondes.
        // set_atomic() échoue si la valeur a changé entre-temps → garde atomicité.
        $throttle_key = 'bastien59_stats_cn_no_interaction_last_run';
        $last_run = (int)($this->config[$throttle_key] ?? 0);
        if ((int)$time_now - $last_run < 60) {
            return;
        }
        $this->config->set_atomic($throttle_key, $last_run, (string)(int)$time_now);

        $countries = $this->get_cn_no_interaction_country_codes();
        if (empty($countries)) {
            return;
        }

        $signal = self::CN_NO_INTERACTION_SIGNAL;
        $cutoff = (int)$time_now - (int)self::CN_NO_INTERACTION_DELAY_SEC;
        if ($cutoff <= 0) {
            return;
        }

        $country_sql = [];
        foreach ($countries as $cc) {
            $country_sql[] = '\'' . $this->db->sql_escape($cc) . '\'';
        }
        $stats_table = $this->table_prefix . 'bastien59_stats';

        $sql = 'SELECT s.log_id, s.session_id, s.user_ip, s.user_id, s.user_agent, s.page_url, s.screen_res, s.signals,
                       (SELECT COUNT(*) FROM ' . $stats_table . ' s4
                        WHERE s4.session_id = s.session_id
                        AND s4.log_id >= s.log_id) AS page_count
                FROM ' . $stats_table . ' s
                WHERE s.is_first_visit = 1
                AND s.user_id <= 1
                AND s.is_bot = 0
                AND s.visit_time <= ' . (int)$cutoff . '
                AND UPPER(s.country_code) IN (' . implode(',', $country_sql) . ')
                AND (s.signals = \'\' OR s.signals NOT LIKE \'%' . $this->db->sql_escape($signal) . '%\')
                AND s.log_id = (
                    SELECT MAX(sx.log_id)
                    FROM ' . $stats_table . ' sx
                    WHERE sx.session_id = s.session_id
                    AND sx.is_first_visit = 1
                )
                AND (SELECT COUNT(*) FROM ' . $stats_table . ' s4b
                     WHERE s4b.session_id = s.session_id
                     AND s4b.log_id >= s.log_id) = 1
                AND NOT EXISTS (
                    SELECT 1
                    FROM ' . $stats_table . ' s5
                    WHERE s5.user_ip = s.user_ip
                    AND s5.session_id <> s.session_id
                    AND s5.visit_time >= (s.visit_time - 86400)
                    AND s5.visit_time < s.visit_time
                )
                AND NOT EXISTS (
                    SELECT 1
                    FROM ' . $stats_table . ' s3
                    WHERE s3.session_id = s.session_id
                    AND s3.log_id >= s.log_id
                    AND s3.is_bot = 1
                )
                ORDER BY s.visit_time ASC';

        $result = $this->db->sql_query_limit($sql, (int) self::CN_NO_INTERACTION_BATCH_LIMIT);
        while ($row = $this->db->sql_fetchrow($result)) {
            $existing_signals = (string)($row['signals'] ?? '');
            $updated_signals = $this->append_signal_once($existing_signals, $signal);
            if ($updated_signals === $existing_signals) {
                continue; // signal déjà posé (sécurité)
            }

            // Mise à jour atomique : n'affecte la ligne que si le signal n'a pas encore été posé.
            // Si deux workers ont sélectionné la même ligne, seul le premier UPDATE réussit (affectedrows > 0).
            $sql_update = 'UPDATE ' . $stats_table . '
                           SET signals = \'' . $this->db->sql_escape(substr($updated_signals, 0, 255)) . '\'
                           WHERE log_id = ' . (int)$row['log_id'] . '
                           AND (signals = \'\' OR signals NOT LIKE \'%' . $this->db->sql_escape($signal) . '%\')';
            $this->db->sql_query($sql_update);

            // N'émettre le signal audit que si le UPDATE a réellement posé la marque (pas de doublon).
            if ($this->db->sql_affectedrows() > 0) {
                $this->write_security_audit(
                    (string)($row['user_ip'] ?? ''),
                    (string)($row['session_id'] ?? ''),
                    (int)($row['user_id'] ?? 0),
                    [$signal],
                    (string)($row['user_agent'] ?? ''),
                    (string)($row['page_url'] ?? ''),
                    (string)($row['screen_res'] ?? ''),
                    (int)($row['page_count'] ?? 1),
                    '',
                    '',
                    ''
                );
            }
        }
        $this->db->sql_freeresult($result);
    }

    /**
     * Active/désactive le signal CN "pas d'interaction en 5 minutes".
     * Défaut: actif si la config n'existe pas encore.
     */
    private function is_cn_no_interaction_enabled()
    {
        if (!isset($this->config['bastien59_stats_cn_no_interaction_enabled'])) {
            return true;
        }
        return (int)$this->config['bastien59_stats_cn_no_interaction_enabled'] === 1;
    }

    /**
     * Codes pays concernés par la méthode "pas d'interaction en 5 minutes".
     *
     * @return string[]
     */
    private function get_cn_no_interaction_country_codes()
    {
        $out = [];
        foreach (self::CN_NO_INTERACTION_COUNTRY_CODES as $cc) {
            $cc = strtoupper(trim((string)$cc));
            if (preg_match('/^[A-Z]{2}$/', $cc)) {
                $out[$cc] = true;
            }
        }
        return array_keys($out);
    }

    /**
     * Ajoute un signal dans une chaîne CSV sans doublon.
     */
    private function append_signal_once($signals_csv, $signal)
    {
        $target = trim((string)$signal);
        if ($target === '') {
            return (string)$signals_csv;
        }

        $seen = [];
        $list = [];
        foreach (explode(',', (string)$signals_csv) as $item) {
            $item = trim((string)$item);
            if ($item === '' || isset($seen[$item])) {
                continue;
            }
            $seen[$item] = true;
            $list[] = $item;
        }

        if (!isset($seen[$target])) {
            $list[] = $target;
        }

        return implode(',', $list);
    }

    /**
     * Vérifie que les tables d'apprentissage comportemental existent.
     */
    private function has_behavior_learning_tables()
    {
        if ($this->has_behavior_learning_tables !== null) {
            return $this->has_behavior_learning_tables;
        }

        $profile_sql = 'SELECT profile_key
                        FROM ' . $this->table_prefix . 'bastien59_stats_behavior_profile
                        WHERE 1 = 0';
        $seen_sql = 'SELECT session_id
                     FROM ' . $this->table_prefix . 'bastien59_stats_behavior_seen
                     WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result_profile = $this->db->sql_query_limit($profile_sql, 1);
        $profile_error = (bool)$this->db->get_sql_error_triggered();
        if ($result_profile !== false) {
            $this->db->sql_freeresult($result_profile);
        }

        $result_seen = $this->db->sql_query_limit($seen_sql, 1);
        $seen_error = (bool)$this->db->get_sql_error_triggered();
        if ($result_seen !== false) {
            $this->db->sql_freeresult($result_seen);
        }
        $this->db->sql_return_on_error(false);

        $this->has_behavior_learning_tables = !$profile_error && !$seen_error;
        return $this->has_behavior_learning_tables;
    }

    /**
     * Détecte si les colonnes "reactions" des tables d'apprentissage sont disponibles.
     */
    private function has_reactions_learning_columns()
    {
        if ($this->has_reactions_learning_columns !== null) {
            return $this->has_reactions_learning_columns;
        }

        $profile_sql = 'SELECT reactions_missing_hits
                        FROM ' . $this->table_prefix . 'bastien59_stats_behavior_profile
                        WHERE 1 = 0';
        $seen_sql = 'SELECT reactions_extension_expected, reactions_css_seen, reactions_js_seen
                     FROM ' . $this->table_prefix . 'bastien59_stats_behavior_seen
                     WHERE 1 = 0';

        $this->db->sql_return_on_error(true);
        $result_profile = $this->db->sql_query_limit($profile_sql, 1);
        $profile_error = (bool)$this->db->get_sql_error_triggered();
        if ($result_profile !== false) {
            $this->db->sql_freeresult($result_profile);
        }

        $result_seen = $this->db->sql_query_limit($seen_sql, 1);
        $seen_error = (bool)$this->db->get_sql_error_triggered();
        if ($result_seen !== false) {
            $this->db->sql_freeresult($result_seen);
        }
        $this->db->sql_return_on_error(false);

        $this->has_reactions_learning_columns = !$profile_error && !$seen_error;
        return $this->has_reactions_learning_columns;
    }

    /**
     * Vérifie si l'extension reactions est active.
     */
    private function is_reactions_extension_active()
    {
        if ($this->reactions_extension_active !== null) {
            return $this->reactions_extension_active;
        }

        $sql = 'SELECT ext_active
                FROM ' . $this->table_prefix . 'ext
                WHERE ext_name = \'bastien59960/reactions\'';
        $this->db->sql_return_on_error(true);
        $result = $this->db->sql_query_limit($sql, 1);
        $has_error = (bool)$this->db->get_sql_error_triggered();
        $active = false;
        if (!$has_error && $result !== false) {
            $row = $this->db->sql_fetchrow($result);
            $active = ((int)($row['ext_active'] ?? 0) === 1);
        }
        if ($result !== false) {
            $this->db->sql_freeresult($result);
        }
        $this->db->sql_return_on_error(false);

        $this->reactions_extension_active = $active;
        return $this->reactions_extension_active;
    }

    /**
     * Catégorie navigateur simplifiée pour profils d'apprentissage.
     */
    private function get_browser_family($user_agent)
    {
        $ua = strtolower((string)$user_agent);

        if (strpos($ua, 'edg/') !== false || strpos($ua, 'edge/') !== false) {
            return 'edge';
        }
        if (strpos($ua, 'opr/') !== false || strpos($ua, 'opera') !== false) {
            return 'opera';
        }
        if (strpos($ua, 'firefox/') !== false) {
            return 'firefox';
        }
        if (strpos($ua, 'safari/') !== false && strpos($ua, 'chrome/') === false) {
            return 'safari';
        }
        if (strpos($ua, 'chrome/') !== false || strpos($ua, 'crios/') !== false) {
            return 'chrome';
        }
        if (strpos($ua, 'trident/') !== false || strpos($ua, 'msie ') !== false) {
            return 'ie';
        }

        return 'other';
    }

    /**
     * Clé stable de profil: OS + device + famille navigateur.
     */
    private function build_behavior_profile_key($user_os, $user_device, $browser_family)
    {
        $normalize = function ($value) {
            $v = strtolower(trim((string)$value));
            $v = preg_replace('/\s+/', ' ', $v);
            return $v ?: '-';
        };

        $raw = $normalize($user_os) . '|' . $normalize($user_device) . '|' . $normalize($browser_family);
        return substr(sha1($raw), 0, 40);
    }

    /**
     * Compte le nombre de bits à 1 d'un masque d'interaction.
     */
    private function bit_count($value)
    {
        $v = max(0, (int)$value);
        $count = 0;
        while ($v > 0) {
            $count += ($v & 1);
            $v = $v >> 1;
        }
        return $count;
    }

    /**
     * Apprentissage: enregistre un profil moyen basé sur sessions de membres.
     */
    private function learn_registered_behavior($session_id, $user_agent, $screen_res)
    {
        if (empty($this->config['bastien59_stats_learning_enabled'])) {
            return;
        }

        if (!$this->has_ajax_advanced_columns() || !$this->has_behavior_learning_tables()) {
            return;
        }

        if (!preg_match('/^[A-Za-z0-9]{32}$/', (string)$session_id)) {
            return;
        }

        $user_id = (int)$this->user->data['user_id'];
        if ($user_id <= 1) {
            return;
        }
        $has_reactions_learning_columns = $this->has_reactions_learning_columns();
        $has_reactions_probe_columns = $this->has_reactions_probe_columns();

        $sql = 'SELECT session_id
                FROM ' . $this->table_prefix . 'bastien59_stats_behavior_seen
                WHERE session_id = \'' . $this->db->sql_escape($session_id) . '\'';
        $result = $this->db->sql_query_limit($sql, 1);
        $already_seen = (bool)$this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);
        if ($already_seen) {
            return;
        }

        $fields = [
            'MAX(user_os) AS user_os',
            'MAX(user_device) AS user_device',
            'MAX(screen_res) AS screen_res',
            'MAX(screen_res_ajax) AS screen_res_ajax',
            'MAX(ajax_seen_time) AS ajax_seen_time',
            'MAX(ajax_first_scroll_ms) AS ajax_first_scroll_ms',
            'MAX(ajax_scroll_events) AS ajax_scroll_events',
            'MAX(ajax_scroll_max_y) AS ajax_scroll_max_y',
            'MAX(ajax_interact_mask) AS ajax_interact_mask',
            'MAX(ajax_webdriver) AS ajax_webdriver',
        ];
        if ($has_reactions_probe_columns) {
            $fields[] = 'MAX(reactions_extension_expected) AS reactions_extension_expected';
            $fields[] = 'MAX(reactions_css_seen) AS reactions_css_seen';
            $fields[] = 'MAX(reactions_js_seen) AS reactions_js_seen';
        }

        $sql = 'SELECT ' . implode(', ', $fields) . '
                FROM ' . $this->table_prefix . 'bastien59_stats
                WHERE session_id = \'' . $this->db->sql_escape($session_id) . '\'
                AND user_id = ' . (int)$user_id . '
                AND is_bot = 0';

        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if (!$row) {
            return;
        }

        $ajax_seen_time = (int)($row['ajax_seen_time'] ?? 0);
        $first_scroll_ms = (int)($row['ajax_first_scroll_ms'] ?? 0);
        $scroll_events = (int)($row['ajax_scroll_events'] ?? 0);
        $scroll_max_y = (int)($row['ajax_scroll_max_y'] ?? 0);
        $interact_mask = (int)($row['ajax_interact_mask'] ?? 0);
        $ajax_webdriver = (int)($row['ajax_webdriver'] ?? 0);

        if ($ajax_seen_time <= 0 || $first_scroll_ms <= 0 || $scroll_events <= 0 || $scroll_max_y <= 0) {
            return;
        }
        if ($ajax_webdriver === 1) {
            return;
        }

        // Garde-fous anti-bruit pour garder une base d'apprentissage exploitable.
        $first_scroll_ms = min($first_scroll_ms, 120000);
        $scroll_events = min($scroll_events, 120);
        $scroll_max_y = min($scroll_max_y, 500000);
        if ($scroll_max_y < 20) {
            return;
        }
        if ($scroll_events <= 1 && $scroll_max_y < 80) {
            return;
        }

        $effective_res = trim((string)($row['screen_res_ajax'] ?? ''));
        if ($effective_res === '') {
            $effective_res = trim((string)($row['screen_res'] ?? ''));
        }
        if ($effective_res === '') {
            $effective_res = trim((string)$screen_res);
        }

        $user_os = trim((string)($row['user_os'] ?? ''));
        if ($user_os === '') {
            $user_os = $this->get_os($user_agent, $effective_res);
        }

        $user_device = trim((string)($row['user_device'] ?? ''));
        if ($user_device === '') {
            $user_device = $this->get_device($user_agent, $effective_res);
        }

        $browser_family = $this->get_browser_family($user_agent);
        $profile_key = $this->build_behavior_profile_key($user_os, $user_device, $browser_family);
        $profile_label = substr($user_os . ' | ' . $user_device . ' | ' . $browser_family, 0, 120);
        $reactions_expected = (int)($row['reactions_extension_expected'] ?? 0);
        $reactions_css_seen = (int)($row['reactions_css_seen'] ?? 0);
        $reactions_js_seen = (int)($row['reactions_js_seen'] ?? 0);
        $reactions_missing_hit = ($reactions_expected === 1 && ($reactions_css_seen === 0 || $reactions_js_seen === 0)) ? 1 : 0;

        // Dédup: une seule contribution par session.
        $seen_insert = [
            'session_id' => $session_id,
            'profile_key' => $profile_key,
            'learned_time' => time(),
        ];
        if ($has_reactions_learning_columns) {
            $seen_insert['reactions_extension_expected'] = $reactions_expected;
            $seen_insert['reactions_css_seen'] = $reactions_css_seen;
            $seen_insert['reactions_js_seen'] = $reactions_js_seen;
        }
        $sql = 'INSERT INTO ' . $this->table_prefix . 'bastien59_stats_behavior_seen ' . $this->db->sql_build_array('INSERT', $seen_insert);

        $this->db->sql_return_on_error(true);
        $this->db->sql_query($sql);
        $seen_error = (bool)$this->db->get_sql_error_triggered();
        $this->db->sql_return_on_error(false);
        if ($seen_error) {
            return;
        }

        $interact_score = $this->bit_count($interact_mask);
        $no_interact_hit = ($interact_mask === 0) ? 1 : 0;
        $fast_scroll_hit = ($first_scroll_ms <= 350) ? 1 : 0;
        $jump_scroll_hit = ($scroll_max_y >= 1400 && $scroll_events <= 2) ? 1 : 0;

        $profile_fields = [
            'sample_count',
            'avg_first_scroll_ms',
            'avg_scroll_events',
            'avg_scroll_max_y',
            'avg_interact_score',
            'no_interact_hits',
            'fast_scroll_hits',
            'jump_scroll_hits',
        ];
        if ($has_reactions_learning_columns) {
            $profile_fields[] = 'reactions_missing_hits';
        }
        $sql = 'SELECT ' . implode(', ', $profile_fields) . '
                FROM ' . $this->table_prefix . 'bastien59_stats_behavior_profile
                WHERE profile_key = \'' . $this->db->sql_escape($profile_key) . '\'';
        $result = $this->db->sql_query_limit($sql, 1);
        $profile_row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        if ($profile_row) {
            $old_count = max(1, (int)$profile_row['sample_count']);
            $new_count = $old_count + 1;

            $update = [
                'sample_count' => $new_count,
                'avg_first_scroll_ms' => (int)round((((int)$profile_row['avg_first_scroll_ms'] * $old_count) + $first_scroll_ms) / $new_count),
                'avg_scroll_events' => (int)round((((int)$profile_row['avg_scroll_events'] * $old_count) + $scroll_events) / $new_count),
                'avg_scroll_max_y' => (int)round((((int)$profile_row['avg_scroll_max_y'] * $old_count) + $scroll_max_y) / $new_count),
                'avg_interact_score' => (int)round((((int)$profile_row['avg_interact_score'] * $old_count) + $interact_score) / $new_count),
                'no_interact_hits' => (int)$profile_row['no_interact_hits'] + $no_interact_hit,
                'fast_scroll_hits' => (int)$profile_row['fast_scroll_hits'] + $fast_scroll_hit,
                'jump_scroll_hits' => (int)$profile_row['jump_scroll_hits'] + $jump_scroll_hit,
                'updated_time' => time(),
                'profile_label' => $profile_label,
            ];
            if ($has_reactions_learning_columns) {
                $update['reactions_missing_hits'] = (int)($profile_row['reactions_missing_hits'] ?? 0) + $reactions_missing_hit;
            }

            $sql = 'UPDATE ' . $this->table_prefix . 'bastien59_stats_behavior_profile
                    SET ' . $this->db->sql_build_array('UPDATE', $update) . '
                    WHERE profile_key = \'' . $this->db->sql_escape($profile_key) . '\'';
            $this->db->sql_query($sql);
            $this->behavior_profile_cache[$profile_key] = $update + ['profile_key' => $profile_key];
        } else {
            $insert = [
                'profile_key' => $profile_key,
                'profile_label' => $profile_label,
                'sample_count' => 1,
                'avg_first_scroll_ms' => $first_scroll_ms,
                'avg_scroll_events' => $scroll_events,
                'avg_scroll_max_y' => $scroll_max_y,
                'avg_interact_score' => $interact_score,
                'no_interact_hits' => $no_interact_hit,
                'fast_scroll_hits' => $fast_scroll_hit,
                'jump_scroll_hits' => $jump_scroll_hit,
                'updated_time' => time(),
                'created_time' => time(),
            ];
            if ($has_reactions_learning_columns) {
                $insert['reactions_missing_hits'] = $reactions_missing_hit;
            }

            $sql = 'INSERT INTO ' . $this->table_prefix . 'bastien59_stats_behavior_profile ' . $this->db->sql_build_array('INSERT', $insert);
            $this->db->sql_query($sql);
            $this->behavior_profile_cache[$profile_key] = $insert;
        }
    }

    /**
     * Récupère un profil appris pour comparaison invité.
     */
    private function get_behavior_profile_row($profile_key)
    {
        $key = trim((string)$profile_key);
        if ($key === '' || !$this->has_behavior_learning_tables()) {
            return [];
        }

        if (isset($this->behavior_profile_cache[$key])) {
            return $this->behavior_profile_cache[$key];
        }

        $fields = [
            'profile_key',
            'sample_count',
            'avg_first_scroll_ms',
            'avg_scroll_events',
            'avg_scroll_max_y',
            'avg_interact_score',
            'no_interact_hits',
            'fast_scroll_hits',
            'jump_scroll_hits',
            'updated_time',
        ];
        if ($this->has_reactions_learning_columns()) {
            $fields[] = 'reactions_missing_hits';
        }

        $sql = 'SELECT ' . implode(', ', $fields) . '
                FROM ' . $this->table_prefix . 'bastien59_stats_behavior_profile
                WHERE profile_key = \'' . $this->db->sql_escape($key) . '\'
                AND updated_time > ' . (time() - 90 * 86400);

        $result = $this->db->sql_query_limit($sql, 1);
        $row = $this->db->sql_fetchrow($result);
        $this->db->sql_freeresult($result);

        $this->behavior_profile_cache[$key] = $row ?: [];
        return $this->behavior_profile_cache[$key];
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
        // null = hostname non résolu (cron async en attente) : ne pas marquer comme imposteur.
        // La vérification sera effectuée lors d'une prochaine visite une fois le cache rempli.
        if ($hostname === null) {
            return true;
        }
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
        // Cache géo uniquement — pas de résolution DNS en temps réel.
        // shell_exec/popen acquiert un PI mutex (popen_list_mutex glibc) qui peut bloquer
        // tous les workers Apache en cascade pendant 13+ minutes. La résolution rDNS
        // est effectuée de façon asynchrone par le cron geo_async (CLI uniquement).
        $geo = $this->get_geo_cache($ip);
        if ($geo && !empty($geo['hostname'])) {
            return $geo['hostname'];
        }
        // null = hostname non encore résolu, vérification rDNS reportée au cron.
        return null;
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
    private function geolocate_ip($ip, $allow_live_lookup = true)
    {
        $default = ['country_code' => '', 'country_name' => '', 'hostname' => ''];

        // Ignorer les IPs locales
        if ($this->is_local_ip($ip)) {
            return ['country_code' => 'LO', 'country_name' => 'Local', 'hostname' => 'localhost'];
        }

        // Vérifier le cache (clé IP + fallback IPv4 configurable)
        $cached = $this->get_geo_cache($ip);
        if ($cached !== false) {
            return $cached;
        }

        // Mode async: pas d'appel réseau sur le thread web.
        if (!$allow_live_lookup) {
            return $default;
        }

        // DNS Reverse Lookup désactivé en mode live : shell_exec acquiert popen_list_mutex
        // (PI mutex glibc) et bloque les workers Apache en cascade.
        // Le hostname est résolu en mode async (cron geo_async) sans risque de deadlock.
        $hostname = '';

        // Appel API ip-api.com (utilisé uniquement hors mode async)
        $url = 'http://ip-api.com/json/' . urlencode($ip) . '?fields=status,country,countryCode,city';

        $context = stream_context_create([
            'http' => [
                // Timeout agressif: ne pas pénaliser l'affichage.
                'timeout' => 1.2,
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

        // Mettre en cache (TTL configurable, défaut 45 jours)
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
        $keys = $this->build_geo_cache_keys($ip);
        if (empty($keys)) {
            return false;
        }

        $escaped = [];
        foreach ($keys as $key) {
            $escaped[] = '\'' . $this->db->sql_escape($key) . '\'';
        }

        $sql = 'SELECT ip_address, country_code, country_name, city, hostname
                FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache
                WHERE ip_address IN (' . implode(',', $escaped) . ')
                AND cached_time > ' . (time() - $this->get_geo_cache_ttl_sec());

        $result = $this->db->sql_query($sql);
        $rows = [];
        while ($row = $this->db->sql_fetchrow($result)) {
            $rows[(string)$row['ip_address']] = $row;
        }
        $this->db->sql_freeresult($result);

        $exact_key = $this->build_geo_cache_exact_key($ip);
        $scope_key = $this->build_geo_cache_scope_key($ip);
        $exact_row = ($exact_key !== '' && isset($rows[$exact_key])) ? $rows[$exact_key] : null;
        $scope_row = ($scope_key !== '' && isset($rows[$scope_key])) ? $rows[$scope_key] : null;

        $data = [
            'country_code' => '',
            'country_name' => '',
            'city'         => '',
            'hostname'     => '',
        ];

        if ($scope_row !== null) {
            $data['country_code'] = (string)($scope_row['country_code'] ?? '');
            $data['country_name'] = (string)($scope_row['country_name'] ?? '');
            $data['city'] = (string)($scope_row['city'] ?? '');
        }

        if ($exact_row !== null) {
            if ($data['country_code'] === '') {
                $data['country_code'] = (string)($exact_row['country_code'] ?? '');
            }
            if ($data['country_name'] === '') {
                $data['country_name'] = (string)($exact_row['country_name'] ?? '');
            }
            if ($data['city'] === '') {
                $data['city'] = (string)($exact_row['city'] ?? '');
            }
            $data['hostname'] = (string)($exact_row['hostname'] ?? '');
        }

        if (
            $data['country_code'] === ''
            && $data['country_name'] === ''
            && $data['city'] === ''
            && $data['hostname'] === ''
        ) {
            return false;
        }

        return $data;
    }

    /**
     * Stocke la géoloc par sous-réseau et le rDNS par IP exacte.
     */
    private function set_geo_cache($ip, $data)
    {
        $scope_key = $this->build_geo_cache_scope_key($ip);
        $exact_key = $this->build_geo_cache_exact_key($ip);
        if ($scope_key === '' && $exact_key === '') {
            return;
        }

        $country_code = substr((string)($data['country_code'] ?? ''), 0, 5);
        $country_name = substr((string)($data['country_name'] ?? ''), 0, 100);
        $city = substr((string)($data['city'] ?? ''), 0, 100);
        $hostname = substr(trim((string)($data['hostname'] ?? '')), 0, 255);
        if ($country_code === '' && $country_name === '' && $city === '' && $hostname === '') {
            return;
        }

        if ($scope_key !== '') {
            $sql = 'DELETE FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache
                    WHERE ip_address = \'' . $this->db->sql_escape($scope_key) . '\'';
            $this->db->sql_query($sql);

            $sql_ary = [
                'ip_address'   => $scope_key,
                'country_code' => $country_code,
                'country_name' => $country_name,
                'city'         => $city,
                'hostname'     => '',
                'cached_time'  => time(),
            ];

            $sql = 'INSERT INTO ' . $this->table_prefix . 'bastien59_stats_geo_cache ' .
                $this->db->sql_build_array('INSERT', $sql_ary);
            $this->db->sql_query($sql);
        }

        if ($exact_key !== '') {
            $sql = 'DELETE FROM ' . $this->table_prefix . 'bastien59_stats_geo_cache
                    WHERE ip_address = \'' . $this->db->sql_escape($exact_key) . '\'';
            $this->db->sql_query($sql);

            $sql_ary = [
                'ip_address'   => $exact_key,
                'country_code' => $country_code,
                'country_name' => $country_name,
                'city'         => $city,
                'hostname'     => $hostname,
                'cached_time'  => time(),
            ];

            $sql = 'INSERT INTO ' . $this->table_prefix . 'bastien59_stats_geo_cache ' .
                $this->db->sql_build_array('INSERT', $sql_ary);
            $this->db->sql_query($sql);
        }
    }

    private function get_geo_cache_ttl_sec()
    {
        $ttl_days = (int)($this->config['bastien59_stats_geo_cache_ttl_days'] ?? 45);
        $ttl_days = max(1, min(365, $ttl_days));
        return $ttl_days * 86400;
    }

    /**
     * @return string[]
     */
    private function build_geo_cache_keys($ip)
    {
        $keys = [];
        $exact_key = $this->build_geo_cache_exact_key($ip);
        $scope_key = $this->build_geo_cache_scope_key($ip);

        if ($exact_key !== '') {
            $keys[] = $exact_key;
        }
        if ($scope_key !== '') {
            $keys[] = $scope_key;
        }

        return array_values(array_unique($keys));
    }

    private function build_geo_cache_exact_key($ip)
    {
        $clean_ip = trim((string)$ip);
        if ($clean_ip === '') {
            return '';
        }

        if (filter_var($clean_ip, FILTER_VALIDATE_IP) === false) {
            return '';
        }

        return $clean_ip;
    }

    private function build_geo_cache_scope_key($ip)
    {
        $clean_ip = trim((string)$ip);
        if ($clean_ip === '') {
            return '';
        }

        // IPv4 : clé sous-réseau uniquement (ex: v4:1.2.3.0/24)
        $subnet = $this->get_ipv4_subnet_key($clean_ip);
        if ($subnet !== '') {
            return 'v4:' . $subnet;
        }

        // IPv6 : clé sous-réseau uniquement (ex: v6:2001:db8::/48)
        $v6 = $this->get_ipv6_subnet_key($clean_ip);
        if ($v6 !== '') {
            return 'v6:' . $v6;
        }

        return '';
    }

    private function get_ipv6_subnet_key($ip)
    {
        $ip = trim((string)$ip);
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return '';
        }

        $prefix_len = $this->get_geo_ipv6_prefix_len();
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return '';
        }

        $masked = '';
        for ($i = 0; $i < 16; $i++) {
            $byte_start = $i * 8;
            if ($byte_start >= $prefix_len) {
                $masked .= chr(0);
            } elseif ($byte_start + 8 <= $prefix_len) {
                $masked .= $bin[$i];
            } else {
                $bits = $prefix_len - $byte_start;
                $mask = 0xFF & (0xFF << (8 - $bits));
                $masked .= chr(ord($bin[$i]) & $mask);
            }
        }

        $network = @inet_ntop($masked);
        if ($network === false) {
            return '';
        }

        return $network . '/' . $prefix_len;
    }

    private function get_geo_ipv6_prefix_len()
    {
        $bits = (int)($this->config['bastien59_stats_geo_ipv6_prefix_len'] ?? 48);
        return max(32, min(64, $bits));
    }

    private function get_ipv4_subnet_key($ip)
    {
        $clean_ip = trim((string)$ip);
        if (!preg_match('/^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/', $clean_ip, $m)) {
            return '';
        }

        $a = (int)$m[1];
        $b = (int)$m[2];
        $c = (int)$m[3];
        $d = (int)$m[4];
        if (
            $a < 0 || $a > 255 ||
            $b < 0 || $b > 255 ||
            $c < 0 || $c > 255 ||
            $d < 0 || $d > 255
        ) {
            return '';
        }

        $ip_num = ip2long($clean_ip);
        if ($ip_num === false) {
            return '';
        }
        $ip_num = (int)sprintf('%u', $ip_num);

        $prefix_len = $this->get_geo_ipv4_prefix_len();
        $host_bits = 32 - (int)$prefix_len;
        $mask = ($host_bits <= 0)
            ? 0xFFFFFFFF
            : ((0xFFFFFFFF << $host_bits) & 0xFFFFFFFF);
        $start = (int)($ip_num & $mask);

        $o1 = (int)(($start >> 24) & 0xFF);
        $o2 = (int)(($start >> 16) & 0xFF);
        $o3 = (int)(($start >> 8) & 0xFF);
        $o4 = (int)($start & 0xFF);

        return $o1 . '.' . $o2 . '.' . $o3 . '.' . $o4 . '/' . (int)$prefix_len;
    }

    private function get_geo_ipv4_prefix_len()
    {
        $bits = (int)($this->config['bastien59_stats_geo_ipv4_prefix_len'] ?? 24);
        return max(16, min(32, $bits));
    }
}
