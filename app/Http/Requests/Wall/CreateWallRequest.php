<?php
// app/Http/Requests/Wall/CreateWallRequest.php

namespace App\Http\Requests\Wall;

use Illuminate\Foundation\Http\FormRequest;

class CreateWallRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'cover_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'is_active'   => ['nullable', 'boolean'],
        ];
    }
}
