<?php

namespace App\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCrawlTargetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('manage-crawler') ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['is_enabled' => $this->boolean('is_enabled'), 'schedule_hours' => $this->input('schedule_hours') ?: null]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\x00-\x1F\x7F]+$/u'],
            'url' => ['required', 'string', 'max:2048', 'url:http,https', function (string $attribute, mixed $value, Closure $fail): void {
                $parts = parse_url($value);
                if ($parts === false || isset($parts['user']) || isset($parts['pass']) || preg_match('/[\x00-\x20\x7F\\\\]/', $value)) {
                    $fail('Use an HTTP/HTTPS URL without credentials or control characters.');
                }
            }],
            'type' => ['required', Rule::in(['business', 'job'])],
            'is_enabled' => ['required', 'boolean'],
            'max_pages' => ['required', 'integer', 'between:1,1000'],
            'max_depth' => ['required', 'integer', 'between:0,10'],
            'schedule_hours' => ['nullable', 'integer', Rule::in([6, 24, 168])],
        ];
    }
}
