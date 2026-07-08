<?php

namespace App\Http\Requests\Admin;

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
        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('sections', 'slug')->ignore($this->route('section')),
            ],
            'description' => ['nullable', 'string'],
            'position' => ['nullable', 'integer', 'min:0'],
            'is_visible' => ['boolean'],
        ];
    }

    public function sectionData(): array
    {
        $data = $this->validated();
        $data['slug'] = $data['slug'] ?? Str::slug($data['title']);
        $data['position'] = $data['position'] ?? 0;
        $data['is_visible'] = $this->boolean('is_visible');

        return $data;
    }
}
