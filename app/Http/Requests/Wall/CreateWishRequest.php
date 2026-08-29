<?php

namespace App\Http\Requests\Wall;

use Illuminate\Foundation\Http\FormRequest;

class CreateWishRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'         => ['required', 'string', 'max:200'],
            'message'      => ['required', 'string', 'max:2000'],
            'is_anonymous' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'message.max' => 'Message must not exceed 2000 characters.',
        ];
    }
}
