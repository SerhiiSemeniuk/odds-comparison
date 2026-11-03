# Odds Comparison

A WordPress plugin that compares live odds from many bookmakers. Editors add a
**Gutenberg block**, pick a sport, an event and a market, and visitors see a
responsive table with the best price highlighted and a decimal / fractional /
American odds switcher. Odds are scraped from public odds sites and cached in
custom database tables.

- Requires WordPress 6.5+ and PHP 8.1+. No Composer, no external services.
- Built assets are committed, so the plugin works straight after cloning;
  Node is only needed to change the block's JavaScript.

## Features

| Area | What it does |
| --- | --- |
| **Sources** | Pluggable scrapers behind one interface. Ships with **BestOdds** (per-bookmaker prices for NBA, NFL, MLB, NHL) and **BetExplorer** (sports, leagues, matches and average prices). |
| **Admin dashboard** | Pick the active source and fallbacks, see each source's availability and re-check it with one click, choose bookmakers per source, set their links, enable markets, set the default odds format. |
| **Gutenberg block** | Sport → event → market pickers, add or remove bookmakers, live editor preview, highlight-best and format-switcher toggles. |
| **Odds conversion** | Decimal, fractional and American, converted client-side without reloading; the visitor's choice is remembered. |
| **Performance** | Odds are stored per source/event/market, served instantly when fresh, fetched under a lock when not, and the page itself stays cacheable (odds load asynchronously). |
| **Respectful scraping** | Honest user agent, `robots.txt` obeyed, per-host throttling, `Retry-After`, circuit breaker. Bot-protection challenges are reported, never bypassed. |

## Installation

1. Copy the `odds-comparison` folder to `wp-content/plugins/` (or clone this repository there).
2. Activate **Odds Comparison** in *Plugins*. Activation creates two database tables; they are also created or migrated automatically when the schema version changes.
3. Open **Odds Comparison** in the admin menu:
   - **Sources**: the active source defaults to BestOdds. Click *Check availability* to see its status.
   - **Bookmakers**: click *Detect bookmakers* for a source to load the bookmakers it provides, then set your affiliate links.
4. Edit a post or page, add the **Odds Comparison** block and choose a sport, event and market.

Deleting the plugin removes its tables, options and cached data.

## Development

```bash
npm install        # once
npm run dev        # watch and rebuild the block
npm run build      # production build into build/
npm run lint:js    # lint the block JavaScript
npm run format     # format it
```

Source lives in `blocks/odds-table/`; `build/` is generated and committed.
PHP follows PSR-12 style with `declare(strict_types=1)` and the `OddsComparison\`
namespace, autoloaded by `src/autoload.php`.

## Documentation

- [docs/DOCUMENTATION.md](docs/DOCUMENTATION.md): structure, data flow, caching, database, REST API, security.
- [docs/EXTENDING.md](docs/EXTENDING.md): add a source, a bookmaker, an odds format, a market; hooks and filters reference.
- [CHANGELOG.md](CHANGELOG.md)

## Good to know

- **Oddschecker** (the example site in the brief) sits behind a Cloudflare bot challenge: every request is answered with `403` and `cf-mitigated: challenge`. The plugin does not try to bypass this. The source architecture is meant for exactly this situation: other sources are used, and a source that becomes unavailable is shown as *Blocked* in the dashboard.
- **BestOdds** covers US sports and US sportsbooks only. **BetExplorer** exposes one aggregated price, not prices per bookmaker. For European football with per-bookmaker prices a licensed odds API is the right source; the interface is designed so it can be added as one class.
- The "Bet" button opens the link you set for the bookmaker. Links may contain `{sport}`, `{event}`, `{name}` and `{market}`. Sources do not know a bookmaker's own event ids, so a link cannot point at one specific bet slip.
- Scraped sites can change their markup. The dashboard reports this as *Parser mismatch*.
- Check the terms of use of any site you scrape before using this on a production site.

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).
