<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->unique();
            $table->string('display_name')->nullable();
            $table->string('app_phone', 20)->nullable();
            $table->text('bio')->nullable();
            $table->string('avatar_path')->nullable();
            $table->json('preferences')->nullable();
            $table->timestamp('avatar_updated_at')->nullable();
            $table->timestamps();

            $table->index('avatar_updated_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_profiles');
    }
};