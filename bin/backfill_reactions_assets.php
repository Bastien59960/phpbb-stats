#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must run in CLI mode.\n");
    exit(1);
}

$opts = getopt('', [
    'apply',
    'window::',
    'from-ts::',
    'to-ts::',
    'log::',
    'verbose',
]);

$apply = isset($opts['apply']);
$verbose = isset($opts['verbose']);
$matchWindow = max(15, min(900, (int)($opts['window'] ?? 120)));
$fromTs = isset($opts['from-ts']) ? (int)$opts['from-ts'] : 0;
$toTs = isset($opts['to-ts']) ? (int)$opts['to-ts'] : 0;
if ($fromTs > 0 && $toTs > 0 && $fromTs > $toTs) {
    fwrite(STDERR, "Invalid range: from-ts > to-ts\n");
    exit(1);
}

$logs = [];
if (isset($opts['log'])) {
    $rawLogs = $opts['log'];
    if (!is_array($rawLogs)) {
        $rawLogs = [$rawLogs];
    }
    foreach ($rawLogs as $path) {
        $p = trim((string)$path);
        if ($p !== '') {
            $logs[] = $p;
        }
    }
}
if (empty($logs)) {
    $logs = [
        '/var/log/apache2/forum_access.log.2.gz',
        '/var/log/apache2/forum_access.log.1',
        '/var/log/apache2/forum_access.log',
    ];
}

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

$statsTable = (string)$table_prefix . 'bastien59_stats';
$extTable = (string)$table_prefix . 'ext';
if (!hasReactionsColumns($db, $statsTable)) {
    fwrite(STDERR, "Missing reactions columns in {$statsTable}. Run db:migrate first.\n");
    $db->close();
    exit(1);
}
$reactionsActive = isExtensionEnabled($db, $extTable, 'bastien59960/reactions');

$lineRegex = '~^(\S+) \S+ \S+ \[([^\]]+)\] "([A-Z]+) ([^" ]+) [^"]*" (\d{3}) \S+ "([^"]*)" "([^"]*)"~';

$sessionFlags = []; // sid => ['expected' => 1, 'css' => 0/1, 'js' => 0/1]
$pageEventsByIp = []; // ip => [ts, ts, ...]
$assetEventsByIp = []; // ip => [['ts'=>int,'css'=>0/1,'js'=>0/1], ...]

$summary = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'match_window_sec' => $matchWindow,
    'from_ts' => $fromTs,
    'to_ts' => $toTs,
    'reactions_extension_active' => $reactionsActive ? 1 : 0,
    'logs' => [],
    'parse' => [
        'lines_total' => 0,
        'lines_matched' => 0,
        'main_page_events' => 0,
        'reactions_css_events' => 0,
        'reactions_js_events' => 0,
        'events_skipped_range' => 0,
    ],
    'sid_mapping' => [
        'main_page_with_sid' => 0,
        'asset_with_sid' => 0,
        'main_page_without_sid' => 0,
        'asset_without_sid' => 0,
    ],
    'fallback_ip_time' => [
        'ips_loaded' => 0,
        'rows_loaded' => 0,
        'main_page_matched' => 0,
        'main_page_unmatched' => 0,
        'asset_matched' => 0,
        'asset_unmatched' => 0,
    ],
    'sessions' => [
        'total_expected' => 0,
        'expected_only' => 0,
        'css_only' => 0,
        'js_only' => 0,
        'both_css_js' => 0,
    ],
    'db' => [
        'rows_updated' => 0,
        'queries' => 0,
    ],
];

$minEventTs = 0;
$maxEventTs = 0;

foreach ($logs as $logFile) {
    $fileInfo = [
        'file' => $logFile,
        'exists' => is_file($logFile) ? 1 : 0,
        'opened' => 0,
        'lines' => 0,
    ];
    if (!is_file($logFile)) {
        $summary['logs'][] = $fileInfo;
        continue;
    }

    $isGz = (substr($logFile, -3) === '.gz');
    $fh = $isGz ? @gzopen($logFile, 'rb') : @fopen($logFile, 'rb');
    if (!$fh) {
        $summary['logs'][] = $fileInfo;
        continue;
    }
    $fileInfo['opened'] = 1;

    while (true) {
        $line = $isGz ? gzgets($fh) : fgets($fh);
        if ($line === false) {
            break;
        }
        $summary['parse']['lines_total']++;
        $fileInfo['lines']++;

        if (!preg_match($lineRegex, $line, $m)) {
            continue;
        }
        $summary['parse']['lines_matched']++;

        $ip = (string)$m[1];
        $ts = parseApacheTs((string)$m[2]);
        if ($ts <= 0) {
            continue;
        }
        if (($fromTs > 0 && $ts < $fromTs) || ($toTs > 0 && $ts > $toTs)) {
            $summary['parse']['events_skipped_range']++;
            continue;
        }

        if ($minEventTs <= 0 || $ts < $minEventTs) {
            $minEventTs = $ts;
        }
        if ($ts > $maxEventTs) {
            $maxEventTs = $ts;
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

        $isMainPage = isMainForumPath($path);
        $isCss = isReactionsCssPath($path);
        $isJs = isReactionsJsPath($path);
        if (!$isMainPage && !$isCss && !$isJs) {
            continue;
        }

        $sid = extractSid($uri, $referer);
        if ($isMainPage) {
            $summary['parse']['main_page_events']++;
            if ($sid !== '') {
                markSession($sessionFlags, $sid, true, false, false);
                $summary['sid_mapping']['main_page_with_sid']++;
            } else {
                $pageEventsByIp[$ip][] = $ts;
                $summary['sid_mapping']['main_page_without_sid']++;
            }
        }

        if ($isCss || $isJs) {
            if ($isCss) {
                $summary['parse']['reactions_css_events']++;
            }
            if ($isJs) {
                $summary['parse']['reactions_js_events']++;
            }

            if ($sid !== '') {
                markSession($sessionFlags, $sid, true, $isCss, $isJs);
                $summary['sid_mapping']['asset_with_sid']++;
            } else {
                $assetEventsByIp[$ip][] = [
                    'ts' => $ts,
                    'css' => $isCss ? 1 : 0,
                    'js' => $isJs ? 1 : 0,
                ];
                $summary['sid_mapping']['asset_without_sid']++;
            }
        }
    }

    if ($isGz) {
        gzclose($fh);
    } else {
        fclose($fh);
    }
    $summary['logs'][] = $fileInfo;
}

if (!empty($pageEventsByIp) || !empty($assetEventsByIp)) {
    $ips = array_keys($pageEventsByIp + $assetEventsByIp);
    $summary['fallback_ip_time']['ips_loaded'] = count($ips);

    if ($minEventTs > 0 && $maxEventTs > 0 && !empty($ips)) {
        $rowsByIp = loadRowsByIp($db, $statsTable, $ips, $minEventTs - $matchWindow, $maxEventTs + $matchWindow);
        foreach ($rowsByIp as $ip => $rows) {
            $summary['fallback_ip_time']['rows_loaded'] += count($rows);
        }

        foreach ($pageEventsByIp as $ip => $events) {
            $rows = $rowsByIp[$ip] ?? [];
            foreach ($events as $ts) {
                $sid = nearestSessionForTs($rows, (int)$ts, $matchWindow);
                if ($sid === '') {
                    $summary['fallback_ip_time']['main_page_unmatched']++;
                    continue;
                }
                markSession($sessionFlags, $sid, true, false, false);
                $summary['fallback_ip_time']['main_page_matched']++;
            }
        }

        foreach ($assetEventsByIp as $ip => $events) {
            $rows = $rowsByIp[$ip] ?? [];
            foreach ($events as $ev) {
                $sid = nearestSessionForTs($rows, (int)$ev['ts'], $matchWindow);
                if ($sid === '') {
                    $summary['fallback_ip_time']['asset_unmatched']++;
                    continue;
                }
                markSession($sessionFlags, $sid, true, ((int)$ev['css'] === 1), ((int)$ev['js'] === 1));
                $summary['fallback_ip_time']['asset_matched']++;
            }
        }
    }
}

$expectedOnly = [];
$cssOnly = [];
$jsOnly = [];
$both = [];
foreach ($sessionFlags as $sid => $flags) {
    if (empty($flags['expected'])) {
        continue;
    }
    $css = !empty($flags['css']);
    $js = !empty($flags['js']);
    if ($css && $js) {
        $both[] = $sid;
    } elseif ($css) {
        $cssOnly[] = $sid;
    } elseif ($js) {
        $jsOnly[] = $sid;
    } else {
        $expectedOnly[] = $sid;
    }
}

$summary['sessions']['total_expected'] = count($expectedOnly) + count($cssOnly) + count($jsOnly) + count($both);
$summary['sessions']['expected_only'] = count($expectedOnly);
$summary['sessions']['css_only'] = count($cssOnly);
$summary['sessions']['js_only'] = count($jsOnly);
$summary['sessions']['both_css_js'] = count($both);

if ($apply) {
    $summary['db']['rows_updated'] += updateSessions($db, $statsTable, $expectedOnly, 'reactions_extension_expected = 1', $summary['db']['queries']);
    $summary['db']['rows_updated'] += updateSessions($db, $statsTable, $cssOnly, 'reactions_extension_expected = 1, reactions_css_seen = 1', $summary['db']['queries']);
    $summary['db']['rows_updated'] += updateSessions($db, $statsTable, $jsOnly, 'reactions_extension_expected = 1, reactions_js_seen = 1', $summary['db']['queries']);
    $summary['db']['rows_updated'] += updateSessions($db, $statsTable, $both, 'reactions_extension_expected = 1, reactions_css_seen = 1, reactions_js_seen = 1', $summary['db']['queries']);
}

if ($verbose) {
    $sample = array_slice($both, 0, 20);
    if (!empty($sample)) {
        echo "# Sample SID (both css/js)\n";
        foreach ($sample as $sid) {
            echo $sid, "\n";
        }
    }
}

echo "# SUMMARY\n";
echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";

$db->close();
exit(0);

function hasReactionsColumns(mysqli $db, string $statsTable): bool
{
    $sql = "SELECT reactions_extension_expected, reactions_css_seen, reactions_js_seen FROM {$statsTable} WHERE 1 = 0";
    $res = $db->query($sql);
    if (!$res) {
        return false;
    }
    $res->free();
    return true;
}

function isExtensionEnabled(mysqli $db, string $extTable, string $extName): bool
{
    $name = $db->real_escape_string($extName);
    $sql = "SELECT ext_active FROM {$extTable} WHERE ext_name = '{$name}' LIMIT 1";
    $res = $db->query($sql);
    if (!$res) {
        return false;
    }
    $row = $res->fetch_assoc();
    $res->free();
    return ((int)($row['ext_active'] ?? 0) === 1);
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

function isMainForumPath(string $path): bool
{
    return (bool)preg_match('~^/(?:index\.php|viewtopic\.php|viewforum\.php|memberlist\.php|search\.php|posting\.php|ucp\.php)(?:$|[/?])~', $path);
}

function isReactionsCssPath(string $path): bool
{
    return (bool)preg_match('~/ext/bastien59960/reactions/styles/prosilver/theme/reactions\.css$~', $path);
}

function isReactionsJsPath(string $path): bool
{
    return (bool)preg_match('~/ext/bastien59960/reactions/styles/prosilver/template/js/reactions\.js$~', $path);
}

function parseSidFromUrl(string $url): string
{
    if ($url === '' || $url === '-') {
        return '';
    }
    $q = parse_url($url, PHP_URL_QUERY);
    if (!is_string($q) || $q === '') {
        return '';
    }
    parse_str($q, $params);
    $sid = (string)($params['sid'] ?? '');
    if (!preg_match('/^[A-Za-z0-9]{32}$/', $sid)) {
        return '';
    }
    return $sid;
}

function extractSid(string $uri, string $referer): string
{
    $sid = parseSidFromUrl($uri);
    if ($sid !== '') {
        return $sid;
    }
    return parseSidFromUrl($referer);
}

function markSession(array &$sessionFlags, string $sid, bool $expected, bool $css, bool $js): void
{
    if (!preg_match('/^[A-Za-z0-9]{32}$/', $sid)) {
        return;
    }
    if (!isset($sessionFlags[$sid])) {
        $sessionFlags[$sid] = [
            'expected' => 0,
            'css' => 0,
            'js' => 0,
        ];
    }
    if ($expected) {
        $sessionFlags[$sid]['expected'] = 1;
    }
    if ($css) {
        $sessionFlags[$sid]['css'] = 1;
    }
    if ($js) {
        $sessionFlags[$sid]['js'] = 1;
    }
}

/**
 * @param string[] $ips
 * @return array<string, array<int, array{ts:int,sid:string}>>
 */
function loadRowsByIp(mysqli $db, string $statsTable, array $ips, int $fromTs, int $toTs): array
{
    $rowsByIp = [];
    if (empty($ips)) {
        return $rowsByIp;
    }

    foreach (array_chunk($ips, 700) as $chunk) {
        $quoted = [];
        foreach ($chunk as $ip) {
            $quoted[] = "'" . $db->real_escape_string((string)$ip) . "'";
        }
        if (empty($quoted)) {
            continue;
        }
        $sql = "SELECT user_ip, visit_time, session_id
                FROM {$statsTable}
                WHERE user_ip IN (" . implode(',', $quoted) . ")
                  AND visit_time BETWEEN {$fromTs} AND {$toTs}
                  AND session_id REGEXP '^[A-Za-z0-9]{32}$'
                ORDER BY user_ip ASC, visit_time ASC";
        $res = $db->query($sql);
        if (!$res) {
            fwrite(STDERR, "SQL error while loading rows by IP: {$db->error}\n");
            exit(1);
        }
        while ($row = $res->fetch_assoc()) {
            $ip = (string)$row['user_ip'];
            $sid = (string)$row['session_id'];
            $ts = (int)$row['visit_time'];
            if (!isset($rowsByIp[$ip])) {
                $rowsByIp[$ip] = [];
            }
            $rowsByIp[$ip][] = ['ts' => $ts, 'sid' => $sid];
        }
        $res->free();
    }

    return $rowsByIp;
}

/**
 * @param array<int, array{ts:int,sid:string}> $rows
 */
function nearestSessionForTs(array $rows, int $targetTs, int $maxDelta): string
{
    if (empty($rows)) {
        return '';
    }

    $lo = 0;
    $hi = count($rows) - 1;
    while ($lo <= $hi) {
        $mid = intdiv($lo + $hi, 2);
        $midTs = (int)$rows[$mid]['ts'];
        if ($midTs < $targetTs) {
            $lo = $mid + 1;
        } elseif ($midTs > $targetTs) {
            $hi = $mid - 1;
        } else {
            return (string)$rows[$mid]['sid'];
        }
    }

    $bestSid = '';
    $bestDelta = $maxDelta + 1;
    $candidates = [];
    if ($hi >= 0) {
        $candidates[] = $hi;
    }
    if ($lo < count($rows)) {
        $candidates[] = $lo;
    }
    foreach ($candidates as $idx) {
        $delta = abs((int)$rows[$idx]['ts'] - $targetTs);
        if ($delta < $bestDelta) {
            $bestDelta = $delta;
            $bestSid = (string)$rows[$idx]['sid'];
        }
    }

    return ($bestDelta <= $maxDelta) ? $bestSid : '';
}

/**
 * @param string[] $sessionIds
 */
function updateSessions(mysqli $db, string $statsTable, array $sessionIds, string $setClause, int &$queries): int
{
    if (empty($sessionIds)) {
        return 0;
    }

    $updated = 0;
    foreach (array_chunk($sessionIds, 500) as $chunk) {
        $quoted = [];
        foreach ($chunk as $sid) {
            if (!preg_match('/^[A-Za-z0-9]{32}$/', $sid)) {
                continue;
            }
            $quoted[] = "'" . $db->real_escape_string($sid) . "'";
        }
        if (empty($quoted)) {
            continue;
        }

        $sql = "UPDATE {$statsTable}
                SET {$setClause}
                WHERE session_id IN (" . implode(',', $quoted) . ")";
        $res = $db->query($sql);
        $queries++;
        if (!$res) {
            fwrite(STDERR, "SQL update error: {$db->error}\n");
            exit(1);
        }
        $updated += (int)$db->affected_rows;
    }
    return $updated;
}
