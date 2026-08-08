<?php
// app/Http/Requests/Registry/CreateRegistryRequest.php

namespace App\Http\Requests\Registry;

use Illuminate\Foundation\Http\FormRequest;

class CreateRegistryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'        => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'cover_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'is_public'   => ['nullable', 'boolean'],
        ];
    }
}
