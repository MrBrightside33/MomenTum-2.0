<?php

namespace Tests\Unit\MysqlImport;

use App\Support\MysqlImport\Converter;
use PHPUnit\Framework\TestCase;

class ConverterTest extends TestCase
{
    public function test_boolean_values_convert_from_mysql_zero_and_one(): void
    {
        $this->assertSame(false, Converter::booleanValue('0'));
        $this->assertSame(true, Converter::booleanValue(1));
        $this->assertSame('true', Converter::booleanValue('true'));
    }

    public function test_valid_json_stays_a_valid_json_string(): void
    {
        $this->assertSame(['value' => '{"enabled":true}', 'valid' => true], Converter::jsonValue('{"enabled":true}'));
    }

    public function test_invalid_json_is_reported_as_invalid(): void
    {
        $this->assertSame(['value' => null, 'valid' => false], Converter::jsonValue('{invalid'));
    }

    public function test_invalid_json_warning_names_only_table_id_and_column(): void
    {
        $warnings = [];
        $converted = (new Converter)->convertRow('audit_logs', [
            'id' => 12,
            'metadata' => '{invalid}',
        ], [
            'id' => 'bigint',
            'metadata' => 'jsonb',
        ], $warnings);

        $this->assertSame(null, $converted['metadata']);
        $this->assertSame([
            'audit_logs.12.metadata contains invalid JSON; stored as NULL.',
        ], $warnings);
    }

    public function test_null_json_remains_null_without_becoming_a_warning(): void
    {
        $this->assertSame(['value' => null, 'valid' => true], Converter::jsonValue(null));
    }

    public function test_empty_json_array_is_preserved(): void
    {
        $this->assertSame(['value' => '[]', 'valid' => true], Converter::jsonValue([]));
    }

    public function test_strings_have_nul_bytes_removed_and_are_valid_utf8(): void
    {
        $converted = Converter::cleanString("Momen\0Tum \xC3(");

        $this->assertStringNotContainsString("\0", $converted);
        $this->assertTrue(mb_check_encoding($converted, 'UTF-8'));
    }

    public function test_zero_date_becomes_null_and_regular_dates_stay_unchanged(): void
    {
        $warnings = [];
        $converter = new Converter;

        $converted = $converter->convertRow('tasks', [
            'id' => 1,
            'due_date' => '0000-00-00',
            'completed_at' => '2026-01-02 03:04:05',
            'description' => '0000-00-00',
        ], [
            'id' => 'bigint',
            'due_date' => 'date',
            'completed_at' => 'timestamp without time zone',
            'description' => 'text',
        ], $warnings);

        $this->assertSame(null, $converted['due_date']);
        $this->assertSame('2026-01-02 03:04:05', $converted['completed_at']);
        $this->assertSame('0000-00-00', $converted['description']);
        $this->assertSame([], $warnings);
    }

    public function test_user_emails_are_trimmed_and_lowercased(): void
    {
        $this->assertSame('person@example.com', Converter::normalizeEmail('  Person@Example.com '));
    }

    public function test_email_normalization_reports_colliding_ids_without_exposing_emails(): void
    {
        $collisions = Converter::collidingEmailIds([
            ['id' => 5, 'email' => 'Person@example.com'],
            ['id' => 2, 'email' => ' person@example.com '],
            ['id' => 8, 'email' => 'other@example.com'],
        ]);

        $this->assertSame([[2, 5]], $collisions);
    }
}
