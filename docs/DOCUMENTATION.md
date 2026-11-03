# Documentation

How the plugin is built, how data moves through it, and why. To extend it, see
[EXTENDING.md](EXTENDING.md).

## 1. Overview

The plugin has four layers, each depending only on the one below it:

```
Gutenberg block / Admin dashboard        (UI)
REST controllers, block rendering        (delivery)
OddsService, OddsPresenter               (application: caching, rules, view model)
Sources, Fetcher, OddsStore, Repository  (infrastructure: scraping, storage)
```

Everything is plain PHP classes in the `OddsComparison\` namespace
(autoloaded, no Composer). `Plugin` is the single entry point: a singleton that
registers modules (anything implementing `HookableInterface`) and lazily builds
shared services on first use, so a page view that does not touch odds pays
nothing.

### Directory layout

```
odds-comparison.php        bootstrap, constants, activation hooks
uninstall.php              removes tables, options, transients
src/
  Plugin.php               singleton kernel and service locator
  Contracts/               interfaces (Source, HttpClient, Cache, Formatter, Hookable)
  Sources/                 scrapers, models, health checks, registry
  Http/                    Fetcher (polite HTTP), RobotsTxt, CircuitBreaker
  Cache/                   TransientCache, ArrayCache
  Database/ Storage/       Schema (tables), OddsStore (odds snapshots)
  Bookmakers/              Bookmaker, BookmakerRepository, LinkTemplate
  Settings/                Settings (typed option access)
  Service/                 OddsService, OddsPresenter, OddsResult, Freshness
  Odds/                    Odds value object, Converter, Strategy formatters
  Rest/                    AdminController, OddsController, TableController
  Block/                   BlockModule, OddsTableBlock, ConfigSigner
  Admin/ + templates/      dashboard module and its templates
  Support/                 NuxtPayload decoder
blocks/odds-table/         block source (edit, view, shared renderer, CSS)
build/                     compiled block (committed)
assets/                    admin JS and CSS
```

### Design patterns in use

| Pattern | Where |
| --- | --- |
| Singleton | `Plugin` |
| Factory / Registry | `SourceRegistry` builds sources lazily from factories |
| Strategy | `FormatterInterface` implementations; interchangeable `SourceInterface` implementations |
| Template Method | `AbstractScraperSource` provides fetching, block detection, health probe; subclasses supply parsing |
| Repository | `BookmakerRepository`, `OddsStore` |
| Value Object | `Odds`, `Event`, `Sport`, `Market`, `Outcome`, `OddsSnapshot`, `HealthStatus` (immutable) |
| Observer | WordPress actions and filters (see the hook reference in EXTENDING.md) |
| Circuit Breaker | `CircuitBreaker` per scraped host |

## 2. Data flow

### Editor
1. The block asks the editor REST endpoints for sources, sports, events, markets and bookmakers. These lists are cached with stale-while-revalidate because only editors see them.
2. The chosen event, market and bookmakers are saved as block attributes (source, sport, event id, name, path, start time, market, bookmaker ids).

### Front end
1. The block is **dynamic**: PHP renders only a loading placeholder, carrying the configuration and an HMAC signature of it (`ConfigSigner`).
2. `view.js` (a script module, loaded only on pages that contain the block) calls the public `GET /odds-comparison/v1/table` with the signed configuration.
3. The endpoint verifies the signature, asks `OddsService` for the odds and returns a ready table model built by `OddsPresenter`.
4. `view.js` draws the table; the format switcher works locally on data that already contains every format.

Because odds never appear in the page HTML, full-page caches (Cloudflare, WP Rocket…) cannot freeze outdated prices.

## 3. Sources

A source implements `SourceInterface`: `sports()`, `events()`, `markets()`, `odds()` and `checkAvailability()`. All failures are typed exceptions:

| Exception | Meaning | Dashboard status |
| --- | --- | --- |
| `BlockedException` | HTTP 401/403/429, a `cf-mitigated` header or a challenge page | Blocked |
| `UnreachableException` | network error, 5xx, robots.txt disallow | Unreachable |
| `ParseException` | the page loaded but its structure changed | Parser mismatch |

`AbstractScraperSource::checkAvailability()` runs the real `sports()` parser, so
a block, an outage and a markup change are told apart. The result is stored by
`HealthMonitor` and shown with a latency and a timestamp.

Bundled sources:

- **BestOdds**: reads the `__NUXT_DATA__` payload of event pages (structured, far more stable than HTML tables) via `NuxtPayload`. It yields moneyline, spread and total for about a dozen US sportsbooks; American prices are converted to decimal. For each sportsbook only the latest quote counts, and for spread/total only quotes on the line most sportsbooks currently offer, so prices are comparable.
- **BetExplorer**: server-rendered pages give sports, leagues and matches with one aggregated price per outcome, stored under the pseudo bookmaker `average`. Per-bookmaker prices there are loaded by JavaScript and are not scraped.

### Polite scraping (`Fetcher`)

- Honest `OddsComparisonBot/<version>` user agent.
- Obeys the host's `robots.txt` (cached 12 h); disallowed paths are never requested.
- At least one second between requests to the same host.
- Honours `Retry-After`; opens a circuit breaker after repeated failures (15 min pause); retries a transient 5xx once.
- Reports bot-protection challenges as blocks and does nothing to defeat them.
- The `odds_comparison_http_args` filter lets a site route requests through its own proxy or rendering service.

## 4. Caching and freshness (`OddsService`)

For the odds that visitors see, outdated prices are never presented as current:

1. One stored snapshot per **source + event + market**, shared by every block showing it.
2. **Fresh** (within the TTL, default 300 s): returned immediately.
3. **Expired**: fetched anew under a short lock. Concurrent visitors get `202 pending` and ask again after a moment, so the source is not stampeded.
4. **Source failure**: the last known odds are returned flagged `stale` with a notice, and the source is left alone for a minute. With nothing stored, an error is shown.
5. **Finished events** (start time + 4 h, filterable): the stored odds are final and never refreshed.

Editor lists (sports, events, markets) use stale-while-revalidate and a
fallback chain: if the active source is down or blocked, the configured
fallback sources are tried in order.

Short-lived values (locks, failure markers, source status, robots.txt, circuit
breaker state) are transients, so a persistent object cache is used when there
is one. Settings are in one option.

## 5. Database

Custom tables keep the data that can grow out of `wp_options`. Names are prefixed with `$wpdb->prefix`.

**`oddscmp_bookmakers`**: bookmakers per source.

| Column | Notes |
| --- | --- |
| `source_id`, `bookmaker_id` | unique together |
| `name`, `url` | only what the admin customised; empty means "use the default" |
| `enabled`, `sort_order` | visibility and order |

**`oddscmp_odds`**: latest snapshot per source, event and market.

| Column | Notes |
| --- | --- |
| `source_id`, `event_id`, `market_id` | unique key; one atomic upsert per refresh |
| `payload` | JSON of an `OddsSnapshot` |
| `fetched_at`, `fresh_until` | unix times |
| `starts_at` | event start, `NULL` when unknown; indexed for cleanup |

`Schema` stores a version in the `odds_comparison_db_version` option and calls
`dbDelta()` whenever it differs from the code's version, so updates migrate by
themselves. Old snapshots (events that ended more than 7 days ago, filter
`odds_comparison_retention_days`) are purged opportunistically, at most once an
hour, on a write. No cron job is involved.

Bookmakers are **discovered from data**: whenever a source returns prices, the
bookmaker ids in them are recorded for that source. A source's list therefore
contains exactly the bookmakers it provides. *Detect bookmakers* in the
dashboard reads one real event to fill the list immediately.

## 6. Odds conversion

`Odds` is an immutable value object that stores **decimal** odds, the single
source of truth, so repeated conversions never drift.

| From | To |
| --- | --- |
| decimal `d` | fractional: the smallest denominator (≤ 100) within a tolerance, so 1.91 reads `10/11` not `91/100` |
| decimal `d` | American: `d ≥ 2` → `+(d−1)·100`, otherwise `−100/(d−1)` |
| fractional `a/b` | decimal `a/b + 1` |
| American `+x` / `−x` | decimal `1 + x/100` / `1 + 100/x` |

`Odds::parse()` accepts `2.50`, `3/2`, `evens`, `+150`, `-200`. `Converter` is a
registry of `FormatterInterface` strategies (decimal, fractional, American);
more formats can be added with the `odds_comparison_formatters` filter. The
presenter sends all formats for every price, so the switcher needs no further
requests.

## 7. REST API

Namespace `odds-comparison/v1`.

| Route | Auth | Purpose |
| --- | --- | --- |
| `POST /sources/{id}/check` (`id` or `all`) | `manage_options` | check availability |
| `POST /sources/{id}/bookmakers/detect` | `manage_options` | discover a source's bookmakers |
| `POST /cache/flush` | `manage_options` | clear stored odds and cached lists |
| `GET /sources`, `/sports`, `/events`, `/markets`, `/bookmakers`, `/odds` | `edit_posts` | block editor data |
| `GET /table?token=&sig=` | public, signature required | front-end table |

Editor `odds` and public `table` answer `202 {status: "pending"}` while another
request is fetching, `200` with the table model otherwise. A table model has
`status` (`fresh`, `stale`, `finished`), `notice`, `source`, `event`, `market`,
`updated`, `formats`, `bookmakers`, `outcomes` (cells with decimal value, every
format and a `best` flag) and `link` (`rel`, `target`).

## 8. Security

- **Capabilities and nonces**: admin forms use `check_admin_referer`; admin and editor REST routes check capabilities and rely on the cookie nonce added by `wp.apiFetch`.
- **Public endpoint**: only configurations the server itself signed (HMAC-SHA256 over the payload with the site's `auth` salt, compared with `hash_equals`) are accepted, so visitors cannot make the site scrape arbitrary events or sources. Internal error text is never returned to visitors.
- **SSRF**: an event's fetch path must be a single-slash, site-relative path of letters, digits, `/`, `_` and `-`. Schemes, `//host`, dot segments and query strings are rejected before any request, and sources prefix their own base URL.
- **Output**: PHP templates escape every value; the table is built with `createElement`/`textContent` (never `innerHTML`); link hrefs must be `http(s)`; saved links are sanitised while keeping placeholders.
- **SQL**: all queries use `$wpdb->prepare()` or `$wpdb->update()`.
- **Uninstall** removes tables, options and transients.

## 9. Admin dashboard

*Odds Comparison* menu, four tabs:

- **Sources**: radio for the active source, checkboxes for fallbacks, a colour-and-text status badge (Available, Blocked, Unreachable, Parser mismatch, Needs API key, Not checked) with message, latency and age, per-source and *Check all* buttons, the odds freshness TTL and *Clear cache*.
- **Bookmakers**: one section per source with only the bookmakers it provides; show/hide, name, link (with placeholders) and order; *Detect bookmakers*.
- **Markets**: which betting markets may be shown.
- **Display**: default odds format, link `rel` and target.

## 10. Known limitations

- No source gives per-bookmaker prices for European football; a licensed odds API source would.
- Oddschecker cannot be scraped (Cloudflare challenge).
- Bookmakers are recorded from the markets that have been loaded; a bookmaker that only quotes a market nobody opened yet appears after that market is loaded.
- Fractional prices are approximated to a nearby fraction (tolerance 0.0035).
- Multisite network activation is not handled; tables are created for the site where the plugin is activated.
