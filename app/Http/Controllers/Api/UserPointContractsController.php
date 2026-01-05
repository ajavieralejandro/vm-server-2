<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreateUserPointContractRequest;
use App\Models\User;
use App\Services\Loyalty\PointsService;

class UserPointContractsController extends Controller
{
    public function __construct(private PointsService $service) {}

    public function index(User $user)
    {
        $this->authorize('view', $user);
        return response()->json($user->pointContracts()->latest()->get());
    }

    public function store(CreateUserPointContractRequest $req, User $user)
    {
        $this->authorize('update', $user);
        $contract = $this->service->createContract(
            $user,
            $req->input('name'),
            $req->integer('months') ?: 12,
            $req->date('starts_at'),
            $req->input('meta')
        );
        return response()->json($contract, 201);
    }
}
