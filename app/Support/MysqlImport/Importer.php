<?php

namespace App\Support\MysqlImport;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Throwable;

class Importer
{
    /**
     * The allow-list prevents an unrelated table in a dump from being copied accidentally.
     *
     * @var list<string>
     */
    private const COPY_TABLES = [
        'users',
        'projects',
        'tasks',
        'task_assignees',
        'task_activities',
        'task_comments',
        'calendar_events',
        'notifications',
        'audit_logs',
    ];

    /**
     * Tables rebuilt or managed by Laravel are deliberately outside the import.
     *
     * @var list<string>
     */
    private const SKIPPED_TABLES = [
        'migrations',
        'sessions',
        'cache',
        'cache_locks',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
    ];

    public function __construct(
        private readonly Converter $converter,
        private readonly Verifier $verifier,
    ) {}

    /**
     * Compare source and target metadata before any target data is changed.
     *
     * @return array<string, mixed>
     */
    public function preflight(bool $allowMissingColumns, bool $fresh): array
    {
        $source = DB::connection('mysql_old');
        $target = DB::connection();

        try {
            $source->getPdo();
        } catch (Throwable) {
            throw new ImportFailure(
                'The temporary MySQL source is unavailable. Start it with docker compose --profile import up -d mysql-import and wait until healthy.',
            );
        }

        $schema = (string) $target->selectOne('SELECT current_schema() AS schema_name')->schema_name;
        $sourceDatabase = (string) $source->getDatabaseName();

        $sourceTables = array_map(
            static fn (object $row): string => (string) $row->table_name,
            $source->select(
                'SELECT TABLE_NAME AS table_name FROM information_schema.tables WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
                [$sourceDatabase, 'BASE TABLE'],
            ),
        );
        $targetTables = array_map(
            static fn (object $row): string => (string) $row->table_name,
            $target->select(
                'SELECT table_name AS table_name FROM information_schema.tables WHERE table_schema = ? AND table_type = ?',
                [$schema, 'BASE TABLE'],
            ),
        );

        $sourceTableSet = array_fill_keys($sourceTables, true);
        $targetTableSet = array_fill_keys($targetTables, true);
        $sourceColumns = $this->columnMetadata(
            $source->select(
                'SELECT TABLE_NAME AS table_name, COLUMN_NAME AS column_name, DATA_TYPE AS data_type, IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default FROM information_schema.columns WHERE TABLE_SCHEMA = ?',
                [$sourceDatabase],
            ),
            includeIdentity: false,
        );
        $targetColumns = $this->columnMetadata(
            $target->select(
                'SELECT table_name AS table_name, column_name AS column_name, data_type AS data_type, is_nullable AS is_nullable, column_default AS column_default, is_identity AS is_identity, is_generated AS is_generated FROM information_schema.columns WHERE table_schema = ?',
                [$schema],
            ),
            includeIdentity: true,
        );

        $sourceCounts = [];

        foreach ($sourceTables as $table) {
            $sourceCounts[$table] = (int) $source->table($table)->count();
        }

        $targetCounts = [];
        $errors = [];
        $warnings = [];
        $comparison = [];

        foreach (self::COPY_TABLES as $table) {
            $expected = Verifier::EXPECTED_ROWS[$table];

            if (! isset($sourceTableSet[$table])) {
                $errors[] = "Required source table {$table} is missing.";
            }

            if (! isset($targetTableSet[$table])) {
                $errors[] = "Required PostgreSQL table {$table} is missing.";
                $targetCounts[$table] = null;
            } else {
                $targetCounts[$table] = (int) $target->table($table)->count();
            }

            if (isset($sourceTableSet[$table]) && isset($targetTableSet[$table])) {
                $sourceColumnSet = $sourceColumns[$table] ?? [];
                $targetColumnSet = $targetColumns[$table] ?? [];

                if (isset($targetColumnSet['id']) && ! isset($sourceColumnSet['id'])) {
                    $errors[] = "PostgreSQL table {$table} has an id column but MySQL does not; original ids cannot be preserved.";
                }

                foreach ($sourceColumnSet as $column => $metadata) {
                    if (isset($targetColumnSet[$column])) {
                        continue;
                    }

                    $hasValue = $source->table($table)->whereNotNull($column)->exists();

                    if ($hasValue) {
                        $message = "MySQL column {$table}.{$column} has non-NULL data but is missing in PostgreSQL.";

                        if ($allowMissingColumns) {
                            $warnings[] = $message.' Its values will not be imported.';
                        } else {
                            $errors[] = $message.' Use --allow-missing-columns to skip it knowingly.';
                        }
                    }
                }

                foreach ($targetColumnSet as $column => $metadata) {
                    if (
                        ! isset($sourceColumnSet[$column])
                        && $metadata['is_nullable'] === 'NO'
                        && $metadata['column_default'] === null
                        && $metadata['is_identity'] === 'NO'
                        && $metadata['is_generated'] === 'NEVER'
                    ) {
                        $errors[] = "Required PostgreSQL column {$table}.{$column} has no MySQL source value or default.";
                    }
                }
            }

            $comparison[] = [
                'table' => $table,
                'source_rows' => $sourceCounts[$table] ?? 0,
                'expected_rows' => $expected,
                'target_rows' => $targetCounts[$table],
            ];
        }

        foreach ($sourceCounts as $table => $count) {
            if ($count === 0 || in_array($table, self::SKIPPED_TABLES, true)) {
                continue;
            }

            if (! isset($targetTableSet[$table])) {
                $errors[] = "MySQL table {$table} has {$count} row(s) but does not exist in PostgreSQL.";

                continue;
            }

            if (! in_array($table, self::COPY_TABLES, true)) {
                $errors[] = "MySQL table {$table} has {$count} row(s) but is not in the explicit import allow-list.";
            }
        }

        foreach (self::COPY_TABLES as $table) {
            $actual = $sourceCounts[$table] ?? null;
            $expected = Verifier::EXPECTED_ROWS[$table];

            if ($actual !== null && $actual !== $expected) {
                $warnings[] = "Source reference count for {$table} is {$actual}; expected {$expected}.";
            }
        }

        $collisions = $this->sourceEmailCollisions($source, $sourceTableSet);

        if ($collisions !== []) {
            $ids = implode('; ', array_map(
                static fn (array $group): string => implode(', ', $group),
                $collisions,
            ));
            $errors[] = "User email normalization would collide for user ids: {$ids}.";
        }

        $referenceWarnings = $this->sourceReferenceWarnings($source, $sourceTableSet);
        $warnings = array_merge($warnings, $referenceWarnings);

        $foreignKeys = $this->foreignKeys($target, $schema);
        $order = $this->verifier->topologicalOrder(self::COPY_TABLES, $foreignKeys);
        $externalCascadeTables = [];

        foreach ($foreignKeys as $foreignKey) {
            if (
                in_array($foreignKey['referenced_table'], self::COPY_TABLES, true)
                && ! in_array($foreignKey['table'], self::COPY_TABLES, true)
            ) {
                $externalCascadeTables[$foreignKey['table']] = true;
            }
        }

        if ($fresh && $externalCascadeTables !== []) {
            $tables = implode(', ', array_keys($externalCascadeTables));
            $errors[] = "Fresh mode would cascade into tables outside the import scope: {$tables}.";
        }

        $migrationDifferences = $this->migrationDifferences($source, $target, $sourceTableSet, $targetTableSet);

        if ($migrationDifferences !== []) {
            $warnings[] = 'MySQL migration names are missing from PostgreSQL; the schema may differ: '.implode(', ', $migrationDifferences);
        }

        $sequences = $this->sequences($target, $schema, $targetColumns);

        foreach ($sequences as $table => $sequence) {
            $canUpdate = (bool) $target->selectOne(
                "SELECT has_sequence_privilege(current_user, ?::regclass, 'UPDATE') AS allowed",
                [$sequence],
            )->allowed;

            if (! $canUpdate) {
                $errors[] = "PostgreSQL role cannot reset the {$table} id sequence.";
            }
        }

        $skippedCounts = [];

        foreach (self::SKIPPED_TABLES as $table) {
            $skippedCounts[$table] = $sourceCounts[$table] ?? 0;
        }

        return [
            'schema' => $schema,
            'source_counts' => $sourceCounts,
            'target_counts' => $targetCounts,
            'comparison' => $comparison,
            'skipped_counts' => $skippedCounts,
            'source_columns' => $sourceColumns,
            'target_columns' => $targetColumns,
            'foreign_keys' => $foreignKeys,
            'order' => $order,
            'sequences' => $sequences,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'reference_matched' => $referenceWarnings === [],
        ];
    }

    /**
     * Copy rows and verify them while a single PostgreSQL transaction is open.
     *
     * @param  array<string, mixed>  $plan
     * @return array<string, mixed>
     */
    public function import(array $plan, bool $fresh, bool $dryRun, int $chunkSize): array
    {
        $source = DB::connection('mysql_old');
        $target = DB::connection();
        $warnings = $plan['warnings'];

        $target->beginTransaction();

        try {
            $existingTables = [];

            if (! $fresh) {
                foreach (self::COPY_TABLES as $table) {
                    $count = (int) $target->table($table)->count();

                    if ($count > 0) {
                        $existingTables[] = "{$table} ({$count})";
                    }
                }

                if ($existingTables !== []) {
                    throw new ImportFailure(
                        'Target data tables are not empty. Use --fresh after confirming the data can be replaced: '.implode(', ', $existingTables).'.',
                    );
                }
            } else {
                $this->truncateImportTables($target);
            }

            foreach ($plan['order'] as $table) {
                $this->copyTable(
                    $source,
                    $target,
                    $table,
                    $plan['source_columns'][$table],
                    $plan['target_columns'][$table],
                    $chunkSize,
                    $warnings,
                );
            }

            $verification = $this->verifyData($source, $target, $plan, $warnings);
            $failedChecks = array_filter(
                $verification['checks'],
                static fn (array $check): bool => ! $check['passed'],
            );

            if ($failedChecks !== []) {
                $this->rollback($target);

                return [
                    'success' => false,
                    'dry_run' => $dryRun,
                    'tables' => $verification['tables'],
                    'checks' => $verification['checks'],
                    'skipped_counts' => $plan['skipped_counts'],
                    'warnings' => array_values(array_unique($warnings)),
                ];
            }

            $sequenceChecks = $this->resetAndVerifySequences($target, $plan, $dryRun);
            $verification['checks'] = array_merge($verification['checks'], $sequenceChecks);
            $failedChecks = array_filter(
                $sequenceChecks,
                static fn (array $check): bool => ! $check['passed'],
            );

            foreach ($sequenceChecks as $check) {
                if ($check['table'] !== null && ! $check['passed']) {
                    $verification['tables'][$check['table']]['status'] = 'FAIL';
                }
            }

            if ($failedChecks !== []) {
                $this->rollback($target);

                return [
                    'success' => false,
                    'dry_run' => $dryRun,
                    'tables' => $verification['tables'],
                    'checks' => $verification['checks'],
                    'skipped_counts' => $plan['skipped_counts'],
                    'warnings' => array_values(array_unique($warnings)),
                ];
            }

            if ($dryRun) {
                $this->rollback($target);
            } else {
                $target->commit();
            }

            return [
                'success' => true,
                'dry_run' => $dryRun,
                'tables' => $verification['tables'],
                'checks' => $verification['checks'],
                'skipped_counts' => $plan['skipped_counts'],
                'warnings' => array_values(array_unique($warnings)),
            ];
        } catch (ImportFailure $failure) {
            $this->rollback($target);

            throw $failure;
        } catch (Throwable $exception) {
            $this->rollback($target);

            throw new ImportFailure(
                'The import failed with '.class_basename($exception).' and PostgreSQL changes were rolled back. No rows were committed.',
                previous: $exception,
            );
        }
    }

    /**
     * @param  list<object>  $rows
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function columnMetadata(array $rows, bool $includeIdentity): array
    {
        $columns = [];

        foreach ($rows as $row) {
            $columns[$row->table_name][$row->column_name] = [
                'data_type' => (string) $row->data_type,
                'is_nullable' => (string) $row->is_nullable,
                'column_default' => $row->column_default,
                'is_identity' => $includeIdentity ? (string) $row->is_identity : 'NO',
                'is_generated' => $includeIdentity ? (string) $row->is_generated : 'NEVER',
            ];
        }

        return $columns;
    }

    /**
     * @return list<array{table: string, referenced_table: string, name: string, columns: list<string>, referenced_columns: list<string>}>
     */
    private function foreignKeys(Connection $target, string $schema): array
    {
        $rows = $target->select(
            <<<'SQL'
                SELECT constraint_row.conname AS constraint_name,
                       child.relname AS table_name,
                       parent.relname AS referenced_table,
                       child_column.attname AS column_name,
                       parent_column.attname AS referenced_column,
                       child_key.ordinality AS column_order
                FROM pg_constraint AS constraint_row
                JOIN pg_class AS child ON child.oid = constraint_row.conrelid
                JOIN pg_namespace AS child_schema ON child_schema.oid = child.relnamespace
                JOIN pg_class AS parent ON parent.oid = constraint_row.confrelid
                JOIN pg_namespace AS parent_schema ON parent_schema.oid = parent.relnamespace
                JOIN LATERAL unnest(constraint_row.conkey) WITH ORDINALITY AS child_key(attnum, ordinality) ON TRUE
                JOIN LATERAL unnest(constraint_row.confkey) WITH ORDINALITY AS parent_key(attnum, ordinality)
                    ON parent_key.ordinality = child_key.ordinality
                JOIN pg_attribute AS child_column ON child_column.attrelid = child.oid AND child_column.attnum = child_key.attnum
                JOIN pg_attribute AS parent_column ON parent_column.attrelid = parent.oid AND parent_column.attnum = parent_key.attnum
                WHERE constraint_row.contype = 'f'
                  AND child_schema.nspname = ?
                  AND parent_schema.nspname = ?
                ORDER BY child.relname, constraint_row.conname, child_key.ordinality
                SQL,
            [$schema, $schema],
        );

        $foreignKeys = [];

        foreach ($rows as $row) {
            $key = $row->table_name.'|'.$row->constraint_name;
            $foreignKeys[$key] ??= [
                'table' => (string) $row->table_name,
                'referenced_table' => (string) $row->referenced_table,
                'name' => (string) $row->constraint_name,
                'columns' => [],
                'referenced_columns' => [],
            ];
            $foreignKeys[$key]['columns'][] = (string) $row->column_name;
            $foreignKeys[$key]['referenced_columns'][] = (string) $row->referenced_column;
        }

        return array_values($foreignKeys);
    }

    /**
     * @param  array<string, bool>  $sourceTableSet
     * @return list<string>
     */
    private function sourceEmailCollisions(Connection $source, array $sourceTableSet): array
    {
        if (! isset($sourceTableSet['users'])) {
            return [];
        }

        $users = [];

        foreach ($source->table('users')->select(['id', 'email'])->cursor() as $user) {
            $users[] = ['id' => $user->id, 'email' => $user->email];
        }

        return array_map(
            static fn (array $ids): string => implode(', ', $ids),
            Converter::collidingEmailIds($users),
        );
    }

    /**
     * @param  array<string, int>  $sourceCounts
     * @param  array<string, bool>  $sourceTableSet
     * @return list<string>
     */
    private function sourceReferenceWarnings(Connection $source, array $sourceTableSet): array
    {
        $warnings = [];

        $expectedAggregates = [
            'users' => ['max_id' => 4],
            'projects' => ['max_id' => 8],
            'tasks' => ['max_id' => 18, 'sum_id' => 147],
            'task_activities' => ['max_id' => 59, 'sum_id' => 1570],
            'calendar_events' => ['max_id' => 2],
            'audit_logs' => ['max_id' => 155, 'sum_id' => 12090],
        ];

        foreach ($expectedAggregates as $table => $expected) {
            if (! isset($sourceTableSet[$table])) {
                continue;
            }

            foreach ($expected as $metric => $expectedValue) {
                $query = $source->table($table);
                $actual = $metric === 'max_id' ? $query->max('id') : $query->sum('id');
                $actual = $actual === null ? 0 : (int) $actual;

                if ($actual !== $expectedValue) {
                    $warnings[] = "Source reference {$table}.{$metric} is {$actual}; expected {$expectedValue}.";
                }
            }
        }

        if (isset($sourceTableSet['tasks'])) {
            foreach (['In Progress' => 8, 'Open' => 6, 'Completed' => 1] as $status => $expected) {
                $actual = (int) $source->table('tasks')->where('status', $status)->count();

                if ($actual !== $expected) {
                    $warnings[] = "Source reference task status {$status} count is {$actual}; expected {$expected}.";
                }
            }

            if ($source->table('tasks')->whereIn('id', [7, 8, 9])->exists()) {
                $warnings[] = 'Source reference requires task ids 7, 8, and 9 to be absent.';
            }
        }

        if (isset($sourceTableSet['users'])) {
            $roles = [
                'admin' => (int) $source->table('users')->where('role', 'admin')->count(),
                'member' => (int) $source->table('users')->where('role', 'member')->count(),
            ];

            if ($roles !== ['admin' => 1, 'member' => 3]) {
                $warnings[] = 'Source reference user roles must contain one admin and three members.';
            }

            $bcryptCount = 0;
            $nonLowercaseEmails = 0;

            foreach ($source->table('users')->select(['email', 'password'])->cursor() as $user) {
                if (password_get_info((string) $user->password)['algoName'] === 'bcrypt') {
                    $bcryptCount++;
                }

                if (Converter::normalizeEmail((string) $user->email) !== $user->email) {
                    $nonLowercaseEmails++;
                }
            }

            if ($bcryptCount !== 4) {
                $warnings[] = "Source reference bcrypt password hash count is {$bcryptCount}; expected 4.";
            }

            if ($nonLowercaseEmails !== 0) {
                $warnings[] = 'Source reference contains email addresses that are not already lowercase and trimmed.';
            }

            $userSettings = $source->table('users')
                ->select(['id', 'settings'])
                ->orderBy('id')
                ->get()
                ->map(static fn (object $user): array => [
                    'id' => (int) $user->id,
                    'settings' => $user->settings,
                ])
                ->all();

            if (! Verifier::hasExpectedUserSettings($userSettings)) {
                $warnings[] = 'Source reference expects only user id 3 to have non-NULL users.settings JSON with the single key "appearance".';
            }
        }

        return $warnings;
    }

    /**
     * @param  array<string, bool>  $sourceTableSet
     * @param  array<string, bool>  $targetTableSet
     * @return list<string>
     */
    private function migrationDifferences(
        Connection $source,
        Connection $target,
        array $sourceTableSet,
        array $targetTableSet,
    ): array {
        if (! isset($sourceTableSet['migrations'], $targetTableSet['migrations'])) {
            return [];
        }

        $sourceNames = $source->table('migrations')->pluck('migration')->all();
        $targetNames = $target->table('migrations')->pluck('migration')->all();

        return array_values(array_diff($sourceNames, $targetNames));
    }

    /**
     * @param  array<string, array<string, array<string, mixed>>>  $targetColumns
     * @return array<string, string>
     */
    private function sequences(Connection $target, string $schema, array $targetColumns): array
    {
        $sequences = [];

        foreach (self::COPY_TABLES as $table) {
            $idType = $targetColumns[$table]['id']['data_type'] ?? null;

            if (! in_array($idType, ['smallint', 'integer', 'bigint'], true)) {
                continue;
            }

            $sequence = $target->selectOne(
                "SELECT pg_get_serial_sequence(?, 'id') AS sequence_name",
                [$schema.'.'.$table],
            )->sequence_name;

            if ($sequence !== null) {
                $sequences[$table] = (string) $sequence;
            }
        }

        return $sequences;
    }

    private function truncateImportTables(Connection $target): void
    {
        $tables = array_map(
            fn (string $table): string => $target->getQueryGrammar()->wrapTable($table),
            self::COPY_TABLES,
        );

        $target->statement('TRUNCATE TABLE '.implode(', ', $tables).' RESTART IDENTITY CASCADE');
    }

    /**
     * Read MySQL rows through the query builder and insert bounded batches without model events.
     *
     * @param  array<string, array<string, mixed>>  $sourceColumns
     * @param  array<string, array<string, mixed>>  $targetColumns
     * @param  list<string>  $warnings
     */
    private function copyTable(
        Connection $source,
        Connection $target,
        string $table,
        array $sourceColumns,
        array $targetColumns,
        int $chunkSize,
        array &$warnings,
    ): void {
        $copyColumns = array_values(array_intersect(
            array_keys($sourceColumns),
            array_keys(array_filter(
                $targetColumns,
                static fn (array $metadata): bool => $metadata['is_generated'] === 'NEVER',
            )),
        ));
        $columnTypes = [];

        foreach ($copyColumns as $column) {
            $columnTypes[$column] = $targetColumns[$column]['data_type'];
        }

        $query = $source->table($table)->select($copyColumns);

        if (in_array('id', $copyColumns, true)) {
            $query->orderBy('id');
        } elseif ($table === 'task_assignees') {
            $query->orderBy('task_id')->orderBy('user_id');
        }

        $batch = [];

        foreach ($query->cursor() as $sourceRow) {
            $row = $this->converter->convertRow($table, (array) $sourceRow, $columnTypes, $warnings);
            $batch[] = $row;

            if (count($batch) >= $chunkSize) {
                $target->table($table)->insert($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $target->table($table)->insert($batch);
        }
    }

    /**
     * @param  array<string, mixed>  $plan
     * @param  list<string>  $warnings
     * @return array{tables: array<string, array<string, mixed>>, checks: list<array{check: string, table: ?string, passed: bool, details: string}>}
     */
    private function verifyData(Connection $source, Connection $target, array $plan, array &$warnings): array
    {
        $tableResults = [];
        $checks = [];

        foreach (self::COPY_TABLES as $table) {
            $sourceCount = (int) $source->table($table)->count();
            $targetCount = (int) $target->table($table)->count();
            $passed = $sourceCount === $targetCount;

            $tableResults[$table] = [
                'source_rows' => $sourceCount,
                'target_rows' => $targetCount,
                'status' => $passed ? 'OK' : 'FAIL',
            ];
            $checks[] = [
                'check' => "{$table} row count",
                'table' => $table,
                'passed' => $passed,
                'details' => "MySQL {$sourceCount}; PostgreSQL {$targetCount}.",
            ];

            if (isset($plan['target_columns'][$table]['id'])) {
                $idType = $plan['target_columns'][$table]['id']['data_type'];

                if (in_array($idType, ['smallint', 'integer', 'bigint'], true)) {
                    $sourceMetrics = $this->idMetrics($source, $table);
                    $targetMetrics = $this->idMetrics($target, $table);
                    $idMatches = $sourceMetrics === $targetMetrics;
                    $checks[] = [
                        'check' => "{$table} id sum and maximum",
                        'table' => $table,
                        'passed' => $idMatches,
                        'details' => $idMatches ? 'Matched.' : 'The id aggregates differ.',
                    ];

                    if (! $idMatches) {
                        $tableResults[$table]['status'] = 'FAIL';
                    }
                }
            }
        }

        $sourceAssigneeSum = (int) $source->table('task_assignees')
            ->selectRaw('COALESCE(SUM(task_id * 1000000 + user_id), 0) AS composite_sum')
            ->value('composite_sum');
        $targetAssigneeSum = (int) $target->table('task_assignees')
            ->selectRaw('COALESCE(SUM(task_id * 1000000 + user_id), 0) AS composite_sum')
            ->value('composite_sum');
        $assigneeMatches = $sourceAssigneeSum === $targetAssigneeSum;
        $checks[] = [
            'check' => 'task_assignees composite key checksum',
            'table' => 'task_assignees',
            'passed' => $assigneeMatches,
            'details' => $assigneeMatches ? 'Matched.' : 'The relationship checksum differs.',
        ];

        if (! $assigneeMatches) {
            $tableResults['task_assignees']['status'] = 'FAIL';
        }

        $sourceEmailDigest = Verifier::emailDigest(
            $source->table('users')->whereNotNull('email')->pluck('email')->map(
                static fn (string $email): string => Converter::normalizeEmail($email),
            )->all(),
        );
        $targetEmailDigest = Verifier::emailDigest(
            $target->table('users')->whereNotNull('email')->pluck('email')->map(
                static fn (string $email): string => Converter::normalizeEmail($email),
            )->all(),
        );
        $emailsMatch = hash_equals($sourceEmailDigest, $targetEmailDigest);
        $checks[] = [
            'check' => 'users normalized email checksum',
            'table' => 'users',
            'passed' => $emailsMatch,
            'details' => $emailsMatch ? 'Matched.' : 'The email checksums differ.',
        ];

        $sourcePasswordDigest = $this->passwordDigest($source);
        $targetPasswordDigest = $this->passwordDigest($target);
        $passwordsMatch = hash_equals($sourcePasswordDigest, $targetPasswordDigest);
        $checks[] = [
            'check' => 'users password hashes preserved',
            'table' => 'users',
            'passed' => $passwordsMatch,
            'details' => $passwordsMatch ? 'Matched without displaying hash values.' : 'Password hash checksums differ.',
        ];

        if (! $emailsMatch || ! $passwordsMatch) {
            $tableResults['users']['status'] = 'FAIL';
        }

        foreach ($plan['foreign_keys'] as $foreignKey) {
            $orphanCount = $this->orphanCount($target, $foreignKey);
            $passed = $orphanCount === 0;
            $checks[] = [
                'check' => $foreignKey['name'].' orphan rows',
                'table' => $foreignKey['table'],
                'passed' => $passed,
                'details' => "{$orphanCount} orphan row(s).",
            ];

            if (! $passed) {
                if (isset($tableResults[$foreignKey['table']])) {
                    $tableResults[$foreignKey['table']]['status'] = 'FAIL';
                }
            }
        }

        $completedWithInvalidProgress = (int) $source->table('tasks')
            ->where('status', 'Completed')
            ->where(function (Builder $query): void {
                $query->whereNull('progress')->orWhere('progress', '<>', 100);
            })
            ->count();
        $fullProgressWithOtherStatus = (int) $source->table('tasks')
            ->where('progress', 100)
            ->where(function (Builder $query): void {
                $query->whereNull('status')->orWhere('status', '<>', 'Completed');
            })
            ->count();

        if ($completedWithInvalidProgress > 0) {
            $warnings[] = "{$completedWithInvalidProgress} task(s) have status Completed and progress other than 100.";
        }

        if ($fullProgressWithOtherStatus > 0) {
            $warnings[] = "{$fullProgressWithOtherStatus} task(s) have progress 100 and status other than Completed.";
        }

        return ['tables' => $tableResults, 'checks' => $checks];
    }

    /**
     * @return array{max_id: int|null, sum_id: int}
     */
    private function idMetrics(Connection $connection, string $table): array
    {
        $aggregate = $connection->table($table)
            ->selectRaw('MAX(id) AS max_id, COALESCE(SUM(id), 0) AS sum_id')
            ->first();

        return [
            'max_id' => $aggregate->max_id === null ? null : (int) $aggregate->max_id,
            'sum_id' => (int) $aggregate->sum_id,
        ];
    }

    /**
     * A digest checks that hashes are byte-for-byte unchanged without ever printing them.
     */
    private function passwordDigest(Connection $connection): string
    {
        $passwords = [];

        foreach ($connection->table('users')->select(['id', 'password'])->orderBy('id')->cursor() as $user) {
            $passwords[] = $user->id.':'.$user->password;
        }

        return hash('sha256', implode("\n", $passwords));
    }

    /**
     * @param  array{table: string, referenced_table: string, name: string, columns: list<string>, referenced_columns: list<string>}  $foreignKey
     */
    private function orphanCount(Connection $target, array $foreignKey): int
    {
        $query = $target->table($foreignKey['table'].' as child')
            ->leftJoin($foreignKey['referenced_table'].' as parent', function (JoinClause $join) use ($foreignKey): void {
                foreach ($foreignKey['columns'] as $index => $column) {
                    $join->on('child.'.$column, '=', 'parent.'.$foreignKey['referenced_columns'][$index]);
                }
            });

        foreach ($foreignKey['columns'] as $column) {
            $query->whereNotNull('child.'.$column);
        }

        return (int) $query->whereNull('parent.'.$foreignKey['referenced_columns'][0])->count();
    }

    /**
     * Set sequences only after row and foreign-key verification succeeds. PostgreSQL sequence
     * updates are not rolled back with a transaction, so dry-run validates the planned state only.
     *
     * @param  array<string, mixed>  $plan
     * @return list<array{check: string, table: ?string, passed: bool, details: string}>
     */
    private function resetAndVerifySequences(Connection $target, array $plan, bool $dryRun): array
    {
        $checks = [];

        foreach ($plan['sequences'] as $table => $sequence) {
            $maxId = $target->table($table)->max('id');
            $expected = Verifier::sequenceState($maxId === null ? null : (int) $maxId);

            if (! $dryRun) {
                $target->select(
                    'SELECT setval(?::regclass, ?, ?)',
                    [$sequence, $expected['value'], $expected['is_called']],
                );
            }

            if ($dryRun) {
                $actual = $expected;
            } else {
                $sequenceTable = $target->getQueryGrammar()->wrapTable($sequence);
                $state = $target->selectOne("SELECT last_value, is_called FROM {$sequenceTable}");
                $actual = [
                    'value' => (int) $state->last_value,
                    'is_called' => (bool) $state->is_called,
                ];
            }

            $passed = $maxId === null
                ? $actual === ['value' => 1, 'is_called' => false]
                : $actual['value'] >= (int) $maxId;
            $checks[] = [
                'check' => "{$table} id sequence",
                'table' => $table,
                'passed' => $passed,
                'details' => $dryRun
                    ? 'Planned sequence state is valid.'
                    : ($passed ? 'Sequence is at or above the maximum id.' : 'Sequence is below the maximum id.'),
            ];
        }

        return $checks;
    }

    private function rollback(Connection $target): void
    {
        if ($target->transactionLevel() > 0) {
            $target->rollBack();
        }
    }
}
