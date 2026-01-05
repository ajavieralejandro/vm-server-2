<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('point_credits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contract_id')->constrained('user_point_contracts')->cascadeOnDelete();
            $table->unsignedInteger('points');
            $table->unsignedInteger('consumed_points')->default(0);
            $table->string('reason')->nullable();
            $table->timestamp('awarded_at')->useCurrent();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id','contract_id']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('point_credits');
    }
};
