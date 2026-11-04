# Extending the plugin

Everything below can be done from a theme or another plugin, without touching
this one. For how the pieces fit together, see [DOCUMENTATION.md](DOCUMENTATION.md).

## Add an odds source

A source is one class. Extend `AbstractScraperSource` for an HTML site (it
brings fetching, block detection, an XPath helper and the availability check),
or implement `SourceInterface` directly for a JSON API.

```php
use OddsComparison\Sources\AbstractScraperSource;
use OddsComparison\Sources\Model\{Event, Market, OddsSnapshot, Outcome, Sport};

final class ExampleSource extends AbstractScraperSource
{
    public function id(): string { return 'example'; }          // stable id (max 32 chars)
    public function label(): string { return 'Example'; }
    public function description(): string { return 'Odds from example.com'; }
    protected function baseUrl(): string { return 'https://www.example.com'; }

    public function sports(): array
    {
        $xpath = $this->xpath('/');                              // fetch + parse
        $sports = [];
        foreach ($xpath->query('//nav//a/@href') as $href) {
            $slug = trim($href->nodeValue, '/');
            $sports[] = new Sport($slug, ucfirst($slug));
        }
        return $sports;
    }

    public function events(string $sportId): array { /* list<Event> */ }
    public function markets(Event $event): array { return [new Market('1x2', 'Match Winner')]; }

    public function odds(Event $event, string $marketId): OddsSnapshot
    {
        // prices are DECIMAL odds keyed by bookmaker id
        return new OddsSnapshot($event->id, $marketId, $this->id(), [
            new Outcome('Home', ['bookmaker-a' => 2.10, 'bookmaker-b' => 2.05]),
        ], time());
    }
}

add_filter('odds_comparison_sources', static function (array $factories): array {
    $factories['example'] = static fn () => new ExampleSource(\OddsComparison\Plugin::instance()->http());
    return $factories;
});
```

Rules a source should follow:

- Throw `BlockedException`, `UnreachableException` or `ParseException` (all extend `SourceException`); never return partial data silently. `checkAvailability()` maps them to dashboard statuses.
- Request only clean URLs and let `Fetcher` (the injected HTTP client) do the requests: it obeys robots.txt, throttles and trips the circuit breaker.
- Event `url` must be a site-relative path such as `/odds/nba/event/slug` (letters, digits, `/`, `_`, `-`); the plugin refuses anything else.
- Keep ids short: source ≤ 32, market ≤ 32, event ≤ 120 characters (they are database key parts).
- Return decimal prices (> 1.0). Use the same market ids across sources where it makes sense (`moneyline`, `spread`, `total`, `1x2`).

Once registered, the source appears in the dashboard with its own status and
check button, and its bookmakers are discovered from its data.

### Sources that need an API key

Implement `KeyedSourceInterface` (it adds `hasKey(): bool` to
`SourceInterface`) and return `true` from `requiresApiKey()`. The dashboard then
shows *Needs API key* until `hasKey()` is true, without making any request.
When the service rejects the key, throw `AuthenticationException`; the
dashboard shows the same status with your message. `ApiSource` is a complete
example, including how to keep the key out of error messages (it travels in the
query string there). Build your source in the registry factory from
`Plugin::instance()->settings()`, so a newly saved key is used on the next request.
A source that does not implement the interface can still use the
`odds_comparison_source_has_key` filter.

### Names and deep links

`OddsSnapshot` takes two optional maps keyed by bookmaker id: `names` (display
names, used for bookmakers the plugin has no name for) and `links` (deep links
to the bet). A deep link is shown on the "Bet" button unless the admin set their
own link for that bookmaker.

### Route requests through a proxy or renderer

```php
add_filter('odds_comparison_http_args', static function (array $args): array {
    $args['headers']['X-My-Gateway'] = 'secret';
    return $args;
});
```

The filter receives the arguments of every `wp_remote_get()` the scrapers make.
It is for sites you are permitted to access; the plugin itself never tries to
get past a bot challenge.

## Add or rename a bookmaker

Bookmakers appear by themselves when a source returns prices for them. A new id
is shown with a name derived from the id (`espn_bet` → "Espn Bet"). To give
ids proper names and default links:

```php
add_filter('odds_comparison_known_bookmakers', static function (array $known): array {
    $known['williamhill'] = ['William Hill', 'https://sports.williamhill.com'];
    return $known;
});
```

The dashboard's own name and link always win over these defaults. Links may use
`{sport}`, `{event}`, `{name}` and `{market}`.

## Add an odds format

```php
use OddsComparison\Contracts\FormatterInterface;
use OddsComparison\Odds\Odds;

final class ImpliedProbability implements FormatterInterface
{
    public function key(): string { return 'probability'; }
    public function label(): string { return 'Probability'; }
    public function format(Odds $odds): string { return round(100 / $odds->toDecimal(), 1) . '%'; }
}

add_filter('odds_comparison_formatters', static function (array $formatters): array {
    $formatters[] = new ImpliedProbability();
    return $formatters;
});
```

The format appears in the switcher, the REST responses and the *Display*
setting without any other change.

## Add a market

```php
add_filter('odds_comparison_markets', static function (array $markets): array {
    $markets['btts'] = 'Both Teams To Score';
    return $markets;
});
```

This makes it selectable in *Markets*. Your source must return a `Market` with
that id from `markets()`.

## Add a module

A module is any class implementing `HookableInterface`; it receives the `Plugin`
in its constructor and registers its hooks in `register()`:

```php
use OddsComparison\Contracts\HookableInterface;
use OddsComparison\Plugin;

final class MyModule implements HookableInterface
{
    public function __construct(private Plugin $plugin) {}

    public function register(): void
    {
        // Example: add a "Clear odds cache" link to the admin bar for administrators.
        add_action('admin_bar_menu', function (\WP_Admin_Bar $bar): void {
            if (current_user_can('manage_options')) {
                $bar->add_node(['id' => 'my-odds', 'title' => 'Odds', 'href' => admin_url('admin.php?page=odds-comparison')]);
            }
        });
    }
}

add_filter('odds_comparison_modules', static fn (array $modules): array => [...$modules, MyModule::class]);
```

Shared services are available as `Plugin::instance()->odds()`, `->sources()`,
`->settings()`, `->bookmakers()`, `->converter()`, `->http()`, `->store()`.

## Hook reference

| Hook | Type | Arguments | Purpose |
| --- | --- | --- | --- |
| `odds_comparison_sources` | filter | `array $factories` (id → callable) | register sources |
| `odds_comparison_formatters` | filter | `list<FormatterInterface>` | register odds formats |
| `odds_comparison_markets` | filter | `array id => label` | markets that can be enabled |
| `odds_comparison_known_bookmakers` | filter | `array id => [name, url]` | names and default links |
| `odds_comparison_modules` | filter | `list<class-string>` | add modules |
| `odds_comparison_http_args` | filter | `array $args` | change scraper HTTP requests |
| `odds_comparison_event_duration` | filter | `int $seconds, Event $event` | when an event counts as finished (default 4 h) |
| `odds_comparison_retention_days` | filter | `int $days` | how long snapshots are kept after the event (default 7) |
| `odds_comparison_source_has_key` | filter | `bool $has, string $sourceId` | tell the dashboard a key is configured |
| `odds_comparison_activate` | action | none | after the plugin's activation work |
| `odds_comparison_deactivate` | action | none | on deactivation |
