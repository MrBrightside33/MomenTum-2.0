<?php

namespace Tests\Feature\Projects;

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ProjectsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_projects_page_renders_only_visible_high_priority_task_projects(): void
    {
        $owner = User::factory()->create();
        $otherOwner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create([
            'name' => 'Product Design',
            'description' => 'Research and refine product experiences.',
        ]);
        $privateProject = Project::factory()->for($otherOwner, 'owner')->create([
            'name' => 'Private project',
        ]);
        $task = Task::factory()->for($project)->create([
            'created_by_id' => $owner->getKey(),
            'title' => 'Finalize wireframes',
            'priority' => TaskPriority::High,
        ]);
        Task::factory()->for($privateProject)->create([
            'created_by_id' => $otherOwner->getKey(),
            'priority' => TaskPriority::High,
        ]);
        $regularProject = Project::factory()->for($owner, 'owner')->create([
            'name' => 'Regular project',
        ]);
        Task::factory()->for($regularProject)->create([
            'title' => 'A normal priority task',
            'priority' => TaskPriority::Medium,
        ]);

        $response = $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertOk();

        $this->assertSame(1, substr_count($response->getContent(), 'data-project-card'));

        $response
            ->assertSee('Projects')
            ->assertSee('Product Design')
            ->assertSee('data-project-task-id="'.$task->getKey().'"', false)
            ->assertDontSee('Private project')
            ->assertDontSee('A normal priority task')
            ->assertSee('data-project-filter="in_progress"', false)
            ->assertSee('data-project-search', false)
            ->assertSee(route('projects.show', ['task' => $task->getKey()]), false)
            ->assertSee('New project');
    }

    public function test_project_creation_persists_a_project_for_the_authenticated_owner(): void
    {
        $owner = User::factory()->create();

        $this->actingAs($owner)
            ->post(route('projects.store'), [
                'name' => 'Release planning',
                'description' => 'Coordinate the upcoming release.',
            ])
            ->assertRedirect(route('projects.index'))
            ->assertSessionHas('status', 'Project created');

        $this->assertDatabaseHas('projects', [
            'owner_id' => $owner->getKey(),
            'name' => 'Release planning',
            'description' => 'Coordinate the upcoming release.',
        ]);
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'project.created')
                ->where('subject_id', Project::query()->where('name', 'Release planning')->value('id'))
                ->count(),
        );
    }

    public function test_projects_page_includes_a_project_where_the_user_created_a_task(): void
    {
        $owner = User::factory()->create();
        $creator = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create([
            'name' => 'Shared research',
        ]);
        $task = Task::factory()->for($project)->create([
            'created_by_id' => $creator->getKey(),
            'title' => 'Document findings',
            'priority' => TaskPriority::High,
        ]);

        $this->actingAs($creator)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Shared research')
            ->assertSee('Document findings')
            ->assertSee(route('projects.show', ['task' => $task->getKey()]), false);
    }

    public function test_new_high_priority_task_is_reflected_as_a_project_card(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create([
            'name' => 'Launch planning',
        ]);

        $this->actingAs($owner)
            ->postJson(route('tasks.store'), [
                'title' => 'Review production readiness',
                'project' => $project->getKey(),
                'priority' => TaskPriority::High->value,
                'status' => TaskStatus::Open->value,
                'due_date' => today()->toDateString(),
            ])
            ->assertCreated();

        $task = Task::query()
            ->where('title', 'Review production readiness')
            ->firstOrFail();

        $this->actingAs($owner)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('id="create-task-form"', false)
            ->assertSee('Review production readiness')
            ->assertSee('data-project-task-id="'.$task->getKey().'"', false);
    }

    public function test_updated_task_progress_is_reflected_in_its_card_on_the_main_projects_page(): void
    {
        $owner = User::factory()->create();
        $project = Project::factory()->for($owner, 'owner')->create();
        $task = Task::factory()->for($project)->create([
            'created_by_id' => $owner->getKey(),
            'priority' => TaskPriority::High,
            'status' => TaskStatus::InProgress,
            'progress' => 25,
        ]);
        Task::factory()->for($project)->create([
            'created_by_id' => $owner->getKey(),
            'priority' => TaskPriority::Medium,
            'status' => TaskStatus::Open,
            'progress' => 0,
        ]);

        $this->actingAs($owner)
            ->patchJson(route('tasks.progress', ['task' => $task]), [
                'progress' => 50,
                'expected_progress' => 25,
            ])
            ->assertOk();

        $this->get(route('projects.index'))
            ->assertOk()
            ->assertSee('aria-valuenow="50"', false)
            ->assertDontSee('aria-valuenow="25"', false);
    }

    public function test_each_high_priority_task_has_its_own_project_card_and_collaboration_type(): void
    {
        $user = User::factory()->create();
        $teamOwner = User::factory()->create();
        $personalProject = Project::factory()->for($user, 'owner')->create(['name' => 'Personal work']);
        $teamProject = Project::factory()->for($teamOwner, 'owner')->create(['name' => 'Team launch']);
        $personalTask = Task::factory()->for($personalProject)->create([
            'title' => 'Finalize launch checklist',
            'priority' => TaskPriority::High,
            'status' => TaskStatus::InProgress,
            'progress' => 25,
            'description' => 'Coordinate the release checklist.',
        ]);
        $secondPersonalTask = Task::factory()->for($personalProject)->create([
            'title' => 'Prepare meeting notes',
            'priority' => TaskPriority::High,
            'status' => TaskStatus::Open,
            'progress' => 0,
        ]);
        $teamTask = Task::factory()->for($teamProject)->create([
            'title' => 'Review release blockers',
            'priority' => TaskPriority::High,
            'status' => TaskStatus::Completed,
            'progress' => 100,
            'description' => 'Resolve the blockers with the team.',
        ]);
        $teamTask->assignees()->attach($user);

        $this->actingAs($user)
            ->get(route('projects.index'))
            ->assertOk()
            ->assertSee('Personal work')
            ->assertSee('Team launch')
            ->assertSee('Finalize launch checklist')
            ->assertSee('Prepare meeting notes')
            ->assertSee('Review release blockers')
            ->assertDontSeeText('Coordinate the release checklist.')
            ->assertDontSeeText('Resolve the blockers with the team.')
            ->assertSee('One-person project')
            ->assertSee('Team project')
            ->assertSee('data-project-task-id="'.$personalTask->getKey().'"', false)
            ->assertSee('data-project-task-id="'.$secondPersonalTask->getKey().'"', false)
            ->assertSee('data-project-task-id="'.$teamTask->getKey().'"', false)
            ->assertSee('aria-valuenow="25"', false)
            ->assertSee('aria-valuenow="0"', false)
            ->assertSee('aria-valuenow="100"', false)
            ->assertSee(route('projects.show', ['task' => $personalTask->getKey()]), false)
            ->assertSee(route('projects.show', ['task' => $teamTask->getKey()]), false);
    }
}
