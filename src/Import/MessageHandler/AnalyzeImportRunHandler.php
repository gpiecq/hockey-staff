<?php

declare(strict_types=1);

namespace App\Import\MessageHandler;

use App\Entity\ImportRun;
use App\Enum\ImportRunStatus;
use App\Import\CatalogueImportAnalyzer;
use App\Import\Exception\MissingColumnsException;
use App\Import\Message\AnalyzeImportRun;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Analyse un ImportRun Pending : Analyzed (diff stocké) ou Failed (message lisible).
 * Aucune exception n'est relancée : un échec est un état du run, pas une erreur à rejouer.
 */
#[AsMessageHandler]
final class AnalyzeImportRunHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogueImportAnalyzer $analyzer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(AnalyzeImportRun $message): void
    {
        $run = $this->em->find(ImportRun::class, $message->importRunId);
        if (null === $run) {
            $this->logger->warning('Analyse d\'import ignorée : run introuvable.', ['importRunId' => $message->importRunId]);

            return;
        }
        if (ImportRunStatus::Pending !== $run->getStatus()) {
            $this->logger->info('Analyse d\'import ignorée : run déjà traité.', ['importRunId' => $message->importRunId, 'status' => $run->getStatus()->value]);

            return;
        }

        try {
            $run->markAnalyzed($this->analyzer->analyze($run)->toArray());
        } catch (MissingColumnsException|\DomainException $e) {
            $run->markFailed($e->getMessage());
        } catch (\Throwable $e) {
            $this->logger->error('Analyse d\'import en échec.', ['importRunId' => $message->importRunId, 'exception' => $e]);
            if (!$this->em->isOpen()) {
                return;
            }
            $run->markFailed('Le fichier n\'a pas pu être analysé. Vérifiez qu\'il s\'agit d\'un tableur valide (xlsx) puis recommencez.');
        }

        $this->em->flush();
    }
}
