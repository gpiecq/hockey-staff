<?php

declare(strict_types=1);

namespace App\Repository;

use App\Catalogue\NameNormalizer;
use App\Entity\Category;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Category>
 */
class CategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Category::class);
    }

    /**
     * Retrouve ou crée la catégorie désignée par un chemin (racine d'abord), en créant les niveaux manquants.
     * Les niveaux vides de fin sont ignorés.
     *
     * @param list<?string> $names
     */
    public function findOrCreatePath(array $names): Category
    {
        $levels = [];
        foreach ($names as $name) {
            $normalized = null === $name ? '' : NameNormalizer::normalize($name);
            if ('' === $normalized) {
                $levels[] = null;
            } elseif ([] !== $levels && null === $levels[array_key_last($levels)]) {
                throw new \InvalidArgumentException('Un niveau de catégorie vide ne peut pas précéder un niveau renseigné.');
            } else {
                $levels[] = $normalized;
            }
        }
        $levels = array_values(array_filter($levels, static fn (?string $l): bool => null !== $l));

        if ([] === $levels) {
            throw new \InvalidArgumentException('Le chemin de catégorie est vide.');
        }
        if (\count($levels) > Category::MAX_DEPTH) {
            throw new \InvalidArgumentException('Une catégorie ne peut pas dépasser '.Category::MAX_DEPTH.' niveaux.');
        }

        $em = $this->getEntityManager();
        $category = null;
        foreach ($levels as $name) {
            // findOneBy(parent => null) génère « parent_id IS NULL » : seules les racines sont retrouvées
            $found = $this->findOneBy(['parent' => $category, 'name' => $name]);
            if (null === $found) {
                $found = (new Category())->setName($name)->setParent($category);
                $em->persist($found);
                // flush par niveau : le niveau suivant est ainsi retrouvé en base
                $em->flush();
            }
            $category = $found;
        }

        return $category;
    }

    /**
     * Retrouve (sans jamais rien créer) la catégorie désignée par un chemin, racine d'abord. Niveaux normalisés,
     * niveaux vides de fin ignorés ; null si le chemin est vide ou si un niveau n'existe pas.
     *
     * @param list<?string> $names
     */
    public function findByPath(array $names): ?Category
    {
        $levels = [];
        foreach ($names as $name) {
            $normalized = null === $name ? '' : NameNormalizer::normalize($name);
            if ('' !== $normalized) {
                $levels[] = $normalized;
            }
        }
        if ([] === $levels) {
            return null;
        }

        $category = null;
        foreach ($levels as $name) {
            $category = $this->findOneBy(['parent' => $category, 'name' => $name]);
            if (null === $category) {
                return null;
            }
        }

        return $category;
    }

    /**
     * Catégories pouvant recevoir une sous-catégorie (niveaux 1 et 2), avec leurs parents pour afficher le chemin.
     */
    public function createParentCandidatesQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->addSelect('p')
            ->leftJoin('c.parent', 'p')
            ->andWhere('p.id IS NULL OR p.parent IS NULL');
    }

    /**
     * Liste complète triée par chemin, parents chargés.
     */
    public function createTreeQueryBuilder(): QueryBuilder
    {
        return $this->createQueryBuilder('c')
            ->addSelect('p', 'g')
            ->leftJoin('c.parent', 'p')
            ->leftJoin('p.parent', 'g')
            ->addSelect('COALESCE(g.name, p.name, c.name) AS HIDDEN k1')
            ->addSelect("CASE WHEN g.id IS NOT NULL THEN p.name WHEN p.id IS NOT NULL THEN c.name ELSE '' END AS HIDDEN k2")
            ->addSelect("CASE WHEN g.id IS NOT NULL THEN c.name ELSE '' END AS HIDDEN k3")
            ->orderBy('k1')
            ->addOrderBy('k2')
            ->addOrderBy('k3');
    }
}
