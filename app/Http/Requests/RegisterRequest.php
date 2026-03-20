<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    private function isPadronUser(?User $u): bool
    {
        if (!$u) return false;

        $userType = is_object($u->user_type) ? ($u->user_type->value ?? null) : $u->user_type;
        $socioSource = $u->socio_source ?? null;

        return $userType === 'api' || $socioSource === 'padron';
    }

    public function rules(): array
    {
        $dni = (string) $this->input('dni', '');
        $existing = $dni ? User::where('dni', $dni)->first() : null;
        $existingIsPadron = $this->isPadronUser($existing);

        return [
            'dni' => [
                'required',
                'string',
                'size:8',
                'regex:/^[0-9]+$/',
                // ✅ Solo exigir unique si NO existe, o si existe pero NO es padrón
                Rule::unique('users', 'dni')->when($existingIsPadron, function ($rule) {
                    // cuando es padrón, "unique" no aplica (permitimos update)
                    return Rule::unique('users', 'dni')->ignore(null);
                }),
            ],

            'name' => ['required', 'string', 'min:2', 'max:100'],

            'email' => [
                'required',
                'email',
                'max:255',
                // ✅ Si es update de padrón, ignorar el propio user para email unique
                Rule::unique('users', 'email')->when($existingIsPadron && $existing, function () use ($existing) {
                    return Rule::unique('users', 'email')->ignore($existing->id);
                }),
            ],

            'password' => ['required', 'string', 'min:8', 'confirmed'],

            'phone' => ['nullable', 'string', 'max:20'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $dni = (string) $this->input('dni', '');
            if (!$dni) return;

            $existing = User::where('dni', $dni)->first();
            if (!$existing) return;

            // ✅ Si existe pero NO es padrón => bloquear con code claro
            if (!$this->isPadronUser($existing)) {
                $v->errors()->add('dni', 'Ese DNI ya tiene una cuenta registrada. Iniciá sesión o recuperá la contraseña.');
                $v->errors()->add('code', 'ALREADY_REGISTERED');
            }
        });
    }

    public function messages(): array
    {
        return [
            'dni.required' => 'El DNI es obligatorio.',
            'dni.size' => 'El DNI debe tener exactamente 8 dígitos.',
            'dni.regex' => 'El DNI solo puede contener números.',
            'dni.unique' => 'Este DNI ya está registrado.',

            'name.required' => 'El nombre es obligatorio.',
            'name.min' => 'El nombre debe tener al menos 2 caracteres.',
            'name.max' => 'El nombre no puede exceder 100 caracteres.',

            'email.required' => 'El email es obligatorio.',
            'email.email' => 'Debe ser un email válido.',
            'email.unique' => 'Este email ya está registrado.',

            'password.required' => 'La contraseña es obligatoria.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',

            'phone.max' => 'El teléfono no puede exceder 20 caracteres.'
        ];
    }

    public function attributes(): array
    {
        return [
            'dni' => 'DNI',
            'name' => 'nombre',
            'email' => 'email',
            'password' => 'contraseña',
            'phone' => 'teléfono'
        ];
    }
}
