<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ForumPost;
use App\Models\ForumTopic;
use App\Models\Redirect;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Правка архива форума: структура повторяет публичный /forum —
 * категория (forum_group) → раздел (forum_title) → темы → сообщения.
 * Категории и разделы не имеют своих таблиц (денормализованы в полях
 * каждой темы), поэтому их переименование/удаление — массовое обновление
 * тем. Создания новых тем/сообщений нет: архив только правится и чистится.
 */
class ForumController extends Controller
{
    public function index()
    {
        $topics = ForumTopic::query()
            ->orderBy('forum_position')
            ->orderByDesc('last_posted_at')
            ->get();

        // Категория → разделы → темы, порядок — как на публичном /forum
        $groups = $topics
            ->groupBy(fn ($t) => $t->forum_group ?? 'Форум')
            ->sortBy(fn ($items) => $items->min('forum_position'))
            ->map(fn ($items) => $items->groupBy('forum_title'));

        return view('admin.forum.index', [
            'groups' => $groups,
            'topicsCount' => $topics->count(),
            'postsCount' => (int) $topics->sum('posts_count'),
        ]);
    }

    /** Переименовать категорию (forum_group) во всех её темах. */
    public function renameGroup(Request $request)
    {
        $data = $request->validate([
            'old' => ['nullable', 'string'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $this->groupQuery($data)->update(['forum_group' => $data['name']]);

        return back()->with('status', 'Категория переименована.');
    }

    /** Удалить категорию целиком: все её разделы, темы и сообщения. */
    public function destroyGroup(Request $request)
    {
        $data = $request->validate([
            'old' => ['nullable', 'string'],
        ]);

        // Сообщения тем удаляет каскад БД (FK cascadeOnDelete)
        $deleted = $this->groupQuery($data)->delete();

        return redirect()
            ->route('admin.forum.index')
            ->with('status', "Категория удалена (тем: {$deleted}).");
    }

    /** Переименовать раздел форума (forum_title) во всех его темах. */
    public function renameSection(Request $request)
    {
        $data = $request->validate([
            'group' => ['nullable', 'string'],
            'old' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
        ]);

        $this->sectionQuery($data)->update(['forum_title' => $data['name']]);

        return back()->with('status', 'Раздел переименован.');
    }

    /** Удалить раздел форума со всеми темами (сообщения — каскадом). */
    public function destroySection(Request $request)
    {
        $data = $request->validate([
            'group' => ['nullable', 'string'],
            'old' => ['required', 'string'],
        ]);

        // Сообщения тем удаляет каскад БД (FK cascadeOnDelete)
        $deleted = $this->sectionQuery($data)->delete();

        return redirect()
            ->route('admin.forum.index')
            ->with('status', "Раздел удалён (тем: {$deleted}).");
    }

    public function edit(ForumTopic $topic)
    {
        return view('admin.forum.edit', [
            'topic' => $topic,
            'posts' => $topic->posts()->paginate(25)->withQueryString(),
            'groupNames' => ForumTopic::query()->whereNotNull('forum_group')->distinct()->orderBy('forum_group')->pluck('forum_group'),
            'sectionNames' => ForumTopic::query()->distinct()->orderBy('forum_title')->pluck('forum_title'),
        ]);
    }

    public function update(Request $request, ForumTopic $topic)
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/', Rule::unique('forum_topics', 'slug')->ignore($topic)],
            'forum_title' => ['required', 'string', 'max:255'],
            'forum_group' => ['nullable', 'string', 'max:255'],
            'disclaimer' => ['nullable', 'string'],
        ]);

        // Смена адреса темы: 301 со старого URL — внешние ссылки и выдача
        // поисковиков не ломаются (как при переносе страниц, PageMoveRedirect)
        if ($data['slug'] !== $topic->slug) {
            Redirect::updateOrCreate(
                ['from_path' => '/forum/'.$topic->slug],
                ['to_url' => '/forum/'.$data['slug'], 'status_code' => 301, 'comment' => 'Переименование темы форума (админка)'],
            );
            // Старый редирект НА новый адрес зациклил бы навигацию — убираем
            Redirect::where('from_path', '/forum/'.$data['slug'])->delete();
        }

        $topic->update($data);

        return redirect()
            ->route('admin.forum.edit', $topic)
            ->with('status', 'Тема сохранена.');
    }

    public function destroy(ForumTopic $topic)
    {
        $topic->delete();

        return redirect()
            ->route('admin.forum.index')
            ->with('status', "Тема «{$topic->title}» удалена.");
    }

    public function updatePost(Request $request, ForumPost $post)
    {
        $data = $request->validate([
            'author' => ['required', 'string', 'max:100'],
            'posted_at' => ['nullable', 'date'],
            'body' => ['required', 'string'],
        ]);

        $post->update($data);
        $this->refreshTopicStats($post->topic);

        return back()->with('status', 'Сообщение сохранено.');
    }

    public function destroyPost(ForumPost $post)
    {
        $topic = $post->topic;
        $post->delete();
        $this->refreshTopicStats($topic);

        return back()->with('status', 'Сообщение удалено.');
    }

    /** Темы категории; пустой old — темы вовсе без категории (NULL). */
    protected function groupQuery(array $data)
    {
        return ForumTopic::query()->when(
            filled($data['old'] ?? null),
            fn ($q) => $q->where('forum_group', $data['old']),
            fn ($q) => $q->whereNull('forum_group'),
        );
    }

    protected function sectionQuery(array $data)
    {
        return ForumTopic::query()
            ->where('forum_title', $data['old'])
            ->when(
                filled($data['group'] ?? null),
                fn ($q) => $q->where('forum_group', $data['group']),
                fn ($q) => $q->whereNull('forum_group'),
            );
    }

    /**
     * Счётчик и даты темы — производные от сообщений: после правки или
     * удаления поста пересчитываются, чтобы плитки /forum не врали.
     */
    protected function refreshTopicStats(ForumTopic $topic): void
    {
        $topic->update([
            'posts_count' => $topic->posts()->count(),
            'started_at' => $topic->posts()->min('posted_at'),
            'last_posted_at' => $topic->posts()->max('posted_at'),
        ]);
    }
}
