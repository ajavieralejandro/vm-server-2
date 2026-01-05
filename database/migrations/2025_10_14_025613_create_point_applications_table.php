<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('point_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('redemption_id')->constrained('point_redemptions')->cascadeOnDelete();
            $table->foreignId('credit_id')->constrained('point_credits')->cascadeOnDelete();
            $table->unsignedInteger('points');
            $table->timestamps();

            $table->index(['credit_id','redemption_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('point_applications');
    }
};
