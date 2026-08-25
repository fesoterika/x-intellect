<?php

namespace Tests\Feature;

use App\Jobs\SubmitToIndexNow;
use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Section;
use App\Services\IndexNow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class IndexNowTest extends TestCase
{
    use RefreshDatabase;

    protected function withKey(): void
    {
        config([
            'indexnow.key' => 'testkey1234567890',
            'app.url' => 'https://x-intellect.org',
            'indexnow.host' => 'https://x-intellect.org',
        ]);
    }

    public function test_submission_is_skipped_without_key(): void
    {
        config(['indexnow.key' => '']);
        Http::fake();

        $this->assertSame(0, app(IndexNow::class)->submit(['/wiki/karma']));

        Http::assertNothingSent();
    }

    public function test_relative_urls_become_absolute_and_foreign_hosts_are_dropped(): void
    {
        $this->withKey();

        $urls = app(IndexNow::class)->normalize([
            '/wiki/karma',
            'https://x-intellect.org/forum',
            'https://example.com/чужое',
            '/wiki/karma',   // дубль
            '   ',           // пустое
        ]);

        $this->assertSame([
            'https://x-intellect.org/wiki/karma',
            'https://x-intellect.org/forum',
        ], $urls);
    }

    public function test_payload_carries_host_key_and_key_location(): void
    {
        $this->withKey();
        Http::fake(['*' => Http::response('', 200)]);

        $sent = app(IndexNow::class)->submit(['/wiki/karma']);

        $this->assertSame(1, $sent);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $body['host'] === 'x-intellect.org'
                && $body['key'] === 'testkey1234567890'
                && $body['keyLocation'] === 'https://x-intellect.org/testkey1234567890.txt'
                && $body['urlList'] === ['https://x-intellect.org/wiki/karma'];
        });
    }

    public function test_rejection_does_not_throw(): void
    {
        $this->withKey();
        Http::fake(['*' => Http::response('forbidden', 403)]);

        $this->assertSame(0, app(IndexNow::class)->submit(['/wiki/karma']));
    }

    public function test_unreachable_endpoint_does_not_throw(): void
    {
        $this->withKey();
        Http::fake(fn () => throw new \RuntimeException('сеть недоступна'));

        $this->assertSame(0, app(IndexNow::class)->submit(['/wiki/karma']));
    }

    public function test_publishing_a_page_queues_submission(): void
    {
        $this->withKey();
        Queue::fake();

        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki']);
        $page = Page::create([
            'section_id' => $section->id,
            'title' => 'Карма',
            'slug' => 'karma',
            'status' => 'published',
        ]);

        Queue::assertPushed(SubmitToIndexNow::class, fn ($job) => in_array($page->url(), $job->urls, true));
    }

    public function test_draft_page_is_not_submitted(): void
    {
        $this->withKey();
        Queue::fake();

        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki']);
        Page::create([
            'section_id' => $section->id,
            'title' => 'Черновик',
            'slug' => 'draft',
            'status' => 'draft',
        ]);

        Queue::assertNotPushed(SubmitToIndexNow::class);
    }

    public function test_deleting_a_page_queues_submission(): void
    {
        $this->withKey();

        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki']);
        $page = Page::create([
            'section_id' => $section->id,
            'title' => 'Карма',
            'slug' => 'karma',
            'status' => 'published',
        ]);
        $url = $page->url();

        Queue::fake();
        $page->delete();

        Queue::assertPushed(SubmitToIndexNow::class, fn ($job) => in_array($url, $job->urls, true));
    }

    public function test_relative_urls_follow_indexnow_host_when_it_differs_from_app_url(): void
    {
        // Разошедшиеся настройки: адреса достраивались от app.url, а хост
        // сверялся с indexnow.host — совпадения не было и пакет пустел.
        config([
            'indexnow.key' => 'testkey1234567890',
            'app.url' => 'http://localhost',
            'indexnow.host' => 'https://x-intellect.org',
        ]);

        $this->assertSame(
            ['https://x-intellect.org/wiki/karma'],
            app(IndexNow::class)->normalize(['/wiki/karma']),
        );

        $this->assertSame(
            'https://x-intellect.org/testkey1234567890.txt',
            app(IndexNow::class)->keyLocation(),
        );
    }

    public function test_path_beginning_with_http_is_not_mistaken_for_absolute_url(): void
    {
        $this->withKey();

        $this->assertSame(
            ['https://x-intellect.org/httpd-nastroika'],
            app(IndexNow::class)->normalize(['httpd-nastroika']),
        );
    }

    public function test_nothing_is_queued_while_the_key_is_empty(): void
    {
        config(['indexnow.key' => '']);
        Queue::fake();

        $section = Section::create(['title' => 'Вики', 'slug' => 'wiki']);
        Page::create([
            'section_id' => $section->id,
            'title' => 'Карма',
            'slug' => 'karma',
            'status' => 'published',
        ]);

        Queue::assertNotPushed(SubmitToIndexNow::class);
    }

    public function test_bulk_submission_matches_the_sitemap(): void
    {
        $this->withKey();
        Http::fake(['*' => Http::response('', 200)]);

        $visible = Section::create(['title' => 'Вики', 'slug' => 'wiki', 'is_visible' => true]);
        $hidden = Section::create(['title' => 'Черновики', 'slug' => 'drafts', 'is_visible' => false]);
        $page = Page::create([
            'section_id' => $visible->id,
            'title' => 'Карма',
            'slug' => 'karma',
            'status' => 'published',
        ]);
        Page::create([
            'section_id' => $visible->id,
            'title' => 'Черновик',
            'slug' => 'draft',
            'status' => 'draft',
        ]);
        $term = GlossaryTerm::create([
            'term' => 'Карма',
            'slug' => 'karma',
            'definition' => 'Закон причины и следствия.',
        ]);

        $this->artisan('indexnow:submit --all')->assertSuccessful();

        Http::assertSent(function ($request) use ($page, $term, $visible, $hidden) {
            $urls = $request->data()['urlList'];
            $base = 'https://x-intellect.org';

            return in_array($base.'/', $urls, true)
                && in_array($base.$visible->url(), $urls, true)
                && in_array($base.$page->url(), $urls, true)
                // термин глоссария — самостоятельный адрес, он в карте сайта
                && in_array($base.$term->url(), $urls, true)
                // черновик и скрытый раздел в поиск не подаются
                && ! in_array($base.'/wiki/draft', $urls, true)
                && ! in_array($base.$hidden->url(), $urls, true);
        });
    }
}
