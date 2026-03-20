<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadOwnAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'avatar' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
    }

    public function messages(): array
    {
        return [
            'avatar.required' => 'Debe seleccionar una imagen.',
            'avatar.image' => 'El archivo debe ser una imagen válida.',
            'avatar.mimes' => 'El avatar debe ser JPG, JPEG, PNG o WEBP.',
            'avatar.max' => 'El avatar no puede superar los 5 MB.',
        ];
    }
}