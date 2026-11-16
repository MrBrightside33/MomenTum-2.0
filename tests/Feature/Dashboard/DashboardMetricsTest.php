<?php

namespace Tests\Feature\Dashboard;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class DashboardMetricsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_dashboard_uses_viewer_local_today_for_aggregate_task_metrics(): void
    {
        $this->travelTo('2026-11-12 16:30:00');
        $user = User::factory()->create([
            'settings' => ['timezone' => 'Asia/Manila'],
        ]);
        $project = Project::factory()->for($user, 'owner')->create();

        Task::factory()->for($project)->create([
            'created_by_id' => $user->getKey(),
            'status' => TaskStatus::Open,
            'due_date' => '2026-11-12',
        ]);
        Task::factory()->for($project)->create([
            'created_by_id' => $user->getKey(),
            'status' => TaskStatus::InProgress,
            'due_date' => '2026-11-13',
        ]);
        Task::factory()->for($project)->create([
            'created_by_id' => $user->getKey(),
            'status' => TaskStatus::Completed,
            'due_date' => '2026-11-12',
        ]);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-stat="total" class="pt-2 text-3xl font-bold tracking-tight text-slate-900">3</p>', false)
            ->assertSee('data-stat="in_progress" class="pt-2 text-3xl font-bold tracking-tight text-slate-900">1</p>', false)
            ->assertSee('data-stat="completed" class="pt-2 text-3xl font-bold tracking-tight text-slate-900">1</p>', false)
            ->assertSee('data-stat="productivity" class="pt-2 text-3xl font-bold tracking-tight text-slate-900">33%</p>', false)
            ->assertSeeText('1 due today')
            ->assertSeeText('33%');
    }

    public function test_manager_dashboard_metrics_include_every_visible_task(): void
    {
        $this->travelTo('2026-11-16 10:00:00');
        $manager = User::factory()->manager()->create([
            'settings' => ['timezone' => 'UTC'],
        ]);
        $firstOwner = User::factory()->create();
        $secondOwner = User::factory()->create();
        $firstProject = Project::factory()->for($firstOwner, 'owner')->create();
        $secondProject = Project::factory()->for($secondOwner, 'owner')->create();

        Task::factory()->for($firstProject)->create([
            'created_by_id' => $firstOwner->getKey(),
            'status' => TaskStatus::Open,
            'due_date' => '2026-11-16',
        ]);
        Task::factory()->for($firstProject)->create([
            'created_by_id' => $firstOwner->getKey(),
            'status' => TaskStatus::InProgress,
            'due_date' => '2026-11-17',
        ]);
        Task::factory()->for($secondProject)->create([
            'created_by_id' => $secondOwner->getKey(),
            'status' => TaskStatus::Completed,
            'due_date' => '2026-11-16',
        ]);

        $this->actingAs($manager)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-stat="total" class="pt-2 text-3xl font-bold tracking-tight text-slate-900">3</p>', false)
            ->assertSee('data-stat="in_progress" class="pt-2 text-3xl font-bold tracking-tight text-slate-900">1</p>', false)
            ->assertSee('data-stat="completed" class="pt-2 text-3xl font-bold tracking-tight text-slate-900">1</p>', false)
            ->assertSee('data-stat="productivity" class="pt-2 text-3xl font-bold tracking-tight text-slate-900">33%</p>', false)
            ->assertSeeText('1 due today');
    }
}
