<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\ForumTopic;
use App\Support\RussianText;
use Illuminate\Http\Request;

/**
 * Архив форума phpBB (слепок 2015 года) — только чтение: темы и сообщения
 * с никами авторов. Регистрации, профилей и отправки сообщений нет.
 */
class ForumController extends Controller
{
    /**
     * Темы, где участники обсуждали здоровье, болезни и «целительство»:
     * внизу таких тем выводится приписка-дисклеймер (forum-medical-note),
     * что мнения авторов — не медицинские рекомендации.
     */
    private const MEDICAL_TOPIC_SLUGS = [
        'vic-infekciia',
        'voprosy-po-proektu-izosfera-i-parallelnymi-miry',
        'socialnye-iavleniia-zemli-civilizaciia-zemli',
        'celitelstvo-praktika',
        'novogodniaia-konferenciia-2016',
        'volnovaia-genetika',
        'etalony-osoboe-mnenie',
        'obsuzdenie-temy-celovek-zemlia-kosmos',
    ];

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
            'showMedicalNote' => in_array($topic->slug, self::MEDICAL_TOPIC_SLUGS, true),
        ]);
    }

    /**
     * Поиск по архиву форума: темы по заголовку и содержанию сообщений,
     * без учёта регистра. С ?partial=1 отдаёт только список найденных тем —
     * живые подсказки под строкой поиска (Alpine), без JS — обычная страница.
     */
    public function search(Request $request)
    {
        $query = trim((string) $request->query('q', ''));
        $topics = collect();

        if (mb_strlen($query) >= 2) {
            $topics = ForumTopic::query()
                ->where(function ($q) use ($query) {
                    RussianText::contains($q, 'title', $query);
                    $q->orWhereHas('posts', fn ($p) => RussianText::contains($p, 'body', $query));
                })
                ->orderBy('forum_position')
                ->orderByDesc('last_posted_at')
                ->get()
                // совпадения в заголовке — выше совпадений только в сообщениях
                ->sortBy(fn ($t) => mb_stripos($t->title, $query) === false ? 1 : 0)
                ->values();
        }

        if ($request->boolean('partial')) {
            return view('site.forum.results', ['query' => $query, 'topics' => $topics]);
        }

        return view('site.forum.search', ['query' => $query, 'topics' => $topics]);
    }
}
