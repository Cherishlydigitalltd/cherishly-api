<?php

namespace App\Http\Requests\Monetary;

use Illuminate\Foundation\Http\FormRequest;

class CreateMonetaryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title'         => ['required', 'string', 'max:200'],
            'description'   => ['nullable', 'string', 'max:1000'],
            'target_amount' => ['required', 'numeric', 'min:1000'],
            'cover_photo'   => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_amount.min' => 'Target amount must be at least ₦1,000.',
        ];
    }
}
