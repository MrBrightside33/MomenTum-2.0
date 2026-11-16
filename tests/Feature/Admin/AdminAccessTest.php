<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_guests_are_redirected_to_login_for_admin_routes(): void
    {
        $this->get(route('admin.overview'))
            ->assertRedirectToRoute('login');
    }

    public function test_members_cannot_open_admin_routes(): void
    {
        $member = User::factory()->create();

        $this->actingAs($member)
            ->get(route('admin.overview'))
            ->assertForbidden();

        $this->get(route('admin.overview.data'))
            ->assertForbidden();

        $this->get(route('admin.reports.data'))
            ->assertForbidden();
    }

    public function test_inactive_admins_receive_a_json_forbidden_response(): void
    {
        $admin = User::factory()->admin()->inactive()->create();

        $this->actingAs($admin)
            ->getJson(route('admin.overview'))
            ->assertForbidden()
            ->assertJsonPath('message', 'Forbidden.');
    }

    public function test_admins_can_open_the_admin_overview_and_dashboard_redirects_there(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get(route('admin.overview'))
            ->assertOk()
            ->assertSeeText('Admin overview')
            ->assertSee('id="admin-overview-data"', false)
            ->assertSee('aria-label="Administration navigation"', false);

        $this->get(route('admin.overview.data'))
            ->assertOk()
            ->assertSeeText('Workspace members');

        $this->get(route('dashboard'))
            ->assertRedirectToRoute('admin.overview');
    }

    public function test_admin_login_redirects_to_the_admin_overview(): void
    {
        $admin = User::factory()->admin()->create();

        $this->post(route('login.submit'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirectToRoute('admin.overview');
    }

    public function test_password_hash_changes_invalidate_existing_admin_sessions(): void
    {
        $admin = User::factory()->admin()->create();

        $this->post(route('login.submit'), [
            'email' => $admin->email,
            'password' => 'password',
        ])->assertRedirectToRoute('admin.overview');

        $this->get(route('admin.overview'))->assertOk();

        $admin->forceFill(['password' => 'reset-password'])->save();
        $this->app['auth']->forgetGuards();

        $this->get(route('admin.overview'))
            ->assertRedirectToRoute('login');
        $this->assertGuest();
    }
}
