<?php

declare(strict_types=1);

namespace App\Doctrine;

use App\Entity\Variant;
use App\Repository\VariantRepository;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/** Attribue le code interne HV-nnnnnn à toute nouvelle variante (formulaire, import, fixtures). */
#[AsEntityListener(event: Events::prePersist, entity: Variant::class)]
final readonly class VariantCodeListener
{
    public function __construct(private VariantRepository $variants)
    {
    }

    public function prePersist(Variant $variant): void
    {
        if (null === $variant->getCode()) {
            $variant->assignCode($this->variants->nextCode());
        }
    }
}
