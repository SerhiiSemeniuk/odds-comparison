<?php
/**
 * Bookmaker model.
 *
 * @package OddsComparison
 */

declare(strict_types=1);

namespace OddsComparison\Bookmakers;

/**
 * A bookmaker as shown to visitors: name, outbound link and visibility.
 */
final class Bookmaker
{
    /**
     * @param string $id      Id used by sources (e.g. "draftkings").
     * @param string $name    Display name.
     * @param string $url     Outbound (affiliate) link; empty means no link.
     * @param bool   $enabled Whether it is shown in comparisons.
     * @param int    $order   Sort position, lowest first.
     * @param bool   $urlIsCustom Whether the admin set the link (it then beats a source's deep link).
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $url = '',
        public readonly bool $enabled = true,
        public readonly int $order = 0,
        public readonly bool $urlIsCustom = false,
    ) {
    }

    /**
     * @return array{id:string,name:string,url:string,enabled:bool,order:int}
     */
    public function toArray(): array
    {
        return [
            'id'      => $this->id,
            'name'    => $this->name,
            'url'     => $this->url,
            'enabled' => $this->enabled,
            'order'   => $this->order,
        ];
    }
}
