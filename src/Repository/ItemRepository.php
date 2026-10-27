<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Item;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
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

    /**
     * Relit la pièce en base avec un verrou PESSIMISTIC_WRITE (état rafraîchi) ; à appeler dans une transaction.
     */
    public function findOneByCode(string $code): ?Item
    {
        $item = $this->findOneBy(['code' => strtoupper(trim($code))]);

        return $item instanceof Item ? $item : null;
    }

    public function findForUpdate(Item $item): Item
    {
        $fresh = $this->createQueryBuilder('i')
            ->where('i.id = :id')
            ->setParameter('id', $item->getId())
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        \assert($fresh instanceof Item);

        return $fresh;
    }
}
