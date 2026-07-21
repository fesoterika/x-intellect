<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * История изменений правится из формы страницы: причина правки пишется при
 * сохранении, запись истории редактируется и удаляется отдельно.
 */
class PageRevisionEditTest extends TestCase
{
    use RefreshDatabase;

    private function makePage(): Page
    {
        $section = Section::firstOrCreate(['slug' => 'articles'], ['title' => 'Статьи', 'position' => 1]);

        return Page::create([
            'section_id' => $section->id,
            'title' => 'Материал',
            'slug' => 'material',
            'body' => '<p>Текст.</p>',
            'status' => 'published',
        ]);
    }

    private function pageForm(Page $page, array $overrides = []): array
    {
        return array_merge([
            'section_id' => $page->section_id,
            'title' => $page->title,
            'slug' => $page->slug,
            'body' => $page->body,
            'page_type' => 'page',
            'status' => 'published',
            'source_type' => 'new',
        ], $overrides);
    }

    public function test_reason_from_form_lands_in_the_new_revision(): void
    {
        $page = $this->makePage();
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($editor)
            ->put(route('admin.pages.update', $page), $this->pageForm($page, [
                'body' => '<p>Другой текст.</p>',
                'revision_reason' => 'Уточнил даты по архивной копии',
            ]))
            ->assertRedirect();

        $revision = $page->revisions()->firstOrFail();
        $this->assertSame('Уточнил даты по архивной копии', $revision->reason);
        // Служебная пометка на месте: по ней импортёры узнают ручную правку
        $this->assertStringStartsWith('Отредактирована вручную', $revision->note);
    }

    public function test_revision_can_be_edited_and_deleted(): void
    {
        $page = $this->makePage();
        $editor = User::factory()->create(['role' => 'editor']);
        $revision = $page->revisions()->create(['title' => 'Старый заголовок', 'body' => '<p>Было.</p>']);

        $this->actingAs($editor)
            ->put(route('admin.pages.revisions.update', [$page, $revision]), [
                'title' => 'Исправленный заголовок',
                'reason' => 'Опечатка в названии',
                'archived_at' => '2014-05-01',
            ])
            ->assertRedirect();

        $revision->refresh();
        $this->assertSame('Исправленный заголовок', $revision->title);
        $this->assertSame('Опечатка в названии', $revision->reason);
        $this->assertSame('2014-05-01', $revision->archived_at->format('Y-m-d'));

        $this->actingAs($editor)
            ->delete(route('admin.pages.revisions.destroy', [$page, $revision]))
            ->assertRedirect();

        $this->assertModelMissing($revision);
    }

    public function test_revision_of_another_page_is_not_reachable(): void
    {
        $page = $this->makePage();
        $other = Page::create(['title' => 'Другая', 'slug' => 'other', 'body' => '<p>x</p>', 'status' => 'draft']);
        $revision = $other->revisions()->create(['title' => 'Чужая редакция']);

        $this->actingAs(User::factory()->create(['role' => 'editor']))
            ->put(route('admin.pages.revisions.update', [$page, $revision]), ['title' => 'Подмена'])
            ->assertNotFound();

        $this->assertSame('Чужая редакция', $revision->fresh()->title);
    }
}
