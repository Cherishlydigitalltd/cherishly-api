<?php
// app/Http/Requests/Registry/CreateGiftRequest.php

namespace App\Http\Requests\Registry;

use Illuminate\Foundation\Http\FormRequest;

class CreateGiftRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'                    => ['required', 'string', 'max:200'],
            'description'             => ['nullable', 'string', 'max:1000'],
            'price'                   => ['required', 'numeric', 'min:0'],
            'quantity'                => ['nullable', 'integer', 'min:1'],
            'category'                => ['nullable', 'string', 'max:100'],
            'image'                   => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'type'                    => ['nullable', 'in:physical,monetary'],
            'allow_cash_contribution' => ['nullable', 'boolean'],
        ];
    }
}
