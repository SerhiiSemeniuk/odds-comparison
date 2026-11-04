<?php
/**
 * Odds presenter.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Service;

use InvalidArgumentException;
use OddsComparison\Bookmakers\LinkTemplate;
use OddsComparison\Contracts\BookmakerRepositoryInterface;
use OddsComparison\Odds\Converter;
use OddsComparison\Odds\Odds;
use OddsComparison\Sources\Model\Event;

/**
 * Builds the view model of an odds table from a snapshot.
 *
 * Both the editor preview and the front-end block use it, so what editors
 * preview is exactly what visitors get. The result's freshness (fresh, stale
 * with a notice, or finished) is passed through for the UI. Only enabled
 * bookmakers of the snapshot's source that have at least one price are shown,
 * optionally limited to a selection. A bookmaker's link is the admin's own
 * (affiliate) link when set, else the source's deep link, else the default site.
 */
final class OddsPresenter
{
    public function __construct(
        private readonly Converter $converter,
        private readonly BookmakerRepositoryInterface $bookmakers,
    ) {
    }

    /**
     * @param list<string> $selected Bookmaker ids to show; empty shows every enabled one.
     * @return array<string,mixed>
     */
    public function present(OddsResult $result, Event $event, string $marketName, array $selected = []): array
    {
        $snapshot = $result->snapshot;
        if ($snapshot === null) {
            throw new InvalidArgumentException('A pending result has no odds to present.');
        }

        $priced  = $snapshot->bookmakerIds();
        $columns = [];

        foreach ($this->bookmakers->enabled($snapshot->sourceId) as $bookmaker) {
            if (!in_array($bookmaker->id, $priced, true)) {
                continue;
            }
            if ($selected !== [] && !in_array($bookmaker->id, $selected, true)) {
                continue;
            }
            // The link the admin set wins (affiliate); otherwise the source's deep link to
            // the bet, if it gave one; otherwise the bookmaker's default site.
            $deepLink = esc_url_raw($snapshot->links[$bookmaker->id] ?? '');
            $url      = $deepLink !== '' && !$bookmaker->urlIsCustom
                ? $deepLink
                : LinkTemplate::expand($bookmaker->url, $event, $snapshot->marketId);

            $columns[] = ['id' => $bookmaker->id, 'name' => $bookmaker->name, 'url' => $url];
        }

        $outcomes = [];
        foreach ($snapshot->outcomes as $outcome) {
            $best = null;
            foreach ($columns as $column) {
                $price = $outcome->prices[$column['id']] ?? null;
                if ($price !== null && ($best === null || $price > $best)) {
                    $best = $price;
                }
            }

            $cells = [];
            foreach ($columns as $column) {
                $price   = $outcome->prices[$column['id']] ?? null;
                $cells[] = [
                    'bookmaker' => $column['id'],
                    'decimal'   => $price,
                    'formats'   => $price !== null ? $this->formats($price) : null,
                    'best'      => $price !== null && $best !== null && abs($price - $best) < 0.00001,
                ];
            }

            $outcomes[] = ['name' => $outcome->name, 'cells' => $cells];
        }

        return [
            'status'     => $result->freshness->value,
            'notice'     => $result->notice,
            'source'     => $snapshot->sourceId,
            'event'      => ['id' => $event->id, 'name' => $event->name],
            'market'     => ['id' => $snapshot->marketId, 'name' => $marketName],
            'updated'    => $snapshot->fetchedAt,
            'formats'    => $this->converter->formats(),
            'bookmakers' => $columns,
            'outcomes'   => $outcomes,
        ];
    }

    /**
     * Every format of a decimal price, or null when it is not a valid price.
     *
     * @return array<string,string>|null
     */
    private function formats(float $decimal): ?array
    {
        try {
            return $this->converter->formatAll(Odds::fromDecimal($decimal));
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
