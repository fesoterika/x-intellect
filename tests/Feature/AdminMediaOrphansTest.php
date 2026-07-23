<?php

namespace Tests\Feature;

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\GlossaryTerm;
use App\Models\Media;
use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use App\Services\OrphanMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Массовая очистка бесхозных медиафайлов в админке: скан ?orphans=1
 * с модалкой подтверждения и DELETE media/orphans. Бесхозный = без
 * привязки page_id И без упоминаний в текстах сайта (тела страниц,
 * ревизии, глоссарий, форум) — см. App\Services\OrphanMedia.
 */
class AdminMediaOrphansTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makePage(string $body = '<p>Текст</p>'): Page
    {
        $section = Section::firstOrCreate(['slug' => 'articles'], ['title' => 'Статьи', 'position' => 0]);

        return Page::create([
            'section_id' => $section->id,
            'title' => 'Страница '.uniqid(),
            'slug' => 'stranica-'.uniqid(),
            'body' => $body,
            'status' => 'published',
            'source_type' => 'new',
        ]);
    }

    private function makeMedia(array $attrs = []): Media
    {
        $path = 'media/audio/'.uniqid().'.mp3';
        Storage::disk('public')->put($attrs['file_path'] ?? $path, 'mp3-данные');

        return Media::create(array_merge([
            'type' => 'audio',
            'title' => 'Запись '.uniqid(),
            'file_path' => $path,
            'disk' => 'public',
            'size' => 100,
        ], $attrs));
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_attached_and_mentioned_media_are_not_orphans(): void
    {
        $service = app(OrphanMedia::class);

        // Привязан к странице
        $attached = $this->makeMedia(['page_id' => $this->makePage()->id]);

        // Аудио подключено short-кодом без привязки page_id
        $shortcode = $this->makeMedia();
        $this->makePage('<p>Слушать: [[audio:'.$shortcode->id.']]</p>');

        // Файл упомянут по имени в теле страницы (Trix-вложение)
        $inline = $this->makeMedia(['type' => 'image', 'file_path' => 'media/inline/kartinka-abc.png']);
        $this->makePage('<figure data-trix-attachment=\'{"url":"\/storage\/media\/inline\/kartinka-abc.png"}\'></figure>');

        // Упомянут в определении глоссария
        $glossary = $this->makeMedia(['type' => 'pdf', 'file_path' => 'media/pdf/kniga-xyz.pdf']);
        GlossaryTerm::create([
            'term' => 'Термин', 'slug' => 'termin',
            'definition' => '<a href="/storage/media/pdf/kniga-xyz.pdf">Книга</a>',
        ]);

        // Упомянут в сообщении форума
        $forum = $this->makeMedia(['type' => 'image', 'file_path' => 'media/inline/forum-img-42.jpg']);
        $topic = ForumTopic::create([
            'old_id' => 1, 'forum_old_id' => 1, 'forum_title' => 'Раздел', 'forum_group' => 'Группа',
            'forum_position' => 0, 'slug' => 'tema', 'title' => 'Тема', 'posts_count' => 1,
        ]);
        ForumPost::create([
            'topic_id' => $topic->id, 'old_id' => 1, 'author' => 'Гость', 'position' => 0,
            'posted_at' => now(), 'body' => '<img src="/storage/media/inline/forum-img-42.jpg">',
        ]);

        // Внешний URL, упомянутый в теле страницы
        $external = $this->makeMedia(['file_path' => 'https://s3.example.com/zapis.mp3']);
        $this->makePage('<a href="https://s3.example.com/zapis.mp3">запись</a>');

        // А этот — настоящий сирота
        $orphan = $this->makeMedia();

        $found = $service->find();
        $this->assertSame([$orphan->id], $found->pluck('id')->all());

        foreach ([$attached, $shortcode, $inline, $glossary, $forum, $external] as $media) {
            $this->assertFalse($service->isOrphan($media), 'Media #'.$media->id.' не должен считаться бесхозным');
        }
    }

    public function test_mention_in_page_revision_protects_file(): void
    {
        $media = $this->makeMedia(['type' => 'pdf', 'file_path' => 'media/pdf/staraia-versia.pdf']);
        $page = $this->makePage();
        $page->revisions()->create([
            'title' => $page->title,
            'body' => '<a href="/storage/media/pdf/staraia-versia.pdf">PDF</a>',
        ]);

        $this->assertFalse(app(OrphanMedia::class)->isOrphan($media));
    }

    public function test_scan_shows_confirmation_modal_with_orphans(): void
    {
        $orphan = $this->makeMedia(['title' => 'Забытая запись']);
        $this->makeMedia(['title' => 'Нужная запись', 'page_id' => $this->makePage()->id]);

        $this->actingAs($this->admin())
            ->get(route('admin.media.index', ['orphans' => 1]))
            ->assertOk()
            ->assertSee('Найдено бесхозных файлов: 1')
            ->assertSee('Забытая запись')
            ->assertSee('Удалить все перечисленные файлы?')
            ->assertSee($orphan->file_path);
    }

    public function test_scan_reports_when_nothing_found(): void
    {
        $this->makeMedia(['page_id' => $this->makePage()->id]);

        $this->actingAs($this->admin())
            ->get(route('admin.media.index', ['orphans' => 1]))
            ->assertOk()
            ->assertSee('Бесхозных файлов не найдено');
    }

    public function test_index_without_flag_does_not_show_modal(): void
    {
        $this->makeMedia();

        $this->actingAs($this->admin())
            ->get(route('admin.media.index'))
            ->assertOk()
            ->assertDontSee('Удалить все перечисленные файлы?');
    }

    public function test_confirmed_orphans_are_deleted_with_files(): void
    {
        $orphan1 = $this->makeMedia();
        $orphan2 = $this->makeMedia();

        $this->actingAs($this->admin())
            ->delete(route('admin.media.orphans.destroy'), ['ids' => [$orphan1->id, $orphan2->id]])
            ->assertRedirect(route('admin.media.index'))
            ->assertSessionHas('status', 'Удалено бесхозных файлов: 2.');

        $this->assertDatabaseMissing('media', ['id' => $orphan1->id]);
        $this->assertDatabaseMissing('media', ['id' => $orphan2->id]);
        Storage::disk('public')->assertMissing($orphan1->file_path);
        Storage::disk('public')->assertMissing($orphan2->file_path);
    }

    public function test_media_no_longer_orphaned_is_skipped_on_confirm(): void
    {
        // Между показом списка и подтверждением файл привязали к странице —
        // сервер перепроверяет и пропускает его
        $media = $this->makeMedia();
        $media->update(['page_id' => $this->makePage()->id]);

        $this->actingAs($this->admin())
            ->delete(route('admin.media.orphans.destroy'), ['ids' => [$media->id]])
            ->assertRedirect(route('admin.media.index'));

        $this->assertDatabaseHas('media', ['id' => $media->id]);
        Storage::disk('public')->assertExists($media->file_path);
    }

    public function test_external_url_record_is_deleted_without_touching_storage(): void
    {
        $media = $this->makeMedia(['file_path' => 'https://s3.example.com/odinokii.mp3']);

        $this->actingAs($this->admin())
            ->delete(route('admin.media.orphans.destroy'), ['ids' => [$media->id]])
            ->assertRedirect(route('admin.media.index'));

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }

    public function test_guest_cannot_delete_orphans(): void
    {
        $media = $this->makeMedia();

        $this->delete(route('admin.media.orphans.destroy'), ['ids' => [$media->id]])
            ->assertRedirect(route('login'));

        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }
}
