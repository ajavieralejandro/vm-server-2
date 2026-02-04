<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     * 
     * Cambia la FK professor_socio.socio_id de users(id) a socios_padron(id)
     */
    public function up(): void
    {
        // Opcional: truncar datos de testing si existen
        // DB::table('professor_socio')->truncate();

        Schema::table('professor_socio', function (Blueprint $table) {
            // Dropear la FK anterior (users)
            $table->dropForeign(['socio_id']);
            
            // Crear nueva FK hacia socios_padron con cascade delete
            $table->foreign('socio_id')
                ->references('id')
                ->on('socios_padron')
                ->onDelete('cascade')
                ->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     * 
     * Revierte la FK a users(id)
     */
    public function down(): void
    {
        Schema::table('professor_socio', function (Blueprint $table) {
            // Dropear la FK hacia socios_padron
            $table->dropForeign(['socio_id']);
            
            // Restaurar FK a users
            $table->foreign('socio_id')
                ->references('id')
                ->on('users')
                ->onDelete('cascade')
                ->onUpdate('cascade');
        });
    }
};
