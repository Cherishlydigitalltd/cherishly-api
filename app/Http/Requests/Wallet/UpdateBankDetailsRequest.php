<?php
// app/Http/Requests/Wallet/UpdateBankDetailsRequest.php

namespace App\Http\Requests\Wallet;

use Illuminate\Foundation\Http\FormRequest;

class UpdateBankDetailsRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'bank_name'      => ['required', 'string', 'max:100'],
            'account_number' => ['required', 'string', 'size:10'],
            'account_name'   => ['required', 'string', 'max:200'],
            'bank_code'      => ['required', 'string', 'max:10'],
        ];
    }

    public function messages(): array
    {
        return [
            'account_number.size' => 'Account number must be exactly 10 digits.',
        ];
    }
}
