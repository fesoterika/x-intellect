<?php

namespace Database\Seeders;

use App\Models\Redirect;
use Illuminate\Database\Seeder;

/**
 * Редиректы (Этап 1/5 плана). Обёртки /go/*.html переносятся с заглушки
 * как есть и поддерживаются рабочими - они уже разошлись по сети;
 * это осознанный механизм обхода adblock, вырезающего прямые ссылки.
 */
class RedirectSeeder extends Seeder
{
    public function run(): void
    {
        $redirects = [
            // Обёртки /go/* с заглушки (цели взяты из исходных файлов go/*.html)
            ['from_path' => '/go/dzen.html', 'to_url' => 'https://dzen.ru/fesoterika', 'status_code' => 302,
                'comment' => 'Дзен-канал автора (обход adblock)'],
            ['from_path' => '/go/about.html', 'to_url' => 'https://dzen.ru/a/aCwgF-lXJnE3jl37?share_to=link', 'status_code' => 302,
                'comment' => 'Статья «Кто я?» (обход adblock)'],
            ['from_path' => '/go/donate.html', 'to_url' => 'https://dzen.ru/fesoterika?donate=true', 'status_code' => 302,
                'comment' => 'Поддержать автора (обход adblock)'],

            // 301 со старых архивных URL на новые SEO-url.
            // Адрес с query-string матчится middleware по rawurldecode(URI),
            // поэтому здесь он хранится в декодированном юникод-виде.
            ['from_path' => '/wiki/index.php?title=Глоссарий', 'to_url' => '/glossary', 'status_code' => 301,
                'comment' => 'Страница «Глоссарий» старой MediaWiki'],
            ['from_path' => '/wiki/index.php', 'to_url' => '/wiki', 'status_code' => 301,
                'comment' => 'Старый вход в MediaWiki'],
            ['from_path' => '/glossarij', 'to_url' => '/glossary', 'status_code' => 301,
                'comment' => 'Прежний slug глоссария на новом сайте'],
        ];

        foreach ($redirects as $redirect) {
            Redirect::updateOrCreate(['from_path' => $redirect['from_path']], $redirect);
        }
    }
}
