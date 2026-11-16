<?php

namespace App\Console\Commands;

use App\Support\DataVersion;
use App\Support\MysqlImport\Importer;
use App\Support\MysqlImport\ImportFailure;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('db:import-mysql {--fresh} {--dry-run} {--yes} {--chunk=500} {--allow-missing-columns}')]
#[Description('Safely import the known MySQL backup into PostgreSQL')]
class DbImportMysql extends Command
{
    public function handle(Importer $importer): int
    {
        $defaultConnection = (string) config('database.default');
        $defaultDriver = (string) config("database.connections.{$defaultConnection}.driver");

        if ($defaultDriver !== 'pgsql') {
            $this->error('This import is allowed only when the default database connection uses PostgreSQL.');

            return self::FAILURE;
        }

        $target = config("database.connections.{$defaultConnection}");
        $url = $target['url'] ?? null;
        $urlParts = is_string($url) && $url !== '' ? parse_url($url) : false;
        $targetHost = is_array($urlParts) ? ($urlParts['host'] ?? $target['host'] ?? 'unknown') : ($target['host'] ?? 'unknown');
        $targetDatabase = is_array($urlParts) && isset($urlParts['path'])
            ? rawurldecode(ltrim($urlParts['path'], '/'))
            : ($target['database'] ?? 'unknown');

        $this->line("PostgreSQL target: {$targetHost} / {$targetDatabase}");

        $fresh = (bool) $this->option('fresh');
        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = filter_var($this->option('chunk'), FILTER_VALIDATE_INT);

        if ($chunkSize === false || $chunkSize < 1) {
            $this->error('The --chunk option must be a positive whole number.');

            return self::FAILURE;
        }

        if ($fresh && ! $this->option('yes') && ! $this->confirm('This will EMPTY the PostgreSQL data tables. Continue?', false)) {
            $this->line('Import cancelled; PostgreSQL was not changed.');

            return self::FAILURE;
        }

        try {
            $plan = $importer->preflight(
                (bool) $this->option('allow-missing-columns'),
                $fresh,
            );
        } catch (ImportFailure $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        } catch (Throwable $failure) {
            $this->error(
                'Pre-flight comparison failed ('.class_basename($failure).' at '.basename($failure->getFile()).':'.$failure->getLine().'). No PostgreSQL data was changed; check the database services and logs.',
            );

            return self::FAILURE;
        }

        $this->info('Pre-flight comparison (read-only):');
        $this->table(
            ['Table', 'MySQL rows', 'Expected rows', 'PostgreSQL rows'],
            array_map(
                static fn (array $row): array => [
                    $row['table'],
                    $row['source_rows'],
                    $row['expected_rows'],
                    $row['target_rows'] === null ? 'missing' : $row['target_rows'],
                ],
                $plan['comparison'],
            ),
        );

        if ($plan['reference_matched']) {
            $this->line('Known backup reference counts and aggregates: matched.');
        } else {
            $this->warn('One or more source values differ from the supplied backup reference facts; review the warnings below.');
        }

        foreach ($plan['warnings'] as $warning) {
            $this->warn($warning);
        }

        if ($plan['errors'] !== []) {
            foreach ($plan['errors'] as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        try {
            $report = $importer->import($plan, $fresh, $dryRun, $chunkSize);
        } catch (ImportFailure $failure) {
            $this->error($failure->getMessage());

            return self::FAILURE;
        } catch (Throwable $failure) {
            $this->error(
                'The import stopped unexpectedly ('.class_basename($failure).'). PostgreSQL changes were rolled back; check the database logs.',
            );

            return self::FAILURE;
        }

        $postCommitFailure = null;

        if ($report['success'] && ! $dryRun) {
            try {
                if ($this->call('cache:clear') !== self::SUCCESS) {
                    $postCommitFailure = 'The import committed, but cache:clear failed.';
                }
            } catch (Throwable) {
                $postCommitFailure = 'The import committed, but cache:clear failed.';
            }

            try {
                $dataVersionClass = DataVersion::class;

                if (class_exists($dataVersionClass)) {
                    $dataVersionClass::bump();
                }
            } catch (Throwable) {
                $postCommitFailure = 'The import committed, but data-version maintenance failed.';
            }
        }

        $this->table(
            ['Table', 'MySQL rows', 'PostgreSQL rows', 'Status'],
            array_map(
                static fn (array $row, string $table): array => [
                    $table,
                    $row['source_rows'],
                    $row['target_rows'],
                    $row['status'],
                ],
                array_values($report['tables']),
                array_keys($report['tables']),
            ),
        );

        foreach ($report['checks'] as $check) {
            if (! $check['passed']) {
                $this->error("FAIL: {$check['check']} — {$check['details']}");
            }
        }

        $this->info('Skipped MySQL tables:');
        $this->table(
            ['Table', 'MySQL rows'],
            array_map(
                static fn (string $table, int $count): array => [$table, $count],
                array_keys($report['skipped_counts']),
                array_values($report['skipped_counts']),
            ),
        );

        foreach ($report['warnings'] as $warning) {
            $this->warn($warning);
        }

        if (! $report['success']) {
            $this->error('Verification failed; the PostgreSQL transaction was rolled back.');

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('DRY RUN: nothing was saved');

            return self::SUCCESS;
        }

        if ($postCommitFailure !== null) {
            $this->error($postCommitFailure);

            return self::FAILURE;
        }

        $this->info('MySQL data import committed and verified.');

        return self::SUCCESS;
    }
}
