<?php

namespace App\Http\Requests\Invitation;

use Illuminate\Foundation\Http\FormRequest;

class RsvpRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    /** The public RSVP page used to send "declined"; accept it as an alias. */
    protected function prepareForValidation(): void
    {
        if ($this->input('rsvp_status') === 'declined') {
            $this->merge(['rsvp_status' => 'not_attending']);
        }
    }

    public function rules(): array
    {
        return [
            'rsvp_status' => ['required', 'in:attending,not_attending'],
            'has_plus_one' => ['nullable', 'boolean'],
        ];
    }
}
