<?php

namespace App\Http\Requests\Admin;

use App\Models\Page;
use App\Models\Section;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isEditor() ?? false;
    }

    public function rules(): array
    {
        /** @var Section|null $current */
        $current = $this->route('section');

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('sections', 'slug')->ignore($current),
            ],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('sections', 'id'),
                function (string $attribute, mixed $value, \Closure $fail) use ($current) {
                    $parent = Section::find($value);

                    if ($current && (int) $value === $current->id) {
                        $fail('Раздел не может быть родителем самого себя.');
                    } elseif ($parent && ! $parent->isRoot()) {
                        // Иерархия ограничена двумя уровнями: раздел → подраздел.
                        $fail('Родителем может быть только корневой раздел.');
                    } elseif ($current && $current->children()->exists()) {
                        $fail('У раздела есть подразделы — его нельзя сделать подразделом.');
                    }
                },
            ],
            'description' => ['nullable', 'string'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_visible' => ['boolean'],
            'show_on_home' => ['boolean'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            // Подраздел резолвится маршрутом /{раздел}/{slug}, где приоритет
            // у страницы — slug подраздела не должен совпадать со slug страницы.
            $slug = $this->input('slug') ?: Str::slug((string) $this->input('title'));

            if ($this->filled('parent_id') && $slug && Page::where('slug', $slug)->exists()) {
                $validator->errors()->add('slug', 'Slug подраздела совпадает со slug существующей страницы — листинг подраздела будет недоступен.');
            }
        });
    }

    public function sectionData(): array
    {
        $data = $this->validated();
        $data['slug'] = $data['slug'] ?? Str::slug($data['title']);
        $data['position'] = $data['position'] ?? 0;
        $data['parent_id'] = $data['parent_id'] ?? null;
        $data['is_visible'] = $this->boolean('is_visible');
        $data['show_on_home'] = $this->boolean('show_on_home');

        // Пустой документ Trix (<div><br></div> и т.п.) — это отсутствие описания
        if (array_key_exists('description', $data)
            && trim(strip_tags((string) $data['description'])) === '') {
            $data['description'] = null;
        }

        // Ссылки на localhost из редактора → относительные
        if (! empty($data['description'])) {
            $data['description'] = app(\App\Services\LocalLinks::class)->relativize($data['description']);
        }

        return $data;
    }
}
