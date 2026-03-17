<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class CreateLocalProfessorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // El middleware admin protege la ruta
    }

    public function rules(): array
    {
        return [
            'dni'                             => ['required', 'string', 'size:8', 'regex:/^[0-9]+$/', 'unique:users,dni'],
            'name'                            => ['required', 'string', 'min:2', 'max:100'],
            'email'                           => ['required', 'email', 'max:255', 'unique:users,email'],
            'password'                        => ['required', 'string', 'min:8', 'confirmed'],
            'phone'                           => ['nullable', 'string', 'max:20'],
            'qualifications'                  => ['nullable', 'array'],
            'qualifications.education'        => ['nullable', 'string', 'max:255'],
            'qualifications.experience_years' => ['nullable', 'integer', 'min:0', 'max:50'],
            'qualifications.certifications'   => ['nullable', 'array'],
            'qualifications.certifications.*' => ['string', 'max:255'],
            'qualifications.specialties'      => ['nullable', 'array'],
            'qualifications.specialties.*'    => ['string', 'in:strength,hypertrophy,endurance,mobility,rehabilitation,functional,crossfit,yoga,pilates'],
            'notes'                           => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return [
            'dni.required'       => 'El DNI es obligatorio.',
            'dni.size'           => 'El DNI debe tener exactamente 8 dígitos.',
            'dni.regex'          => 'El DNI solo puede contener números.',
            'dni.unique'         => 'Este DNI ya está registrado.',
            'name.required'      => 'El nombre es obligatorio.',
            'name.min'           => 'El nombre debe tener al menos 2 caracteres.',
            'email.required'     => 'El email es obligatorio.',
            'email.email'        => 'Debe ser un email válido.',
            'email.unique'       => 'Este email ya está registrado.',
            'password.required'  => 'La contraseña es obligatoria.',
            'password.min'       => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
        ];
    }

    public function attributes(): array
    {
        return [
            'dni'      => 'DNI',
            'name'     => 'nombre',
            'email'    => 'email',
            'password' => 'contraseña',
            'phone'    => 'teléfono',
        ];
    }
}
