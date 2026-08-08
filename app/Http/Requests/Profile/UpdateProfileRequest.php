<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:100'],
            'last_name'  => ['sometimes', 'string', 'max:100'],
            'phone'      => [
                'sometimes',
                'string',
                'max:20',
                Rule::unique('users', 'phone')->ignore($this->user()->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.unique' => 'This phone number is already linked to another account.',
        ];
    }
}
