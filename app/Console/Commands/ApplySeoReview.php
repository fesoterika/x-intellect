<?php

namespace App\Console\Commands;

use App\Models\ForumTopic;
use App\Models\GlossaryTerm;
use App\Models\Page;
use App\Models\Section;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Apply a reviewed manifest without invoking body/slug transformations. */
class ApplySeoReview extends Command
{
    protected $signature = 'seo:apply-review {manifest} {--apply : Apply the verified changes} {--backup= : JSON snapshot path, required when applying}';

    protected $description = 'Validate and apply approved titles and SEO metadata while preserving URLs and article bodies';

    public const EXCLUDED = [203, 219, 220, 221, 222, 223, 293, 294, 295];

    public function handle(): int
    {
        try {
            $manifest = json_decode(file_get_contents($this->argument('manifest')), true, 512, JSON_THROW_ON_ERROR);
            if (($manifest['url_changes_allowed'] ?? true) !== false || empty($manifest['records'])) {
                throw new RuntimeException('A manifest with fixed URLs and records is required.');
            }
            if ($this->option('apply') && ! $this->option('backup')) {
                throw new RuntimeException('--backup is required when applying.');
            }
            $counts = DB::transaction(function () use ($manifest) {
                $prepared = [];
                $keys = [];
                foreach ($manifest['records'] as $record) {
                    $key = $record['kind'].':'.$record['id'];
                    if (isset($keys[$key])) {
                        throw new RuntimeException('Duplicate record: '.$key);
                    }
                    $keys[$key] = true;
                    $prepared[] = $this->prepare($record);
                }
                $counts = ['verified' => count($prepared), 'changed_records' => 0, 'titles' => 0, 'meta_titles' => 0, 'descriptions' => 0];
                if ($this->option('apply')) {
                    $backup = $this->option('backup');
                    if (file_exists($backup) || ! is_dir(dirname($backup))) {
                        throw new RuntimeException('Backup must be a new file in an existing directory.');
                    }
                    $snapshot = array_map(fn ($item) => ['kind' => $item['record']['kind'], 'id' => $item['record']['id'], 'url' => $item['record']['url'], 'attributes' => $item['model']?->getAttributes()], $prepared);
                    if (file_put_contents($backup, json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) === false) {
                        throw new RuntimeException('Cannot write backup.');
                    }
                    chmod($backup, 0600);
                }
                foreach ($prepared as $item) {
                    $changes = $item['changes'];
                    if (! $changes) {
                        continue;
                    }
                    $counts['changed_records']++;
                    foreach (['titles', 'meta_titles', 'descriptions'] as $field) {
                        $counts[$field] += $item['counts'][$field];
                    }
                    if (! $this->option('apply')) {
                        continue;
                    }
                    $model = $item['model'];
                    if ($item['record']['kind'] === 'site') {
                        DB::table('settings')->updateOrInsert(['key' => $changes['key']], ['value' => $changes['value'], 'updated_at' => now(), 'created_at' => $model?->created_at ?? now()]);
                        continue;
                    }
                    // Store a title revision explicitly. No PageObserver runs:
                    // it would reprocess archival bodies during a metadata edit.
                    if ($model instanceof Page && isset($changes['title'])) {
                        $model->revisions()->create([
                            'title' => $model->title, 'body' => $model->body,
                            'source_type' => $model->source_type, 'source_url' => $model->source_url,
                            'archived_at' => $model->archived_at,
                            'note' => 'Обновлена командой '.now()->format('d.m.Y H:i'),
                            'reason' => 'Согласованный SEO-аудит 07.10.2026: URL и тело страницы сохранены.',
                        ]);
                    }
                    $model->getConnection()->table($model->getTable())->where('id', $model->id)->update($changes + ['updated_at' => now()]);
                    $model->refresh();
                    if ('https://x-intellect.org'.$model->url() !== $item['record']['url']) {
                        throw new RuntimeException('URL changed: '.$item['record']['url']);
                    }
                }
                return $counts;
            });
            if ($this->option('apply')) {
                Cache::forget('setting:seo.home.meta_title');
                Cache::forget('setting:seo.glossary.meta_title');
            }
            $this->line(json_encode(['applied' => (bool) $this->option('apply')] + $counts, JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function prepare(array $record): array
    {
        $kind = $record['kind'];
        $model = match ($kind) {
            'page' => Page::with('section.parent')->lockForUpdate()->findOrFail($record['id']),
            'section' => Section::with('parent')->lockForUpdate()->findOrFail($record['id']),
            'forum' => ForumTopic::with('posts')->lockForUpdate()->findOrFail($record['id']),
            'term' => GlossaryTerm::lockForUpdate()->findOrFail($record['id']),
            'site' => Setting::where('key', 'seo.'.$record['id'].'.meta_title')->lockForUpdate()->first(),
            default => throw new RuntimeException('Unknown kind: '.$kind),
        };
        $changes = [];
        $counts = ['titles' => 0, 'meta_titles' => 0, 'descriptions' => 0];
        if ($kind === 'site') {
            if (! in_array($record['id'], ['home', 'glossary'], true) || $record['url'] !== 'https://x-intellect.org'.($record['id'] === 'home' ? '/' : '/glossary')) {
                throw new RuntimeException('Invalid site metadata target.');
            }
            if (! empty($record['description'])) {
                throw new RuntimeException('Site descriptions are outside this manifest.');
            }
            $this->validateText($record['meta_title'], 255);
            if ($model?->value !== $record['meta_title']) {
                $changes = ['key' => 'seo.'.$record['id'].'.meta_title', 'value' => $record['meta_title']];
                $counts['meta_titles'] = 1;
            }
            return compact('record', 'model', 'changes', 'counts');
        }
        $title = $kind === 'term' ? $model->term : $model->title;
        if ($title !== $record['old_title'] || 'https://x-intellect.org'.$model->url() !== $record['url']) {
            throw new RuntimeException('Stale title or URL: '.$kind.':'.$record['id']);
        }
        $content = match ($kind) {
            'page' => $model->body ?? '',
            'section' => $model->description ?? '',
            'forum' => $model->posts->pluck('body')->implode("\n"),
            'term' => $model->definition ?? '',
        };
        if (hash('sha256', $content) !== $record['content_sha256']) {
            throw new RuntimeException('Content changed since review: '.$kind.':'.$record['id']);
        }
        if ($kind === 'page' && in_array($model->id, self::EXCLUDED, true)) {
            if ($record['title'] !== $title || $record['meta_title'] !== $model->seoValue('meta_title') || ! empty($record['description'])) {
                throw new RuntimeException('Navigation page must remain unchanged: '.$model->id);
            }
            return compact('record', 'model', 'changes', 'counts');
        }
        if ($kind !== 'page' && $record['title'] !== $title) {
            throw new RuntimeException('Only material headings may change.');
        }
        if ($kind === 'page') {
            $this->validateText($record['title'], 255);
            $this->validateText($record['meta_title'], 255);
            if ($record['title'] !== $title) {
                $changes['title'] = $record['title'];
                $counts['titles'] = 1;
            }
            $seo = $model->seo ?? [];
            if (($seo['meta_title'] ?? null) !== $record['meta_title']) {
                $seo['meta_title'] = $record['meta_title'];
                $counts['meta_titles'] = 1;
            }
            if (! empty($record['description']) && ($seo['meta_description'] ?? null) !== $record['description']) {
                $this->validateText($record['description'], 500);
                $seo['meta_description'] = $record['description'];
                $counts['descriptions'] = 1;
            }
            if ($seo !== ($model->seo ?? [])) {
                $changes['seo'] = json_encode($seo, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        } else {
            if ($kind === 'section' && $model->meta_title !== $record['meta_title']) {
                $this->validateText($record['meta_title'], 255);
                $changes['meta_title'] = $record['meta_title'];
                $counts['meta_titles'] = 1;
            }
            if (! empty($record['description']) && $model->meta_description !== $record['description']) {
                $this->validateText($record['description'], 500);
                $changes['meta_description'] = $record['description'];
                $counts['descriptions'] = 1;
            }
        }
        return compact('record', 'model', 'changes', 'counts');
    }

    private function validateText(string $value, int $limit): void
    {
        if (trim($value) === '' || mb_strlen($value) > $limit) {
            throw new RuntimeException('Empty or oversized SEO field.');
        }
    }
}
