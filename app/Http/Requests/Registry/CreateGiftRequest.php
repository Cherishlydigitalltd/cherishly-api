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
            // On update (PUT) only the fields sent change.
            'name'                    => [$this->isMethod('put') ? 'sometimes' : 'required_without_all:vendor_product_id,catalog_gift_id', 'nullable', 'string', 'max:200'],
            'description'             => ['nullable', 'string', 'max:1000'],
            'price'                   => [$this->isMethod('put') ? 'sometimes' : 'required_without_all:vendor_product_id,catalog_gift_id', 'nullable', 'numeric', 'min:0'],
            'quantity'                => ['nullable', 'integer', 'min:1'],
            'category'                => ['nullable', 'string', 'max:100'],
            'image'                   => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:10240'],
            'type'                    => ['nullable', 'in:physical,monetary'],
            'allow_cash_contribution' => ['nullable', 'boolean'],
            // Picked from the catalog (vendor items): the server copies the
            // vendor's name, price and photo. catalog_gift_id is the old name.
            'vendor_product_id'       => ['nullable', 'integer'],
            'catalog_gift_id'         => ['nullable', 'integer'],
        ];
    }
}
