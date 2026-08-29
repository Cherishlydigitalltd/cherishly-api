<?php

namespace App\Http\Requests\Invitation;

use Illuminate\Foundation\Http\FormRequest;

class CreateInvitationRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title'        => ['required', 'string', 'max:200'],
            'description'  => ['nullable', 'string', 'max:1000'],
            'cover_photo'  => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'rsvp_deadline'=> ['nullable', 'date', 'after:now'],
        ];
    }
}
