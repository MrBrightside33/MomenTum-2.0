<?php

namespace Tests\Feature\Inbox;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Notifications\TaskActivityNotification;
use App\Services\DueTaskReminderService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class InboxTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_inbox_renders_notifications_created_by_real_task_activity(): void
    {
        $owner = User::factory()->create(['name' => 'Alex Morgan']);
        $assignee = User::factory()->create(['name' => 'Lina Kim']);
        $project = Project::factory()->for($owner, 'owner')->create(['name' => 'Product Design']);

        $response = $this->actingAs($owner)->postJson(route('tasks.store'), [
            'title' => 'Finalize mobile app wireframes',
            'project' => $project->getKey(),
            'priority' => 'High',
            'status' => 'Open',
            'due_date' => today()->toDateString(),
            'assignees' => [$assignee->getKey()],
        ]);
        $taskId = $response->assertCreated()->json('task_id');

        $this->actingAs($assignee)
            ->get(route('inbox'))
            ->assertOk()
            ->assertSee('Inbox')
            ->assertSee('Alex Morgan assigned you a task')
            ->assertSee('data-inbox-filter="unread"', false)
            ->assertSee(route('dashboard').'#task-row-'.$taskId, false);
    }

    public function test_inbox_creates_one_reminder_for_each_visible_task_due_today(): void
    {
        $user = User::factory()->create([
            'settings' => ['timezone' => config('app.timezone')],
        ]);
        $otherUser = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->create(['name' => 'Personal']);
        $otherProject = Project::factory()->for($otherUser, 'owner')->create(['name' => 'Private']);
        $today = now(config('app.timezone'))->toDateString();

        $dueTask = Task::factory()->for($project)->create([
            'title' => 'Submit final report',
            'due_date' => $today,
        ]);
        Task::factory()->for($project)->create([
            'title' => 'Completed task',
            'due_date' => $today,
            'status' => TaskStatus::Completed,
        ]);
        Task::factory()->for($project)->create([
            'title' => 'Tomorrow task',
            'due_date' => now(config('app.timezone'))->addDay()->toDateString(),
        ]);
        Task::factory()->for($otherProject)->create([
            'title' => 'Private task',
            'due_date' => $today,
        ]);

        $response = $this->actingAs($user)->get(route('inbox'));

        $response
            ->assertOk()
            ->assertSee('A task is due today')
            ->assertSee('Submit final report')
            ->assertDontSee('Completed task')
            ->assertDontSee('Tomorrow task')
            ->assertDontSee('Private task');

        $this->get(route('inbox'))->assertOk();

        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseHas('task_activities', [
            'task_id' => $dueTask->getKey(),
            'user_id' => $user->getKey(),
            'event_type' => 'due_reminder',
            'dedupe_key' => 'due-reminder:'.$dueTask->getKey().':'.$user->getKey().':'.$today,
        ]);
    }

    public function test_inbox_labels_tomorrow_reminders_as_due_tomorrow(): void
    {
        $this->travelTo('2026-11-16 08:00:00');
        $user = User::factory()->create([
            'settings' => ['timezone' => 'UTC'],
        ]);
        $project = Project::factory()->for($user, 'owner')->create();
        Task::factory()->for($project)->create([
            'created_by_id' => $user->getKey(),
            'title' => 'Today task',
            'due_date' => '2026-11-16',
        ]);
        Task::factory()->for($project)->create([
            'created_by_id' => $user->getKey(),
            'title' => 'Tomorrow task',
            'due_date' => '2026-11-17',
        ]);

        app(DueTaskReminderService::class)->sendDueReminders($user, now('UTC'), includeTomorrow: true);

        $this->actingAs($user)
            ->get(route('inbox'))
            ->assertOk()
            ->assertSee('A task is due today')
            ->assertSee('A task is due tomorrow')
            ->assertSeeText('Today task')
            ->assertSeeText('Tomorrow task');
    }

    public function test_inbox_does_not_create_due_reminders_when_the_user_disabled_them(): void
    {
        $user = User::factory()->create([
            'settings' => [
                'timezone' => config('app.timezone'),
                'notifications' => ['due_date_reminders' => false],
            ],
        ]);
        $project = Project::factory()->for($user, 'owner')->create();
        Task::factory()->for($project)->create([
            'title' => 'Review launch plan',
            'due_date' => now(config('app.timezone'))->toDateString(),
        ]);

        $this->actingAs($user)
            ->get(route('inbox'))
            ->assertOk()
            ->assertDontSee('Review launch plan');

        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('task_activities', 0);
    }

    public function test_read_and_archive_actions_persist_for_only_the_authenticated_user(): void
    {
        $owner = User::factory()->create();
        $assignee = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $task = Task::factory()->for($project)->create([
            'created_by_id' => $owner->getKey(),
        ]);
        $task->assignees()->attach($assignee);
        $assignee->notify(new TaskActivityNotification('task_assigned', $task, $owner));
        $notification = $assignee->notifications()->firstOrFail();

        $this->actingAs($assignee)
            ->patchJson(route('inbox.read', ['notification' => $notification->getKey()]), ['read' => true])
            ->assertOk()
            ->assertJsonPath('counts.unread', 0);

        $this->assertNotNull($assignee->notifications()->whereKey($notification->getKey())->firstOrFail()->read_at);

        $this->patchJson(route('inbox.archive', ['notification' => $notification->getKey()]))
            ->assertOk()
            ->assertJsonPath('counts.archived', 1);
        $this->assertNotNull($assignee->notifications()->whereKey($notification->getKey())->firstOrFail()->archived_at);

        $this->patchJson(route('inbox.restore', ['notification' => $notification->getKey()]))
            ->assertOk()
            ->assertJsonPath('counts.all', 1);
        $this->assertNull($assignee->notifications()->whereKey($notification->getKey())->firstOrFail()->archived_at);
    }
}
