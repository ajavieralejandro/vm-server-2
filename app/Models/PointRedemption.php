<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PointRedemption extends Model
{
    use HasFactory;

    protected $fillable = ['user_id','contract_id','points','reason','redeemed_at','meta'];
    protected $casts = ['redeemed_at'=>'datetime','meta'=>'array'];

    public function user() { return $this->belongsTo(User::class); }
    public function contract() { return $this->belongsTo(UserPointContract::class, 'contract_id'); }
    public function applications() { return $this->hasMany(PointApplication::class, 'redemption_id'); }
}
