<?php

namespace Tests\Feature;

use App\Models\Media;
use App\Models\User;
use Database\Seeders\AdminUserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Проверки из аудита безопасности: белый список типов при загрузке медиа,
 * роль вне mass assignment, защитные заголовки.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /**
     * Файл сохраняется под случайным именем, но расширение берётся из
     * настоящего MIME-типа: .html и .svg получили бы исполняемый URL на
     * /storage — то есть XSS с домена сайта.
     */
    public function test_media_upload_rejects_html_and_svg(): void
    {
        Storage::fake('public');

        foreach ([
            ['shell.html', '<html><script>alert(document.domain)</script></html>', 'text/html'],
            ['shell.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/svg+xml'],
        ] as [$name, $content, $mime]) {
            $file = UploadedFile::fake()->createWithContent($name, $content);

            $this->actingAs($this->admin())
                ->post(route('admin.media.store'), [
                    'title' => 'Проверка',
                    'type' => 'image',
                    'file' => $file,
                ])
                ->assertSessionHasErrors('file');

            $this->assertDatabaseCount('media', 0);
        }
    }

    public function test_media_upload_rejects_file_not_matching_chosen_type(): void
    {
        Storage::fake('public');

        // Картинка под видом аудио: тип выбран в форме, содержимое ему не отвечает
        $this->actingAs($this->admin())
            ->post(route('admin.media.store'), [
                'title' => 'Не аудио',
                'type' => 'audio',
                'file' => UploadedFile::fake()->image('cover.jpg'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertDatabaseCount('media', 0);
    }

    public function test_media_upload_accepts_allowed_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin())
            ->post(route('admin.media.store'), [
                'title' => 'Обложка',
                'type' => 'image',
                'file' => UploadedFile::fake()->image('cover.jpg'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('media', ['title' => 'Обложка', 'type' => 'image']);
    }

    public function test_editor_upload_rejects_svg(): void
    {
        Storage::fake('public');

        $svg = UploadedFile::fake()->createWithContent(
            'x.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        );

        $this->actingAs($this->admin())
            ->postJson(route('admin.editor.upload'), ['file' => $svg])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_mimetypes_allowlist_has_no_executable_or_scriptable_types(): void
    {
        $all = array_merge(...array_values(Media::MIMETYPES));

        foreach (['image/svg+xml', 'text/html', 'text/x-php', 'application/x-httpd-php'] as $dangerous) {
            $this->assertNotContains($dangerous, $all);
        }
    }

    /** role вне Fillable: обновление профиля не должно поднимать права. */
    public function test_role_is_not_mass_assignable(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $editor->fill(['name' => 'Новое имя', 'role' => 'admin']);

        $this->assertSame('editor', $editor->role);
        $this->assertSame('Новое имя', $editor->name);
    }

    public function test_profile_update_cannot_escalate_role(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($editor)
            ->patch(route('profile.update'), [
                'name' => 'Редактор',
                'email' => 'editor@example.com',
                'role' => 'admin',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('editor', $editor->fresh()->role);
    }

    /** Сидер обязан выдать админу роль admin явно — колонка по умолчанию 'editor'. */
    public function test_admin_seeder_assigns_admin_role(): void
    {
        $this->seed(AdminUserSeeder::class);

        $admin = User::where('email', env('SEED_ADMIN_EMAIL', 'admin@x-intellect.org'))->first();

        $this->assertNotNull($admin);
        $this->assertTrue($admin->isAdmin());
    }

    public function test_security_headers_present_on_public_pages(): void
    {
        $this->get('/')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
    }

    /** HandleRedirects отвечает, не вызывая $next, — заголовки нужны и на 301. */
    public function test_security_headers_present_on_redirects(): void
    {
        \App\Models\Redirect::create([
            'from_path' => '/go/test.html',
            'to_url' => 'https://example.com/',
            'status_code' => 302,
        ]);

        $this->get('/go/test.html')
            ->assertRedirect('https://example.com/')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
    }

    /** HSTS — только по HTTPS: на http он бы залип на весь localhost. */
    public function test_hsts_only_over_https(): void
    {
        $this->get('http://localhost/')->assertHeaderMissing('Strict-Transport-Security');
    }
}
