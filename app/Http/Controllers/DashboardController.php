<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Support\Metrics\TaskMetrics;
use App\Support\TaskPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __invoke(Request $request, TaskPresenter $presenter, TaskMetrics $metrics): View|RedirectResponse
    {
        $user = $request->user();

        if ($user->isAdmin()) {
            return redirect()->route('admin.overview');
        }

        $tasks = Task::query()
            ->visibleTo($user)
            ->with(['project:id,name', 'assignees:id,name'])
            ->orderBy('due_date')
            ->orderByDesc('id')
            ->get();

        $timezone = $user->settings['timezone'] ?? config('app.timezone');

        if (! in_array($timezone, array_keys(config('settings.timezones', [])), true)) {
            $timezone = config('app.timezone');
        }

        $localNow = now($timezone);
        $taskScope = ['today' => $localNow->toDateString()];

        if (! $user->isManager()) {
            $taskScope['user_id'] = $user->getKey();
        }

        $taskCounts = $metrics->counts($taskScope);
        $weekStart = $localNow->copy()->startOfWeek();
        $weekEnd = $weekStart->copy()->endOfWeek();
        $completedThisWeek = $metrics->completedActivity([
            'user_id' => $user->getKey(),
            'from' => $weekStart->copy()->utc()->toDateTimeString(),
            'to' => $weekEnd->copy()->utc()->toDateTimeString(),
        ])['completed'];
        $weekActivities = TaskActivity::query()
            ->where('user_id', $user->getKey())
            ->where('to_status', TaskStatus::Completed->value)
            ->whereBetween('created_at', [
                $weekStart->copy()->utc(),
                $weekEnd->copy()->utc(),
            ])
            ->get(['created_at'])
            ->groupBy(fn (TaskActivity $activity): string => $activity->created_at->setTimezone($timezone)->toDateString());
        $maximumDailyCount = max(1, $weekActivities->map->count()->max() ?? 0);

        $week = collect(range(0, 6))->map(function (int $offset) use ($weekStart, $weekActivities, $maximumDailyCount, $localNow): array {
            $date = $weekStart->copy()->addDays($offset);
            $count = $weekActivities->get($date->toDateString(), collect())->count();

            return [
                'day' => mb_substr($date->format('D'), 0, 1),
                'value' => $count === 0 ? 8 : max(8, (int) round(($count / $maximumDailyCount) * 100)),
                'count' => $count,
                'highlight' => $date->isSameDay($localNow),
            ];
        });

        $localMonthStart = $localNow->copy()->subDays(30)->startOfDay();
        $activityDates = TaskActivity::query()
            ->where('user_id', $user->getKey())
            ->where('to_status', TaskStatus::Completed->value)
            ->whereBetween('created_at', [$localMonthStart->copy()->utc(), $localNow->copy()->utc()])
            ->get(['created_at'])
            ->map(fn (TaskActivity $activity): string => $activity->created_at->setTimezone($timezone)->toDateString())
            ->unique()
            ->flip();
        $today = $localNow->copy()->startOfDay();
        $streakCursor = $activityDates->has($today->toDateString())
            ? $today
            : $today->copy()->subDay();
        $streakDays = 0;

        while ($streakCursor !== null && $activityDates->has($streakCursor->toDateString())) {
            $streakDays++;
            $streakCursor->subDay();
        }

        $stats = [
            [
                'key' => 'total',
                'label' => 'Total tasks',
                'value' => $taskCounts['total'],
                'note' => $taskCounts['open'].' open',
                'icon' => 'list',
                'bg' => 'bg-indigo-50',
                'fg' => 'text-indigo-600',
            ],
            [
                'key' => 'in_progress',
                'label' => 'In progress',
                'value' => $taskCounts['in_progress'],
                'note' => $taskCounts['due_today'].' due today',
                'icon' => 'clock',
                'bg' => 'bg-amber-50',
                'fg' => 'text-amber-600',
            ],
            [
                'key' => 'completed',
                'label' => 'Completed',
                'value' => $taskCounts['completed'],
                'note' => $taskCounts['completed'].' completed',
                'icon' => 'check',
                'bg' => 'bg-emerald-50',
                'fg' => 'text-emerald-600',
            ],
            [
                'key' => 'productivity',
                'label' => 'Productivity',
                'value' => $taskCounts['completion_rate'].'%',
                'note' => $taskCounts['completed'].' of '.$taskCounts['total'].' tasks completed',
                'icon' => 'chart-line',
                'bg' => 'bg-violet-50',
                'fg' => 'text-violet-600',
            ],
        ];

        return view('dashboard', [
            'user' => $user,
            'stats' => $stats,
            'tasks' => $tasks->map(fn (Task $task): array => $presenter->present($task, $user)),
            'week' => $week,
            'weekTitle' => $weekStart->format('M j').' – '.$weekEnd->format('M j'),
            'completedThisWeek' => $completedThisWeek,
            'streakDays' => $streakDays,
            'streakProgress' => min(100, (int) round(($streakDays / 7) * 100)),
            'streakTitle' => $streakDays > 0 ? "You’re on a {$streakDays}-day streak" : 'Start a new streak today',
            'streakCopy' => $streakDays > 0
                ? 'Keep the momentum going. Complete one more task today.'
                : 'Complete a task today to begin building your streak.',
        ]);
    }
}
