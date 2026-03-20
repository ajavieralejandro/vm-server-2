<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $userType = is_object($this->user_type)
            ? ($this->user_type->value ?? 'local')
            : ($this->user_type ?? 'local');

        $promotionStatus = is_object($this->promotion_status)
            ? ($this->promotion_status->value ?? 'none')
            : ($this->promotion_status ?? 'none');

        return [
            'id' => $this->id,
            'dni' => $this->dni,
            'user_type' => $userType,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            
            // Campos de promoción
            'promotion_status' => $promotionStatus,
            
            // Foto URL (siempre incluir, puede ser null)
            'foto_url' => $this->foto_url,
            
            // Campos específicos de usuarios API (solo si es tipo API)
            'socio_id' => $this->when($userType === 'api', $this->socio_id),
            'socio_n' => $this->when($userType === 'api', $this->socio_n),
            'nombre' => $this->when($userType === 'api', $this->nombre),
            'apellido' => $this->when($userType === 'api', $this->apellido),
            'barcode' => $this->when($userType === 'api', $this->barcode),
            
            // Campos financieros críticos para usuarios API
            'saldo' => $this->when($userType === 'api', $this->saldo),
            'semaforo' => $this->when($userType === 'api', $this->semaforo),
            'deuda' => $this->when($userType === 'api', $this->deuda),
            
            // Información personal adicional para usuarios API
            'nacionalidad' => $this->when($userType === 'api', $this->nacionalidad),
            'nacimiento' => $this->when($userType === 'api', $this->nacimiento?->toDateString()),
            'domicilio' => $this->when($userType === 'api', $this->domicilio),
            'localidad' => $this->when($userType === 'api', $this->localidad),
            'telefono' => $this->when($userType === 'api', $this->telefono),
            'celular' => $this->when($userType === 'api', $this->celular),
            
            // Estado del socio
            'estado_socio' => $this->when($userType === 'api', $this->estado_socio),
            'suspendido' => $this->when($userType === 'api', $this->suspendido),
            'categoria' => $this->when($userType === 'api', $this->categoria),
            
            // Campos del sistema (roles y permisos)
            'is_professor' => $this->is_professor ?? false,
            'is_admin' => $this->is_admin ?? false,
            'permissions' => $this->permissions ?? [],
            'account_status' => $this->account_status ?? 'active',
            'type_label' => $this->type_label,
            
            // Timestamps
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
