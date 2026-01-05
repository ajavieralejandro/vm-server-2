<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('point_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->nullable()->constrained('user_point_contracts')->cascadeOnDelete();
            $table->unsignedInteger('points');
            $table->string('reason')->nullable();
            $table->timestamp('redeemed_at')->useCurrent();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id','contract_id','redeemed_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('point_redemptions');
    }
};
