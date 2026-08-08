<?php

namespace App\Http\Requests\Profile;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.required' => 'Please select an image to upload.',
            'avatar.mimes'    => 'Avatar must be a JPG, PNG or WebP image.',
            'avatar.max'      => 'Avatar must not exceed 5MB.',
        ];
    }
}
