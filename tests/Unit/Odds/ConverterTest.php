<?php
/**
 * Odds conversion tests.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Tests\Unit\Odds;

use InvalidArgumentException;
use OddsComparison\Odds\Converter;
use OddsComparison\Odds\Odds;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ConverterTest extends TestCase
{
    /**
     * @return array<string,array{string,string,string}>
     */
    public static function conversions(): array
    {
        return [
            'decimal to fractional'                => ['2.50', 'fractional', '3/2'],
            'decimal to american (underdog)'       => ['2.5', 'american', '+150'],
            'decimal to american (favourite)'      => ['1.5', 'american', '-200'],
            'evens in american'                    => ['2.0', 'american', '+100'],
            'evens in fractional'                  => ['2.0', 'fractional', '1/1'],
            'snaps to a trader fraction (10/11)'   => ['1.91', 'fractional', '10/11'],
            'snaps to a trader fraction (5/6)'     => ['1.83', 'fractional', '5/6'],
            'large price'                          => ['13', 'fractional', '12/1'],
            'fractional to decimal'                => ['5/2', 'decimal', '3.50'],
            'fractional to american'               => ['5/2', 'american', '+250'],
            'american underdog to decimal'         => ['+150', 'decimal', '2.50'],
            'american favourite to decimal'        => ['-200', 'decimal', '1.50'],
            'american favourite to fractional'     => ['-200', 'fractional', '1/2'],
            'the word evens'                       => ['evens', 'decimal', '2.00'],
            'EVS in capitals'                      => ['EVS', 'american', '+100'],
            'whitespace around the value'          => ['  3/1  ', 'decimal', '4.00'],
        ];
    }

    #[DataProvider('conversions')]
    public function testConvertsBetweenFormats(string $input, string $to, string $expected): void
    {
        $this->assertSame($expected, (new Converter())->convert($input, $to));
    }

    /**
     * @return array<string,array{string}>
     */
    public static function invalidValues(): array
    {
        return [
            'decimal of exactly 1.0'   => ['1.0'],
            'decimal below 1'          => ['0.5'],
            'american between -100/+100' => ['+50'],
            'zero numerator'           => ['0/5'],
            'words'                    => ['abc'],
            'empty string'             => [''],
        ];
    }

    #[DataProvider('invalidValues')]
    public function testRejectsInvalidValues(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Converter())->convert($value, 'decimal');
    }

    public function testRejectsAnUnknownFormat(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Converter())->format(Odds::fromDecimal(2.0), 'nope');
    }

    public function testFormatAllReturnsEveryFormat(): void
    {
        $all = (new Converter())->formatAll(Odds::fromDecimal(3.0));

        $this->assertSame(['decimal' => '3.00', 'fractional' => '2/1', 'american' => '+200'], $all);
    }

    public function testListsTheAvailableFormats(): void
    {
        $converter = new Converter();

        $this->assertSame(['decimal', 'fractional', 'american'], array_keys($converter->formats()));
        $this->assertTrue($converter->has('american'));
        $this->assertFalse($converter->has('nope'));
    }

    public function testRoundTripThroughDecimalIsStable(): void
    {
        $odds = Odds::fromAmerican(175);

        $this->assertSame(175, Odds::fromDecimal($odds->toDecimal())->toAmerican());
    }

    public function testAdditionalFormattersCanBeRegisteredThroughTheFilter(): void
    {
        $GLOBALS['oddscmp_filters']['odds_comparison_formatters'] = static function (array $formatters): array {
            $formatters[] = new class () implements \OddsComparison\Contracts\FormatterInterface {
                public function key(): string
                {
                    return 'probability';
                }

                public function label(): string
                {
                    return 'Probability';
                }

                public function format(Odds $odds): string
                {
                    return round(100 / $odds->toDecimal()) . '%';
                }
            };

            return $formatters;
        };

        try {
            $this->assertSame('50%', (new Converter())->convert('2.0', 'probability'));
        } finally {
            unset($GLOBALS['oddscmp_filters']['odds_comparison_formatters']);
        }
    }
}
