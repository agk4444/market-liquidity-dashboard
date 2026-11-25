<?php
/**
 * Market Liquidity Dashboard — PHP edition
 * ========================================
 * Server-side twin of the Market Liquidity Dashboard artifact.
 * Four buckets (equity, Treasury, funding, credit) from free sources only:
 *   - Yahoo Finance chart API: VIX, MOVE, 10Y yield (^TNX), DXY, HYG, LQD, SPY, QQQ
 *   - FRED (keyless CSV): SOFR, EFFR (DFF), ON RRP (RRPONTSYD) + extras
 *   - TreasuryDirect TA_WS API: the 8 most recent Note/Bond auctions
 * No API keys. No database. One JSON cache file (cache/snapshot.json).
 *
 * Behaviour:
 *   - Serves the cached snapshot when it is fresher than CACHE_TTL.
 *   - ?refresh=1 forces a fresh fetch (also usable from a cron with curl).
 *   - ?format=json returns the raw snapshot JSON.
 *   - When a source fails, that gauge is dropped and listed in the
 *     partial-data note — the page never dies on one bad source.
 *
 * Deploy: copy this directory to the web root (e.g. liquidity.socialnews.xyz)
 * and make cache/ writable by the web server. PHP 8+ with curl or
 * allow_url_fopen.
 */

declare(strict_types=1);

define('CACHE_FILE', __DIR__ . '/cache/snapshot.json');
define('CACHE_TTL', 1800); // 30 minutes
define('UA', 'Mozilla/5.0 (compatible; MarketLiquidityDashboard/1.0; +https://www.socialnews.xyz)');

/* ------------------------------------------------------------------ */
/* HTTP                                                                */
/* ------------------------------------------------------------------ */

function http_get(string $url, int $timeout = 20): ?string
{
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_USERAGENT      => UA,
            CURLOPT_HTTPHEADER     => ['Accept: application/json, text/csv, */*'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($body === false || $code < 200 || $code >= 300) {
            return null;
        }
        return (string) $body;
    }
    $ctx = stream_context_create([
        'http' => [
            'method'  => 'GET',
            'header'  => 'User-Agent: ' . UA . "\r\nAccept: application/json, text/csv, */*\r\n",
            'timeout' => $timeout,
        ],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    return $body === false ? null : $body;
}

/* ------------------------------------------------------------------ */
/* Series fetchers — each returns [{d: 'YYYY-MM-DD', v: float}] or []  */
/* ------------------------------------------------------------------ */

/** Yahoo Finance v8 chart: 1y of daily closes + volumes. */
function yahoo_series(string $symbol): array
{
    $url = 'https://query1.finance.yahoo.com/v8/finance/chart/'
        . rawurlencode($symbol) . '?range=1y&interval=1d';
    $raw = http_get($url);
    if ($raw === null) {
        return ['points' => [], 'volumes' => [], 'error' => "Yahoo $symbol: fetch failed"];
    }
    $j = json_decode($raw, true);
    $res = $j['chart']['result'][0] ?? null;
    if (!$res || empty($res['timestamp'])) {
        return ['points' => [], 'volumes' => [], 'error' => "Yahoo $symbol: no data"];
    }
    $closes  = $res['indicators']['quote'][0]['close'] ?? [];
    $volumes = $res['indicators']['quote'][0]['volume'] ?? [];
    $points = [];
    $vols = [];
    foreach ($res['timestamp'] as $i => $ts) {
        $c = $closes[$i] ?? null;
        if ($c === null) {
            continue;
        }
        $points[] = ['d' => gmdate('Y-m-d', (int) $ts), 'v' => (float) $c];
        $vols[] = ['d' => gmdate('Y-m-d', (int) $ts), 'v' => (float) ($volumes[$i] ?? 0)];
    }
    return ['points' => $points, 'volumes' => $vols, 'error' => null];
}

/** FRED keyless CSV: https://fred.stlouisfed.org/graph/fredgraph.csv?id=SERIES */
function fred_series(string $series, int $days = 400): array
{
    $url = 'https://fred.stlouisfed.org/graph/fredgraph.csv?id=' . rawurlencode($series);
    $raw = http_get($url);
    if ($raw === null) {
        return ['points' => [], 'error' => "FRED $series: fetch failed"];
    }
    $cutoff = gmdate('Y-m-d', time() - $days * 86400);
    $points = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, 'DATE')) {
            continue;
        }
        // FRED returns full history; skip old rows without parsing them.
        if (substr($line, 0, 10) < $cutoff) {
            continue;
        }
        // Explicit escape arg: PHP 8.5 deprecates relying on the default.
        [$d, $v] = array_pad(str_getcsv($line, ',', '"', ''), 2, '');
        if ($v === '' || $v === '.' || $d < $cutoff) {
            continue;
        }
        $points[] = ['d' => $d, 'v' => (float) $v];
    }
    if (!$points) {
        return ['points' => [], 'error' => "FRED $series: no data"];
    }
    return ['points' => $points, 'error' => null];
}

/* ------------------------------------------------------------------ */
/* Treasury auctions (TreasuryDirect TA_WS)                            */
/* ------------------------------------------------------------------ */

function fetch_auctions(array &$errors): array
{
    $end   = gmdate('m/d/Y');
    $start = gmdate('m/d/Y', time() - 90 * 86400);
    $url = 'https://www.treasurydirect.gov/TA_WS/securities/auctioned'
        . '?format=json&startDate=' . $start . '&endDate=' . $end;
    $raw = http_get($url, 25);
    if ($raw === null) {
        $errors[] = 'TreasuryDirect auctions: fetch failed';
        return [];
    }
    $list = json_decode($raw, true);
    if (!is_array($list)) {
        $errors[] = 'TreasuryDirect auctions: bad response';
        return [];
    }
    $rows = [];
    foreach ($list as $a) {
        $type = $a['securityType'] ?? '';
        if ($type !== 'Note' && $type !== 'Bond') {
            continue; // bills out; notes/bonds only, like the artifact
        }
        // TA_WS field names (verified Nov 25): bidder splits are the
        // *CompetitiveParAwarded amounts; the date is the competitive close.
        $ind = (float) ($a['indirectBiddersCompetitiveParAwarded'] ?? 0);
        $dir = (float) ($a['directBiddersCompetitiveParAwarded'] ?? 0);
        $dea = (float) ($a['primaryDealersCompetitiveParAwarded'] ?? 0);
        $tot = $ind + $dir + $dea;
        if ($tot <= 0) {
            continue;
        }
        $rows[] = [
            'term'         => $a['securityTerm'] ?? $type,
            'type'         => $type,
            'date'         => substr($a['competitiveAuctionCloseDate'] ?? '', 0, 10),
            'offeringBn'   => round(((float) ($a['offeringAmount'] ?? 0)) / 1e9, 1),
            'highYield'    => ($a['highYield'] ?? '') === '' ? null : (float) $a['highYield'],
            'bidToCover'   => (float) ($a['bidToCoverRatio'] ?? 0),
            'indirectPct'  => round($ind / $tot * 100, 1),
            'directPct'    => round($dir / $tot * 100, 1),
            'dealerPct'    => round($dea / $tot * 100, 1),
        ];
    }
    usort($rows, fn($a, $b) => strcmp($b['date'], $a['date']));
    // Indirects vs the average of earlier auctions of the same term,
    // computed over the full 90-day window (not just the displayed 8).
    foreach ($rows as &$r) {
        $priors = [];
        foreach ($rows as $o) {
            if ($o['term'] === $r['term'] && $o['date'] < $r['date']) {
                $priors[] = $o['indirectPct'];
            }
        }
        $r['indirectVsAvg'] = $priors
            ? round($r['indirectPct'] - array_sum($priors) / count($priors), 1)
            : null;
    }
    unset($r);
    return array_slice($rows, 0, 8);
}

/* ------------------------------------------------------------------ */
/* Gauges                                                              */
/* ------------------------------------------------------------------ */

/** Percentile of the latest value within its own 1y history. */
function percentile(array $points, float $latest): float
{
    $n = count($points);
    if ($n === 0) {
        return 0.0;
    }
    $le = 0;
    foreach ($points as $p) {
        if ($p['v'] <= $latest) {
            $le++;
        }
    }
    return round($le / $n * 100, 1);
}

/**
 * Stress label from the percentile.
 * Normal direction (VIX, MOVE, yields, SOFR, EFFR, DXY, OAS, NFCI):
 *   high percentile = stress. Inverted (HYG, LQD, RRP, reserves):
 *   low percentile = stress, because the price/level falls as stress rises.
 */
function stress_label(float $pctl, string $dir): string
{
    if ($dir === 'inverted') {
        if ($pctl <= 10) return 'stress';
        if ($pctl <= 25) return 'elevated';
        return 'normal';
    }
    if ($pctl >= 90) return 'stress';
    if ($pctl >= 75) return 'elevated';
    return 'normal';
}

function build_indicator(array $cfg, array $series): ?array
{
    $points = $series['points'];
    if (count($points) < 20) {
        return null;
    }
    $latest = $points[count($points) - 1]['v'];
    $prev   = $points[count($points) - 2]['v'];
    $pctl   = percentile($points, $latest);
    return [
        'bucket'     => $cfg['bucket'],
        'key'        => $cfg['key'],
        'label'      => $cfg['label'],
        'latest'     => $latest,
        'date'       => $points[count($points) - 1]['d'],
        'decimals'   => $cfg['dec'],
        'unit'       => $cfg['unit'],
        'note'       => $cfg['note'],
        'percentile' => $pctl,
        'prevChange' => round($latest - $prev, $cfg['dec']),
        'stress'     => stress_label($pctl, $cfg['dir']),
        'points'     => $points,
    ];
}

/* The 9 core gauges — same set, sources and notes as the artifact. */
function core_config(): array
{
    return [
        ['key' => 'vix',   'label' => 'VIX',                'bucket' => 'equity',   'unit' => '',  'dec' => 2, 'dir' => 'normal',   'note' => 'Equity implied vol — the stress proxy', 'src' => ['yahoo', '^VIX']],
        ['key' => 'move',  'label' => 'MOVE Index',         'bucket' => 'treasury', 'unit' => '',  'dec' => 1, 'dir' => 'normal',   'note' => 'Treasury implied vol',                    'src' => ['yahoo', '^MOVE']],
        ['key' => 'dgs10', 'label' => '10Y Treasury Yield', 'bucket' => 'treasury', 'unit' => '%', 'dec' => 2, 'dir' => 'normal',   'note' => 'Benchmark yield (^TNX)',                 'src' => ['yahoo', '^TNX']],
        ['key' => 'sofr',  'label' => 'SOFR',               'bucket' => 'funding',  'unit' => '%', 'dec' => 2, 'dir' => 'normal',   'note' => 'Secured overnight funding rate',          'src' => ['fred', 'SOFR']],
        ['key' => 'effr',  'label' => 'EFFR',               'bucket' => 'funding',  'unit' => '%', 'dec' => 2, 'dir' => 'normal',   'note' => 'Effective fed funds rate',              'src' => ['fred', 'DFF']],
        ['key' => 'rrp',   'label' => 'ON RRP Usage',       'bucket' => 'funding',  'unit' => '$B','dec' => 2, 'dir' => 'inverted', 'note' => 'Overnight reverse repo take-up',          'src' => ['fred', 'RRPONTSYD']],
        ['key' => 'dxy',   'label' => 'Dollar Index (DXY)', 'bucket' => 'funding',  'unit' => '',  'dec' => 2, 'dir' => 'normal',   'note' => 'ICE US Dollar Index',                   'src' => ['yahoo', 'DX-Y.NYB']],
        ['key' => 'hyg',   'label' => 'HY Credit ETF (HYG)','bucket' => 'credit',   'unit' => '$', 'dec' => 2, 'dir' => 'inverted', 'note' => 'High-yield ETF price — falls as HY spreads widen', 'src' => ['yahoo', 'HYG']],
        ['key' => 'lqd',   'label' => 'IG Credit ETF (LQD)','bucket' => 'credit',   'unit' => '$', 'dec' => 2, 'dir' => 'inverted', 'note' => 'Investment-grade ETF price — falls as IG spreads widen', 'src' => ['yahoo', 'LQD']],
    ];
}

/* FRED-only extras — shown when they land, never counted in the verdict. */
function extras_config(): array
{
    return [
        ['key' => 'nfci',     'label' => 'NFCI',         'series' => 'NFCI',        'unit' => '',  'dec' => 2, 'dir' => 'normal',   'note' => 'Chicago Fed National Financial Conditions Index', 'scale' => 1],
        ['key' => 'stlfsi4',  'label' => 'STLFSI4',      'series' => 'STLFSI4',     'unit' => '',  'dec' => 2, 'dir' => 'normal',   'note' => 'St. Louis Fed Financial Stress Index',            'scale' => 1],
        ['key' => 'hyoas',    'label' => 'HY OAS',       'series' => 'BAMLH0A0HYM2','unit' => '%', 'dec' => 2, 'dir' => 'normal',   'note' => 'ICE BofA High Yield Option-Adjusted Spread',      'scale' => 1],
        ['key' => 'igoas',    'label' => 'IG OAS',       'series' => 'BAMLC0A0CM',  'unit' => '%', 'dec' => 2, 'dir' => 'normal',   'note' => 'ICE BofA IG Corporate OAS',                     'scale' => 1],
        ['key' => 'dgs2',     'label' => '2Y Yield',     'series' => 'DGS2',        'unit' => '%', 'dec' => 2, 'dir' => 'normal',   'note' => '2-year Treasury yield',                         'scale' => 1],
        ['key' => 'iorb',     'label' => 'IORB',         'series' => 'IORB',        'unit' => '%', 'dec' => 2, 'dir' => 'normal',   'note' => 'Interest on reserve balances',                  'scale' => 1],
        ['key' => 'wresbal',  'label' => 'Bank Reserves','series' => 'WRESBAL',     'unit' => '$B','dec' => 0, 'dir' => 'inverted', 'note' => 'Reserve balances at the Fed',                   'scale' => 0.001], // FRED reports $M
        ['key' => 'dtwexbgs', 'label' => 'Broad Dollar', 'series' => 'DTWEXBGS',    'unit' => '',  'dec' => 2, 'dir' => 'normal',   'note' => 'Fed broad dollar index',                          'scale' => 1],
    ];
}

/* ------------------------------------------------------------------ */
/* Snapshot                                                            */
/* ------------------------------------------------------------------ */

function build_snapshot(): array
{
    $errors = [];
    $indicators = [];
    $extras = [];

    // Core 9 gauges.
    $yahooCache = [];
    foreach (core_config() as $cfg) {
        [$kind, $sym] = $cfg['src'];
        if ($kind === 'yahoo') {
            $yahooCache[$sym] ??= yahoo_series($sym);
            $series = $yahooCache[$sym];
        } else {
            $series = fred_series($sym);
        }
        if ($series['error'] !== null) {
            $errors[] = $series['error'];
            continue;
        }
        $ind = build_indicator($cfg, $series);
        if ($ind === null) {
            $errors[] = $cfg['label'] . ': too few points';
            continue;
        }
        $indicators[] = $ind;
    }

    // FRED extras — attempted every refresh, shown only when they land.
    foreach (extras_config() as $cfg) {
        $series = fred_series($cfg['series']);
        if ($series['error'] !== null) {
            $errors[] = $series['error'];
            continue;
        }
        if ($cfg['scale'] !== 1) {
            foreach ($series['points'] as &$p) {
                $p['v'] *= $cfg['scale'];
            }
            unset($p);
        }
        $ind = build_indicator(
            ['bucket' => 'fred', 'key' => $cfg['key'], 'label' => $cfg['label'],
             'unit' => $cfg['unit'], 'dec' => $cfg['dec'], 'dir' => $cfg['dir'],
             'note' => $cfg['note']],
            $series
        );
        if ($ind !== null) {
            $extras[] = $ind;
        }
    }

    // Equity tape: SPY / QQQ close, day change, volume vs 20-session avg.
    $equity = ['date' => gmdate('Y-m-d')];
    foreach (['SPY' => 'spy', 'QQQ' => 'qqq'] as $sym => $key) {
        $yahooCache[$sym] ??= yahoo_series($sym);
        $s = $yahooCache[$sym];
        if ($s['error'] !== null) {
            $errors[] = $s['error'];
            continue;
        }
        $pts = $s['points'];
        $vols = array_column($s['volumes'], 'v');
        $n = count($pts);
        if ($n < 22) {
            continue;
        }
        $last = $pts[$n - 1]['v'];
        $prev = $pts[$n - 2]['v'];
        // Volume is meaningless mid-session (today's bar is partial), so the
        // ratio always uses the last COMPLETED session: before 4pm ET on a
        // trading day that is the previous bar, otherwise the latest bar.
        $etNow = time() - 5 * 3600;
        $intraday = $pts[$n - 1]['d'] === gmdate('Y-m-d', $etNow)
            && (int) gmdate('G', $etNow) < 16;
        $volIdx = $intraday ? $n - 2 : $n - 1;
        $avgVol = array_sum(array_slice($vols, $volIdx - 20, 20)) / 20;
        $equity[$key . 'Close'] = $last;
        $equity[$key . 'ChangePct'] = round(($last - $prev) / $prev * 100, 2);
        $equity[$key . 'VolumeRatio'] = $avgVol > 0 ? round($vols[$volIdx] / $avgVol, 2) : null;
        $equity['date'] = $pts[$n - 1]['d'] . ($intraday ? ' (intraday)' : '');
        if ($key === 'spy') {
            $equity['spyVolumeBn'] = round($vols[$volIdx] / 1e9, 2);
        }
    }

    $auctions = fetch_auctions($errors);

    // Composite verdict from the core gauges only.
    $stress = 0;
    $elevated = 0;
    foreach ($indicators as $ind) {
        if ($ind['stress'] === 'stress') $stress++;
        elseif ($ind['stress'] === 'elevated') $elevated++;
    }
    if ($stress >= 3) {
        $verdict = 'Stressed';
    } elseif ($stress >= 1 || $elevated >= 4) {
        $verdict = 'Elevated';
    } else {
        $verdict = 'Calm';
    }

    return [
        'asOf'       => gmdate('Y-m-d\TH:i:s\Z'),
        'status'     => 'ready',
        'verdict'    => $verdict,
        'stressCount'    => $stress,
        'elevatedCount'  => $elevated,
        'indicators' => $indicators,
        'extras'     => $extras,
        'equity'     => $equity,
        'auctions'   => $auctions,
        'errors'     => $errors,
    ];
}

function load_snapshot(bool $force): array
{
    $cacheFile = CACHE_FILE;
    if (!$force && is_file($cacheFile)) {
        $raw = @file_get_contents($cacheFile);
        $snap = $raw ? json_decode($raw, true) : null;
        if (is_array($snap) && !empty($snap['asOf'])
            && (time() - strtotime($snap['asOf'])) < CACHE_TTL) {
            $snap['cached'] = true;
            return $snap;
        }
    }
    $snap = build_snapshot();
    $snap['cached'] = false;
    $dir = dirname($cacheFile);
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $tmp = $cacheFile . '.' . getmypid() . '.tmp';
    if (@file_put_contents($tmp, json_encode($snap)) !== false) {
        @rename($tmp, $cacheFile);
    }
    return $snap;
}

/* ------------------------------------------------------------------ */
/* Rendering                                                           */
/* ------------------------------------------------------------------ */

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function fmt(float $v, int $dec, string $unit = ''): string
{
    return number_format($v, $dec) . ($unit !== '' ? ' ' . $unit : '');
}

/** Tiny inline SVG sparkline from the 1y history points. */
function sparkline(array $points, string $color): string
{
    $n = count($points);
    if ($n < 2) {
        return '';
    }
    $vals = array_column($points, 'v');
    $min = min($vals);
    $max = max($vals);
    $span = ($max - $min) > 0 ? ($max - $min) : 1;
    $w = 220;
    $h = 36;
    $step = $w / ($n - 1);
    $d = '';
    foreach ($vals as $i => $v) {
        $x = round($i * $step, 1);
        $y = round($h - 3 - (($v - $min) / $span) * ($h - 6), 1);
        $d .= ($i === 0 ? "M$x,$y" : " L$x,$y");
    }
    return '<svg class="spark" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
        . '<path d="' . $d . '" fill="none" stroke="' . $color . '" stroke-width="1.5"/></svg>';
}

function stress_class(string $s): string
{
    return $s === 'stress' ? 'st-stress' : ($s === 'elevated' ? 'st-elevated' : 'st-normal');
}

function stress_color(string $s): string
{
    return $s === 'stress' ? '#f87171' : ($s === 'elevated' ? '#fbbf24' : '#34d399');
}

function gauge_card(array $ind): string
{
    $cls = stress_class($ind['stress']);
    $color = stress_color($ind['stress']);
    $chg = $ind['prevChange'];
    $chgTxt = ($chg > 0 ? '+' : '') . number_format($chg, $ind['decimals']);
    $pct = number_format($ind['percentile'], 1);
    $html = '<div class="card ' . $cls . '">';
    $html .= '<div class="card-top"><span class="label">' . esc($ind['label']) . '</span>'
        . '<span class="badge">' . esc($ind['stress']) . '</span></div>';
    $html .= '<div class="value">' . fmt((float) $ind['latest'], $ind['decimals'], $ind['unit']) . '</div>';
    $html .= '<div class="meta">1y percentile <b>' . $pct . '%</b> &middot; day ' . $chgTxt
        . ' &middot; ' . esc($ind['date']) . '</div>';
    $html .= '<div class="pbar"><span style="width:' . min(100, max(0, $ind['percentile'])) . '%;background:' . $color . '"></span></div>';
    $html .= sparkline($ind['points'], $color);
    $html .= '<div class="note">' . esc($ind['note']) . '</div>';
    $html .= '</div>';
    return $html;
}

function render(array $snap): string
{
    $verdict = $snap['verdict'] ?? 'Unknown';
    $vcls = $verdict === 'Stressed' ? 'st-stress' : ($verdict === 'Elevated' ? 'st-elevated' : 'st-normal');
    $asOf = $snap['asOf'] ?? '';
    $asOfLocal = $asOf !== '' ? gmdate('M j, Y g:i A', strtotime($asOf) - 5 * 3600) . ' ET' : '—';
    $eq = $snap['equity'] ?? [];
    $buckets = ['equity' => 'Equity depth', 'treasury' => 'Treasury depth', 'funding' => 'Funding', 'credit' => 'Credit'];
    $byBucket = [];
    foreach ($snap['indicators'] as $ind) {
        $byBucket[$ind['bucket']][] = $ind;
    }

    $h = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Market Liquidity Dashboard</title><style>'
        . 'body{margin:0;background:#0b1220;color:#e5e7eb;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif}'
        . '.wrap{max-width:1060px;margin:0 auto;padding:24px 16px 48px}'
        . 'header{display:flex;flex-wrap:wrap;gap:12px;align-items:center;justify-content:space-between}'
        . 'h1{font-size:22px;margin:0} .sub{color:#9ca3af;font-size:13px;margin-top:4px}'
        . '.verdict{font-size:20px;font-weight:700;padding:10px 18px;border-radius:999px}'
        . '.st-stress{background:#7f1d1d;color:#fecaca} .st-elevated{background:#78350f;color:#fde68a} .st-normal{background:#064e3b;color:#a7f3d0}'
        . '.refresh{color:#93c5fd;font-size:13px}'
        . '.tape{display:flex;flex-wrap:wrap;gap:16px;background:#111827;border:1px solid #1f2937;border-radius:12px;padding:14px 18px;margin:18px 0}'
        . '.tape b{font-size:18px} .up{color:#34d399} .down{color:#f87171}'
        . 'h2{font-size:16px;margin:28px 0 10px;color:#cbd5e1;text-transform:uppercase;letter-spacing:.06em}'
        . '.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:12px}'
        . '.card{background:#111827;border:1px solid #1f2937;border-left:4px solid #374151;border-radius:10px;padding:14px}'
        . '.card.st-stress{border-left-color:#ef4444} .card.st-elevated{border-left-color:#f59e0b} .card.st-normal{border-left-color:#10b981}'
        . '.card-top{display:flex;justify-content:space-between;align-items:center;gap:8px}'
        . '.label{font-weight:600} .badge{font-size:11px;text-transform:uppercase;letter-spacing:.05em;padding:2px 8px;border-radius:999px;background:#1f2937;color:#d1d5db}'
        . '.st-stress .badge{background:#7f1d1d;color:#fecaca} .st-elevated .badge{background:#78350f;color:#fde68a} .st-normal .badge{background:#064e3b;color:#a7f3d0}'
        . '.value{font-size:28px;font-weight:700;margin:8px 0 2px} .meta{font-size:12px;color:#9ca3af}'
        . '.pbar{height:6px;background:#1f2937;border-radius:3px;margin:10px 0 6px;overflow:hidden} .pbar span{display:block;height:100%}'
        . '.spark{width:100%;height:36px;display:block} .note{font-size:12px;color:#9ca3af;margin-top:6px}'
        . 'table{width:100%;border-collapse:collapse;font-size:13px;background:#111827;border-radius:10px;overflow:hidden}'
        . 'th,td{padding:9px 10px;text-align:right;border-bottom:1px solid #1f2937} th:first-child,td:first-child{text-align:left}'
        . 'th{color:#9ca3af;font-weight:600;background:#0f172a} tr:last-child td{border-bottom:0}'
        . '.soft{color:#fbbf24;font-weight:700} .flag{font-size:11px;color:#fbbf24}'
        . '.notebox{background:#111827;border:1px solid #78350f;border-radius:10px;padding:12px 14px;font-size:13px;color:#fcd34d;margin-top:22px}'
        . 'footer{color:#6b7280;font-size:12px;margin-top:28px;line-height:1.6}'
        . '</style></head><body><div class="wrap">';

    $h .= '<header><div><h1>Market Liquidity Dashboard</h1>'
        . '<div class="sub">Snapshot ' . esc($asOfLocal)
        . ($snap['cached'] ? ' &middot; cached' : ' &middot; fresh')
        . ' &middot; ' . (int) ($snap['stressCount'] ?? 0) . ' stress / '
        . (int) ($snap['elevatedCount'] ?? 0) . ' elevated gauges</div></div>'
        . '<div><span class="verdict ' . $vcls . '">' . esc($verdict) . '</span> '
        . '<a class="refresh" href="?refresh=1">Refresh</a></div></header>';

    if (!empty($eq['spyClose'])) {
        $h .= '<div class="tape">';
        foreach ([['SPY', 'spy'], ['QQQ', 'qqq']] as [$sym, $k]) {
            if (empty($eq[$k . 'Close'])) continue;
            $chg = $eq[$k . 'ChangePct'];
            $cls = $chg >= 0 ? 'up' : 'down';
            $h .= '<div><b>' . $sym . ' ' . number_format((float) $eq[$k . 'Close'], 2) . '</b> '
                . '<span class="' . $cls . '">' . ($chg >= 0 ? '+' : '') . number_format($chg, 2) . '%</span>'
                . '<div class="sub">volume ' . number_format((float) ($eq[$k . 'VolumeRatio'] ?? 0), 2)
                . '&times; 20-day avg</div></div>';
        }
        $h .= '<div class="sub" style="margin-left:auto;align-self:center">Last session: ' . esc($eq['date'] ?? '') . '</div></div>';
    }

    foreach ($buckets as $b => $title) {
        if (empty($byBucket[$b])) continue;
        $h .= '<h2>' . esc($title) . '</h2><div class="grid">';
        foreach ($byBucket[$b] as $ind) {
            $h .= gauge_card($ind);
        }
        $h .= '</div>';
    }

    if (!empty($snap['auctions'])) {
        $h .= '<h2>Recent Treasury auctions</h2><table><tr><th>Term</th><th>Date</th><th>Size</th>'
            . '<th>High yield</th><th>Bid/cover</th><th>Indirects</th><th>vs avg</th><th>Directs</th><th>Dealers</th></tr>';
        foreach ($snap['auctions'] as $a) {
            $soft = ($a['indirectVsAvg'] !== null && $a['indirectVsAvg'] <= -5);
            $h .= '<tr><td>' . esc($a['term']) . ' ' . esc($a['type'])
                . ($soft ? ' <span class="flag">soft</span>' : '') . '</td>'
                . '<td>' . esc($a['date']) . '</td>'
                . '<td>$' . number_format((float) $a['offeringBn'], 0) . 'B</td>'
                . '<td>' . ($a['highYield'] === null ? '—' : number_format((float) $a['highYield'], 3) . '%') . '</td>'
                . '<td>' . number_format((float) $a['bidToCover'], 2) . '</td>'
                . '<td class="' . ($soft ? 'soft' : '') . '">' . number_format((float) $a['indirectPct'], 1) . '%</td>'
                . '<td>' . ($a['indirectVsAvg'] === null ? '—' : (($a['indirectVsAvg'] > 0 ? '+' : '') . number_format((float) $a['indirectVsAvg'], 1) . 'pp')) . '</td>'
                . '<td>' . number_format((float) $a['directPct'], 1) . '%</td>'
                . '<td>' . number_format((float) $a['dealerPct'], 1) . '%</td></tr>';
        }
        $h .= '</table>';
    }

    if (!empty($snap['extras'])) {
        $h .= '<h2>FRED extras</h2><div class="grid">';
        foreach ($snap['extras'] as $ind) {
            $h .= gauge_card($ind);
        }
        $h .= '</div>';
    }

    if (!empty($snap['errors'])) {
        $h .= '<div class="notebox"><b>Partial data:</b> ' . count($snap['errors'])
            . ' series did not load on this refresh and are shown from the last good value or omitted: '
            . esc(implode('; ', $snap['errors'])) . '</div>';
    }

    $h .= '<footer>Sources: Yahoo Finance (VIX, MOVE, 10Y, DXY, HYG, LQD, SPY, QQQ), '
        . 'FRED (SOFR, EFFR, ON RRP, NFCI, stress indexes, spreads, reserves), '
        . 'TreasuryDirect (auction results), NY Fed. Free public data, no API keys. '
        . 'Percentiles are the latest value ranked against its own 1-year history; '
        . 'stress at ≥90th percentile (≤10th for HYG, LQD, ON RRP and reserves, where low is stress), '
        . 'elevated at ≥75th (≤25th inverted). Verdict: Stressed with 3+ stress gauges, '
        . 'Elevated with 1–2 stress or 4+ elevated, otherwise Calm. '
        . 'Snapshot cached for 30 minutes; add ?refresh=1 to force a fresh pull.</footer>';

    $h .= '</div></body></html>';
    return $h;
}

/* ------------------------------------------------------------------ */
/* Entry                                                               */
/* ------------------------------------------------------------------ */

$force = isset($_GET['refresh']) && $_GET['refresh'] !== '0';
$snap = load_snapshot($force);

if (isset($_GET['format']) && $_GET['format'] === 'json') {
    header('Content-Type: application/json');
    echo json_encode($snap, JSON_PRETTY_PRINT);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
echo render($snap);
