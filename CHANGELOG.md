# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added
- The Odds API source: a licensed odds feed with football and many other sports, per-bookmaker prices, bookmaker names and optional deep links. API key (write-only) and bookmaker regions are set in the dashboard; the dashboard shows the credits left after a check.
- `KeyedSourceInterface` and `AuthenticationException` for sources that need credentials.
- Bookmaker names and deep links can be reported by a source (`OddsSnapshot`); a deep link is used for the "Bet" button unless the admin set their own link.
- 179 unit tests with fakes and structural fixtures, and 81 integration tests that boot a real WordPress (REST permissions, database classes, the block end to end, the dashboard). `composer test`, `composer test:integration`, `composer test:all`.
- WordPress Coding Standards (`composer lint`, `phpcs.xml.dist`): security, escaping, SQL, i18n, deprecated APIs and prefix rules, enforced and clean.
- Screenshots of the block and the dashboard in the README.

### Changed
- `OddsService` and `OddsPresenter` depend on `OddsStoreInterface` and `BookmakerRepositoryInterface`, so they can be tested without a database.
- The odds table follows the site's own colours instead of the visitor's OS dark-mode setting, which made light sites show dark cells; the best price is also marked with a bar so colour is not the only cue.
- Variables in `uninstall.php` and the admin templates no longer clash with WordPress globals; the placeholder help text on the Bookmakers tab is escaped.

## [1.0.0]

### Added
- Pluggable odds sources behind `SourceInterface`, with a source registry, availability checks and typed errors. Bundled sources: BestOdds (per-bookmaker prices for US sports) and BetExplorer (sports, matches, average prices).
- Polite HTTP client: honest user agent, `robots.txt`, per-host throttling, `Retry-After`, circuit breaker.
- Custom database tables for bookmakers and odds snapshots, with versioned schema migration and opportunistic cleanup of finished events.
- Odds conversion between decimal, fractional and American formats, extensible through a filter.
- Admin dashboard: choose the active and fallback sources with an availability status and a check button, per-source bookmakers with links (placeholders supported), markets and display settings.
- Gutenberg block with sport, event and market pickers, add/remove bookmakers, a live preview, best-odds highlighting and an odds format switcher.
- Public, signed REST endpoint and asynchronous front-end loading, so pages stay cacheable and visitors never see frozen prices.
- Documentation: README, structure and data-flow guide, extension guide with a hook reference.
