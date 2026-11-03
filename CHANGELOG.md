# Changelog

All notable changes to this project are documented here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses [Semantic Versioning](https://semver.org/).

## [Unreleased]

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
