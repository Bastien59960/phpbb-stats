#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI mode.\n");
    exit(1);
}

$options = getopt('', [
    'emit',
    'target::',
    'context::',
    'min-downloads::',
    'soft-score::',
    'hard-score::',
    'dedup-soft::',
    'dedup-hard::',
    'verbose',
]);

$emit = isset($options['emit']);
$verbose = isset($options['verbose']);
$targetWindow = max(300, min(86400, (int)($options['target'] ?? 3600)));      // default: 1h
$contextWindow = max($targetWindow, min(172800, (int)($options['context'] ?? 86400))); // default: 24h
$minDownloads = max(4, min(200, (int)($options['min-downloads'] ?? 8)));
$softScoreThreshold = max(20, min(200, (int)($options['soft-score'] ?? 60)));
$hardScoreThreshold = max($softScoreThreshold + 1, min(300, (int)($options['hard-score'] ?? 80)));
$dedupSoft = max(300, min(86400, (int)($options['dedup-soft'] ?? 7200)));   // 2h
$dedupHard = max(300, min(172800, (int)($options['dedup-hard'] ?? 21600))); // 6h

$forumRoot = dirname(__DIR__, 4);
$configFile = $forumRoot . '/config.php';
if (!is_file($configFile)) {
    fwrite(STDERR, "config.php not found at {$configFile}\n");
    exit(1);
}

/** @noinspection PhpIncludeInspection */
include $configFile;
if (empty($dbhost) || empty($dbuser) || !isset($dbpasswd) || empty($dbname) || empty($table_prefix)) {
    fwrite(STDERR, "Missing DB settings in config.php\n");
    exit(1);
}

mysqli_report(MYSQLI_REPORT_OFF);
$db = @new mysqli($dbhost, $dbuser, $dbpasswd, $dbname);
if ($db->connect_error) {
    fwrite(STDERR, "DB connect error: {$db->connect_error}\n");
    exit(1);
}
$db->set_charset('utf8mb4');

$now = time();
$targetStart = $now - $targetWindow;
$contextStart = $now - $contextWindow;

$apacheLogs = [
    '/var/log/apache2/forum_access.log.2.gz',
    '/var/log/apache2/forum_access.log.1',
    '/var/log/apache2/forum_access.log',
];

$lineRegex = '~^(\S+) \S+ \S+ \[([^\]]+)\] "([A-Z]+) ([^" ]+) [^"]*" (\d{3}) \S+ "([^"]*)" "([^"]*)"~';

$rawViews = [];      // ts, ip, t, p
$rawDownloads = [];  // ts, ip, aid, referer
$postIdsToResolve = [];
$attachIdsToResolve = [];
$ipStats = [];
$parse = [
    'lines' => 0,
    'viewtopic_raw' => 0,
    'download_raw' => 0,
];

foreach ($apacheLogs as $logFile) {
    if (!is_file($logFile)) {
        continue;
    }

    $isGz = substr($logFile, -3) === '.gz';
    $fh = $isGz ? @gzopen($logFile, 'rb') : @fopen($logFile, 'rb');
    if (!$fh) {
        continue;
    }

    while (true) {
        $line = $isGz ? gzgets($fh) : fgets($fh);
        if ($line === false) {
            break;
        }
        $parse['lines']++;

        if (!preg_match($lineRegex, $line, $m)) {
            continue;
        }

        $ip = (string)$m[1];
        $ts = parseApacheTs((string)$m[2]);
        if ($ts <= 0 || $ts < $contextStart) {
            continue;
        }

        $method = (string)$m[3];
        if ($method !== 'GET' && $method !== 'HEAD') {
            continue;
        }

        $uri = (string)$m[4];
        $status = (int)$m[5];
        $referer = (string)$m[6];
        if ($status < 200 || $status >= 400) {
            continue;
        }

        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            continue;
        }

        if ($ts >= $targetStart) {
            ensureIpStats($ipStats, $ip);
            if (isMainForumPath($path)) {
                $ipStats[$ip]['main_requests']++;
            }
            if ($path === '/viewforum.php') {
                $ipStats[$ip]['viewforum']++;
            }
            if ($path === '/index.php') {
                $ipStats[$ip]['index']++;
            }
        }

        if ($path === '/viewtopic.php') {
            $ids = parseQueryIds($uri);
            if ($ids['t'] > 0 || $ids['p'] > 0) {
                $rawViews[] = [
                    'ts' => $ts,
                    'ip' => $ip,
                    't' => $ids['t'],
                    'p' => $ids['p'],
                ];
                $parse['viewtopic_raw']++;
                if ($ids['t'] <= 0 && $ids['p'] > 0) {
                    $postIdsToResolve[$ids['p']] = 1;
                }
            }
            continue;
        }

        if ($ts >= $targetStart && $path === '/download/file.php') {
            $ids = parseQueryIds($uri);
            if ($ids['id'] > 0) {
                $rawDownloads[] = [
                    'ts' => $ts,
                    'ip' => $ip,
                    'aid' => $ids['id'],
                    'referer' => $referer,
                ];
                $attachIdsToResolve[$ids['id']] = 1;
                $parse['download_raw']++;
                ensureIpStats($ipStats, $ip);
                $ipStats[$ip]['downloads_raw']++;
            }
        }
    }

    if ($isGz) {
        gzclose($fh);
    } else {
        fclose($fh);
    }
}

$postToTopic = [];
if (!empty($postIdsToResolve)) {
    $postIds = array_keys($postIdsToResolve);
    foreach (array_chunk($postIds, 1000) as $chunk) {
        $idsSql = implode(',', array_map('intval', $chunk));
        $sql = "SELECT post_id, topic_id FROM {$table_prefix}posts WHERE post_id IN ({$idsSql})";
        $res = $db->query($sql);
        if (!$res) {
            fwrite(STDERR, "SQL error post->topic: {$db->error}\n");
            exit(1);
        }
        while ($row = $res->fetch_assoc()) {
            $postToTopic[(int)$row['post_id']] = (int)$row['topic_id'];
        }
        $res->free();
    }
}

$attachToTopic = [];
if (!empty($attachIdsToResolve)) {
    $attachIds = array_keys($attachIdsToResolve);
    foreach (array_chunk($attachIds, 1000) as $chunk) {
        $idsSql = implode(',', array_map('intval', $chunk));
        $sql = "SELECT attach_id, topic_id FROM {$table_prefix}attachments WHERE is_orphan = 0 AND attach_id IN ({$idsSql})";
        $res = $db->query($sql);
        if (!$res) {
            fwrite(STDERR, "SQL error attach->topic: {$db->error}\n");
            exit(1);
        }
        while ($row = $res->fetch_assoc()) {
            $attachToTopic[(int)$row['attach_id']] = (int)$row['topic_id'];
        }
        $res->free();
    }
}

$viewEvents = [];
$viewsByTopic = [];
$viewMapFailed = 0;

foreach ($rawViews as $v) {
    $topicId = (int)$v['t'];
    if ($topicId <= 0 && (int)$v['p'] > 0) {
        $topicId = (int)($postToTopic[(int)$v['p']] ?? 0);
    }
    if ($topicId <= 0) {
        $viewMapFailed++;
        continue;
    }

    $evt = [
        'ts' => (int)$v['ts'],
        'ip' => (string)$v['ip'],
        'topic_id' => $topicId,
    ];
    $viewEvents[] = $evt;
    if (!isset($viewsByTopic[$topicId])) {
        $viewsByTopic[$topicId] = [];
    }
    $viewsByTopic[$topicId][] = $evt;

    if ((int)$v['ts'] >= $targetStart) {
        $ip = (string)$v['ip'];
        ensureIpStats($ipStats, $ip);
        $ipStats[$ip]['viewtopic']++;
        $ipStats[$ip]['topics_viewed'][$topicId] = 1;
    }
}

$viewTsByTopic = [];
$viewIpByTopic = [];
foreach ($viewsByTopic as $topicId => $events) {
    usort($events, static function (array $a, array $b): int {
        if ($a['ts'] === $b['ts']) {
            return strcmp((string)$a['ip'], (string)$b['ip']);
        }
        return ($a['ts'] < $b['ts']) ? -1 : 1;
    });

    $tsArr = [];
    $ipArr = [];
    foreach ($events as $e) {
        $tsArr[] = (int)$e['ts'];
        $ipArr[] = (string)$e['ip'];
    }

    $viewTsByTopic[$topicId] = $tsArr;
    $viewIpByTopic[$topicId] = $ipArr;
}

$downloadsMapped = 0;
$downloadMapFailed = 0;
foreach ($rawDownloads as $d) {
    $ip = (string)$d['ip'];
    $aid = (int)$d['aid'];
    $topicId = (int)($attachToTopic[$aid] ?? 0);

    if ($topicId <= 0) {
        $downloadMapFailed++;
        continue;
    }

    ensureIpStats($ipStats, $ip);
    $downloadsMapped++;
    $ipStats[$ip]['downloads']++;
    $ipStats[$ip]['download_ts'][] = (int)$d['ts'];
    $ipStats[$ip]['topics_downloaded'][$topicId] = 1;

    $tsArr = $viewTsByTopic[$topicId] ?? [];
    $ipArr = $viewIpByTopic[$topicId] ?? [];
    $flags = priorFlags($tsArr, $ipArr, (int)$d['ts'], $ip, 24 * 3600);

    if ($flags['other']) {
        $ipStats[$ip]['prior_other24']++;
    }
    if ($flags['same']) {
        $ipStats[$ip]['prior_same24']++;
    }
    if ($flags['other'] && !$flags['same']) {
        $ipStats[$ip]['distributed24']++;
    }

    if (refererMatchesTopic((string)$d['referer'], $topicId)) {
        $ipStats[$ip]['related_referer']++;
    } else {
        $ipStats[$ip]['missing_or_unrelated_referer']++;
    }
}

$auditLogPath = '/var/log/security_audit.log';
$configSql = "SELECT config_value FROM {$table_prefix}config WHERE config_name = 'bastien59_stats_audit_log_path'";
$configRes = $db->query($configSql);
if ($configRes) {
    $cfg = $configRes->fetch_assoc();
    $configRes->free();
    if (!empty($cfg['config_value'])) {
        $auditLogPath = (string)$cfg['config_value'];
    }
}

$candidates = [];
$summary = [
    'target_window_sec' => $targetWindow,
    'context_window_sec' => $contextWindow,
    'emit' => $emit ? 1 : 0,
    'parse' => $parse,
    'mapped' => [
        'views' => count($viewEvents),
        'view_map_failed' => $viewMapFailed,
        'downloads_raw' => count($rawDownloads),
        'downloads_mapped' => $downloadsMapped,
        'download_map_failed' => $downloadMapFailed,
        'topics_with_views' => count($viewsByTopic),
    ],
    'ips_with_downloads' => 0,
    'ips_scored' => 0,
    'ips_soft' => 0,
    'ips_hard' => 0,
    'signals_emitted' => 0,
    'signals_write_failed' => 0,
    'signals_skipped_country' => 0,
    'signals_skipped_trusted_bot' => 0,
    'signals_skipped_dedup' => 0,
];

foreach ($ipStats as $ip => $s) {
    $dl = (int)$s['downloads'];
    if ($dl <= 0) {
        continue;
    }

    $summary['ips_with_downloads']++;
    if ($dl < $minDownloads) {
        continue;
    }

    sort($s['download_ts']);
    $fast = 0;
    $gaps = 0;
    for ($i = 1; $i < count($s['download_ts']); $i++) {
        $gap = (int)$s['download_ts'][$i] - (int)$s['download_ts'][$i - 1];
        if ($gap >= 0) {
            $gaps++;
            if ($gap <= 2) {
                $fast++;
            }
        }
    }

    $priorOtherRatio = $dl > 0 ? ((int)$s['prior_other24'] / $dl) : 0.0;
    $priorSameRatio = $dl > 0 ? ((int)$s['prior_same24'] / $dl) : 0.0;
    $distributedRatio = $dl > 0 ? ((int)$s['distributed24'] / $dl) : 0.0;
    $missingRefRatio = $dl > 0 ? ((int)$s['missing_or_unrelated_referer'] / $dl) : 0.0;
    $fastRatio = $gaps > 0 ? ($fast / $gaps) : 0.0;
    $topicsDl = count($s['topics_downloaded']);
    $topicsView = count($s['topics_viewed']);

    $score = 0;
    $rules = [];

    if ($dl >= 8 && (int)$s['viewtopic'] === 0) {
        $score += 30;
        $rules[] = 'dl>=8_and_no_viewtopic';
    }
    if ($dl >= 8 && $distributedRatio >= 0.95) {
        $score += 35;
        $rules[] = 'distributed_ratio>=95';
    } elseif ($dl >= 8 && $distributedRatio >= 0.85) {
        $score += 30;
        $rules[] = 'distributed_ratio>=85';
    } elseif ($dl >= 8 && $distributedRatio >= 0.75) {
        $score += 15;
        $rules[] = 'distributed_ratio>=75';
    }
    if ($dl >= 8 && $priorOtherRatio >= 0.95 && $priorSameRatio <= 0.10) {
        $score += 20;
        $rules[] = 'prior_other_high_prior_same_low';
    }
    if ($dl >= 8 && $fastRatio >= 0.80) {
        $score += 10;
        $rules[] = 'fast_burst_ratio>=80';
    }
    if ($dl >= 8 && $missingRefRatio >= 0.90) {
        $score += 8;
        $rules[] = 'missing_ref_ratio>=90';
    }
    if ($dl >= 8 && $topicsDl >= 4 && $topicsView <= 1) {
        $score += 10;
        $rules[] = 'many_topics_dl_low_topics_view';
    }
    if ($dl >= 8 && (int)$s['main_requests'] > 0 && (int)$s['main_requests'] === (int)$s['downloads_raw']) {
        $score += 10;
        $rules[] = 'only_download_requests';
    }

    $severity = '';
    if ($score >= $hardScoreThreshold && $dl >= 10 && $distributedRatio >= 0.90 && $priorSameRatio <= 0.15) {
        $severity = 'hard';
    } elseif ($score >= $softScoreThreshold && $dl >= 8 && $distributedRatio >= 0.80) {
        $severity = 'soft';
    }

    if ($severity === '') {
        continue;
    }

    $summary['ips_scored']++;
    if ($severity === 'hard') {
        $summary['ips_hard']++;
    } else {
        $summary['ips_soft']++;
    }

    $countryCode = getCountryCodeForIp($db, (string)$table_prefix, (string)$ip);
    if ($countryCode === '' || $countryCode === 'FR') {
        $summary['signals_skipped_country']++;
        continue;
    }

    $hostname = getHostnameForIp($db, (string)$table_prefix, (string)$ip);
    if (isTrustedVerifiedCrawler((string)$ip, $hostname)) {
        $summary['signals_skipped_trusted_bot']++;
        continue;
    }

    $method = ($severity === 'hard') ? 'xip_dl_hard_v1' : 'xip_dl_soft_v1';
    $dedupTtl = ($severity === 'hard') ? $dedupHard : $dedupSoft;
    if ($emit && !dedupAllow($ip, $method, $dedupTtl)) {
        $summary['signals_skipped_dedup']++;
        continue;
    }

    $line = sprintf(
        '%s PHPBB-XIP ip=%s cc=%s method=%s severity=%s score=%d downloads=%d viewtopic=%d main_requests=%d prior_other24=%d prior_same24=%d distributed24=%d dist_ratio=%.2f same_ratio=%.2f miss_ref_ratio=%.2f fast_ratio=%.2f topics_dl=%d topics_view=%d rules="%s"',
        date('Y-m-d H:i:s', $now),
        $ip,
        $countryCode,
        $method,
        $severity,
        $score,
        $dl,
        (int)$s['viewtopic'],
        (int)$s['main_requests'],
        (int)$s['prior_other24'],
        (int)$s['prior_same24'],
        (int)$s['distributed24'],
        round($distributedRatio * 100, 2),
        round($priorSameRatio * 100, 2),
        round($missingRefRatio * 100, 2),
        round($fastRatio * 100, 2),
        $topicsDl,
        $topicsView,
        implode(',', $rules)
    );

    $candidates[] = [
        'line' => $line,
        'ip' => $ip,
        'severity' => $severity,
        'method' => $method,
        'score' => $score,
        'downloads' => $dl,
    ];
}

usort($candidates, static function (array $a, array $b): int {
    if ((int)$a['score'] === (int)$b['score']) {
        return (int)$b['downloads'] <=> (int)$a['downloads'];
    }
    return (int)$b['score'] <=> (int)$a['score'];
});

foreach ($candidates as $c) {
    $line = (string)$c['line'];
    if ($emit) {
        $written = @file_put_contents($auditLogPath, $line . "\n", FILE_APPEND | LOCK_EX);
        if ($written === false) {
            $summary['signals_write_failed']++;
        } else {
            $summary['signals_emitted']++;
        }
    }

    if ($emit || $verbose) {
        echo $line, "\n";
    }
}

$summary['signals_candidates'] = count($candidates);
$summary['audit_log_path'] = $auditLogPath;
$summary['mode'] = $emit ? 'emit' : 'dry-run';

echo "# SUMMARY\n";
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

$db->close();
exit(0);

function ensureIpStats(array &$ipStats, string $ip): void
{
    if (isset($ipStats[$ip])) {
        return;
    }

    $ipStats[$ip] = [
        'downloads_raw' => 0,
        'downloads' => 0,
        'viewtopic' => 0,
        'viewforum' => 0,
        'index' => 0,
        'main_requests' => 0,
        'prior_other24' => 0,
        'prior_same24' => 0,
        'distributed24' => 0,
        'missing_or_unrelated_referer' => 0,
        'related_referer' => 0,
        'download_ts' => [],
        'topics_downloaded' => [],
        'topics_viewed' => [],
    ];
}

function parseApacheTs(string $raw): int
{
    static $cache = [];
    if (isset($cache[$raw])) {
        return $cache[$raw];
    }

    $dt = DateTime::createFromFormat('d/M/Y:H:i:s O', $raw);
    $cache[$raw] = $dt ? $dt->getTimestamp() : 0;
    return $cache[$raw];
}

function parseQueryIds(string $uri): array
{
    $out = ['id' => 0, 'p' => 0, 't' => 0];
    $q = parse_url($uri, PHP_URL_QUERY);
    if (!is_string($q) || $q === '') {
        return $out;
    }

    parse_str($q, $params);
    if (isset($params['id']) && ctype_digit((string)$params['id'])) {
        $out['id'] = (int)$params['id'];
    }
    if (isset($params['p']) && ctype_digit((string)$params['p'])) {
        $out['p'] = (int)$params['p'];
    }
    if (isset($params['t']) && ctype_digit((string)$params['t'])) {
        $out['t'] = (int)$params['t'];
    }

    return $out;
}

function isMainForumPath(string $path): bool
{
    return (bool)preg_match('~^/(?:index\.php|viewtopic\.php|viewforum\.php|memberlist\.php|search\.php|posting\.php|ucp\.php|download/file\.php)(?:$|\?)~', $path);
}

function upperBoundLe(array $arr, int $target): int
{
    $lo = 0;
    $hi = count($arr);
    while ($lo < $hi) {
        $mid = intdiv($lo + $hi, 2);
        if ((int)$arr[$mid] <= $target) {
            $lo = $mid + 1;
        } else {
            $hi = $mid;
        }
    }
    return $lo - 1;
}

function priorFlags(array $tsArr, array $ipArr, int $eventTs, string $eventIp, int $windowSec): array
{
    if (empty($tsArr)) {
        return ['same' => false, 'other' => false];
    }

    $idx = upperBoundLe($tsArr, $eventTs - 1);
    if ($idx < 0) {
        return ['same' => false, 'other' => false];
    }

    $minTs = $eventTs - $windowSec;
    $same = false;
    $other = false;

    for ($i = $idx; $i >= 0; $i--) {
        $ts = (int)$tsArr[$i];
        if ($ts < $minTs) {
            break;
        }

        if ((string)$ipArr[$i] === $eventIp) {
            $same = true;
        } else {
            $other = true;
        }

        if ($same && $other) {
            break;
        }
    }

    return ['same' => $same, 'other' => $other];
}

function refererMatchesTopic(string $referer, int $topicId): bool
{
    if ($referer === '' || $referer === '-' || $topicId <= 0) {
        return false;
    }

    $path = parse_url($referer, PHP_URL_PATH);
    if ($path !== '/viewtopic.php') {
        return false;
    }

    $q = parse_url($referer, PHP_URL_QUERY);
    if (!is_string($q) || $q === '') {
        return false;
    }

    parse_str($q, $params);
    $t = (isset($params['t']) && ctype_digit((string)$params['t'])) ? (int)$params['t'] : 0;
    return ($t > 0 && $t === $topicId);
}

function getCountryCodeForIp(mysqli $db, string $tablePrefix, string $ip): string
{
    $ipEsc = $db->real_escape_string($ip);

    $sql = "SELECT country_code FROM {$tablePrefix}bastien59_stats_geo_cache WHERE ip_address = '{$ipEsc}' LIMIT 1";
    $res = $db->query($sql);
    if ($res) {
        $row = $res->fetch_assoc();
        $res->free();
        $cc = strtoupper(trim((string)($row['country_code'] ?? '')));
        if ($cc !== '') {
            return substr($cc, 0, 5);
        }
    }

    $sql = "SELECT country_code FROM {$tablePrefix}bastien59_stats WHERE user_ip = '{$ipEsc}' AND country_code <> '' ORDER BY visit_time DESC LIMIT 1";
    $res = $db->query($sql);
    if ($res) {
        $row = $res->fetch_assoc();
        $res->free();
        $cc = strtoupper(trim((string)($row['country_code'] ?? '')));
        if ($cc !== '') {
            return substr($cc, 0, 5);
        }
    }

    return '';
}

function getHostnameForIp(mysqli $db, string $tablePrefix, string $ip): string
{
    $ipEsc = $db->real_escape_string($ip);

    $sql = "SELECT hostname FROM {$tablePrefix}bastien59_stats_geo_cache WHERE ip_address = '{$ipEsc}' LIMIT 1";
    $res = $db->query($sql);
    if ($res) {
        $row = $res->fetch_assoc();
        $res->free();
        $hostname = trim((string)($row['hostname'] ?? ''));
        if ($hostname !== '' && $hostname !== '-') {
            return $hostname;
        }
    }

    $rdnsRaw = @shell_exec('timeout 0.25 getent hosts ' . escapeshellarg($ip) . ' 2>/dev/null');
    if (is_string($rdnsRaw) && trim($rdnsRaw) !== '') {
        $parts = preg_split('/\s+/', trim($rdnsRaw));
        $candidate = $parts ? (string)end($parts) : '';
        if ($candidate !== '' && $candidate !== $ip) {
            return $candidate;
        }
    }

    return '';
}

function isTrustedVerifiedCrawler(string $ip, string $hostname): bool
{
    $hostname = strtolower(trim($hostname));
    if ($hostname === '') {
        return false;
    }

    $trustedSuffixes = [
        '.googlebot.com',
        '.google.com',
        '.search.msn.com',
        '.openai.com',
        '.applebot.apple.com',
        '.duckduckgo.com',
        '.yandex.com',
        '.yandex.ru',
        '.yandex.net',
    ];

    $suffixOk = false;
    foreach ($trustedSuffixes as $suffix) {
        if (substr($hostname, -strlen($suffix)) === $suffix) {
            $suffixOk = true;
            break;
        }
    }
    if (!$suffixOk) {
        return false;
    }

    // Forward DNS verification.
    if (strpos($ip, ':') !== false) {
        $aaaa = @dns_get_record($hostname, DNS_AAAA);
        if (!empty($aaaa)) {
            foreach ($aaaa as $rec) {
                if (isset($rec['ipv6']) && (string)$rec['ipv6'] === $ip) {
                    return true;
                }
            }
        }
        return false;
    }

    $a = @gethostbynamel($hostname);
    if ($a === false || empty($a)) {
        return false;
    }
    return in_array($ip, $a, true);
}

function dedupAllow(string $ip, string $method, int $ttl): bool
{
    $file = sys_get_temp_dir() . '/phpbb_xip_' . md5($method . '|' . $ip);
    if (@file_exists($file)) {
        $age = time() - (int)@filemtime($file);
        if ($age >= 0 && $age < $ttl) {
            return false;
        }
    }

    @touch($file);
    return true;
}
