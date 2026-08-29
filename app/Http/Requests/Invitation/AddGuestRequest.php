<?php

namespace App\Http\Requests\Invitation;

use Illuminate\Foundation\Http\FormRequest;

class AddGuestRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'guests'                => ['required', 'array', 'min:1'],
            'guests.*.full_name'    => ['required', 'string', 'max:200'],
            'guests.*.email'        => ['nullable', 'email'],
            'guests.*.phone'        => ['nullable', 'string', 'max:20'],
            'guests.*.allow_plus_one' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'guests.required'           => 'At least one guest is required.',
            'guests.*.full_name.required' => 'Guest name is required.',
        ];
    }
}
