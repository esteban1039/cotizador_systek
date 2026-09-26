<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ClauseController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HistoryController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\QuoteAssistController;
use App\Http\Controllers\QuoteController;
use App\Http\Controllers\QuoteEmissionController;
use App\Http\Controllers\QuoteFollowupController;
use App\Http\Controllers\QuotePdfController;
use App\Http\Controllers\QuoteReviewController;
use App\Http\Controllers\UserController;
use App\Http\Middleware\ActiveUser;
use App\Http\Middleware\RequireMfaEnrollment;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:20,1');
    Route::middleware(['auth:sanctum', ActiveUser::class, RequireMfaEnrollment::class])->group(function () {
        Route::get('auth/mfa', [MfaController::class, 'status']);
        Route::post('auth/mfa/setup', [MfaController::class, 'setup'])->middleware('throttle:5,1,mfa-setup');
        Route::post('auth/mfa/confirm', [MfaController::class, 'confirm'])->middleware('throttle:5,1,mfa-confirm');
        Route::post('auth/mfa/disable', [MfaController::class, 'disable'])->middleware('throttle:5,1,mfa-disable');
        Route::get('auth/me', [AuthController::class, 'me']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::post('auth/password', [AuthController::class, 'changePassword']);
        Route::get('dashboard', DashboardController::class)->middleware('role:admin,quoter,approver');
        Route::get('clients', [ClientController::class, 'index']);
        Route::get('catalog', [CatalogController::class, 'current']);
        Route::get('quotes', [QuoteController::class, 'index']);
        Route::get('quotes/{id}/pdf', [QuotePdfController::class, 'show'])->whereUuid('id');
        Route::get('quotes/{id}', [QuoteController::class, 'show'])->whereUuid('id');
        Route::middleware('role:admin,approver,quoter')->group(function () {
            Route::post('quotes/{id}/issue', [QuoteEmissionController::class, 'issue'])->whereUuid('id')->middleware('throttle:10,1,quote-issue');
            Route::get('quotes/{id}/official-pdf', [QuoteEmissionController::class, 'officialPdf'])->whereUuid('id');
            Route::get('quotes/{id}/followups', [QuoteFollowupController::class, 'index'])->whereUuid('id');
            Route::post('quotes/{id}/followups', [QuoteFollowupController::class, 'store'])->whereUuid('id')->middleware('throttle:30,1,quote-followup');
        });
        Route::middleware('role:admin,quoter')->group(function () {
            Route::post('clients', [ClientController::class, 'store']);
            Route::post('clients/{id}/sites', [ClientController::class, 'site'])->whereUuid('id');
            Route::post('clients/{id}/contacts', [ClientController::class, 'contact'])->whereUuid('id');
            Route::post('quotes/assist', QuoteAssistController::class)->middleware('throttle:quote-assist');
            Route::post('quotes/preview', [QuoteController::class, 'preview']);
            Route::post('quotes', [QuoteController::class, 'store']);
            Route::post('quotes/{id}/revisions', [QuoteController::class, 'revise'])->whereUuid('id');
            Route::post('quotes/{id}/submit', [QuoteReviewController::class, 'submit'])->whereUuid('id');
            Route::get('clauses', [ClauseController::class, 'current']);
        });
        Route::middleware('role:admin,approver')->group(function () {
            Route::post('quotes/{id}/review', [QuoteReviewController::class, 'review'])->whereUuid('id');
            Route::get('rules', [CatalogController::class, 'rules']);
        });
        Route::middleware('role:admin')->group(function () {
            Route::get('admin/history', [HistoryController::class, 'index']);
            Route::post('admin/history/import', [HistoryController::class, 'import'])->middleware('throttle:10,1,history-import');
            Route::get('admin/history/{id}', [HistoryController::class, 'show'])->whereUuid('id');
            Route::post('admin/history/{id}/review', [HistoryController::class, 'review'])->whereUuid('id');
            Route::get('admin/catalog', [CatalogController::class, 'index']);
            Route::post('admin/catalog', [CatalogController::class, 'store']);
            Route::post('admin/catalog/{id}/prices', [CatalogController::class, 'publish'])->whereUuid('id');
            Route::patch('admin/catalog/{id}/active', [CatalogController::class, 'active'])->whereUuid('id');
            Route::post('rules', [CatalogController::class, 'saveRule']);
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::patch('users/{id}', [UserController::class, 'update'])->whereNumber('id');
            Route::get('audit', [UserController::class, 'audit']);
            Route::get('admin/company', [CompanyController::class, 'show']);
            Route::post('admin/company', [CompanyController::class, 'publish'])->middleware('throttle:10,1,company-publish');
            Route::get('admin/clauses', [ClauseController::class, 'index']);
            Route::post('admin/clauses', [ClauseController::class, 'store']);
            Route::get('admin/clauses/{id}', [ClauseController::class, 'show'])->whereUuid('id');
            Route::post('admin/clauses/{id}/versions', [ClauseController::class, 'publish'])->whereUuid('id');
            Route::patch('admin/clauses/{id}', [ClauseController::class, 'update'])->whereUuid('id');
            Route::patch('clients/{id}/tax-profile', [ClientController::class, 'taxProfile'])->whereUuid('id');
        });
    });
});
