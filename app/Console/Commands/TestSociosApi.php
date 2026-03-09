<?php

namespace App\Console\Commands;

use App\Services\External\SociosApi;
use Illuminate\Console\Command;

class TestSociosApi extends Command
{
    protected $signature = 'socios:test {dni?}';
    protected $description = 'Test connectivity with Socios API';

    public function handle()
    {
        $dni = $this->argument('dni') ?? '59964604';
        
        $this->info("Testing Socios API with DNI: {$dni}");
        $this->info("Configuration:");
        
        $config = config('services.socios');
        $this->table(['Setting', 'Value'], [
            ['Base URL', $config['base'] ?? 'NOT SET'],
            ['Login', $config['login'] ?? 'NOT SET'],
            ['Token', empty($config['token']) ? 'NOT SET' : 'SET (' . strlen($config['token']) . ' chars)'],
            ['Timeout', $config['timeout'] ?? 'NOT SET'],
            ['Verify SSL', $config['verify'] ? 'YES' : 'NO'],
        ]);
        
        if (empty($config['token'])) {
            $this->error('❌ SOCIOS_API_TOKEN is not configured in .env file');
            $this->info('Please add: SOCIOS_API_TOKEN=your_token_here');
            return 1;
        }
        
        try {
            $sociosApi = app(SociosApi::class);
            $this->info("Calling API...");
            
            $result = $sociosApi->getSocioPorDni($dni);
            
            if ($result) {
                $this->info("✅ SUCCESS: User found in API");
                $this->table(['Field', 'Value'], [
                    ['ID', $result['Id'] ?? 'N/A'],
                    ['Name', ($result['apellido'] ?? '') . ', ' . ($result['nombre'] ?? '')],
                    ['Email', $result['email'] ?? 'N/A'],
                    ['Status', $result['estado'] ?? 'N/A'],
                ]);
            } else {
                $this->warn("⚠️  User not found in API (or API returned empty result)");
            }
            
        } catch (\Exception $e) {
            $this->error("❌ API Error: " . $e->getMessage());
            return 1;
        }
        
        return 0;
    }
}
