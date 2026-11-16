<?php

namespace Tests\Feature\Calendar;

use App\Models\CalendarEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_calendar_renders_database_deadlines_and_persisted_schedule_events(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->create(['name' => 'Product Design']);
        Task::factory()->for($project)->create([
            'created_by_id' => $user->getKey(),
            'title' => 'Finalize mobile app wireframes',
            'due_date' => today(),
        ]);
        Task::factory()->for($project)->create([
            'created_by_id' => $user->getKey(),
            'title' => 'Review design notes',
            'due_date' => today()->addDay(),
        ]);
        CalendarEvent::factory()->for($user)->create([
            'title' => 'Quarterly planning',
            'date' => today()->addDays(2),
            'time' => '10:30',
        ]);

        $this->actingAs($user)
            ->get(route('calendar'))
            ->assertOk()
            ->assertSee('Calendar')
            ->assertSee('1 deadline due today')
            ->assertSee('1 more due tomorrow')
            ->assertSee('Finalize mobile app wireframes')
            ->assertSee('Quarterly planning')
            ->assertSee('data-calendar-filter="deadline"', false)
            ->assertSee('data-calendar-schedule-form', false)
            ->assertSee('data-calendar-schedules', false);
    }

    public function test_calendar_uses_the_users_validated_timezone_for_dates(): void
    {
        config(['app.timezone' => 'UTC']);
        $this->travelTo('2026-11-16 01:00:00');
        $user = User::factory()->create([
            'settings' => ['timezone' => 'America/Los_Angeles'],
        ]);
        $project = Project::factory()->for($user, 'owner')->create();
        Task::factory()->for($project)->create([
            'created_by_id' => $user->getKey(),
            'title' => 'Deadline on local today',
            'due_date' => '2026-11-15',
        ]);
        Task::factory()->for($project)->create([
            'created_by_id' => $user->getKey(),
            'title' => 'Deadline on local tomorrow',
            'due_date' => '2026-11-16',
        ]);

        $this->actingAs($user)
            ->get(route('calendar'))
            ->assertOk()
            ->assertSee('data-today="2026-11-15"', false)
            ->assertSee('data-timezone="America/Los_Angeles"', false)
            ->assertSee('"due_label":"Due today"', false)
            ->assertSee('"due_label":"Due tomorrow"', false);
    }

    public function test_schedule_creation_persists_to_the_authenticated_users_calendar(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('calendar.events.store'), [
                'title' => 'Sprint review',
                'date' => today()->addDays(2)->toDateString(),
                'time' => '14:15',
            ])
            ->assertCreated()
            ->assertJsonPath('event.title', 'Sprint review')
            ->assertJsonPath('event.time', '14:15');

        $this->assertDatabaseHas('calendar_events', [
            'user_id' => $user->getKey(),
            'title' => 'Sprint review',
            'time' => '14:15',
        ]);
    }

    public function test_user_cannot_remove_another_users_schedule(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $event = CalendarEvent::factory()->for($otherUser)->create();

        $this->actingAs($owner)
            ->deleteJson(route('calendar.events.destroy', ['calendarEvent' => $event]))
            ->assertNotFound();

        $this->assertModelExists($event);
    }
}
