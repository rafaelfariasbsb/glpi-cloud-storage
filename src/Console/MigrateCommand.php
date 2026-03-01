<?php

namespace GlpiPlugin\Cloudstorage\Console;

use Glpi\Console\AbstractCommand;
use GlpiPlugin\Cloudstorage\StorageClientFactory;
use GlpiPlugin\Cloudstorage\Config;
use GlpiPlugin\Cloudstorage\DocumentTracker;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateCommand extends AbstractCommand
{
    protected function configure(): void
    {
        parent::configure();

        $this
            ->setName('plugins:cloudstorage:migrate')
            ->setDescription('Migrate existing GLPI documents to cloud storage')
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
                'Delete local file after successful upload to cloud storage'
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
                'glpi_plugin_cloudstorage_documenttrackers AS t' => [
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
        ]);

        $total = (int) ($countResult->current()['total'] ?? 0);

        if ($total === 0) {
            $output->writeln('<info>No documents to migrate. All documents are already tracked in cloud storage.</info>');
            return 0;
        }

        $output->writeln(sprintf('<info>Found %d documents to migrate</info>', $total));

        if (!$dryRun) {
            try {
                $client = StorageClientFactory::getInstance();
                $testResult = $client->testConnection();
                if ($testResult !== true) {
                    $output->writeln(sprintf('<error>Cloud storage connection failed: %s</error>', $testResult));
                    return 1;
                }
            } catch (\Throwable $e) {
                $output->writeln(sprintf('<error>Cloud storage connection error: %s</error>', $e->getMessage()));
                return 1;
            }
        }

        $processed = 0;
        $uploaded = 0;
        $skipped = 0;
        $errors = 0;
        $excludedIds = [];

        while (true) {
            $criteria = [
                'SELECT' => ['d.id', 'd.filepath', 'd.sha1sum', 'd.filename'],
                'FROM'      => 'glpi_documents AS d',
                'LEFT JOIN' => [
                    'glpi_plugin_cloudstorage_documenttrackers AS t' => [
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

            // Exclude already-processed IDs to prevent infinite loop
            if (!empty($excludedIds)) {
                $criteria['WHERE'][] = ['NOT' => ['d.id' => $excludedIds]];
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
                try {
                    $localPath = Config::validateLocalPath($filepath);
                } catch (\RuntimeException $e) {
                    $errors++;
                    $excludedIds[] = $docId;
                    $output->writeln(sprintf(
                        '  <error>Skipped document #%d: %s</error>',
                        $docId,
                        $e->getMessage()
                    ));
                    continue;
                }

                if ($dryRun) {
                    $output->writeln(sprintf(
                        '  [DRY RUN] Would migrate document #%d: %s',
                        $docId,
                        $filepath
                    ), OutputInterface::VERBOSITY_VERBOSE);
                    $uploaded++;
                    $excludedIds[] = $docId;
                    continue;
                }

                if (!file_exists($localPath)) {
                    $output->writeln(sprintf(
                        '  <comment>Skipped document #%d: local file not found (%s)</comment>',
                        $docId,
                        $filepath
                    ), OutputInterface::VERBOSITY_VERBOSE);
                    $skipped++;
                    $excludedIds[] = $docId;
                    continue;
                }

                // Check deduplication: if SHA1 already in Azure, just track
                if (!empty($sha1sum) && DocumentTracker::sha1Exists($sha1sum)) {
                    $fileSize = filesize($localPath) ?: 0;
                    DocumentTracker::track($docId, $filepath, $sha1sum, $fileSize);

                    if ($deleteLocal) {
                        if (!unlink($localPath)) {
                            trigger_error(
                                sprintf('[CloudStorage] Failed to delete local file: %s', $localPath),
                                E_USER_WARNING
                            );
                        }
                    }

                    $uploaded++;
                    $output->writeln(sprintf(
                        '  Tracked document #%d (deduplicated, SHA1 already in cloud storage)',
                        $docId
                    ), OutputInterface::VERBOSITY_VERBOSE);
                    continue;
                }

                try {
                    $client = StorageClientFactory::getInstance();
                    $client->upload($filepath, $localPath);

                    $fileSize = filesize($localPath) ?: 0;
                    DocumentTracker::track($docId, $filepath, $sha1sum, $fileSize);

                    if ($deleteLocal) {
                        if (!unlink($localPath)) {
                            trigger_error(
                                sprintf('[CloudStorage] Failed to delete local file: %s', $localPath),
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
                    $excludedIds[] = $docId;
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
