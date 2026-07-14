<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Section;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SectionHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private function makeTree(): array
    {
        $root = Section::create(['title' => 'Вики', 'slug' => 'wiki', 'position' => 1]);
        $child = Section::create(['title' => 'Сеансы', 'slug' => 'seansy', 'parent_id' => $root->id, 'position' => 1, 'show_on_home' => false]);

        return [$root, $child];
    }

    private function makePage(Section $section, string $slug, array $extra = []): Page
    {
        return Page::create(array_merge([
            'section_id' => $section->id,
            'title' => 'Страница '.$slug,
            'slug' => $slug,
            'body' => '<p>Текст страницы для теста иерархии разделов.</p>',
            'status' => 'published',
            'published_at' => now()->subDay(),
        ], $extra));
    }

    public function test_child_section_listing_available_at_nested_url(): void
    {
        [, $child] = $this->makeTree();
        $this->makePage($child, 'seansy-2013');

        $this->get('/wiki/seansy')
            ->assertOk()
            ->assertSee('Сеансы')
            ->assertSee('Страница seansy-2013');
    }

    public function test_page_of_child_section_resolves_under_root_url(): void
    {
        [, $child] = $this->makeTree();
        $page = $this->makePage($child, 'seansy-2013');

        $this->assertSame('/wiki/seansy-2013', $page->url());
        $this->get('/wiki/seansy-2013')->assertOk()->assertSee('Страница seansy-2013');
    }

    public function test_root_section_lists_child_pages_too(): void
    {
        [$root, $child] = $this->makeTree();
        $this->makePage($child, 'seansy-2013');
        $this->makePage($root, 'texniki');

        $this->get('/wiki')
            ->assertOk()
            ->assertSee('Страница seansy-2013')
            ->assertSee('Страница texniki');
    }

    public function test_home_page_does_not_tile_subsections(): void
    {
        [, $child] = $this->makeTree();
        $child->update(['show_on_home' => true]); // даже с включённым флагом

        $this->get('/')->assertOk()->assertDontSee('href="/wiki/seansy"', false);
    }

    public function test_hidden_parent_hides_child_listing(): void
    {
        [$root, $child] = $this->makeTree();
        $this->makePage($child, 'seansy-2013');
        $root->update(['is_visible' => false]);

        $this->get('/wiki/seansy')->assertNotFound();
    }

    public function test_admin_rejects_depth_two_parent(): void
    {
        [, $child] = $this->makeTree();
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.sections.store'), [
                'title' => 'Глубже',
                'slug' => 'deeper',
                'parent_id' => $child->id,
            ])
            ->assertSessionHasErrors('parent_id');
    }

    public function test_admin_rejects_subsection_slug_clashing_with_page(): void
    {
        [$root] = $this->makeTree();
        $this->makePage($root, 'texniki');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->post(route('admin.sections.store'), [
                'title' => 'Техники',
                'slug' => 'texniki',
                'parent_id' => $root->id,
            ])
            ->assertSessionHasErrors('slug');
    }
}
