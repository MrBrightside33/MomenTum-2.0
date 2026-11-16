<?php

namespace App\Services;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskActivityNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DueTaskReminderService
{
    public function localNow(User $user): Carbon
    {
        $timezone = $user->settings['timezone'] ?? config('app.timezone');
        $supportedTimezones = array_keys(config('settings.timezones', []));

        if (! in_array($timezone, $supportedTimezones, true)) {
            Log::warning('A due reminder user has an unsupported timezone preference.', [
                'user_id' => $user->getKey(),
                'timezone' => $timezone,
            ]);
            $timezone = config('app.timezone');
        }

        return now($timezone);
    }

    public function sendDueReminders(User $user, Carbon $localNow, bool $includeTomorrow = false): int
    {
        if (($user->settings['notifications']['due_date_reminders'] ?? true) !== true) {
            return 0;
        }

        $today = $localNow->toDateString();
        $dueDates = [$today];

        if ($includeTomorrow) {
            $dueDates[] = $localNow->copy()->addDay()->toDateString();
        }

        $tasks = Task::query()
            ->where(function (Builder $query) use ($dueDates): void {
                foreach ($dueDates as $dueDate) {
                    $query->orWhereDate('due_date', $dueDate);
                }
            })
            ->where('status', '!=', TaskStatus::Completed->value)
            ->where(function (Builder $query) use ($user): void {
                $query->where('created_by_id', $user->getKey())
                    ->orWhereHas('project', fn (Builder $projectQuery): Builder => $projectQuery->where('owner_id', $user->getKey()))
                    ->orWhereHas('assignees', fn (Builder $assigneeQuery): Builder => $assigneeQuery->whereKey($user->getKey()));
            })
            ->with('project')
            ->get();

        $sentCount = 0;

        foreach ($tasks as $task) {
            $reminderDate = $task->due_date->toDateString();
            $dedupeKey = 'due-reminder:'.$task->getKey().':'.$user->getKey().':'.$today;
            $reminderLabel = $reminderDate === $today ? 'today' : 'tomorrow';

            $wasCreated = DB::transaction(function () use ($user, $task, $reminderDate, $reminderLabel, $dedupeKey): bool {
                $inserted = DB::table('task_activities')->insertOrIgnore([
                    'task_id' => $task->getKey(),
                    'user_id' => $user->getKey(),
                    'event_type' => 'due_reminder',
                    'metadata' => json_encode(['reminder_date' => $reminderDate], JSON_THROW_ON_ERROR),
                    'dedupe_key' => $dedupeKey,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted === 0) {
                    return false;
                }

                $user->notify(new TaskActivityNotification('due_reminder', $task, reminderLabel: $reminderLabel));

                return true;
            });

            $sentCount += (int) $wasCreated;
        }

        return $sentCount;
    }
}
