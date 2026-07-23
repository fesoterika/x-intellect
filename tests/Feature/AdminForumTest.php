<?php

namespace Tests\Feature;

use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Redirect;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Правка архива форума в админке: темы, разделы (денормализованные поля
 * тем), сообщения и дисклеймеры. Раздел доступен только администратору.
 */
class AdminForumTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    private function makeTopic(array $attrs = []): ForumTopic
    {
        return ForumTopic::create(array_merge([
            'old_id' => 100,
            'forum_old_id' => 1,
            'forum_title' => 'Исследуем',
            'forum_group' => 'Исследования',
            'forum_position' => 5,
            'slug' => 'test-tema',
            'title' => 'Тестовая тема',
            'posts_count' => 1,
        ], $attrs));
    }

    private function makePost(ForumTopic $topic, array $attrs = []): ForumPost
    {
        return $topic->posts()->create(array_merge([
            'old_id' => 500,
            'author' => 'Орлангур',
            'posted_at' => '2014-03-01 10:00:00',
            'body' => '<p>Первое сообщение</p>',
            'position' => 0,
        ], $attrs));
    }

    public function test_editor_cannot_open_forum_admin(): void
    {
        $editor = User::factory()->create(['role' => 'editor']);

        $this->actingAs($editor)->get(route('admin.forum.index'))->assertForbidden();
    }

    public function test_index_shows_structure(): void
    {
        $this->makeTopic();

        $this->actingAs($this->admin())
            ->get(route('admin.forum.index'))
            ->assertOk()
            ->assertSee('Исследования')
            ->assertSee('Исследуем')
            ->assertSee('Тестовая тема');
    }

    public function test_topic_update_changes_fields_and_creates_redirect_on_slug_change(): void
    {
        $topic = $this->makeTopic();

        $this->actingAs($this->admin())->put(route('admin.forum.update', $topic), [
            'title' => 'Новое название',
            'slug' => 'novoe-nazvanie',
            'forum_title' => 'Техники',
            'forum_group' => 'Исследования',
            'disclaimer' => 'Мнения участников — не рекомендации.',
        ]);

        $topic->refresh();
        $this->assertSame('Новое название', $topic->title);
        $this->assertSame('Техники', $topic->forum_title);
        $this->assertSame('Мнения участников — не рекомендации.', $topic->disclaimer);

        // Старый адрес темы 301-редиректится на новый
        $this->assertDatabaseHas('redirects', [
            'from_path' => '/forum/test-tema',
            'to_url' => '/forum/novoe-nazvanie',
            'status_code' => 301,
        ]);
        $this->get('/forum/test-tema')->assertRedirect('/forum/novoe-nazvanie');
    }

    public function test_topic_update_without_slug_change_creates_no_redirect(): void
    {
        $topic = $this->makeTopic();

        $this->actingAs($this->admin())->put(route('admin.forum.update', $topic), [
            'title' => 'Новое название',
            'slug' => 'test-tema',
            'forum_title' => 'Исследуем',
            'forum_group' => 'Исследования',
        ]);

        $this->assertSame(0, Redirect::count());
    }

    public function test_section_rename_touches_all_its_topics(): void
    {
        $a = $this->makeTopic();
        $b = $this->makeTopic(['old_id' => 101, 'slug' => 'vtoraia', 'title' => 'Вторая']);
        $other = $this->makeTopic(['old_id' => 102, 'slug' => 'cuzaia', 'title' => 'Чужая', 'forum_title' => 'Техники']);

        $this->actingAs($this->admin())->put(route('admin.forum.section.rename'), [
            'group' => 'Исследования',
            'old' => 'Исследуем',
            'name' => 'Исследуем вместе',
        ]);

        $this->assertSame('Исследуем вместе', $a->refresh()->forum_title);
        $this->assertSame('Исследуем вместе', $b->refresh()->forum_title);
        $this->assertSame('Техники', $other->refresh()->forum_title);
    }

    public function test_section_destroy_deletes_topics_with_posts(): void
    {
        $topic = $this->makeTopic();
        $this->makePost($topic);
        $keep = $this->makeTopic(['old_id' => 102, 'slug' => 'cuzaia', 'title' => 'Чужая', 'forum_title' => 'Техники']);

        $this->actingAs($this->admin())->delete(route('admin.forum.section.destroy'), [
            'group' => 'Исследования',
            'old' => 'Исследуем',
        ]);

        $this->assertDatabaseMissing('forum_topics', ['id' => $topic->id]);
        $this->assertDatabaseMissing('forum_posts', ['topic_id' => $topic->id]);
        $this->assertDatabaseHas('forum_topics', ['id' => $keep->id]);
    }

    /** Удаление категории сносит все её разделы, темы и сообщения. */
    public function test_group_destroy_deletes_all_its_sections_topics_and_posts(): void
    {
        $a = $this->makeTopic();
        $this->makePost($a);
        $b = $this->makeTopic(['old_id' => 101, 'slug' => 'vtoraia', 'title' => 'Вторая', 'forum_title' => 'Техники']);
        $this->makePost($b, ['old_id' => 501]);
        $keep = $this->makeTopic(['old_id' => 102, 'slug' => 'cuzaia', 'title' => 'Чужая', 'forum_group' => 'Общий', 'forum_title' => 'Объявление']);

        $this->actingAs($this->admin())->delete(route('admin.forum.group.destroy'), [
            'old' => 'Исследования',
        ]);

        $this->assertDatabaseMissing('forum_topics', ['id' => $a->id]);
        $this->assertDatabaseMissing('forum_topics', ['id' => $b->id]);
        $this->assertSame(0, ForumPost::count());
        $this->assertDatabaseHas('forum_topics', ['id' => $keep->id]);
    }

    public function test_post_update_and_stats_refresh(): void
    {
        $topic = $this->makeTopic();
        $post = $this->makePost($topic);

        $this->actingAs($this->admin())->put(route('admin.forum.posts.update', $post), [
            'author' => 'Max9003',
            'posted_at' => '2015-05-05T12:30',
            'body' => '<p>Исправленный текст</p>',
        ]);

        $post->refresh();
        $this->assertSame('Max9003', $post->author);
        $this->assertSame('<p>Исправленный текст</p>', $post->body);
        $this->assertSame('2015-05-05 12:30', $topic->refresh()->last_posted_at->format('Y-m-d H:i'));
    }

    public function test_post_destroy_recalculates_topic_counters(): void
    {
        $topic = $this->makeTopic(['posts_count' => 2]);
        $first = $this->makePost($topic);
        $last = $this->makePost($topic, ['old_id' => 501, 'posted_at' => '2014-04-01 10:00:00', 'position' => 1]);

        $this->actingAs($this->admin())->delete(route('admin.forum.posts.destroy', $last));

        $topic->refresh();
        $this->assertSame(1, $topic->posts_count);
        $this->assertSame('2014-03-01', $topic->last_posted_at->format('Y-m-d'));
        $this->assertDatabaseHas('forum_posts', ['id' => $first->id]);
    }

    public function test_topic_destroy_removes_posts(): void
    {
        $topic = $this->makeTopic();
        $this->makePost($topic);

        $this->actingAs($this->admin())->delete(route('admin.forum.destroy', $topic));

        $this->assertDatabaseMissing('forum_topics', ['id' => $topic->id]);
        $this->assertSame(0, ForumPost::count());
    }

    /** Дисклеймер темы виден на публичной странице под сообщениями. */
    public function test_disclaimer_is_rendered_on_public_topic_page(): void
    {
        $topic = $this->makeTopic(['disclaimer' => 'Мнения участников — не медицинские рекомендации.']);
        $this->makePost($topic);

        $this->get('/forum/test-tema')
            ->assertOk()
            ->assertSee('Мнения участников — не медицинские рекомендации.');
    }

    public function test_topic_without_disclaimer_has_no_note(): void
    {
        $topic = $this->makeTopic();
        $this->makePost($topic);

        $this->get('/forum/test-tema')
            ->assertOk()
            ->assertDontSee('forum-medical-note');
    }
}
