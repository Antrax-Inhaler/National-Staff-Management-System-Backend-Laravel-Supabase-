<?php

use App\Enums\RoleEnum;
use App\Http\Controllers\v1\AffiliateImportController;
use App\Http\Controllers\v1\AffiliateController;
use App\Http\Controllers\v1\AffiliateDashboardController;
use App\Http\Controllers\v1\AuditController;
use App\Http\Controllers\v1\AuthController;
use App\Http\Controllers\v1\CsvImportController;
use App\Http\Controllers\v1\DocumentController;
use App\Http\Controllers\v1\DomainController;
use App\Http\Controllers\v1\GovernanceDocumentController;
use App\Http\Controllers\v1\InformationController;
use App\Http\Controllers\v1\LinkController;
use App\Http\Controllers\v1\MemberController;
use App\Http\Controllers\v1\MemberDashboardController;
use App\Http\Controllers\v1\OrganizationDashboardController;
use App\Http\Controllers\v1\OrganizationLeaderController;
use App\Http\Controllers\v1\OfficersController;
use App\Http\Controllers\v1\ProfileController;
use App\Http\Controllers\v1\OrganizationInformationController;
use App\Http\Controllers\v1\ResearchDocumentController;
use App\Http\Controllers\v1\RoleController;
use App\Http\Controllers\v1\HelpVideoController;
use App\Http\Controllers\v1\OrganizationDocumentController;
use Illuminate\Support\Facades\Route;


Route::options('{any}', function () {
    return response('', 204);
})->where('any', '.*');

Route::prefix('v1')->group(function () {
    Route::get('/paginated', [AuthController::class, 'paginate']);
    Route::post('/check-user', [AuthController::class, 'checkUser']);
    Route::get('/members/{id}/photo', [MemberController::class, 'getMemberPhoto']);
    Route::middleware('auth:supabase')->group(function () {
        Route::get('/user/roles-permissions', [AuthController::class, 'getUserRolesAndPermissions']);

        Route::prefix('/dashboard')->group(function () {

            Route::middleware('role:' . implode(',', RoleEnum::nationalRoles()))->group(function () {
                Route::get('/national', [OrganizationDashboardController::class, 'index']);
                Route::get('/national/search-members', [OrganizationDashboardController::class, 'searchMembers']);
                Route::post('/national/export', [OrganizationDashboardController::class, 'export']);
                Route::get('/', [OrganizationDashboardController::class, 'index']);

                // New modular endpoints
                Route::get('/executive-summary', [OrganizationDashboardController::class, 'getExecutiveSummary']);
                Route::get('/demographic-analysis', [OrganizationDashboardController::class, 'getDemographicAnalysis']);
                Route::get('/affiliate-analytics', [OrganizationDashboardController::class, 'getAffiliateAnalytics']);
                Route::get('/temporal-analysis', [OrganizationDashboardController::class, 'getTemporalAnalysis']);
                Route::get('/system-governance', [OrganizationDashboardController::class, 'getSystemGovernance']);
                Route::get('/research-governance', [OrganizationDashboardController::class, 'getResearchGovernance']);
                Route::get('/search', [OrganizationDashboardController::class, 'universalSearch']); // Universal search
                // Add these routes
                Route::get('/affiliate-names', [OrganizationDashboardController::class, 'getAffiliateNames']);
                Route::get('/filters/options', [OrganizationDashboardController::class, 'getFilterOptions']);
                // Existing endpoints
                Route::get('/search-members', [OrganizationDashboardController::class, 'searchMembers']);
            });

            Route::middleware('role:' . implode(',', [RoleEnum::AFFILIATE_OFFICER->value]))->group(function () {
                Route::get('/affiliate', [AffiliateDashboardController::class, 'index']);
            });

            Route::middleware('role:' . implode(',', [RoleEnum::AFFILIATE_MEMBER->value]))->group(function () {
                Route::get('/member', [MemberDashboardController::class, 'index']);
            });
        });

        Route::get('/dashboard/affiliate-directory', [AffiliateDashboardController::class, 'directory']);

        Route::prefix('/profile')->group(function () {
            Route::controller(ProfileController::class)->group(function () {
                Route::get('/info', 'info');
                Route::get('/data', 'getProfileData');
                Route::get('/clear-cache', 'clearProfileCache');
                Route::get('/missing-data-count', 'getMissingDataCount');
                Route::put('/update', 'update');
                Route::post('/upload-photo', 'uploadProfilePhoto');
                Route::delete('/delete-photo', 'deleteProfilePhoto');
                Route::get('/photo-url', 'getProfilePhotoUrl');
                Route::put('/generate-id', 'generateId');
            });
        });
        Route::prefix('help-videos')->group(function () {
            Route::controller(HelpVideoController::class)->group(function () {
                Route::get('/', 'index');
                Route::get('/statistics', 'statistics');
                Route::get('/options', 'options');
                Route::get('/public/{publicUid}', 'showByPublicUid');
                Route::post('/{id}/views', 'incrementViews');
                Route::get('/{id}', 'show');
                Route::post('/', 'store');
                Route::put('/{id}', 'update');
                Route::delete('/{id}', 'destroy');
            });
        });
        // Route::get('/profile/missing-data-count', [ProfileController::class, 'getMissingDataCount']);
        Route::prefix('affiliate-import')->group(function () {
            Route::post('/bulk', [AffiliateImportController::class, 'bulkImport']);
            Route::get('/template', [AffiliateImportController::class, 'getTemplate']);
            Route::get('/download-template', [AffiliateImportController::class, 'downloadTemplate']);
        });

        Route::prefix('/officers')->group(function () {
            Route::controller(OfficersController::class)->group(function () {
                Route::get('/get-officers', 'fetchOfficers');
                Route::get('/affiliate-officers/{id}', 'affiliateOfficers');
                Route::get('/no-positions', 'noPositions');
                Route::patch('/open-position', 'openPosition');
                Route::post('/assign-officer', 'assignOfficer');
                Route::get('/user-search', 'userSearch');
            });
        });

        Route::prefix('/members')->group(function () {
            Route::controller(MemberController::class)->group(function () {
                Route::get('/index', 'index');
                Route::get('/affiliate/{uid}', 'affiliate');
                Route::get('/export', 'export');
                Route::delete('/remove', 'remove');
                Route::get('/archive', 'archive');
                Route::patch('/restore', 'restore');
                Route::post('/create', 'create');
                Route::post('/update',  'update');

                Route::get('/search', 'search');
                Route::get('/{uid}', 'show');
            });
        });

        Route::prefix('national-information')->group(function () {
            Route::get('/unread', [OrganizationInformationController::class, 'getUnreadArticles']);
            Route::get('/', [OrganizationInformationController::class, 'index']);
            Route::get('/options', [OrganizationInformationController::class, 'options']);
            Route::get('/statistics', [OrganizationInformationController::class, 'statistics']);
            Route::post('/log-view', [OrganizationInformationController::class, 'logView']);
            Route::post('/', [OrganizationInformationController::class, 'store']);
            Route::put('/bulk-update', [OrganizationInformationController::class, 'bulkUpdate']); // This should come BEFORE {id} routes
            Route::delete('/bulk-delete', [OrganizationInformationController::class, 'bulkDelete']);
            Route::get('/{id}/viewers', [OrganizationInformationController::class, 'getViewers']);
            Route::get('/{id}/viewer-stats', [OrganizationInformationController::class, 'getViewerStats']);
            Route::get('/unread/{type}', [OrganizationInformationController::class, 'getUnreadArticlesByType']);
            Route::get('/{id}', [OrganizationInformationController::class, 'show']);
            Route::put('/{id}', [OrganizationInformationController::class, 'update']); // This should come AFTER bulk-update
            Route::delete('/{id}', [OrganizationInformationController::class, 'destroy']);
        });

        Route::prefix('csv-import')->group(function () {
            Route::post('/upload', [CsvImportController::class, 'uploadCsv']);
            Route::get('/status/{importId}', [CsvImportController::class, 'getImportStatus']);
            Route::get('/user-imports', [CsvImportController::class, 'getUserImports']);
            Route::post('/pause/{importId}', [CsvImportController::class, 'pauseImport']);
            Route::post('/resume/{importId}', [CsvImportController::class, 'resumeImport']);
            Route::post('/stop/{importId}', [CsvImportController::class, 'stopImport']);
            Route::get('/template', [CsvImportController::class, 'getUploadTemplate']);
            Route::get('/{importId}/data', [CsvImportController::class, 'getImportData']);
            Route::get('/{importId}/data/action/{action}', [CsvImportController::class, 'getImportDataByAction']);
            Route::post('/{importId}/data/search', [CsvImportController::class, 'searchImportData']);
            Route::get('/{importId}/data/export', [CsvImportController::class, 'exportImportData']);
            Route::get('/{importId}/statistics', [CsvImportController::class, 'getImportStatistics']);
            Route::get('/{importId}/progress', [CsvImportController::class, 'getImportProgress']);
            Route::get('/{importId}/chunk/{chunkIndex}/results', [CsvImportController::class, 'getChunkResults']);
        });

        // Route::prefix('/documents')->group(function () {
        //     Route::controller(DocumentController::class)->group(function () {
        //         Route::get('/all', 'index');
        //         Route::post('/upload', 'store');
        //         Route::put('/update', 'update');
        //         Route::delete('/delete/{id}', 'destroy');
        //         Route::get('/status', 'status');
        //         Route::get("/folders", "listFolders");
        //         Route::get('/stream/{id}', 'stream');

        //         Route::post('/create-folder', 'createFolder');

        //         Route::get('/fetch', 'fetch');
        //         Route::get("/search", "search");
        //     });
        // });

        // Route::get('/documents/research', [DocumentController::class, 'researchDocuments']);
        // Route::get('/documents/governance', [DocumentController::class, 'governanceDocuments']);
        // Route::get('/documents/stats/research', [DocumentController::class, 'researchStats']);
        // Route::get('/documents/stats/governance', [DocumentController::class, 'governanceStats']);
        // Route::patch('/documents/{id}/category-group', [DocumentController::class, 'updateCategoryGroup']);

        Route::prefix('/links')->group(function () {
            Route::controller(LinkController::class)->group(function () {
                Route::get('/', 'index');
                Route::get('/public', 'public');
                Route::get('/categories', 'categories');
                Route::get('/export', 'export');
                Route::post('/', 'store');
                Route::get('/{id}', 'show');
                Route::put('/{link}', 'update');
                Route::delete('/{id}', 'destroy');
            });
        });

        Route::middleware(
            'role:' . implode(',', [...RoleEnum::nationalRoles(), RoleEnum::AFFILIATE_OFFICER->value])
        )->group(function () {
            Route::prefix('/affiliates')->group(function () {
                Route::controller(AffiliateController::class)->group(function () {
                    Route::get('/index', 'index');
                    Route::delete('/remove', 'remove');
                    route::get('/export', 'export');
                    Route::get('employers', 'employers');


                    Route::get('/all', 'all');
                    Route::get('/info/{uid}', 'info');
                    Route::post('/create', 'create');
                    Route::put('/update', 'update');
                    Route::delete('/delete/{id}', 'delete');
                    Route::get('options', 'options');

                    Route::get('all-affiliates', 'allAffiliates');
                });
            });

            Route::prefix('/national')->group(function () {
                Route::controller(OrganizationLeaderController::class)->group(function () {
                    Route::get('/all', 'index');
                    Route::get('/roles', 'roles');
                    Route::delete('/detach', 'detachRole');
                    Route::post('/create', 'createRole');
                    Route::post('/assign', 'assignRole');
                    Route::get('users', 'searchUser');
                    Route::get('/leaders', 'leaders');
                    Route::get('role-options', 'rolesOptions');
                    Route::get('/get-history/{id}', 'getHistory');

                    // NEW ROUTES FOR EXECUTIVE COMMITTEE
                    Route::get('/executive-committee-roles', 'executiveCommitteeRoles');
                    Route::get('/executive-committee-members', 'executiveCommitteeMembers');
                });
            });
        });

        Route::prefix('/information')->group(function () {
            // Admin CRUD routes
            Route::controller(InformationController::class)->group(function () {
                Route::get('/info', 'index');
                Route::get('/', 'index');
                Route::get('/show/{id}', 'show');
                Route::post('/create', 'store');
                Route::put('/update', 'update');
                Route::delete('/delete/{id}', 'destroy');
                Route::get('/{id}/attachments', 'getAttachments');
            });

            // Public routes
            Route::get('/public', [InformationController::class, 'publicIndex']);
            Route::get('/public/{id}', [InformationController::class, 'publicShow']);

            // Utility routes
            Route::get('/categories', [InformationController::class, 'categories']);
            Route::get('/stats', [InformationController::class, 'stats']);
            Route::post('/bulk-actions', [InformationController::class, 'bulkActions']);
            Route::get('/export', [InformationController::class, 'export']);
            Route::get('/recent', [InformationController::class, 'recent']);
            Route::get('/suggestions', [InformationController::class, 'suggestions']);
        });

        Route::prefix('/domains')->group(function () {
            Route::controller(DomainController::class)->group(function () {
                Route::get('/all', 'domains');
                Route::get('/affiliate/{id}', 'affiliateDomains');
                Route::get('/blacklisted', 'blacklisted');
                Route::post('/block-domain', 'block');
                Route::post('/create-domain', 'create');
                Route::delete('/delete-domain', 'delete');
            });
        });

        Route::prefix('/roles')->group(function () {
            Route::controller(RoleController::class)->group(function () {
                Route::get('/history/{id}', 'history');
            });
        });

        Route::middleware('role:' . implode(',', [RoleEnum::NATIONAL_ADMINISTRATOR->value, ...RoleEnum::nationalOfficers()]))->group(function () {
            Route::prefix('/audits')->group(function () {
                Route::controller(AuditController::class)->group(function () {
                    Route::get('/logs', 'logs');
                });
            });
        });

        Route::prefix('/research')->group(function () {
            Route::controller(ResearchDocumentController::class)->group(function () {
                Route::get('/index', 'index');
                Route::get('/affiliate/{uid}', 'affiliate');
                Route::post('/upload', 'upload');
                Route::put('/update/{id}', 'update');

                Route::delete('/delete/{id}', 'destroy');
                Route::get('/arbitrators', 'arbitrators');
                Route::get('/stream/{id}', 'stream');

                Route::get('/overview', 'overview');
                Route::get('/folders', 'folders');
                Route::post('/create-folder', 'createFolder');
                Route::put('/update-folder', 'updateFolder');
                Route::get('/affiliate-folders', 'affiliateFolders');
            });
        });

        Route::prefix('/governance')->group(function () {
            Route::controller(GovernanceDocumentController::class)->group(function () {
                Route::get('/index', 'index');
                Route::get('/affiliate/{uid}', 'affiliate');
                Route::post('/upload', 'upload');
                Route::put('/update/{id}', 'update');
                Route::delete('/delete/{id}', 'destroy');




                // OLD
                Route::get('/overview', 'overview');
                Route::get('/folders', 'folders');
                Route::post('/create-folder', 'createFolder');
                Route::put('/update-folder', 'updateFolder');
                Route::get('/affiliate-folders', 'affiliateFolders');
            });
        });

        Route::prefix('/documents')->group(function () {
            Route::controller(OrganizationDocumentController::class)->group(function () {
                route::get('/index', 'index');
                route::post('/upload', 'upload');
                route::put('/update/{id}', 'update');
                route::delete('/delete/{id}', 'destroy');
                route::get('/arbitrators', 'arbitrators');
            });
        });
    });
});
