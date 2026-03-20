<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\AuthProfileController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\PromotionController;
use App\Http\Controllers\Gym\Admin\ExerciseController as GymExerciseController;
use App\Http\Controllers\Gym\Admin\DailyTemplateController as GymDailyTemplateController;
use App\Http\Controllers\Gym\Admin\WeeklyTemplateController as GymWeeklyTemplateController;
use App\Http\Controllers\Gym\Admin\WeeklyAssignmentController as GymWeeklyAssignmentController;
use App\Http\Controllers\Gym\Mobile\MyPlanController as GymMyPlanController;
use App\Http\Controllers\Admin\AssignmentController as AdminAssignmentController;
use App\Http\Controllers\Gym\Professor\AssignmentController as ProfessorAssignmentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// Rutas de autenticación
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('register', [AuthController::class, 'register']);
    
    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::get('profile', [AuthProfileController::class, 'show']);
        Route::patch('profile', [AuthProfileController::class, 'update']);
        Route::post('profile/avatar', [AuthProfileController::class, 'uploadAvatar']);
        Route::delete('profile/avatar', [AuthProfileController::class, 'deleteAvatar']);
    });
});

// Rutas protegidas (requiere auth)
Route::middleware('auth:sanctum')->group(function () {
    
    // CRUD de usuarios
    Route::apiResource('users', UserController::class);
    
    // Rutas adicionales de usuarios
    Route::prefix('users')->group(function () {
        Route::get('search', [UserController::class, 'search']);
        Route::get('stats', [UserController::class, 'stats']);
        Route::get('needing-refresh', [UserController::class, 'needingRefresh']);
        Route::post('{user}/change-type', [UserController::class, 'changeType']);
        // Cache y mantenimiento
        Route::delete('/users/cache/{dni}', [UserController::class, 'clearUserCache']);
        Route::delete('/users/cache', [UserController::class, 'clearAllCache']);
        Route::post('/users/{id}/restore', [UserController::class, 'restore']);
        Route::delete('/users/dni/{dni}', [UserController::class, 'deleteByDni']);
    });
    
    // Rutas de promoción
    Route::prefix('promotion')->group(function () {
        Route::post('promote', [PromotionController::class, 'promote']);
        Route::get('eligibility', [PromotionController::class, 'checkEligibility']);
        Route::post('check-dni', [PromotionController::class, 'checkDniInClub']);
        Route::post('request', [PromotionController::class, 'requestPromotion']);
        Route::get('stats', [PromotionController::class, 'stats']);
        Route::get('eligible', [PromotionController::class, 'eligible']);
        
        // Rutas administrativas
        Route::get('pending', [PromotionController::class, 'pending']);
        Route::get('history', [PromotionController::class, 'history']);
        Route::post('{user}/approve', [PromotionController::class, 'approve']);
        Route::post('{user}/reject', [PromotionController::class, 'reject']);
    });

    // Admin - Gestión de Asignaciones (protegido por rol 'admin')
    Route::prefix('admin')->middleware('admin')->group(function () {
        // Asignaciones profesor-estudiante
        Route::apiResource('assignments', AdminAssignmentController::class);
        Route::get('professors/{professor}/students', [AdminAssignmentController::class, 'professorStudents']);
        Route::get('students/unassigned', [AdminAssignmentController::class, 'unassignedStudents']);
        Route::get('assignments-stats', [AdminAssignmentController::class, 'stats']);
        
        // Acciones específicas de asignaciones
        Route::post('assignments/{assignment}/pause', [AdminAssignmentController::class, 'pause']);
        Route::post('assignments/{assignment}/reactivate', [AdminAssignmentController::class, 'reactivate']);
        Route::post('assignments/{assignment}/complete', [AdminAssignmentController::class, 'complete']);
    });

    // Admin Gym (protegido por rol 'profesor')
    Route::prefix('admin/gym')->middleware('professor')->group(function () {
        Route::apiResource('exercises', GymExerciseController::class);
        Route::apiResource('daily-templates', GymDailyTemplateController::class);
        Route::apiResource('weekly-templates', GymWeeklyTemplateController::class);
        Route::apiResource('weekly-assignments', GymWeeklyAssignmentController::class)->only(['index','show','store','update','destroy']);
    });

    // Profesor - Gestión de sus estudiantes y plantillas (protegido por rol 'professor')
    Route::prefix('professor')->middleware('professor')->group(function () {
        // Mis estudiantes
        Route::get('my-students', [ProfessorAssignmentController::class, 'myStudents']);
        Route::get('my-stats', [ProfessorAssignmentController::class, 'myStats']);
        
        // Asignaciones de plantillas
        Route::post('assign-template', [ProfessorAssignmentController::class, 'assignTemplate']);
        Route::get('assignments/{assignment}', [ProfessorAssignmentController::class, 'show']);
        Route::put('assignments/{assignment}', [ProfessorAssignmentController::class, 'updateAssignment']);
        Route::delete('assignments/{assignment}', [ProfessorAssignmentController::class, 'unassignTemplate']);
        
        // Progreso y feedback
        Route::get('students/{student}/progress', [ProfessorAssignmentController::class, 'studentProgress']);
        Route::post('progress/{progress}/feedback', [ProfessorAssignmentController::class, 'addFeedback']);
        
        // Calendario y sesiones
        Route::get('today-sessions', [ProfessorAssignmentController::class, 'todaySessions']);
        Route::get('weekly-calendar', [ProfessorAssignmentController::class, 'weeklyCalendar']);
    });

    // Estudiantes - Nuevas rutas para el sistema de asignaciones
    Route::prefix('student')->group(function () {
        Route::get('my-templates', [\App\Http\Controllers\Gym\Student\AssignmentController::class, 'myTemplates']);
        Route::get('template/{templateAssignmentId}/details', [\App\Http\Controllers\Gym\Student\AssignmentController::class, 'templateDetails']);
        Route::get('my-weekly-calendar', [\App\Http\Controllers\Gym\Student\AssignmentController::class, 'myWeeklyCalendar']);
    });

    // Móvil (alumno) - Sistema legacy
    Route::prefix('gym')->group(function () {
        Route::get('my-week', [GymMyPlanController::class, 'myWeek']);
        Route::get('my-day', [GymMyPlanController::class, 'myDay']);
    });
});

// System routes (internal use only)
Route::prefix('sys')->group(function () {
    Route::get('hc', [\App\Http\Controllers\System\LicenseController::class, 'status']);
    Route::post('on', [\App\Http\Controllers\System\LicenseController::class, 'activate']);
    Route::post('off', [\App\Http\Controllers\System\LicenseController::class, 'deactivate']);
});

// Incluir rutas de administración
require __DIR__.'/admin.php';
