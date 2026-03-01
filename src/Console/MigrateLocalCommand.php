<?php

namespace GlpiPlugin\Cloudstorage\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Cloudstorage\StorageClientFactory;
use GlpiPlugin\Cloudstorage\Config;
use GlpiPlugin\Cloudstorage\DocumentTracker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateLocalCommand extends AbstractCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('plugins:cloudstorage:migrate-local')
            ->setDescription('Download documents from cloud storage back to local filesystem')
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Number of documents to process per batch',
                100
            )
            ->addOption(
                'delete-azure',
                null,
                InputOption::VALUE_NONE,
                'Delete blobs from cloud storage after successful download (prevents orphaned blobs)'
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Simulate migration without making changes'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        global $DB;

        $batchSize = max(1, (int) $input->getOption('batch-size'));
        $dryRun = $input->getOption('dry-run');
        $deleteAzure = $input->getOption('delete-azure');

        if ($dryRun) {
            $output->writeln('<info>DRY RUN - no changes will be made</info>');
        }

        if ($deleteAzure) {
            $output->writeln('<comment>Remote blobs will be deleted after successful download</comment>');
        }

        // Count tracked documents using query builder
        $countResult = $DB->request([
            'COUNT' => 'total',
            'FROM'  => 'glpi_plugin_cloudstorage_documenttrackers',
        ]);
        $total = (int) ($countResult->current()['total'] ?? 0);

        if ($total === 0) {
            $output->writeln('<info>No documents tracked in cloud storage. Nothing to migrate back.</info>');
            return 0;
        }

        $output->writeln(sprintf('<info>Found %d documents to download from cloud storage</info>', $total));

        $processed = 0;
        $downloaded = 0;
        $skipped = 0;
        $errors = 0;
        $excludedIds = [];

        // Process in batches to avoid OOM on large datasets
        while (true) {
            $criteria = [
                'FROM'  => 'glpi_plugin_cloudstorage_documenttrackers',
                'LIMIT' => $batchSize,
            ];

            if (!empty($excludedIds)) {
                $criteria['WHERE'] = [
                    'NOT' => ['id' => $excludedIds],
                ];
            }

            $batch = $DB->request($criteria);

            if (count($batch) === 0) {
                break;
            }

            foreach ($batch as $record) {
                $processed++;
                $docId = (int) $record['documents_id'];
                $blobPath = $record['remote_path'];
                $filepath = $record['filepath'];
                try {
                    $localPath = Config::validateLocalPath($filepath);
                } catch (\RuntimeException $e) {
                    $errors++;
                    $excludedIds[] = (int) $record['id'];
                    $output->writeln(sprintf(
                        '  <error>Skipped document #%d: %s</error>',
                        $docId,
                        $e->getMessage()
                    ));
                    continue;
                }

                if ($dryRun) {
                    $output->writeln(sprintf(
                        '  [DRY RUN] Would download document #%d: %s',
                        $docId,
                        $filepath
                    ), OutputInterface::VERBOSITY_VERBOSE);
                    $downloaded++;
                    $excludedIds[] = (int) $record['id'];
                    continue;
                }

                // Skip if local file already exists and matches expected SHA1
                if (file_exists($localPath)) {
                    $expectedSha1 = $record['sha1sum'] ?? '';
                    $localSha1 = sha1_file($localPath);
                    if (!empty($expectedSha1) && $localSha1 !== $expectedSha1) {
                        $output->writeln(sprintf(
                            '  <comment>Local file for document #%d has different SHA1 — downloading from cloud storage</comment>',
                            $docId
                        ), OutputInterface::VERBOSITY_VERBOSE);
                        // Fall through to download the correct version
                    } else {
                        $output->writeln(sprintf(
                            '  <comment>Skipped document #%d: local file already exists (SHA1 verified)</comment>',
                            $docId
                        ), OutputInterface::VERBOSITY_VERBOSE);
                        $skipped++;

                        // Remove tracking record since file is confirmed local
                        DocumentTracker::removeByDocumentId($docId);
                        continue;
                    }
                }

                try {
                    $client = StorageClientFactory::getInstance();
                    $client->downloadToFile($blobPath, $localPath);

                    // Fix 7: Verify downloaded file SHA1 matches tracker before removing
                    $expectedSha1 = $record['sha1sum'] ?? '';
                    if (!empty($expectedSha1) && file_exists($localPath)) {
                        $downloadedSha1 = sha1_file($localPath);
                        if ($downloadedSha1 !== $expectedSha1) {
                            $output->writeln(sprintf(
                                '  <error>SHA1 mismatch for document #%d after download (expected: %s, got: %s) — keeping tracker</error>',
                                $docId,
                                $expectedSha1,
                                $downloadedSha1
                            ));
                            $errors++;
                            $excludedIds[] = (int) $record['id'];
                            continue;
                        }
                    }

                    // Delete blob from cloud storage if requested
                    if ($deleteAzure) {
                        $client->delete($blobPath);
                    }

                    // Remove tracking record
                    DocumentTracker::removeByDocumentId($docId);

                    $downloaded++;
                    $output->writeln(sprintf(
                        '  Downloaded document #%d: %s',
                        $docId,
                        $filepath
                    ), OutputInterface::VERBOSITY_VERBOSE);
                } catch (\Throwable $e) {
                    $errors++;
                    $excludedIds[] = (int) $record['id'];
                    $output->writeln(sprintf(
                        '  <error>Failed document #%d (%s): %s</error>',
                        $docId,
                        $filepath,
                        $e->getMessage()
                    ));
                }

                if ($processed % 50 === 0) {
                    $output->writeln(sprintf(
                        '<info>Progress: %d/%d (downloaded: %d, skipped: %d, errors: %d)</info>',
                        $processed,
                        $total,
                        $downloaded,
                        $skipped,
                        $errors
                    ));
                }
            }
        }

        $output->writeln('');
        $output->writeln('<info>Reverse migration complete:</info>');
        $output->writeln(sprintf('  Total processed: %d', $processed));
        $output->writeln(sprintf('  Downloaded:      %d', $downloaded));
        $output->writeln(sprintf('  Skipped:         %d', $skipped));
        $output->writeln(sprintf('  Errors:          %d', $errors));

        return $errors > 0 ? 1 : 0;
    }
}
