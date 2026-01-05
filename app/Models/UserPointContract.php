<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class UserPointContract extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id','code','name','starts_at','expires_at','status','meta'
    ];
    protected $casts = [
        'starts_at' => 'datetime',
        'expires_at'=> 'datetime',
        'meta'      => 'array',
    ];

    public function user() { return $this->belongsTo(User::class); }
    public function credits() { return $this->hasMany(PointCredit::class, 'contract_id'); }
    public function redemptions() { return $this->hasMany(PointRedemption::class, 'contract_id'); }

    public function scopeActive($q) {
        return $q->where('status','active')->where('expires_at','>', now());
    }

    public function getRemainingAttribute(): int
    {
        // saldo de este contrato = sum(points - consumed_points) de sus créditos
        return (int) $this->credits()->sum(\DB::raw('points - consumed_points'));
    }
}
