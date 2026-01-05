<?php

namespace App\Services\Loyalty;

use App\Models\User;
use App\Models\UserPointContract;
use App\Models\PointCredit;
use App\Models\PointRedemption;
use App\Models\PointApplication;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PointsService
{
    /**
     * Crea un contrato de puntos (por defecto 12 meses).
     */
    public function createContract(
        User $user,
        ?string $name = null,
        ?int $months = 12,
        ?Carbon $startsAt = null,
        ?array $meta = null
    ): UserPointContract {
        $startsAt  = $startsAt ?: now();
        $expiresAt = (clone $startsAt)->addMonths($months ?? 12);

        return $user->pointContracts()->create([
            'name'       => $name,
            'starts_at'  => $startsAt,
            'expires_at' => $expiresAt,
            'status'     => 'active',
            'meta'       => $meta,
        ]);
    }

    /**
     * Saldo del usuario. Si pasás contrato, devuelve saldo sólo de ese contrato.
     * Considera sólo contratos activos y no vencidos.
     */
    public function balance(User $user, ?UserPointContract $contract = null): int
    {
        $q = $user->pointCredits()->whereHas('contract', fn($c) => $c->active());
        if ($contract) {
            $q->where('contract_id', $contract->id);
        }
        return (int) $q->sum(DB::raw('points - consumed_points'));
    }

    /**
     * Acredita puntos en un contrato. Si no pasás contrato, crea uno nuevo (12 meses).
     */
    public function award(
        User $user,
        int $points,
        ?string $reason = null,
        ?UserPointContract $contract = null,
        ?Carbon $awardedAt = null,
        ?array $meta = null
    ): PointCredit {
        if ($points <= 0) {
            throw new RuntimeException('Los puntos deben ser > 0');
        }

        $contract = $contract ?: $this->createContract($user);

        if (!($contract->status === 'active' && $contract->expires_at->isFuture())) {
            throw new RuntimeException('El contrato no está activo o está vencido');
        }

        return $user->pointCredits()->create([
            'contract_id'     => $contract->id,
            'points'          => $points,
            'consumed_points' => 0,
            'reason'          => $reason,
            'awarded_at'      => $awardedAt ?: now(),
            'meta'            => $meta,
        ]);
    }

    /**
     * Canjea puntos siguiendo:
     * 1) Contratos que VENCEN ANTES primero (expires_at asc)
     * 2) Dentro de cada contrato, FIFO por awarded_at
     * Si se pasa $contract, consume sólo de ese contrato.
     */
    public function redeem(
        User $user,
        int $points,
        ?string $reason = null,
        ?UserPointContract $contract = null,
        ?array $meta = null
    ): PointRedemption {
        if ($points <= 0) {
            throw new RuntimeException('Los puntos a canjear deben ser > 0');
        }

        return DB::transaction(function () use ($user, $points, $reason, $contract, $meta) {
            $available = $this->balance($user, $contract);
            if ($available < $points) {
                throw new RuntimeException("Saldo insuficiente: disponible {$available}");
            }

            $redemption = $user->pointRedemptions()->create([
                'contract_id' => $contract?->id,  // null si se canjea multi-contrato
                'points'      => $points,
                'reason'      => $reason,
                'redeemed_at' => now(),
                'meta'        => $meta,
            ]);

            $remaining = $points;

            // Selecciona créditos de contratos activos/no vencidos.
            // Orden: 1) contrato que vence antes, 2) awarded_at (FIFO)
            $creditsQ = $user->pointCredits()
                ->whereHas('contract', fn($c) => $c->active())
                ->when($contract, fn($q) => $q->where('contract_id', $contract->id))
                ->whereColumn('consumed_points', '<', 'points')
                ->join('user_point_contracts as upc', 'upc.id', '=', 'point_credits.contract_id')
                ->orderBy('upc.expires_at')
                ->orderBy('point_credits.awarded_at')
                ->lockForUpdate();

            // Importante: pedimos columnas de point_credits para hidratar el modelo
            $credits = $creditsQ->get(['point_credits.*']);

            foreach ($credits as $credit) {
                if ($remaining <= 0) {
                    break;
                }

                $creditRemaining = $credit->remaining; // accessor en PointCredit
                if ($creditRemaining <= 0) {
                    continue;
                }

                $apply = (int) min($remaining, $creditRemaining);

                PointApplication::create([
                    'redemption_id' => $redemption->id,
                    'credit_id'     => $credit->id,
                    'points'        => $apply,
                ]);

                $credit->increment('consumed_points', $apply);
                $remaining -= $apply;
            }

            if ($remaining > 0) {
                // No debería ocurrir con el lock, pero dejamos guard-rail
                throw new RuntimeException('Concurrencia: no se pudo aplicar todo el canje. Reintentá.');
            }

            return $redemption;
        });
    }
}
