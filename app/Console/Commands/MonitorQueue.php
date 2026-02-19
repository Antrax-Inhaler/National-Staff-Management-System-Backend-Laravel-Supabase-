<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MonitorQueue extends Command
{
    protected $signature = 'queue:monitor';
    protected $description = 'Monitor queue health and auto-restart if needed';

    public function handle()
    {
        // Check if there are stalled jobs
        $stalledJobs = DB::table('jobs')
            ->where('reserved_at', '>', 0)
            ->where('reserved_at', '<', now()->subMinutes(5)->timestamp)
            ->count();
        
        if ($stalledJobs > 0) {
            Log::warning("Found {$stalledJobs} stalled jobs in queue");
            
            // Release stalled jobs
            DB::table('jobs')
                ->where('reserved_at', '<', now()->subMinutes(5)->timestamp)
                ->update([
                    'reserved_at' => null,
                    'attempts' => DB::raw('attempts + 1')
                ]);
        }
        
        $this->info("Queue monitoring completed. Found {$stalledJobs} stalled jobs.");
        
        return 0;
    }
}