<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\CsvImport\ImportOrchestratorService;
use App\Services\CsvImport\ImportSummaryService;
use App\Services\CsvImport\ChunkProcessorService;
use App\Services\CsvImport\CsvParserService;
use App\Services\CsvImport\DataCleanerService;
use App\Services\CsvImport\MemberService;
use App\Services\CsvImport\UserImportService;
use App\Services\CsvImport\RoleAssignmentService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ImportOrchestratorService::class, function ($app) {
            return new ImportOrchestratorService(
                $app->make(CsvParserService::class),
                $app->make(DataCleanerService::class),
                $app->make(ChunkProcessorService::class)
            );
        });
        
        $this->app->singleton(ChunkProcessorService::class, function ($app) {
            return new ChunkProcessorService(
                $app->make(DataCleanerService::class),
                $app->make(CsvParserService::class),
                $app->make(MemberService::class),
                $app->make(UserImportService::class),
                $app->make(RoleAssignmentService::class)
            );
        });
        
        $this->app->singleton(MemberService::class, function ($app) {
            return new MemberService($app->make(DataCleanerService::class));
        });
        
        $this->app->singleton(UserImportService::class, function ($app) {
            return new UserImportService(
                $app->make(DataCleanerService::class),
                $app->make(MemberService::class) // Add this line - SECOND PARAMETER
            );
        });
        
        $this->app->singleton(RoleAssignmentService::class, function ($app) {
            return new RoleAssignmentService($app->make(DataCleanerService::class));
        });
        
        $this->app->singleton(ImportSummaryService::class);
        $this->app->singleton(CsvParserService::class);
        $this->app->singleton(DataCleanerService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
