<?php
// app/Http/Requests/Santa/CreateSantaRequest.php

namespace App\Http\Requests\Santa;

use Illuminate\Foundation\Http\FormRequest;

class CreateSantaRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title'       => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:1000'],
            'budget'      => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
