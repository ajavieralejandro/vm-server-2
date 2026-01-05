<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('user_point_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code')->nullable();           // opcional: identificador externo del contrato
            $table->string('name')->nullable();           // opcional: “Plan anual”, etc.
            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('expires_at')->index();     // define el vencimiento del contrato
            $table->string('status')->default('active');  // active | expired | canceled
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['user_id', 'expires_at']);
        });
    }
    public function down(): void {
        Schema::dropIfExists('user_point_contracts');
    }
};
