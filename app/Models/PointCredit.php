<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PointCredit extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id','contract_id','points','consumed_points','reason','awarded_at','meta'
    ];
    protected $casts = ['awarded_at'=>'datetime','meta'=>'array'];

    public function user() { return $this->belongsTo(User::class); }
    public function contract() { return $this->belongsTo(UserPointContract::class, 'contract_id'); }
    public function applications() { return $this->hasMany(PointApplication::class, 'credit_id'); }

    public function getRemainingAttribute(): int {
        return max(0, (int)$this->points - (int)$this->consumed_points);
    }
}
