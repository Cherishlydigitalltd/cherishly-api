<?php

namespace App\Http\Requests\Monetary;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMonetaryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title'         => ['sometimes', 'string', 'max:200'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'target_amount' => ['sometimes', 'numeric', 'min:1000'],
            'cover_photo'   => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'is_active'     => ['sometimes', 'boolean'],
        ];
    }
}
