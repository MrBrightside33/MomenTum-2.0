<?php

namespace App\Support\MysqlImport;

class Converter
{
    /**
     * Convert values using the destination column types, without changing unrelated values.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, string>  $columnTypes
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    public function convertRow(string $table, array $row, array $columnTypes, array &$warnings): array
    {
        foreach ($row as $column => $value) {
            $type = strtolower($columnTypes[$column] ?? '');

            if (is_string($value)) {
                $value = self::cleanString($value);

                $isTemporal = str_starts_with($type, 'date')
                    || str_starts_with($type, 'time')
                    || str_starts_with($type, 'timestamp');

                if ($isTemporal && preg_match('/^0000-00-00(?:[ T].*)?$/', $value) === 1) {
                    $value = null;
                }
            }

            if ($type === 'boolean' && $value !== null) {
                $value = self::booleanValue($value);
            }

            if (in_array($type, ['json', 'jsonb'], true)) {
                $conversion = self::jsonValue($value);

                if (! $conversion['valid']) {
                    $rowId = $row['id'] ?? 'unknown';
                    $warnings[] = "{$table}.{$rowId}.{$column} contains invalid JSON; stored as NULL.";
                }

                $value = $conversion['value'];
            }

            if ($table === 'users' && $column === 'email' && $value !== null) {
                $value = self::normalizeEmail((string) $value);
            }

            $row[$column] = $value;
        }

        return $row;
    }

    public static function booleanValue(mixed $value): mixed
    {
        if ($value === 0 || $value === '0' || $value === false) {
            return false;
        }

        if ($value === 1 || $value === '1' || $value === true) {
            return true;
        }

        return $value;
    }

    /**
     * @return array{value: ?string, valid: bool}
     */
    public static function jsonValue(mixed $value): array
    {
        if ($value === null) {
            return ['value' => null, 'valid' => true];
        }

        if (is_string($value)) {
            $json = self::cleanString($value);
        } else {
            try {
                $json = json_encode($value, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return ['value' => null, 'valid' => false];
            }
        }

        json_decode($json);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return ['value' => null, 'valid' => false];
        }

        return ['value' => $json, 'valid' => true];
    }

    public static function cleanString(string $value): string
    {
        $value = str_replace("\0", '', $value);

        if (! mb_check_encoding($value, 'UTF-8')) {
            $value = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return $value;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim(self::cleanString($email)), 'UTF-8');
    }

    /**
     * Return only the colliding ids so an error never exposes email addresses.
     *
     * @param  list<array{id: int|string, email: ?string}>  $users
     * @return list<list<int|string>>
     */
    public static function collidingEmailIds(array $users): array
    {
        $idsByEmail = [];

        foreach ($users as $user) {
            if ($user['email'] === null) {
                continue;
            }

            $idsByEmail[self::normalizeEmail($user['email'])][] = $user['id'];
        }

        $collisions = [];

        foreach ($idsByEmail as $ids) {
            if (count($ids) > 1) {
                sort($ids);
                $collisions[] = $ids;
            }
        }

        return $collisions;
    }
}
