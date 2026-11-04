<?php
/**
 * Bookmaker repository tests against the real table.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Integration;

use OddsComparison\Bookmakers\BookmakerRepository;
use OddsComparison\Database\Schema;

final class BookmakerRepositoryTest extends IntegrationTestCase
{
    private function repo(): BookmakerRepository
    {
        // A new instance per call: the repository memoises rows for the request.
        return new BookmakerRepository();
    }

    /**
     * @param list<\OddsComparison\Bookmakers\Bookmaker> $list
     */
    private function ids(array $list): string
    {
        return implode(',', array_map(static fn ($b) => $b->id, $list));
    }

    public function testNothingIsPreSeeded(): void
    {
        $this->assertSame([], $this->repo()->all('anysource'));
    }

    public function testDiscoveryIsPerSourceDeduplicatedAndOrdered(): void
    {
        $this->repo()->discover('srcA', ['fanduel', 'draftkings', 'weird_new-book']);
        $this->repo()->discover('srcA', ['fanduel', 'bet365']);
        $this->repo()->discover('srcB', ['average']);

        $this->assertSame('fanduel,draftkings,weird_new-book,bet365', $this->ids($this->repo()->all('srcA')));
        $this->assertSame('average', $this->ids($this->repo()->all('srcB')));
    }

    public function testNamesComeFromTheDefaultsOrAreDerived(): void
    {
        $this->repo()->discover('srcA', ['fanduel', 'weird_new-book']);

        $this->assertSame('FanDuel', $this->repo()->get('srcA', 'fanduel')->name);
        $this->assertSame('https://sportsbook.fanduel.com', $this->repo()->get('srcA', 'fanduel')->url);
        $this->assertSame('Weird New Book', $this->repo()->get('srcA', 'weird_new-book')->name);
    }

    public function testASourceReportedNameIsUsedForUnknownIdsOnly(): void
    {
        $this->repo()->discover('srcA', ['williamhill', 'fanduel'], ['williamhill' => 'William Hill', 'fanduel' => 'Some other title']);

        $this->assertSame('William Hill', $this->repo()->get('srcA', 'williamhill')->name);
        $this->assertSame('FanDuel', $this->repo()->get('srcA', 'fanduel')->name, 'known names are not overridden');
    }

    public function testDashboardChoicesAreSavedAndWinOverDefaults(): void
    {
        $this->repo()->discover('srcA', ['fanduel', 'draftkings', 'bet365']);

        $this->repo()->save(['srcA' => [
            'fanduel'    => ['enabled' => '1', 'url' => 'https://aff.example/{sport}?e={event}', 'name' => 'FanDuel US', 'order' => 5],
            'draftkings' => ['url' => '', 'name' => '', 'order' => 7],
        ]]);

        $repo = $this->repo();
        $this->assertSame('https://aff.example/{sport}?e={event}', $repo->get('srcA', 'fanduel')->url, 'placeholders survive sanitising');
        $this->assertTrue($repo->get('srcA', 'fanduel')->urlIsCustom);
        $this->assertSame('FanDuel US', $repo->get('srcA', 'fanduel')->name);
        $this->assertFalse($repo->get('srcA', 'draftkings')->enabled, 'an unchecked box disables the bookmaker');
        $this->assertSame('fanduel,bet365', $this->ids($repo->enabled('srcA')), 'ordered by the saved position, disabled ones left out');
    }

    public function testAnUnsafeLinkIsNotSaved(): void
    {
        $this->repo()->discover('srcA', ['fanduel']);

        $this->repo()->save(['srcA' => ['fanduel' => ['enabled' => 1, 'url' => 'javascript:alert(1)']]]);

        $this->assertFalse($this->repo()->get('srcA', 'fanduel')->urlIsCustom);
    }

    public function testTheFormCannotCreateBookmakers(): void
    {
        global $wpdb;

        $this->repo()->save(['srcZ' => ['nope' => ['enabled' => 1]]]);

        $this->assertSame('0', $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::bookmakers())); // phpcs:ignore WordPress.DB.PreparedSQL
    }
}
