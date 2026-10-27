<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalogue;

use App\Entity\Brand;
use App\Entity\Product;
use App\Entity\Season;
use App\Entity\SeasonPrice;
use App\Entity\Supplier;
use App\Entity\Variant;
use App\Enum\TrackingMode;
use App\Repository\CategoryRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class CatalogueConstraintsTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $validator = $container->get(ValidatorInterface::class);
        \assert($em instanceof EntityManagerInterface && $validator instanceof ValidatorInterface);
        [$this->em, $this->validator] = [$em, $validator];
    }

    private function firstMessage(ConstraintViolationListInterface $violations): string
    {
        self::assertCount(1, $violations);

        return (string) $violations->get(0)->getMessage();
    }

    private function supplier(string $name): Supplier
    {
        $supplier = (new Supplier())->setName($name);
        $this->em->persist($supplier);

        return $supplier;
    }

    private ?Brand $brand = null;

    private function brand(): Brand
    {
        if (null === $this->brand) {
            $this->brand = (new Brand())->setName('Bauer');
            $this->em->persist($this->brand);
        }

        return $this->brand;
    }

    private function product(Supplier $supplier, string $reference): Product
    {
        $categories = static::getContainer()->get(CategoryRepository::class);
        \assert($categories instanceof CategoryRepository);
        $brand = $this->brand();

        $product = (new Product())
            ->setSupplier($supplier)
            ->setReference($reference)
            ->setDesignation('Patin X')
            ->setBrand($brand)
            ->setCategory($categories->findOrCreatePath(['Equipement', 'Joueur', 'Patin']))
            ->setTrackingMode(TrackingMode::Serialized);
        $this->em->persist($product);

        return $product;
    }

    private function variant(Product $product, ?string $ean, string $size = 'SR L'): Variant
    {
        $variant = (new Variant())->setProduct($product)->setSize($size)->setEan($ean);
        $this->em->persist($variant);

        return $variant;
    }

    public function testSameEanOnTwoProductsIsRejected(): void
    {
        $supplier = $this->supplier('Bauer');
        $this->variant($this->product($supplier, 'A1'), '1234567890123');
        $this->em->flush();
        $this->variant($this->product($supplier, 'A2'), '1234567890123');

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testValidatorReportsDuplicateEan(): void
    {
        $supplier = $this->supplier('Bauer');
        $this->variant($this->product($supplier, 'A1'), '1234567890123');
        $this->em->flush();
        $duplicate = $this->variant($this->product($supplier, 'A2'), '1234567890123');

        $violations = $this->validator->validate($duplicate);

        self::assertSame('Cet EAN est déjà utilisé.', $this->firstMessage($violations));
    }

    public function testVariantsWithoutEanAreAllowed(): void
    {
        $supplier = $this->supplier('Bauer');
        $product = $this->product($supplier, 'A1');
        $this->variant($product, null, 'SR L');
        $this->variant($product, null, 'SR M');
        $this->em->flush();

        self::assertNotNull($product->getId());
    }

    public function testSameMatchKeyOnSameProductIsRejected(): void
    {
        $product = $this->product($this->supplier('Bauer'), 'A1');
        $this->variant($product, null);
        $this->em->flush();
        $duplicate = $this->variant($product, null);

        self::assertSame('Cette variante existe déjà pour ce produit.', $this->firstMessage($this->validator->validate($duplicate)));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testSecondSeasonPriceForSameVariantAndSeasonIsRejected(): void
    {
        $variant = $this->variant($this->product($this->supplier('Bauer'), 'A1'), null);
        $season = new Season('2026-2027', new \DateTimeImmutable('2026-07-01'), new \DateTimeImmutable('2027-06-30'));
        $this->em->persist($season);
        $this->em->persist((new SeasonPrice())->setVariant($variant)->setSeason($season)->setClubPriceTtc(360));
        $this->em->flush();
        $this->em->persist((new SeasonPrice())->setVariant($variant)->setSeason($season)->setClubPriceTtc(400));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }

    public function testSeasonPriceHt(): void
    {
        $price = (new SeasonPrice())->setClubPriceTtc(360);

        self::assertSame(300, $price->getClubPriceHt());
        self::assertNull((new SeasonPrice())->getClubPriceHt());
    }

    public function testSameReferenceAtTwoSuppliersIsAllowed(): void
    {
        $this->product($this->supplier('Bauer'), 'REF');
        $this->product($this->supplier('CCM'), 'REF');
        $this->em->flush();

        $this->addToAssertionCount(1);
    }

    public function testSameReferenceAtSameSupplierIsRejected(): void
    {
        $supplier = $this->supplier('Bauer');
        $this->product($supplier, 'REF');
        $this->em->flush();
        $duplicate = $this->product($supplier, 'REF');

        self::assertSame('Cette référence existe déjà chez ce fournisseur.', $this->firstMessage($this->validator->validate($duplicate)));

        $this->expectException(UniqueConstraintViolationException::class);
        $this->em->flush();
    }
}
