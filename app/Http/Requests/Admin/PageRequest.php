<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isEditor() ?? false;
    }

    public function rules(): array
    {
        return [
            'section_id' => ['nullable', 'exists:sections,id'],
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('pages', 'slug')->ignore($this->route('page')),
            ],
            'excerpt' => ['nullable', 'string'],
            'body' => ['nullable', 'string'],
            'page_type' => ['required', Rule::in(['page', 'author'])],
            'status' => ['required', Rule::in(['draft', 'published'])],
            'is_listed' => ['boolean'],
            'in_wiki_menu' => ['boolean'],
            'source_type' => ['required', Rule::in(array_keys(\App\Models\Page::SOURCE_TYPES))],
            'source_url' => ['nullable', 'url', 'max:2048'],
            'position' => ['nullable', 'integer', 'min:0'],
            'archived_at' => ['nullable', 'date'],
            'seo.meta_title' => ['nullable', 'string', 'max:255'],
            'seo.meta_description' => ['nullable', 'string', 'max:500'],
            'seo.og_image' => ['nullable', 'string', 'max:2048'],
            'seo.canonical' => ['nullable', 'string', 'max:2048'],
            'seo.schema_type' => ['nullable', Rule::in(['Article', 'FAQPage', 'Person', 'WebPage'])],
        ];
    }

    /** Данные страницы: пустые SEO-поля выбрасываются, чтобы сработало автозаполнение. */
    public function pageData(): array
    {
        $data = $this->validated();
        $data['seo'] = array_filter($data['seo'] ?? []) ?: null;
        $data['position'] = $data['position'] ?? 0;
        $data['is_listed'] = $this->boolean('is_listed');
        $data['in_wiki_menu'] = $this->boolean('in_wiki_menu');

        return $data;
    }
}
