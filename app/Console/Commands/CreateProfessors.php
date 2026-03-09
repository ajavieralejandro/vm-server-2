<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateProfessors extends Command
{
    protected $signature = 'professors:create-initial {--force : Recreate if exists}';
    protected $description = 'Crea los profesores iniciales del gimnasio (Belén Baffigi, Leandro Mayo, Oriana Goyenetche)';

    private array $professors = [
        [
            'dni'      => '40859295',
            'name'     => 'Belén Baffigi',
            'email'    => 'Belenbaffigi@hotmail.com',
            'password' => 'profe2024',
        ],
        [
            'dni'      => '28777482',
            'name'     => 'Leandro Mayo',
            'email'    => 'Mayoleandroandres@gmail.com',
            'password' => 'profe2024',
        ],
        [
            'dni'      => '44417846',
            'name'     => 'Oriana Goyenetche',
            'email'    => 'origoyenetche@gmail.com',
            'password' => 'profe2024',
        ],
    ];

    public function handle(): int
    {
        $force = $this->option('force');

        $this->info('Creando profesores iniciales...');
        $this->newLine();

        foreach ($this->professors as $data) {
            $existing = User::where('dni', $data['dni'])
                ->orWhere('email', strtolower($data['email']))
                ->first();

            if ($existing) {
                if (!$force) {
                    $status = $existing->is_professor ? '(ya es profesor)' : '(existe pero NO es profesor)';
                    $this->line("  ⚠️  {$data['name']} ya existe (DNI: {$data['dni']}) {$status}");

                    // Asegurar que tenga el rol de profesor
                    if (!$existing->is_professor) {
                        $existing->update([
                            'is_professor'    => true,
                            'professor_since' => now(),
                        ]);
                        $this->line("     → Rol de profesor asignado.");
                    }
                    continue;
                }

                $existing->delete();
            }

            $user = User::create([
                'name'            => $data['name'],
                'email'           => strtolower($data['email']),
                'dni'             => $data['dni'],
                'password'        => Hash::make($data['password']),
                'user_type'       => 'local',
                'is_professor'    => true,
                'professor_since' => now(),
                'account_status'  => 'active',
            ]);

            $this->line("  ✅ {$user->name} (DNI: {$user->dni}) — creado y asignado como profesor");
        }

        $this->newLine();
        $this->info('Listo. Contraseña por defecto para todos: profe2024');
        $this->warn('Recuerda cambiar las contraseñas luego del primer acceso.');

        return self::SUCCESS;
    }
}
