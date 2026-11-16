# One-time MySQL to PostgreSQL import

## Architecture and safety

The importer reads the SQL dump through a throwaway MySQL 8 container, compares its
schema and known backup fingerprints with the existing PostgreSQL schema, and then
copies only the allow-listed application tables into PostgreSQL:

`mysqldump -> temporary MySQL (tmpfs) -> db:import-mysql -> PostgreSQL`

The MySQL container is available only through the `import` profile and stores its
database files in memory. The dump is mounted read-only and `storage/import/*` is
ignored by Git because it can contain email addresses and password hashes.

The command uses the query builder, preserves primary keys and timestamp values,
and does not invoke Eloquent models, observers, application services, or events.
It compares MySQL and PostgreSQL before copying, checks row and key aggregates,
foreign-key orphans, normalized email and password-hash fingerprints, and
sequences before committing. Any failed verification rolls back the PostgreSQL
transaction. The pre-flight check also refuses unknown populated tables and
unmapped non-NULL columns unless `--allow-missing-columns` is supplied.

`--fresh` is deliberately destructive: it truncates only the import tables, with
identity restart and cascade, inside the import transaction. The pre-flight check
refuses fresh mode if PostgreSQL would cascade into a table outside the import
allow-list. Do not run it against data you need to keep.

PostgreSQL sequence updates are not transactional. A dry run therefore checks the
sequence state it would set, but does not call `setval`; this keeps
`DRY RUN: nothing was saved` true even for sequence state.

## Commands, in order

1. Copy your dump to `storage/import/backup-mysql.sql`. In PowerShell, replace the
   source path below with the actual dump path:

   ```powershell
   Copy-Item -LiteralPath 'C:\path\to\backup-mysql.sql' -Destination 'storage\import\backup-mysql.sql'
   ```

2. Start the disposable MySQL source:

   ```powershell
   docker compose --profile import up -d mysql-import
   ```

3. Wait until its health is `healthy`:

   ```powershell
   docker compose ps mysql-import
   ```

4. Run the complete validation and copy in a rollback-only transaction:

   ```powershell
   docker compose exec app php artisan db:import-mysql --dry-run
   ```

5. After reviewing the output, replace the PostgreSQL import tables and commit:

   ```powershell
   docker compose exec app php artisan db:import-mysql --fresh --yes
   ```

6. Stop and remove the temporary MySQL container:

   ```powershell
   docker compose --profile import down
   ```

The PostgreSQL default connection must be `pgsql`. For a normal import into empty
tables, omit `--fresh`; use `--yes` only when intentionally skipping the fresh
mode confirmation. `--chunk=500` is the default, and
`--allow-missing-columns` is an explicit opt-in that warns and skips non-NULL
source columns absent from PostgreSQL.

The documented dry run without `--fresh` requires empty PostgreSQL import
tables, just like a real non-fresh import. To preview the fresh replacement
workflow on a non-empty target, use
`docker compose exec app php artisan db:import-mysql --dry-run --fresh --yes`;
that preview also rolls back.

## Reference backup facts

| Table | Expected MySQL rows |
| --- | ---: |
| users | 4 |
| projects | 8 |
| tasks | 15 |
| task_assignees | 24 |
| task_activities | 51 |
| task_comments | 0 |
| calendar_events | 2 |
| notifications | 13 |
| audit_logs | 155 |

The source fingerprint also expects max IDs of users 4, projects 8, tasks 18,
task_activities 59, calendar_events 2, and audit_logs 155. Task IDs 7, 8, and 9
are absent; task IDs sum to 147. Task activity IDs sum to 1570, and audit log IDs
sum to 12090. Task statuses are In Progress 8, Open 6, and Completed 1.

The users table contains one admin and three members, four bcrypt password
hashes, and lowercase email addresses. The JSON columns are
`audit_logs.metadata`, `task_activities.metadata`, and `users.settings`. Exactly
one `users.settings` value is expected to be non-NULL: user id 3 must have valid
JSON with only the `appearance` key; all other users' settings must be NULL.
Password hash values are never displayed.

The command skips `migrations`, `sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs`, and `password_reset_tokens`. It reports each
skipped table's MySQL row count.

## Troubleshooting

| Symptom | Action |
| --- | --- |
| MySQL source connection fails | Start it with `docker compose --profile import up -d mysql-import` and wait for `healthy`. Confirm the dump exists at `storage/import/backup-mysql.sql`. |
| Source reference count or aggregate differs | Review the reference warning and confirm this is the intended dump before proceeding; source-to-target verification remains mandatory. |
| Populated MySQL table is absent from PostgreSQL | Review the table list and schema. Do not add a migration as part of this import; resolve the schema mismatch separately. |
| A MySQL column has non-NULL data but is absent from PostgreSQL | Resolve the mismatch, or use `--allow-missing-columns` only if discarding that column's values is acceptable. |
| PostgreSQL has non-empty import tables | Use `--fresh` only after confirming those rows can be replaced; the command asks for confirmation unless `--yes` is used. |
| Migration names differ | The command prints a warning; review the migration history because the schema may differ. |
| Invalid JSON or task progress inconsistency | Review the warning. Invalid JSON becomes NULL; task status/progress warnings do not stop the import. |
| Import fails during verification | The PostgreSQL data transaction is rolled back. Correct the reported mismatch and rerun the dry run. |
| PostgreSQL role cannot reset a sequence | Run with a role that owns or can update the target sequences, then repeat validation. |
