<?php

namespace App\Http\Controllers;

use App\Enums\TaskStatus;
use App\Http\Requests\StoreCalendarEventRequest;
use App\Models\CalendarEvent;
use App\Models\Task;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class CalendarEventController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $timezone = $user->settings['timezone'] ?? config('app.timezone');

        if (! in_array($timezone, array_keys(config('settings.timezones', [])), true)) {
            $timezone = config('app.timezone');
        }

        $today = today($timezone);
        $tasks = Task::query()
            ->visibleTo($user)
            ->with('project:id,name')
            ->orderBy('due_date')
            ->get();
        $calendarTasks = $tasks->map(function (Task $task) use ($today, $timezone): array {
            $dueDate = Carbon::parse($task->due_date->toDateString(), $timezone)->startOfDay();
            $daysUntil = (int) $today->copy()->startOfDay()->diffInDays($dueDate, false);
            $isCompleted = $task->status === TaskStatus::Completed;
            $dueLabel = match (true) {
                $daysUntil < 0 => 'Overdue by '.abs($daysUntil).' day'.(abs($daysUntil) === 1 ? '' : 's'),
                $daysUntil === 0 => 'Due today',
                $daysUntil === 1 => 'Due tomorrow',
                default => 'Due in '.$daysUntil.' days',
            };

            return [
                'id' => (string) $task->getKey(),
                'title' => $task->title,
                'date' => $dueDate->toDateString(),
                'priority' => $task->priority->value,
                'category' => $task->project->name,
                'completed' => $isCompleted,
                'days_until' => $daysUntil,
                'due_label' => $dueLabel,
                'url' => route('dashboard').'#task-row-'.$task->getKey(),
            ];
        });
        $deadlineAlerts = $calendarTasks
            ->filter(fn (array $task): bool => ! $task['completed'] && $task['days_until'] <= 1)
            ->values();
        $upcomingDeadlines = $calendarTasks
            ->filter(fn (array $task): bool => ! $task['completed'] && $task['days_until'] >= 0 && $task['days_until'] <= 7)
            ->values();
        $dueTodayCount = $calendarTasks
            ->filter(fn (array $task): bool => ! $task['completed'] && $task['days_until'] === 0)
            ->count();
        $dueTomorrowCount = $calendarTasks
            ->filter(fn (array $task): bool => ! $task['completed'] && $task['days_until'] === 1)
            ->count();
        $overdueCount = $calendarTasks
            ->filter(fn (array $task): bool => ! $task['completed'] && $task['days_until'] < 0)
            ->count();
        $scheduleEvents = $user->calendarEvents()
            ->orderBy('date')
            ->orderBy('time')
            ->get()
            ->map(fn (CalendarEvent $event): array => [
                'id' => (string) $event->getKey(),
                'title' => $event->title,
                'date' => $event->date->toDateString(),
                'time' => $event->time ?? '',
            ])
            ->values();

        return view('calendar', [
            'calendarTasks' => $calendarTasks->values(),
            'scheduleEvents' => $scheduleEvents,
            'deadlineAlerts' => $deadlineAlerts,
            'upcomingDeadlines' => $upcomingDeadlines,
            'dueTodayCount' => $dueTodayCount,
            'dueTomorrowCount' => $dueTomorrowCount,
            'overdueCount' => $overdueCount,
            'timezone' => $timezone,
            'today' => $today->toDateString(),
            'todayLabel' => $today->format('l, F j, Y'),
            'monthLabel' => $today->format('F Y'),
        ]);
    }

    public function store(StoreCalendarEventRequest $request): JsonResponse|RedirectResponse
    {
        $event = $request->user()->calendarEvents()->create($request->validated());
        $payload = [
            'id' => (string) $event->getKey(),
            'title' => $event->title,
            'date' => $event->date->toDateString(),
            'time' => $event->time ?? '',
        ];

        if (! $request->expectsJson()) {
            return redirect()->route('calendar')->with('status', 'Schedule saved');
        }

        return response()->json([
            'message' => 'Schedule saved',
            'event' => $payload,
        ], 201);
    }

    public function destroy(Request $request, CalendarEvent $calendarEvent): JsonResponse|RedirectResponse
    {
        $event = $request->user()->calendarEvents()->whereKey($calendarEvent->getKey())->firstOrFail();
        $event->delete();

        if (! $request->expectsJson()) {
            return redirect()->route('calendar')->with('status', 'Schedule removed');
        }

        return response()->json([
            'message' => 'Schedule removed',
            'id' => (string) $event->getKey(),
        ]);
    }
}
