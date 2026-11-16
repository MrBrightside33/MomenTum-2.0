<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\DueTaskReminderService;
use App\Support\TaskPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class InboxController extends Controller
{
    public function index(Request $request, DueTaskReminderService $reminderService): View
    {
        $user = $request->user();
        $reminderService->sendDueReminders($user, $reminderService->localNow($user));
        $notifications = $user->notifications()->latest()->paginate(25);
        $inboxGroups = $notifications->getCollection()
            ->map(fn (DatabaseNotification $notification): array => $this->present($notification))
            ->groupBy('group');
        $inboxCounts = $this->counts($user);

        return view('inbox', compact('inboxGroups', 'inboxCounts', 'notifications'));
    }

    public function toggleRead(Request $request, string $notification): JsonResponse
    {
        $validated = $request->validate([
            'read' => ['required', 'boolean'],
        ]);
        $item = $this->findNotification($request->user(), $notification);
        $item->forceFill([
            'read_at' => $request->boolean('read') ? now() : null,
        ])->save();

        return response()->json([
            'message' => $request->boolean('read')
                ? 'Notification marked as read.'
                : 'Notification marked as unread.',
            'counts' => $this->counts($request->user()),
        ]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $request->user()
            ->unreadNotifications()
            ->whereNull('archived_at')
            ->update(['read_at' => now()]);

        return response()->json([
            'message' => 'All inbox notifications marked as read.',
            'counts' => $this->counts($request->user()),
        ]);
    }

    public function archive(Request $request, string $notification): JsonResponse
    {
        $item = $this->findNotification($request->user(), $notification);
        $item->forceFill(['archived_at' => now()])->save();

        return response()->json([
            'message' => 'Notification archived.',
            'counts' => $this->counts($request->user()),
        ]);
    }

    public function restore(Request $request, string $notification): JsonResponse
    {
        $item = $this->findNotification($request->user(), $notification);
        $item->forceFill(['archived_at' => null])->save();

        return response()->json([
            'message' => 'Notification restored to your inbox.',
            'counts' => $this->counts($request->user()),
        ]);
    }

    private function findNotification(User $user, string $id): DatabaseNotification
    {
        return $user->notifications()->whereKey($id)->firstOrFail();
    }

    /**
     * @return array{all: int, unread: int, archived: int}
     */
    private function counts(User $user): array
    {
        $notifications = $user->notifications();
        $archived = (clone $notifications)->whereNotNull('archived_at')->count();
        $all = (clone $notifications)->whereNull('archived_at')->count();
        $unread = (clone $notifications)->whereNull('archived_at')->whereNull('read_at')->count();

        return compact('all', 'unread', 'archived');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DatabaseNotification $notification): array
    {
        $data = $notification->data;
        $event = $data['event'] ?? '';
        $actor = (string) ($data['actor_name'] ?? 'MomenTum');
        $taskTitle = (string) ($data['task_title'] ?? 'Task');
        $projectName = (string) ($data['project_name'] ?? 'Workspace');
        [$headline, $icon, $iconColor] = match ($event) {
            'task_assigned' => [$actor.' assigned you a task', 'users', 'bg-indigo-50 text-indigo-600'],
            'task_status_changed' => [$actor.' updated a task', 'check', 'bg-emerald-50 text-emerald-600'],
            'task_comment' => [$actor.' commented on a task', 'inbox', 'bg-sky-50 text-sky-600'],
            'due_reminder' => [
                ($data['reminder_label'] ?? 'today') === 'tomorrow' ? 'A task is due tomorrow' : 'A task is due today',
                'clock',
                'bg-amber-50 text-amber-600',
            ],
            default => ['A task is due soon', 'clock', 'bg-amber-50 text-amber-600'],
        };
        $createdAt = $notification->created_at;

        return [
            'id' => $notification->getKey(),
            'group' => $createdAt->isToday()
                ? 'Today'
                : ($createdAt->isYesterday() ? 'Yesterday' : $createdAt->format('M j, Y')),
            'actor' => $actor,
            'headline' => $headline,
            'detail' => $taskTitle.' · '.$projectName,
            'initials' => TaskPresenter::initials($actor),
            'avatar' => 'bg-indigo-100 text-indigo-700',
            'icon' => $icon,
            'icon_color' => $iconColor,
            'time' => $createdAt->diffForHumans(),
            'read' => $notification->read_at !== null,
            'archived' => $notification->archived_at !== null,
            'task_id' => $data['task_id'] ?? null,
            'url' => $data['url'] ?? null,
            'search' => $headline.' '.$taskTitle.' '.$projectName,
        ];
    }
}
