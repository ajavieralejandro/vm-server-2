<?php

namespace App\Services\Admin;

use App\Models\User;
use App\Services\Core\AuditService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Hash;

class UserManagementService
{
    public function __construct(
        private AuditService $auditService
    ) {}

    /**
     * Obtiene usuarios con filtros aplicados
     */
    public function getFilteredUsers(array $filters, int $perPage = 20): LengthAwarePaginator
    {
        $query = User::query();

        $this->applyFilters($query, $filters);
        $this->applySorting($query, $filters);

        $users = $query->paginate($perPage);

        // Agregar estadísticas del gimnasio para profesores
        $users->getCollection()->transform(function ($user) {
            $userData = $user->toArray();
            
            if ($user->is_professor) {
                $userData['gym_stats'] = $user->getProfessorStats();
            }
            
            return $userData;
        });

        return $users;
    }

    /**
     * Aplica filtros a la consulta de usuarios
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        // Búsqueda global
        if (!empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('dni', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('nombre', 'like', "%{$search}%")
                  ->orWhere('apellido', 'like', "%{$search}%");
            });
        }

        // Filtros específicos
        if (!empty($filters['user_type'])) {
            $query->whereIn('user_type', $filters['user_type']);
        }

        if (isset($filters['is_professor'])) {
            $query->where('is_professor', $filters['is_professor']);
        }

        if (isset($filters['is_admin'])) {
            $query->where('is_admin', $filters['is_admin']);
        }

        if (!empty($filters['estado_socio'])) {
            $query->whereIn('estado_socio', $filters['estado_socio']);
        }

        if (!empty($filters['semaforo'])) {
            $query->whereIn('semaforo', $filters['semaforo']);
        }

        if (!empty($filters['account_status'])) {
            if (is_array($filters['account_status'])) {
                $query->whereIn('account_status', $filters['account_status']);
            } else {
                $query->where('account_status', $filters['account_status']);
            }
        }

        // Filtros de fecha
        if (!empty($filters['date_from'])) {
            $query->where('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->where('created_at', '<=', $filters['date_to']);
        }

        // Filtro de acceso al gimnasio
        if (isset($filters['has_gym_access'])) {
            $hasGymAccess = $filters['has_gym_access'];
            if ($hasGymAccess) {
                $query->where(function ($q) {
                    $q->where('is_professor', true)
                      ->orWhere(function ($subQ) {
                          $subQ->where('user_type', 'api')
                               ->where('semaforo', 1)
                               ->where('estado_socio', 'ACTIVO');
                      });
                });
            }
        }
    }

    /**
     * Aplica ordenamiento a la consulta
     */
    private function applySorting(Builder $query, array $filters): void
    {
        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        
        $allowedSorts = ['name', 'dni', 'email', 'created_at', 'last_login', 'user_type'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDirection);
        }
    }

    /**
     * Crea un nuevo usuario
     */
    public function createUser(array $data, User $creator): User
    {
        // Validar permisos para crear admin
        if (($data['is_admin'] ?? false) && !$creator->isSuperAdmin()) {
            throw new \Exception('Only super admins can create admin users.');
        }

        $data['password'] = Hash::make($data['password']);
        $data['account_status'] = $data['account_status'] ?? 'active';

        if ($data['is_professor'] ?? false) {
            $data['professor_since'] = now();
        }

        $user = User::create($data);

        // Log de auditoría
        $this->auditService->logCreate('user', $user->id, $data);

        return $user;
    }

    /**
     * Actualiza un usuario
     */
    public function updateUser(User $user, array $data, User $updater): User
    {
        // Validar permisos para modificar admin
        if (isset($data['is_admin']) && $data['is_admin'] !== $user->is_admin) {
            if (!$updater->isSuperAdmin()) {
                throw new \Exception('Only super admins can modify admin roles.');
            }
        }

        // No permitir que un admin se quite sus propios permisos
        if ($user->id === $updater->id && isset($data['is_admin']) && !$data['is_admin']) {
            throw new \Exception('You cannot remove your own admin privileges.');
        }

        $oldValues = $user->only(array_keys($data));

        // Manejar cambio de rol de profesor
        if (isset($data['is_professor'])) {
            if ($data['is_professor'] && !$user->is_professor) {
                $data['professor_since'] = now();
            } elseif (!$data['is_professor'] && $user->is_professor) {
                $data['professor_since'] = null;
            }
        }

        $user->forceFill($data)->save();

        // Log de auditoría
        $this->auditService->logUpdate('user', $user->id, $oldValues, $data);

        return $user;
    }

    /**
     * Suspende un usuario
     */
    public function suspendUser(User $user, User $suspender, ?string $reason = null): User
    {
        if ($user->id === $suspender->id) {
            throw new \Exception('You cannot suspend your own account.');
        }

        if ($user->isSuperAdmin()) {
            throw new \Exception('Super admin accounts cannot be suspended.');
        }

        $user->suspend($reason);

        // Log de auditoría
        $this->auditService->logUserSuspension($user->id, $reason);

        return $user;
    }

    /**
     * Activa un usuario
     */
    public function activateUser(User $user): User
    {
        $user->activate();

        // Log de auditoría
        $this->auditService->logUserActivation($user->id);

        return $user;
    }

    /**
     * Asigna rol de administrador
     */
    public function assignAdminRole(User $user, array $permissions, User $assigner): User
    {
        if (!$assigner->isSuperAdmin()) {
            throw new \Exception('Only super admins can assign admin roles.');
        }

        $user->assignAdminRole($permissions);

        // Log de auditoría
        $this->auditService->logRoleAssignment($user->id, 'admin', $permissions);

        return $user;
    }

    /**
     * Remueve rol de administrador
     */
    public function removeAdminRole(User $user, User $remover): User
    {
        if (!$remover->isSuperAdmin()) {
            throw new \Exception('Only super admins can remove admin roles.');
        }

        if ($user->id === $remover->id) {
            throw new \Exception('You cannot remove your own admin role.');
        }

        $user->removeAdminRole();

        // Log de auditoría
        $this->auditService->logRoleRemoval($user->id, 'admin');

        return $user;
    }

    /**
     * Obtiene estadísticas de usuarios
     */
    public function getUserStats(): array
    {
        return [
            'overview' => [
                'total_users' => User::count(),
                'new_users_this_month' => User::where('created_at', '>=', now()->startOfMonth())->count(),
                'active_users' => User::where('account_status', 'active')->count(),
                'suspended_users' => User::where('account_status', 'suspended')->count(),
            ],
            'by_type' => [
                'local_users' => User::where('user_type', 'local')->count(),
                'api_users' => User::where('user_type', 'api')->count(),
            ],
            'by_role' => [
                'professors' => User::where('is_professor', true)->count(),
                'admins' => User::where('is_admin', true)->count(),
                'regular_users' => User::where('is_professor', false)->where('is_admin', false)->count(),
            ],
            'club_members' => [
                'total_socios' => User::where('user_type', 'api')->whereNotNull('socio_id')->count(),
                'active_socios' => User::where('user_type', 'api')
                    ->where('estado_socio', 'ACTIVO')
                    ->where('semaforo', 1)
                    ->count(),
                'with_gym_access' => User::where(function ($q) {
                    $q->where('is_professor', true)
                      ->orWhere(function ($subQ) {
                          $subQ->where('user_type', 'api')
                               ->where('semaforo', 1)
                               ->where('estado_socio', 'ACTIVO');
                      });
                })->count(),
            ],
        ];
    }

    /**
     * Obtiene resumen de filtros para la respuesta
     */
    public function getFiltersSummary(): array
    {
        return [
            'total_users' => User::count(),
            'professors' => User::where('is_professor', true)->count(),
            'admins' => User::where('is_admin', true)->count(),
            'api_users' => User::where('user_type', 'api')->count(),
            'local_users' => User::where('user_type', 'local')->count(),
            'active_socios' => User::where('user_type', 'api')
                ->where('estado_socio', 'ACTIVO')
                ->where('semaforo', 1)
                ->count(),
            'suspended_accounts' => User::where('account_status', 'suspended')->count(),
        ];
    }
}
