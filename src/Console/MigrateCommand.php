<?php

namespace GlpiPlugin\Azureblobstorage\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Azureblobstorage\AzureBlobClient;
use GlpiPlugin\Azureblobstorage\Config;
use GlpiPlugin\Azureblobstorage\DocumentTracker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends AbstractCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('plugins:azureblobstorage:migrate')
            ->setDescription('Migrate existing GLPI documents to Azure Blob Storage')
            ->addOption(
                'batch-size',
                'b',
                InputOption::VALUE_OPTIONAL,
                'Number of documents to process per batch',
                100
            )
            ->addOption(
                'delete-local',
                'd',
                InputOption::VALUE_NONE,
                'Delete local file after successful upload to Azure'
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

        $batchSize = (int) $input->getOption('batch-size');
        $deleteLocal = $input->getOption('delete-local');
        $dryRun = $input->getOption('dry-run');

        if ($dryRun) {
            $output->writeln('<info>DRY RUN - no changes will be made</info>');
        }

        // Count documents to migrate using GLPI query builder
        $countResult = $DB->request([
            'COUNT'     => 'total',
            'FROM'      => 'glpi_documents AS d',
            'LEFT JOIN' => [
                'glpi_plugin_azureblobstorage_documents AS t' => [
                    'ON' => [
                        'd' => 'id',
                        't' => 'documents_id',
                    ],
                ],
            ],
            'WHERE' => [
                'NOT' => ['d.filepath' => null],
                ['d.filepath', '!=', ''],
                'd.filepath' => ['!=', ''],
                't.id'       => null,
            ],
        ]);

        $total = (int) ($countResult->current()['total'] ?? 0);

        if ($total === 0) {
            $output->writeln('<info>No documents to migrate. All documents are already tracked in Azure.</info>');
            return 0;
        }

        $output->writeln(sprintf('<info>Found %d documents to migrate</info>', $total));

        if (!$dryRun) {
            try {
                $client = AzureBlobClient::getInstance();
                $testResult = $client->testConnection();
                if ($testResult !== true) {
                    $output->writeln(sprintf('<error>Azure connection failed: %s</error>', $testResult));
                    return 1;
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Azure connection error: %s</error>', $e->getMessage()));
                return 1;
            }
        }

        $processed = 0;
        $uploaded = 0;
        $skipped = 0;
        $errors = 0;
        $failedIds = [];

        while (true) {
            $criteria = [
                'SELECT' => ['d.id', 'd.filepath', 'd.sha1sum', 'd.filename'],
                'FROM'      => 'glpi_documents AS d',
                'LEFT JOIN' => [
                    'glpi_plugin_azureblobstorage_documents AS t' => [
                        'ON' => [
                            'd' => 'id',
                            't' => 'documents_id',
                        ],
                    ],
                ],
                'WHERE' => [
                    'NOT' => ['d.filepath' => null],
                    'd.filepath' => ['!=', ''],
                    't.id'       => null,
                ],
                'LIMIT' => $batchSize,
            ];

            // Exclude previously failed document IDs to prevent infinite retry loop
            if (!empty($failedIds)) {
                $criteria['WHERE'][] = ['NOT' => ['d.id' => $failedIds]];
            }

            $result = $DB->request($criteria);

            if (count($result) === 0) {
                break;
            }

            foreach ($result as $doc) {
                $processed++;
                $docId = (int) $doc['id'];
                $filepath = $doc['filepath'];
                $sha1sum = $doc['sha1sum'] ?? '';
                $localPath = GLPI_DOC_DIR . '/' . $filepath;

                if ($dryRun) {
                    $output->writeln(sprintf(
                        '  [DRY RUN] Would migrate document #%d: %s',
                        $docId,
                        $filepath
                    ), OutputInterface::VERBOSITY_VERBOSE);
                    $uploaded++;
                    continue;
                }

                if (!file_exists($localPath)) {
                    $output->writeln(sprintf(
                        '  <comment>Skipped document #%d: local file not found (%s)</comment>',
                        $docId,
                        $filepath
                    ), OutputInterface::VERBOSITY_VERBOSE);
                    $skipped++;
                    $failedIds[] = $docId;
                    continue;
                }

                // Check deduplication: if SHA1 already in Azure, just track
                if (!empty($sha1sum) && DocumentTracker::sha1ExistsInAzure($sha1sum)) {
                    $fileSize = filesize($localPath) ?: 0;
                    DocumentTracker::track($docId, $filepath, $sha1sum, $fileSize);

                    if ($deleteLocal) {
                        if (!unlink($localPath)) {
                            trigger_error(
                                sprintf('[AzureBlobStorage] Failed to delete local file: %s', $localPath),
                                E_USER_WARNING
                            );
                        }
                    }

                    $uploaded++;
                    $output->writeln(sprintf(
                        '  Tracked document #%d (deduplicated, SHA1 already in Azure)',
                        $docId
                    ), OutputInterface::VERBOSITY_VERBOSE);
                    continue;
                }

                try {
                    $client = AzureBlobClient::getInstance();
                    $client->upload($filepath, $localPath);

                    $fileSize = filesize($localPath) ?: 0;
                    DocumentTracker::track($docId, $filepath, $sha1sum, $fileSize);

                    if ($deleteLocal) {
                        if (!unlink($localPath)) {
                            trigger_error(
                                sprintf('[AzureBlobStorage] Failed to delete local file: %s', $localPath),
                                E_USER_WARNING
                            );
                        }
                    }

                    $uploaded++;
                    $output->writeln(sprintf(
                        '  Migrated document #%d: %s',
                        $docId,
                        $filepath
                    ), OutputInterface::VERBOSITY_VERBOSE);
                } catch (\Throwable $e) {
                    $errors++;
                    $failedIds[] = $docId;
                    $output->writeln(sprintf(
                        '  <error>Failed document #%d (%s): %s</error>',
                        $docId,
                        $filepath,
                        $e->getMessage()
                    ));
                }

                // Progress output every 50 documents
                if ($processed % 50 === 0) {
                    $output->writeln(sprintf(
                        '<info>Progress: %d/%d (uploaded: %d, skipped: %d, errors: %d)</info>',
                        $processed,
                        $total,
                        $uploaded,
                        $skipped,
                        $errors
                    ));
                }
            }
        }

        $output->writeln('');
        $output->writeln('<info>Migration complete:</info>');
        $output->writeln(sprintf('  Total processed: %d', $processed));
        $output->writeln(sprintf('  Uploaded:        %d', $uploaded));
        $output->writeln(sprintf('  Skipped:         %d', $skipped));
        $output->writeln(sprintf('  Errors:          %d', $errors));

        if ($deleteLocal && !$dryRun) {
            $output->writeln('<comment>Local copies were deleted for successfully migrated documents.</comment>');
        }

        return $errors > 0 ? 1 : 0;
    }
}
