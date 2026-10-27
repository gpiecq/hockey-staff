<?php

declare(strict_types=1);

namespace App\Stock;

use App\Catalogue\CurrentSeasonProvider;
use App\Entity\Variant;
use App\Repository\SeasonPriceRepository;

final readonly class EntryCostProvider
{
    public function __construct(
        private CurrentSeasonProvider $seasons,
        private SeasonPriceRepository $prices,
    ) {
    }

    /**
     * Prix club HT de la saison courante ; null sans saison courante, sans tarif ou sans prix club.
     */
    public function clubPriceHt(Variant $variant): ?int
    {
        $season = $this->seasons->get();
        if (null === $season) {
            return null;
        }

        return $this->prices->findOneBy(['variant' => $variant, 'season' => $season])?->getClubPriceHt();
    }
}
