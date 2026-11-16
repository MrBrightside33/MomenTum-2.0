<?php

namespace App\Http\Controllers;

use App\Enums\ProjectStatus;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Http\Requests\StoreProjectRequest;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Support\Metrics\TaskMetrics;
use App\Support\TaskPresenter;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ProjectController extends Controller
{
    public function index(
        Request $request,
        TaskPresenter $presenter,
        TaskMetrics $taskMetrics,
    ): View {
        Gate::authorize('viewAny', Project::class);
        $user = $request->user();
        $taskCounts = $taskMetrics->counts([
            'user_id' => $user->getKey(),
            'priority' => TaskPriority::High->value,
        ]);
        $projects = Project::query()
            ->visibleTo($user)
            ->with([
                'owner:id,name',
                'tasks' => function (HasMany $query) use ($user): void {
                    $query->visibleTo($user)
                        ->with([
                            'assignees:id,name',
                            'creator:id,name',
                            'progressUpdater:id,name',
                            'activities.user:id,name',
                            'comments.user:id,name',
                        ])
                        ->orderBy('due_date')
                        ->orderBy('id');
                },
            ])
            ->orderBy('name')
            ->get()
            ->flatMap(function (Project $project) use ($user, $presenter): array {
                return $project->tasks
                    ->filter(fn (Task $task): bool => $task->priority === TaskPriority::High)
                    ->map(function (Task $task) use ($project, $user, $presenter): array {
                        $task->setRelation('project', $project);
                        $presented = $presenter->present($task, $user);
                        $members = collect([$project->owner, $task->creator])
                            ->merge($task->assignees)
                            ->filter()
                            ->unique('id')
                            ->map(fn (User $member): array => [
                                'id' => $member->getKey(),
                                'name' => $member->name,
                                'initials' => TaskPresenter::initials($member->name),
                            ])
                            ->values();

                        return [
                            'id' => $task->getKey(),
                            'name' => $task->title,
                            'status' => match ($task->status) {
                                TaskStatus::Open => ProjectStatus::Planning->value,
                                TaskStatus::InProgress => ProjectStatus::InProgress->value,
                                TaskStatus::Completed => ProjectStatus::Completed->value,
                            },
                            'progress' => $presented['progress'],
                            'completed_task_count' => $task->status === TaskStatus::Completed ? 1 : 0,
                            'task_count' => 1,
                            'collaboration' => $members->count() > 1 ? 'Team project' : 'One-person project',
                            'members' => $members->all(),
                            'parent_project' => $project->name,
                            'due' => $presented['due'],
                            'task_url' => route('projects.show', ['task' => $task->getKey()]),
                            'task' => $presented,
                            'search' => trim($task->title.' '.$task->description.' '.$project->name.' '.$members->pluck('name')->implode(' ')),
                        ];
                    })
                    ->all();
            })
            ->sortBy(fn (array $project): string => mb_strtolower($project['name'].' '.$project['parent_project']))
            ->values();

        $projectCounts = [
            'all' => $taskCounts['total'],
            'in_progress' => $taskCounts['in_progress'],
            'completed' => $taskCounts['completed'],
            'planning' => $taskCounts['statuses'][TaskStatus::Open->value],
        ];
        $totalTasks = $taskCounts['total'];
        $completedTasks = $taskCounts['completed'];
        $projectStats = [
            ['label' => 'High-priority projects', 'value' => $projectCounts['all'], 'note' => 'One project per high-priority task', 'icon' => 'folder', 'bg' => 'bg-indigo-50', 'fg' => 'text-indigo-600'],
            ['label' => 'In progress', 'value' => $projectCounts['in_progress'], 'note' => 'High-priority projects underway', 'icon' => 'clock', 'bg' => 'bg-amber-50', 'fg' => 'text-amber-600'],
            ['label' => 'Tasks completed', 'value' => $completedTasks, 'note' => 'of '.$totalTasks.' high-priority tasks completed', 'icon' => 'check', 'bg' => 'bg-emerald-50', 'fg' => 'text-emerald-600'],
            ['label' => 'Planning', 'value' => $projectCounts['planning'], 'note' => 'High-priority tasks not yet started', 'icon' => 'sparkles', 'bg' => 'bg-violet-50', 'fg' => 'text-violet-600'],
        ];

        return view('projects', [
            'projects' => $projects,
            'projectCounts' => $projectCounts,
            'projectStats' => $projectStats,
            'canCreateProjects' => $user->can('create', Project::class),
            'openedTask' => null,
        ]);
    }

    public function show(
        Request $request,
        string $task,
        TaskPresenter $presenter,
        TaskMetrics $taskMetrics,
    ): View|RedirectResponse {
        if (! ctype_digit($task)) {
            return redirect()->route('projects.index')->with('status', "Project not found or you don't have access");
        }

        $user = $request->user();
        $taskModel = Task::query()
            ->visibleTo($user)
            ->with([
                'project:id,name,owner_id',
                'project.owner:id,name',
                'assignees:id,name',
                'creator:id,name',
                'progressUpdater:id,name',
                'activities.user:id,name',
                'comments.user:id,name',
            ])
            ->find((int) $task);

        if ($taskModel === null || ! $user->can('view', $taskModel)) {
            return redirect()->route('projects.index')->with('status', "Project not found or you don't have access");
        }

        return $this->index($request, $presenter, $taskMetrics)
            ->with('openedTask', $presenter->present($taskModel, $user));
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $request->user()->ownedProjects()->create($request->validated());

        return redirect()->route('projects.index')->with('status', 'Project created');
    }

    public function archive(Request $request, Project $project): RedirectResponse
    {
        Gate::authorize('archive', $project);

        if ($project->status !== ProjectStatus::Archived) {
            $project->forceFill(['status' => ProjectStatus::Archived])->save();
        }

        return redirect()->route('projects.index')->with('status', 'Project archived');
    }
}
