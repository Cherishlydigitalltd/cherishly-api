<?php

namespace App\Http\Requests\Invitation;

use Illuminate\Foundation\Http\FormRequest;

class RsvpRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'rsvp_status' => ['required', 'in:attending,not_attending'],
            'has_plus_one' => ['nullable', 'boolean'],
        ];
    }
}
