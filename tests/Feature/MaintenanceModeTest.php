<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_site_is_open_by_default(): void
    {
        $this->get('/')->assertOk();
    }

    public function test_guest_gets_503_stub_on_any_url_when_enabled(): void
    {
        Setting::set(Setting::MAINTENANCE, '1');

        // /nonexistent-page - несуществующий slug (404 из биндинга),
        // /no/such/deep/path - URL вне всех маршрутов (404 до web-стека)
        foreach (['/', '/search', '/glossary', '/forum', '/nonexistent-page', '/no/such/deep/path'] as $url) {
            $response = $this->get($url);

            $response->assertServiceUnavailable();
            $response->assertHeader('Retry-After', '3600');
            $response->assertSee('технические работы');
            $response->assertSee('https://t.me/+H15kvUCtrUw4ODAy', false);
        }
    }

    public function test_stub_is_not_cacheable(): void
    {
        Setting::set(Setting::MAINTENANCE, '1');

        $this->get('/')->assertHeader('Cache-Control', 'max-age=0, no-store, private');
    }

    public function test_guest_can_reach_login_when_enabled(): void
    {
        Setting::set(Setting::MAINTENANCE, '1');

        $this->get('/login')->assertOk();
        // Прямая ссылка в админку ведёт на форму входа, а не на заглушку
        $this->get('/admin')->assertRedirect('/login');
    }

    public function test_editors_and_admins_see_site_when_enabled(): void
    {
        Setting::set(Setting::MAINTENANCE, '1');

        foreach (['editor', 'admin'] as $role) {
            $response = $this->actingAs(User::factory()->create(['role' => $role]))->get('/');

            $response->assertOk();
            // Плашка-напоминание о включённом режиме
            $response->assertSee('режим технических работ');
        }
    }

    public function test_editor_can_toggle_maintenance_from_dashboard(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($editor)
            ->post(route('admin.maintenance.toggle'), ['enabled' => 1])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertTrue(Setting::maintenanceEnabled());

        $this->actingAs($editor)
            ->post(route('admin.maintenance.toggle'), ['enabled' => 0])
            ->assertRedirect();

        $this->assertFalse(Setting::maintenanceEnabled());
    }

    public function test_guest_cannot_toggle_maintenance(): void
    {
        $this->post(route('admin.maintenance.toggle'), ['enabled' => 1])
            ->assertRedirect('/login');

        $this->assertFalse(Setting::maintenanceEnabled());
    }

    public function test_dashboard_shows_maintenance_block(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Технические работы')
            ->assertSee('сайт открыт');

        Setting::set(Setting::MAINTENANCE, '1');

        $this->actingAs($admin)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('заглушка включена');
    }
}
