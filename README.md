# Market Liquidity Dashboard — PHP edition

Server-side twin of the Market Liquidity Dashboard: four buckets (equity
depth, Treasury depth, funding, credit) from free public sources, with a
composite verdict. No API keys, no database, one JSON cache file.

## What it shows

- **Equity depth** — VIX, SPY/QQQ close, day change, volume vs 20-day average
- **Treasury depth** — MOVE index, 10Y yield, 8 most recent Note/Bond auctions
  with bid-to-cover and bidder split (indirects / directs / dealers), soft
  auctions flagged when indirects run 5+ points below the term average
- **Funding** — SOFR, EFFR, ON RRP usage, Dollar Index
- **Credit** — HYG, LQD, plus FRED extras when they load (NFCI, STLFSI4,
  HY/IG OAS, 2Y yield, IORB, bank reserves, broad dollar)

Each gauge shows its latest value ranked against its own 1-year history
(percentile), a sparkline, and a stress label: stress at the ≥90th
percentile (≤10th for HYG, LQD, ON RRP and reserves, where low is stress),
elevated at ≥75th (≤25th inverted). Verdict: **Stressed** with 3+ stress
gauges, **Elevated** with 1–2 stress or 4+ elevated, otherwise **Calm**.
FRED extras are displayed when they load but never counted in the verdict.

## Sources

- Yahoo Finance chart API (VIX, MOVE, ^TNX, DXY, HYG, LQD, SPY, QQQ)
- FRED keyless CSV (SOFR, DFF, RRPONTSYD, NFCI, STLFSI4, BAMLH0A0HYM2,
  BAMLC0A0CM, DGS2, IORB, WRESBAL, DTWEXBGS)
- TreasuryDirect TA_WS API (auction results)

When a source fails, that gauge is dropped and listed in the partial-data
note — the page never dies on one bad source.

## Deploy (Plesk box)

```bash
# on the box, as root
git clone https://github.com/agk4444/market-liquidity-dashboard.git /root/market-liquidity-dashboard
# point a docroot at it (e.g. liquidity.socialnews.xyz) or copy the files in
cp -r /root/market-liquidity-dashboard /var/www/vhosts/socialnews.xyz/liquidity.socialnews.xyz/
mkdir -p /var/www/vhosts/socialnews.xyz/liquidity.socialnews.xyz/cache
chown -R www-data:www-data /var/www/vhosts/socialnews.xyz/liquidity.socialnews.xyz/cache
# or chmod 775 cache, owned by the web-server user
```

Requirements: PHP 8+ with the curl extension (falls back to
`allow_url_fopen` if curl is missing). The `cache/` directory must be
writable by the web server — that is where `snapshot.json` lives.

## Refresh

- The snapshot is cached for 30 minutes; a normal page view serves the cache.
- `?refresh=1` forces a fresh pull from all sources.
- `?format=json` returns the raw snapshot JSON.
- Optional cron to keep it warm (every 30 min):

```cron
*/30 * * * * curl -s "https://liquidity.socialnews.xyz/?refresh=1" >/dev/null
```

## Files

- `index.php` — fetcher, cache, verdict, and renderer in one file
- `cache/` — snapshot cache (created on first run; keep writable, not committed)
- `cache/.htaccess` — blocks direct web access to the cache file
