<?php

namespace App\Support\MysqlImport;

use JsonException;
use RuntimeException;

class Verifier
{
    /**
     * Known source counts keep this one-time import tied to the intended backup.
     *
     * @var array<string, int>
     */
    public const EXPECTED_ROWS = [
        'users' => 4,
        'projects' => 8,
        'tasks' => 15,
        'task_assignees' => 24,
        'task_activities' => 51,
        'task_comments' => 0,
        'calendar_events' => 2,
        'notifications' => 13,
        'audit_logs' => 155,
    ];

    /**
     * Return a stable parent-before-child ordering; the input table list breaks ties.
     *
     * @param  list<string>  $tables
     * @param  list<array{table: string, referenced_table: string}>  $foreignKeys
     * @return list<string>
     */
    public function topologicalOrder(array $tables, array $foreignKeys): array
    {
        $priority = array_flip($tables);
        $dependencies = array_fill_keys($tables, []);

        foreach ($foreignKeys as $foreignKey) {
            $child = $foreignKey['table'];
            $parent = $foreignKey['referenced_table'];

            if (isset($dependencies[$child], $dependencies[$parent])) {
                $dependencies[$child][$parent] = true;
            }
        }

        $ordered = [];

        while (count($ordered) < count($tables)) {
            $ready = [];

            foreach ($dependencies as $table => $parents) {
                if ($parents === [] && ! in_array($table, $ordered, true)) {
                    $ready[] = $table;
                }
            }

            if ($ready === []) {
                throw new RuntimeException('The PostgreSQL foreign keys contain a dependency cycle.');
            }

            usort($ready, static fn (string $left, string $right): int => $priority[$left] <=> $priority[$right]);
            $next = $ready[0];
            $ordered[] = $next;
            unset($dependencies[$next]);

            foreach ($dependencies as &$parents) {
                unset($parents[$next]);
            }
            unset($parents);
        }

        return $ordered;
    }

    /**
     * PostgreSQL sequences are one-based; an empty table is reset without marking the value used.
     *
     * @return array{value: int, is_called: bool}
     */
    public static function sequenceState(?int $maxId): array
    {
        if ($maxId === null) {
            return ['value' => 1, 'is_called' => false];
        }

        return ['value' => $maxId, 'is_called' => true];
    }

    /**
     * @param  list<string>  $emails
     */
    public static function emailDigest(array $emails): string
    {
        $emails = array_map(
            static fn (string $email): string => mb_strtolower($email, 'UTF-8'),
            $emails,
        );
        sort($emails, SORT_STRING);

        return md5(implode("\n", $emails));
    }

    /**
     * The reference backup has one settings object: user 3's object with only an appearance key.
     *
     * @param  list<array{id: int, settings: mixed}>  $users
     */
    public static function hasExpectedUserSettings(array $users): bool
    {
        $userThreeRows = 0;
        $userThreeSettings = null;

        foreach ($users as $user) {
            if ($user['id'] === 3) {
                $userThreeRows++;
                $userThreeSettings = $user['settings'];

                continue;
            }

            if ($user['settings'] !== null) {
                return false;
            }
        }

        if ($userThreeRows !== 1 || ! is_string($userThreeSettings)) {
            return false;
        }

        try {
            $decodedSettings = json_decode($userThreeSettings, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return false;
        }

        return is_object($decodedSettings)
            && array_keys(get_object_vars($decodedSettings)) === ['appearance'];
    }
}
