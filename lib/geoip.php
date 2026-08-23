<?php
/**
 * WHERE AN ADDRESS IS, roughly — Sean, 2026-08-23: "give the predicted
 * location based on ip in the usage page".
 *
 * NEVER ON THE RENDER PATH. `hit_where()` in hitlog.php classifies what an
 * address says about itself (lan, carrier-nat, internet) with no network at
 * all, and that is what a page falls back to. A real city needs somebody
 * else's database, so this resolves in the BACKGROUND — from the status sweep,
 * which already runs off the back of traffic — and the page only ever reads
 * the cache. A slow or dead lookup service must not be able to make the site
 * slow or dead to decorate a log.
 *
 * IT SENDS ADDRESSES TO A THIRD PARTY. That is what a geo lookup is, and it is
 * worth saying out loud rather than burying: the addresses of people who
 * visited this site are POSTed to ip-api.com, which answers with a city and a
 * country. Only PUBLIC addresses are sent — anything hit_where() calls lan,
 * local, link-local or carrier-nat never leaves this host, because those
 * cannot be located anyway and there is no reason to hand them over. Results
 * are cached for good: an address's country does not change often enough to
 * ask twice.
 *
 * The cache is the only state. Delete `geo.json` to forget everything and
 * re-resolve; delete this file's caller in status-sweep.php to stop sending
 * anything at all, and the page falls back to the offline classification with
 * no other change.
 */

require_once __DIR__ . '/hitlog.php';   // hit_where(), and the log to read

/** Where the resolved locations live — beside the log they describe. */
function geo_cache_path(): string
{
    $shared = '/home/protected/logs';
    if (is_dir($shared)) { return $shared . '/geo.json'; }
    $dir = function_exists('app_config') ? (string) (app_config()['data_dir'] ?? '') : '';
    if ($dir === '') { $dir = dirname(__DIR__) . '/data'; }
    return rtrim($dir, '/') . '/geo.json';
}

/** The whole cache, address => label. Plain JSON: it holds no secret. */
function geo_all(): array
{
    $p = geo_cache_path();
    if (!is_file($p)) { return []; }
    $d = json_decode((string) @file_get_contents($p), true);
    return is_array($d) ? $d : [];
}

/**
 * The label for one address, or null when nothing is known yet. `-` is a real
 * cached answer meaning "asked, and it could not be placed" — which is why a
 * miss and a failure are different values.
 */
function geo_for(string $ip): ?string
{
    if ($ip === '' || $ip === '-') { return null; }
    $all = geo_all();
    return isset($all[$ip]) && $all[$ip] !== '' ? (string) $all[$ip] : null;
}

/** Only a public address can be looked up, and only one is worth sending. */
function geo_worth_asking(string $ip): bool
{
    return in_array(hit_where($ip), ['internet', 'ipv6'], true);
}

/**
 * Resolve every unresolved public address in the recent log, and cache what
 * comes back. Called from the sweep, never from a page.
 *
 * BATCHED AND CAPPED. ip-api.com's free tier takes 100 addresses per POST and
 * rate-limits by the minute; a personal site sees a handful of visitors, so
 * one batch per sweep is far more than enough and the cap is there to stop a
 * log full of scanner traffic turning into a lookup storm.
 */
function geo_resolve_new(int $sinceSecs = 7 * 86400, int $max = 100): int
{
    $cache = geo_all();
    $want  = [];
    foreach (hit_tail_since(time() - $sinceSecs, 4 * 1024 * 1024) as $r) {
        $ip = (string) ($r['ip'] ?? '-');
        if (isset($cache[$ip]) || !geo_worth_asking($ip)) { continue; }
        $want[$ip] = true;
        if (count($want) >= $max) { break; }
    }
    if (!$want) { return 0; }

    $body = json_encode(array_map(fn($ip) => ['query' => $ip, 'fields' => 'status,country,regionName,city,query'],
                                  array_keys($want)));
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 8, 'ignore_errors' => true,
        'header' => "Content-Type: application/json\r\n", 'content' => $body,
    ]]);
    $raw = @file_get_contents('http://ip-api.com/batch', false, $ctx);
    $out = json_decode((string) $raw, true);
    if (!is_array($out)) { return 0; }

    $n = 0;
    foreach ($out as $row) {
        if (!is_array($row) || empty($row['query'])) { continue; }
        $ip = (string) $row['query'];
        if (($row['status'] ?? '') !== 'success') {
            // Cached as a dash so a permanently unplaceable address is asked
            // about once, not on every sweep for ever.
            $cache[$ip] = '-';
            continue;
        }
        $bits = array_values(array_filter([
            (string) ($row['city'] ?? ''), (string) ($row['regionName'] ?? ''), (string) ($row['country'] ?? ''),
        ], fn($x) => $x !== ''));
        $cache[$ip] = $bits ? implode(', ', $bits) : '-';
        $n++;
    }
    /**
     * THE WRITE IS CHECKED, and the permissions self-heal.
     *
     * This cache is written by whoever ran the sweep. Run once over SSH it
     * lands owned by the login; the sweep that matters runs as `web`, which
     * could then never write it again — and with the call suppressed, that
     * failure looked exactly like "nothing new to resolve" for ever. Same
     * shape as the bug that stopped samples.jsonl recording. So: report the
     * failure, and leave the file group-writable so either user can take over.
     */
    $path = geo_cache_path();
    if (@file_put_contents($path, json_encode($cache), LOCK_EX) === false) {
        error_log('geoip: cannot write ' . $path . ' — resolved ' . $n . ' addresses and threw them away');
        return -1;
    }
    @chmod($path, 0664);
    @chgrp($path, 'web');
    return $n;
}
