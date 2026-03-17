<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AdminProfessorController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Gym\Admin\ExerciseController;
use App\Http\Controllers\Gym\Admin\DailyTemplateController;
use App\Http\Controllers\Gym\Admin\SetController;
use App\Http\Controllers\Gym\Admin\WeeklyTemplateController;
use App\Http\Controllers\Gym\Admin\WeeklyAssignmentController;

/*
|--------------------------------------------------------------------------
| Admin Panel Routes
|--------------------------------------------------------------------------
|
| Rutas protegidas para el panel de administración.
| Requieren autenticación y rol de administrador.
|
*/

Route::middleware(['auth:sanctum', 'admin'])->prefix('admin')->group(function () {

    // ==================== GESTIÓN DE USUARIOS ====================

    Route::prefix('users')->group(function () {
        Route::get('/', [AdminUserController::class, 'index'])->name('admin.users.index');
        Route::post('/', [AdminUserController::class, 'store'])->name('admin.users.store');
        Route::get('/stats', [AdminUserController::class, 'stats'])->name('admin.users.stats');
        Route::get('/{user}', [AdminUserController::class, 'show'])->name('admin.users.show');
        Route::put('/{user}', [AdminUserController::class, 'update'])->name('admin.users.update');
        Route::delete('/{user}', [AdminUserController::class, 'destroy'])->name('admin.users.destroy');

        // Gestión de roles
        Route::post('/{user}/assign-admin', [AdminUserController::class, 'assignAdminRole'])
            ->name('admin.users.assign-admin');
        Route::delete('/{user}/remove-admin', [AdminUserController::class, 'removeAdminRole'])
            ->name('admin.users.remove-admin');

        // Gestión de estado de cuenta
        Route::post('/{user}/suspend', [AdminUserController::class, 'suspend'])
            ->name('admin.users.suspend');
        Route::post('/{user}/activate', [AdminUserController::class, 'activate'])
            ->name('admin.users.activate');
    });

    // ==================== GESTIÓN DE PROFESORES (solo admin) ====================
    // Operaciones sensibles que requieren rol admin explícito

    Route::prefix('professors')->group(function () {
        Route::post('/create-local', [AdminProfessorController::class, 'createLocalProfessor'])
            ->name('admin.professors.create-local');
        Route::put('/{professor}', [AdminProfessorController::class, 'update'])
            ->name('admin.professors.update');
        Route::post('/{professor}/reassign-student', [AdminProfessorController::class, 'reassignStudent'])
            ->name('admin.professors.reassign-student');
    });

    // ==================== LOGS DE AUDITORÍA ====================
    
    Route::prefix('audit')->group(function () {
        Route::get('/', [AuditLogController::class, 'index'])->name('admin.audit.index');
        Route::get('/stats', [AuditLogController::class, 'stats'])->name('admin.audit.stats');
        Route::get('/filter-options', [AuditLogController::class, 'filterOptions'])
            ->name('admin.audit.filter-options');
        Route::post('/export', [AuditLogController::class, 'export'])->name('admin.audit.export');
        Route::get('/{auditLog}', [AuditLogController::class, 'show'])->name('admin.audit.show');
    });

    // ==================== CONFIGURACIÓN DEL SISTEMA ====================
    Route::prefix('settings')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('admin.settings.index');
        Route::get('/public', [SettingsController::class, 'public'])->name('admin.settings.public');
        Route::get('/{key}', [SettingsController::class, 'show'])->name('admin.settings.show');
        Route::post('/', [SettingsController::class, 'store'])->name('admin.settings.store');
        Route::put('/{key}', [SettingsController::class, 'update'])->name('admin.settings.update');
        Route::delete('/{key}', [SettingsController::class, 'destroy'])->name('admin.settings.destroy');
        Route::post('/bulk-update', [SettingsController::class, 'bulkUpdate'])->name('admin.settings.bulk-update');
    });
    
    // ==================== DASHBOARD Y ESTADÍSTICAS ====================
    
    Route::get('/dashboard', function () {
        return response()->json([
            'message' => 'Admin dashboard endpoint - to be implemented',
            'user' => auth()->user(),
            'permissions' => auth()->user()->permissions ?? [],
        ]);
    })->name('admin.dashboard');

});

// ==================== PANEL DE GIMNASIO ====================
Route::middleware(['auth:sanctum', 'professor'])->prefix('admin/gym')->group(function () {
    
    // Ejercicios
    Route::prefix('exercises')->group(function () {
        Route::get('/', [ExerciseController::class, 'index'])->name('admin.gym.exercises.index');
        Route::post('/', [ExerciseController::class, 'store'])->name('admin.gym.exercises.store');
        Route::get('/{exercise}', [ExerciseController::class, 'show'])->name('admin.gym.exercises.show');
        Route::put('/{exercise}', [ExerciseController::class, 'update'])->name('admin.gym.exercises.update');
        Route::delete('/{exercise}', [ExerciseController::class, 'destroy'])->name('admin.gym.exercises.destroy');
        Route::post('/{exercise}/duplicate', [ExerciseController::class, 'duplicate'])->name('admin.gym.exercises.duplicate');
        
        // Nuevos endpoints para manejo mejorado de eliminación
        Route::get('/{exercise}/dependencies', [ExerciseController::class, 'checkDependencies'])->name('admin.gym.exercises.dependencies');
        Route::delete('/{exercise}/force', [ExerciseController::class, 'forceDestroy'])->name('admin.gym.exercises.force-destroy');
    });
    
    // Plantillas Diarias
    Route::prefix('daily-templates')->group(function () {
        Route::get('/', [DailyTemplateController::class, 'index'])->name('admin.gym.daily-templates.index');
        Route::post('/', [DailyTemplateController::class, 'store'])->name('admin.gym.daily-templates.store');
        Route::get('/{dailyTemplate}', [DailyTemplateController::class, 'show'])->name('admin.gym.daily-templates.show');
        Route::put('/{dailyTemplate}', [DailyTemplateController::class, 'update'])->name('admin.gym.daily-templates.update');
        Route::delete('/{dailyTemplate}', [DailyTemplateController::class, 'destroy'])->name('admin.gym.daily-templates.destroy');
        Route::post('/{dailyTemplate}/duplicate', [DailyTemplateController::class, 'duplicate'])->name('admin.gym.daily-templates.duplicate');
    });
    
    // Sets (series individuales)
    Route::prefix('sets')->group(function () {
        Route::put('/{set}', [SetController::class, 'update'])->name('admin.gym.sets.update');
        Route::delete('/{set}', [SetController::class, 'destroy'])->name('admin.gym.sets.destroy');
    });
    
    // Plantillas Semanales
    Route::prefix('weekly-templates')->group(function () {
        Route::get('/', [WeeklyTemplateController::class, 'index'])->name('admin.gym.weekly-templates.index');
        Route::post('/', [WeeklyTemplateController::class, 'store'])->name('admin.gym.weekly-templates.store');
        Route::get('/{template}', [WeeklyTemplateController::class, 'show'])->name('admin.gym.weekly-templates.show');
        Route::put('/{template}', [WeeklyTemplateController::class, 'update'])->name('admin.gym.weekly-templates.update');
        Route::delete('/{template}', [WeeklyTemplateController::class, 'destroy'])->name('admin.gym.weekly-templates.destroy');
        Route::post('/{template}/duplicate', [WeeklyTemplateController::class, 'duplicate'])->name('admin.gym.weekly-templates.duplicate');
    });
    
    // Asignaciones Semanales
    Route::prefix('weekly-assignments')->group(function () {
        Route::get('/', [WeeklyAssignmentController::class, 'index'])->name('admin.gym.weekly-assignments.index');
        Route::get('/stats', [WeeklyAssignmentController::class, 'stats'])->name('admin.gym.weekly-assignments.stats');
        Route::post('/', [WeeklyAssignmentController::class, 'store'])->name('admin.gym.weekly-assignments.store');
        Route::get('/{assignment}', [WeeklyAssignmentController::class, 'show'])->name('admin.gym.weekly-assignments.show');
        Route::put('/{assignment}', [WeeklyAssignmentController::class, 'update'])->name('admin.gym.weekly-assignments.update');
        Route::delete('/{assignment}', [WeeklyAssignmentController::class, 'destroy'])->name('admin.gym.weekly-assignments.destroy');
        Route::get('/{assignment}/adherence', [WeeklyAssignmentController::class, 'adherence'])->name('admin.gym.weekly-assignments.adherence');
        Route::post('/{assignment}/duplicate', [WeeklyAssignmentController::class, 'duplicate'])->name('admin.gym.weekly-assignments.duplicate');
    });
});
