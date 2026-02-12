<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PublicAccess\GymShareTokenException;
use App\Services\PublicAccess\GymShareTokenValidator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StudentPublicTemplatesController extends Controller
{
    public function myTemplates(Request $request, GymShareTokenValidator $validator): JsonResponse
    {
        $token = $request->query('token');

        if (!$token) {
            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        try {
            $data = $validator->validate($token);
        } catch (GymShareTokenException $e) {
            if ($e->getMessage() === 'missing_secret') {
                return response()->json([
                    'ok' => false,
                    'message' => 'Internal server error',
                ], 500);
            }

            return response()->json([
                'ok' => false,
                'message' => 'Unauthorized',
            ], 401);
        }

        $user = User::where('dni', $data['dni'])->first();

        if (!$user) {
            return response()->json([
                'ok' => false,
                'message' => 'User not found',
            ], 404);
        }

        // Reutilizar el controlador existente de estudiante para mantener la misma respuesta
        $internalRequest = Request::create($request->getRequestUri(), 'GET', $request->query());
        $internalRequest->setUserResolver(function () use ($user) {
            return $user;
        });

        $assignmentController = app(\App\Http\Controllers\Gym\Student\AssignmentController::class);

        /** @var JsonResponse $response */
        $response = app()->call([$assignmentController, 'myTemplates'], ['request' => $internalRequest]);

        return $response;
    }
}
