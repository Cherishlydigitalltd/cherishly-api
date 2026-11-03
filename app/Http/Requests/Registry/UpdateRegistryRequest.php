<?php
// app/Http/Requests/Registry/UpdateRegistryRequest.php

namespace App\Http\Requests\Registry;

use Illuminate\Foundation\Http\FormRequest;

class UpdateRegistryRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'        => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'cover_photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'is_public'   => ['nullable', 'boolean'],
            // Where vendor items get delivered.
            'delivery_name'    => ['nullable', 'string', 'max:200'],
            'delivery_phone'   => ['nullable', 'string', 'max:30'],
            'delivery_address' => ['nullable', 'string', 'max:500'],
            'delivery_state'   => ['nullable', 'string', \Illuminate\Validation\Rule::in(\App\Models\Vendor::STATES)],
        ];
    }
}
