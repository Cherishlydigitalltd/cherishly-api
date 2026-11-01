<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // No exists:users rule: it would tell callers which emails have accounts.
            'email' => ['required', 'email'],
            'otp'   => ['required', 'string', 'size:6'],
        ];
    }
}