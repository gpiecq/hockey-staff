<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalogue;

use App\Entity\Season;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Validator\Validator\ValidatorInterface;

final class SeasonValidationTest extends KernelTestCase
{
    private function validator(): ValidatorInterface
    {
        $validator = self::getContainer()->get(ValidatorInterface::class);
        \assert($validator instanceof ValidatorInterface);

        return $validator;
    }

    private function season(string $label, string $start = '2026-08-01', string $end = '2027-07-31'): Season
    {
        return new Season($label, new \DateTimeImmutable($start), new \DateTimeImmutable($end));
    }

    /**
     * @return list<string>
     */
    private function messages(Season $season): array
    {
        $messages = [];
        foreach ($this->validator()->validate($season) as $violation) {
            $messages[] = (string) $violation->getMessage();
        }

        return $messages;
    }

    public function testValidSeason(): void
    {
        self::assertSame([], $this->messages($this->season('2026-2027')));
    }

    public function testSecondYearMustFollowFirst(): void
    {
        self::assertSame(
            ['Le libellé doit être de la forme AAAA-AAAA avec deux années consécutives (ex. 2026-2027).'],
            $this->messages($this->season('2026-2028')),
        );
    }

    public function testShortLabelIsInvalid(): void
    {
        self::assertSame(
            ['Le libellé doit être de la forme AAAA-AAAA avec deux années consécutives (ex. 2026-2027).'],
            $this->messages($this->season('26-27')),
        );
    }

    public function testStartMustBeBeforeEnd(): void
    {
        self::assertSame(
            ['La date de début doit être antérieure à la date de fin.'],
            $this->messages($this->season('2026-2027', '2027-07-31', '2026-08-01')),
        );
        self::assertNotSame([], $this->messages($this->season('2026-2027', '2026-08-01', '2026-08-01')));
    }

    public function testLabelMustBeUnique(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        \assert($em instanceof EntityManagerInterface);
        $em->persist($this->season('2026-2027'));
        $em->flush();

        self::assertSame(['Cette saison existe déjà.'], $this->messages($this->season('2026-2027')));
    }
}
