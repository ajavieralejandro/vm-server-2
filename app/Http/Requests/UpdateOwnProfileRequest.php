<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOwnProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'nullable', 'string', 'min:2', 'max:100'],
            'app_phone' => ['sometimes', 'nullable', 'string', 'max:20'],
            'bio' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'preferences' => ['sometimes', 'nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'display_name.min' => 'El nombre para mostrar debe tener al menos 2 caracteres.',
            'display_name.max' => 'El nombre para mostrar no puede exceder 100 caracteres.',
            'app_phone.max' => 'El teléfono no puede exceder 20 caracteres.',
            'bio.max' => 'La biografía no puede exceder 1000 caracteres.',
            'preferences.array' => 'Las preferencias deben ser un objeto JSON válido.',
        ];
    }
}