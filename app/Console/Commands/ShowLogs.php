<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ShowLogs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'logs:show {--lines=50 : Number of lines to show}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show the Laravel logs for debugging';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $logPath = storage_path('logs/laravel.log');
        
        if (!File::exists($logPath)) {
            $this->error('Log file does not exist!');
            return 1;
        }
        
        $lines = $this->option('lines');
        
        // Get the last X lines from the log file
        $logContent = $this->getLastLines($logPath, $lines);
        
        $this->info("Showing last $lines lines of Laravel logs:");
        $this->line($logContent);
        
        return 0;
    }
    
    /**
     * Get the last X lines from a file
     */
    protected function getLastLines($file, $lines)
    {
        $file = new \SplFileObject($file, 'r');
        $file->seek(PHP_INT_MAX);
        $lastLine = $file->key();
        
        $output = [];
        $startLine = max(0, $lastLine - $lines);
        
        $file->seek($startLine);
        
        while (!$file->eof()) {
            $line = $file->current();
            $output[] = $line;
            $file->next();
        }
        
        return implode('', $output);
    }
}
