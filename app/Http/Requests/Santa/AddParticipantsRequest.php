<?php

namespace App\Http\Requests\Santa;

use Illuminate\Foundation\Http\FormRequest;

class AddParticipantsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'participants'           => ['required', 'array', 'min:2'],
            'participants.*.name'    => ['required', 'string', 'max:200'],
            'participants.*.email'   => ['nullable', 'email'],
        ];
    }

    public function messages(): array
    {
        return [
            'participants.min'            => 'At least 2 participants are required.',
            'participants.*.name.required' => 'Participant name is required.',
        ];
    }
}
