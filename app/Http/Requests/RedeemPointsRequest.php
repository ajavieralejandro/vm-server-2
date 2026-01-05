<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RedeemPointsRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array {
        return [
            'points'      => ['required','integer','min:1'],
            'reason'      => ['nullable','string','max:255'],
            'contract_id' => ['nullable','integer','exists:user_point_contracts,id'],
            'meta'        => ['nullable','array'],
        ];
    }
}
