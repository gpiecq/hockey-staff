<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Item;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Item>
 */
class ItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Item::class);
    }

    public function nextCode(): string
    {
        $next = $this->getEntityManager()->getConnection()->fetchOne("SELECT nextval('item_code_seq')");

        return \sprintf('HS-%06d', is_numeric($next) ? (int) $next : throw new \LogicException('item_code_seq indisponible.'));
    }
}
