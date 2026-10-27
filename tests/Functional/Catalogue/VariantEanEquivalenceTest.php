<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalogue;

use App\Entity\Variant;
use App\Tests\Support\ImportFixtures;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class VariantEanEquivalenceTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ValidatorInterface $validator;

    protected function setUp(): void
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $validator = self::getContainer()->get(ValidatorInterface::class);
        \assert($em instanceof EntityManagerInterface && $validator instanceof ValidatorInterface);
        [$this->em, $this->validator] = [$em, $validator];
    }

    protected function tearDown(): void
    {
        ImportFixtures::cleanup();
        parent::tearDown();
    }

    /** @return list<string> */
    private function eanMessages(Variant $variant): array
    {
        $messages = [];
        foreach ($this->validator->validate($variant) as $violation) {
            if ('ean' === $violation->getPropertyPath()) {
                $messages[] = (string) $violation->getMessage();
            }
        }

        return $messages;
    }

    public function testTwelveDigitEanIsRejectedWhenThirteenDigitFormExists(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        ImportFixtures::variant($this->em, ImportFixtures::product($this->em, $supplier, 'A1'), '0123456789012', 'SR L');
        $other = ImportFixtures::variant($this->em, ImportFixtures::product($this->em, $supplier, 'A2'), null, 'SR L');

        $other->setEan('123456789012');

        self::assertSame(['Ce code EAN est déjà utilisé.'], $this->eanMessages($other));
    }

    public function testThirteenDigitEanIsRejectedWhenTwelveDigitFormExists(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        ImportFixtures::variant($this->em, ImportFixtures::product($this->em, $supplier, 'A1'), '123456789012', 'SR L');
        $other = ImportFixtures::variant($this->em, ImportFixtures::product($this->em, $supplier, 'A2'), null, 'SR L');

        $other->setEan('0123456789012');

        self::assertSame(['Ce code EAN est déjà utilisé.'], $this->eanMessages($other));
    }

    public function testEditingTheHolderVariantStillPasses(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        $variant = ImportFixtures::variant($this->em, ImportFixtures::product($this->em, $supplier, 'A1'), '0123456789012', 'SR L');

        self::assertSame([], $this->eanMessages($variant));
    }

    public function testDistinctEanPasses(): void
    {
        $supplier = ImportFixtures::supplierWithMapping($this->em);
        ImportFixtures::variant($this->em, ImportFixtures::product($this->em, $supplier, 'A1'), '0123456789012', 'SR L');
        $other = ImportFixtures::variant($this->em, ImportFixtures::product($this->em, $supplier, 'A2'), '4006381333931', 'SR L');

        self::assertSame([], $this->eanMessages($other));
    }
}
