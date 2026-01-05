<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateUserPointContractRequest extends FormRequest
{
    public function authorize(): bool { return true; } // ajustá con policy si querés
    public function rules(): array {
        return [
            'name'      => ['nullable','string','max:120'],
            'months'    => ['nullable','integer','min:1','max:60'], // o usa expires_at directamente
            'starts_at' => ['nullable','date'],
            'meta'      => ['nullable','array'],
        ];
    }
}
