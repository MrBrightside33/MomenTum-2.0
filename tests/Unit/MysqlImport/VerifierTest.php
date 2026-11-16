<?php

namespace Tests\Unit\MysqlImport;

use App\Support\MysqlImport\Verifier;
use PHPUnit\Framework\TestCase;

class VerifierTest extends TestCase
{
    public function test_tables_are_ordered_by_postgres_parent_dependencies(): void
    {
        $tables = [
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
        $foreignKeys = [
            ['table' => 'projects', 'referenced_table' => 'users'],
            ['table' => 'tasks', 'referenced_table' => 'projects'],
            ['table' => 'tasks', 'referenced_table' => 'users'],
            ['table' => 'task_assignees', 'referenced_table' => 'tasks'],
            ['table' => 'task_activities', 'referenced_table' => 'tasks'],
            ['table' => 'task_comments', 'referenced_table' => 'tasks'],
            ['table' => 'calendar_events', 'referenced_table' => 'users'],
            ['table' => 'audit_logs', 'referenced_table' => 'users'],
        ];

        $this->assertSame($tables, (new Verifier)->topologicalOrder($tables, $foreignKeys));
    }

    public function test_empty_sequence_restarts_at_one_without_marking_it_called(): void
    {
        $this->assertSame(['value' => 1, 'is_called' => false], Verifier::sequenceState(null));
    }

    public function test_populated_sequence_uses_maximum_id_not_row_count(): void
    {
        $this->assertSame(['value' => 18, 'is_called' => true], Verifier::sequenceState(18));
    }

    public function test_email_digest_is_independent_of_row_order_and_case(): void
    {
        $this->assertSame(
            Verifier::emailDigest(['Person@example.com', 'other@example.com']),
            Verifier::emailDigest(['OTHER@example.com', 'person@EXAMPLE.com']),
        );
    }

    public function test_expected_appearance_setting_for_user_three_is_accepted(): void
    {
        $users = [
            ['id' => 1, 'settings' => null],
            ['id' => 2, 'settings' => null],
            ['id' => 3, 'settings' => '{"appearance":"dark"}'],
            ['id' => 4, 'settings' => null],
        ];

        $this->assertSame(true, Verifier::hasExpectedUserSettings($users));
    }

    public function test_settings_on_another_user_are_rejected(): void
    {
        $users = [
            ['id' => 1, 'settings' => '{"appearance":"dark"}'],
            ['id' => 2, 'settings' => null],
            ['id' => 3, 'settings' => '{"appearance":"dark"}'],
        ];

        $this->assertSame(false, Verifier::hasExpectedUserSettings($users));
    }

    public function test_invalid_settings_json_is_rejected(): void
    {
        $users = [
            ['id' => 3, 'settings' => '{"appearance":'],
        ];

        $this->assertSame(false, Verifier::hasExpectedUserSettings($users));
    }

    public function test_settings_with_additional_keys_are_rejected(): void
    {
        $users = [
            ['id' => 3, 'settings' => '{"appearance":"dark","theme":"light"}'],
        ];

        $this->assertSame(false, Verifier::hasExpectedUserSettings($users));
    }

    public function test_user_three_must_have_a_non_null_settings_value(): void
    {
        $users = [
            ['id' => 3, 'settings' => null],
        ];

        $this->assertSame(false, Verifier::hasExpectedUserSettings($users));
    }
}
