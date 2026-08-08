<?php
// app/Http/Requests/Registry/ContributeRequest.php

namespace App\Http\Requests\Registry;

use Illuminate\Foundation\Http\FormRequest;

class ContributeRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'donor_name'      => ['required', 'string', 'max:200'],
            'donor_email'     => ['required', 'email'],
            'donor_phone'     => ['nullable', 'string', 'max:20'],
            'amount'          => ['required', 'numeric', 'min:100'],
            'bvn'             => ['nullable', 'string', 'size:11'],
            'payment_method'  => ['required', 'in:paystack,bank_transfer'],
            'is_anonymous'    => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.min'  => 'Minimum contribution amount is ₦100.',
            'bvn.size'    => 'BVN must be exactly 11 digits.',
        ];
    }
}
