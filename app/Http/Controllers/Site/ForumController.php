<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\ForumTopic;

/**
 * Архив форума phpBB (слепок 2015 года) — только чтение: темы и сообщения
 * с никами авторов. Регистрации, профилей и отправки сообщений нет.
 */
class ForumController extends Controller
{
    public function index()
    {
        $topics = ForumTopic::query()
            ->orderBy('forum_position')
            ->orderByDesc('last_posted_at')
            ->get();

        // Категория → разделы форума → темы (порядок разделов — как на старом форуме)
        $groups = $topics
            ->groupBy(fn ($t) => $t->forum_group ?? 'Форум')
            ->sortBy(fn ($items) => $items->min('forum_position'))
            ->map(fn ($items) => $items->groupBy('forum_title'));

        return view('site.forum.index', [
            'groups' => $groups,
            'topicsCount' => $topics->count(),
            'postsCount' => (int) $topics->sum('posts_count'),
        ]);
    }

    public function show(ForumTopic $topic)
    {
        return view('site.forum.topic', [
            'topic' => $topic,
            'posts' => $topic->posts()->paginate(25),
        ]);
    }
}
