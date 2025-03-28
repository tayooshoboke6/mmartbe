<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;

class DiagnoseRoutes extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'routes:diagnose {pattern? : Optional route pattern to filter}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Diagnose route issues by showing detailed route information';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $pattern = $this->argument('pattern');
        
        $routes = Route::getRoutes();
        $this->info('Total routes: ' . count($routes));
        
        $apiRoutes = [];
        $categoryRoutes = [];
        
        foreach ($routes as $route) {
            $uri = $route->uri();
            
            // Collect API routes
            if (str_starts_with($uri, 'api/')) {
                $apiRoutes[] = [
                    'method' => implode('|', $route->methods()),
                    'uri' => $uri,
                    'name' => $route->getName(),
                    'action' => $route->getActionName(),
                ];
                
                // Specifically collect category routes
                if (str_contains($uri, 'categor')) {
                    $categoryRoutes[] = [
                        'method' => implode('|', $route->methods()),
                        'uri' => $uri,
                        'name' => $route->getName(),
                        'action' => $route->getActionName(),
                    ];
                }
            }
        }
        
        if (empty($apiRoutes)) {
            $this->error('No API routes found! This could indicate a configuration issue.');
            return 1;
        }
        
        $this->info('API Routes (' . count($apiRoutes) . '):');
        $this->table(
            ['Method', 'URI', 'Name', 'Action'],
            $apiRoutes
        );
        
        if (!empty($categoryRoutes)) {
            $this->info('Category Routes (' . count($categoryRoutes) . '):');
            $this->table(
                ['Method', 'URI', 'Name', 'Action'],
                $categoryRoutes
            );
        } else {
            $this->error('No category routes found! This is likely the cause of your 404 error.');
        }
        
        // Check for route conflicts
        $this->info('Checking for route conflicts...');
        $uriMap = [];
        $conflicts = [];
        
        foreach ($routes as $route) {
            $uri = $route->uri();
            $method = implode('|', $route->methods());
            $key = $method . '::' . $uri;
            
            if (!isset($uriMap[$key])) {
                $uriMap[$key] = [];
            }
            
            $uriMap[$key][] = $route->getActionName();
            
            if (count($uriMap[$key]) > 1) {
                $conflicts[$key] = $uriMap[$key];
            }
        }
        
        if (!empty($conflicts)) {
            $this->error('Route conflicts found:');
            foreach ($conflicts as $route => $actions) {
                list($method, $uri) = explode('::', $route, 2);
                $this->line("$method $uri:");
                foreach ($actions as $index => $action) {
                    $this->line("  " . ($index + 1) . ". $action");
                }
            }
        } else {
            $this->info('No route conflicts found.');
        }
        
        return 0;
    }
}
