<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalogue;

use App\Entity\Category;
use App\Repository\CategoryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CategoryRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private CategoryRepository $categories;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $categories = $container->get(CategoryRepository::class);
        $validator = $container->get(ValidatorInterface::class);
        \assert($em instanceof EntityManagerInterface && $categories instanceof CategoryRepository && $validator instanceof ValidatorInterface);
        [$this->em, $this->categories, $this->validator] = [$em, $categories, $validator];
    }

    private function rows(): int
    {
        $rows = $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM category');
        \assert(\is_int($rows));

        return $rows;
    }

    public function testNameIsNormalized(): void
    {
        $category = (new Category())->setName("  protege   genou\tx ");

        self::assertSame('PROTEGE GENOU X', $category->getName());
        self::assertSame('PROTEGE GENOUX', (new Category())->setName('PROTEGE GENOUX ')->getName());
    }

    public function testDepthAndPath(): void
    {
        $leaf = $this->categories->findOrCreatePath(['equipement', 'joueur', 'patin']);

        self::assertSame(3, $leaf->getDepth());
        self::assertSame('EQUIPEMENT › JOUEUR › PATIN', $leaf->getPath());
        self::assertSame('EQUIPEMENT', $leaf->getRoot()->getName());
        self::assertSame(1, $leaf->getRoot()->getDepth());
    }

    public function testDepthFourIsRefusedByValidation(): void
    {
        $leaf = $this->categories->findOrCreatePath(['A', 'B', 'C']);
        $tooDeep = (new Category())->setName('D')->setParent($leaf);

        $violations = $this->validator->validate($tooDeep);

        self::assertCount(1, $violations);
        self::assertSame('Une catégorie ne peut pas dépasser 3 niveaux.', $violations->get(0)->getMessage());
    }

    public function testMovingASubtreeBeyondDepthThreeIsRefused(): void
    {
        $this->categories->findOrCreatePath(['A', 'B', 'C']);
        $other = $this->categories->findOrCreatePath(['X', 'Y']);
        $b = $this->categories->findOneBy(['name' => 'B']);
        self::assertInstanceOf(Category::class, $b);
        $this->em->clear();
        $b = $this->em->find(Category::class, $b->getId());
        $other = $this->em->find(Category::class, $other->getId());
        \assert($b instanceof Category && $other instanceof Category);
        $b->setParent($other);

        self::assertCount(1, $this->validator->validate($b));
    }

    public function testCategoryCannotBeItsOwnDescendant(): void
    {
        $leaf = $this->categories->findOrCreatePath(['A', 'B']);
        $root = $leaf->getRoot();
        $root->setParent($leaf);

        $violations = $this->validator->validate($root);

        self::assertGreaterThanOrEqual(1, \count($violations));
    }

    public function testParentAndNameMustBeUnique(): void
    {
        $this->categories->findOrCreatePath(['EQUIPEMENT', 'JOUEUR']);
        $equipement = $this->categories->findOneBy(['name' => 'EQUIPEMENT', 'parent' => null]);

        $duplicate = (new Category())->setName('joueur')->setParent($equipement);
        $sameNameElsewhere = (new Category())->setName('JOUEUR')->setParent(null);

        self::assertCount(1, $this->validator->validate($duplicate));
        self::assertCount(0, $this->validator->validate($sameNameElsewhere));
    }

    public function testRootNamesAreUniqueByValidationAndByDatabase(): void
    {
        $this->categories->findOrCreatePath(['CONSOMMABLE']);

        self::assertCount(1, $this->validator->validate((new Category())->setName('consommable')));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->getConnection()->executeStatement("INSERT INTO category (name, created_at, updated_at) VALUES ('CONSOMMABLE', now(), now())");
    }

    public function testFindOrCreatePathIsIdempotent(): void
    {
        $first = $this->categories->findOrCreatePath(['EQUIPEMENT', 'JOUEUR', 'PATIN']);
        $second = $this->categories->findOrCreatePath(['EQUIPEMENT', 'JOUEUR', 'PATIN']);

        self::assertSame($first->getId(), $second->getId());
        self::assertSame(3, $this->rows());
    }

    public function testFindOrCreatePathIgnoresTrailingEmptyLevels(): void
    {
        $root = $this->categories->findOrCreatePath(['CONSOMMABLE', null, null]);
        $again = $this->categories->findOrCreatePath(['CONSOMMABLE', '', ' ']);

        self::assertNull($root->getParent());
        self::assertSame($root->getId(), $again->getId());
        self::assertSame(1, $this->rows());
    }

    public function testFindOrCreatePathDoesNotConfuseRootWithSameNamedChild(): void
    {
        $this->categories->findOrCreatePath(['EQUIPEMENT', 'JOUEUR']);
        $root = $this->categories->findOrCreatePath(['JOUEUR']);

        self::assertNull($root->getParent());
        self::assertSame(3, $this->rows());
    }

    public function testFindOrCreatePathRejectsGapsAndTooManyLevels(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->categories->findOrCreatePath(['A', 'B', 'C', 'D']);
    }

    public function testFindOrCreatePathRejectsEmptyMiddleLevel(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->categories->findOrCreatePath(['A', null, 'C']);
    }
}
