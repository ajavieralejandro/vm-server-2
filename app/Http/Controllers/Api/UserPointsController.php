<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CreditPointsRequest;
use App\Http\Requests\RedeemPointsRequest;
use App\Models\User;
use App\Models\UserPointContract;
use App\Services\Loyalty\PointsService;

class UserPointsController extends Controller
{
    public function __construct(private PointsService $service) {}

    public function balance(User $user)
    {
        $this->authorize('view', $user);
        return response()->json(['user_id'=>$user->id, 'balance'=>$this->service->balance($user)]);
    }

    public function ledger(User $user)
    {
        $this->authorize('view', $user);
        return response()->json([
            'contracts'   => $user->pointContracts()->withCount([])->latest()->get(),
            'credits'     => $user->pointCredits()->with('contract')->latest()->get(),
            'redemptions' => $user->pointRedemptions()->with('contract')->latest()->get(),
        ]);
    }

    public function credit(CreditPointsRequest $req, User $user)
    {
        $this->authorize('update', $user);
        $contract = $req->integer('contract_id') ? UserPointContract::findOrFail($req->integer('contract_id')) : null;
        $credit = $this->service->award(
            $user,
            $req->integer('points'),
            $req->input('reason'),
            $contract,
            $req->date('awarded_at'),
            $req->input('meta')
        );
        return response()->json($credit, 201);
    }

    public function redeem(RedeemPointsRequest $req, User $user)
    {
        $this->authorize('update', $user);
        $contract = $req->integer('contract_id') ? UserPointContract::findOrFail($req->integer('contract_id')) : null;
        $redemption = $this->service->redeem(
            $user,
            $req->integer('points'),
            $req->input('reason'),
            $contract,
            $req->input('meta')
        );
        return response()->json($redemption, 201);
    }
}
