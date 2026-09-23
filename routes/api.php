<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\OrgUnitController;
use App\Http\Controllers\PcrAccomplishmentController;
use App\Http\Controllers\PcrAssignmentController;
use App\Http\Controllers\PcrCommentController;
use App\Http\Controllers\PcrDeliveryController;
use App\Http\Controllers\PcrFormController;
use App\Http\Controllers\PcrIndicatorController;
use App\Http\Controllers\PcrOutputController;
use App\Http\Controllers\PcrRatingController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SchoolYearController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserProfileController;
use App\Http\Controllers\WorkflowSettingController;
use Illuminate\Support\Facades\Route;

Route::post('login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::get('organization', [OrganizationController::class, 'show']);

Route::middleware('auth:api')->group(function () {
    $PMS_ADMIN     = 'role:admin';
    $PMS_REVIEWERS = 'role:program_head,vp';
    $PMS_QA        = 'role:qa';
    $PMS_REPORTS   = 'role:president,qa';

    /* ---------------------------------------------------------------- Auth */
    Route::get('me', [AuthController::class, 'me']);
    Route::post('logout', [AuthController::class, 'logout']);
    Route::post('my-password', [AuthController::class, 'changePassword']);

    /* -------------------------------------------- Admin: accounts & org */
    Route::get('users', [UserController::class, 'index'])->middleware($PMS_ADMIN);
    Route::post('users', [UserController::class, 'store'])->middleware($PMS_ADMIN);
    Route::delete('users/{id}', [UserController::class, 'destroy'])->middleware($PMS_ADMIN);
    Route::get('users/options', [UserController::class, 'options']);
    Route::post('users/{id}/avatar', [UserController::class, 'avatar']);

    Route::get('org-units', [OrgUnitController::class, 'index']);
    Route::post('org-units', [OrgUnitController::class, 'store'])->middleware($PMS_ADMIN);
    Route::delete('org-units/{id}', [OrgUnitController::class, 'destroy'])->middleware($PMS_ADMIN);

    /* --------------------------------------------- Admin: rating cycles */
    Route::get('school-years', [SchoolYearController::class, 'index']);
    Route::post('school-years', [SchoolYearController::class, 'store'])->middleware($PMS_ADMIN);
    Route::post('school-years/{id}/activate', [SchoolYearController::class, 'activate'])
        ->middleware($PMS_ADMIN);
    Route::post('rating-periods/{id}/activate', [SchoolYearController::class, 'activatePeriod'])
        ->middleware($PMS_ADMIN);
    Route::post('rating-periods/{id}/status', [SchoolYearController::class, 'setPeriodStatus'])
        ->middleware($PMS_ADMIN);
    Route::post('rating-periods/{id}/lock', [SchoolYearController::class, 'setPeriodLock'])
        ->middleware($PMS_ADMIN);

    /* -------------------------------------------------------- PCR forms */
    Route::get('pcr-forms', [PcrFormController::class, 'index']);
    Route::get('pcr-forms/{id}', [PcrFormController::class, 'show']);
    Route::get('pcr-forms/{id}/opcr-targets', [PcrFormController::class, 'opcrTargets']);
    Route::get('pcr-forms/{id}/opcr-outputs', [PcrFormController::class, 'opcrOutputs']);
    Route::get('pcr-forms/{id}/readiness', [PcrFormController::class, 'readiness']);
    Route::get('pcr-forms/{id}/history', [PcrFormController::class, 'history']);
    Route::post('pcr-forms/{id}/copy-from', [PcrFormController::class, 'copyFrom']);
    Route::get('pcr-forms/{id}/pdf', [ReportController::class, 'formPdf']);
    Route::get('pcr-forms/{id}/xlsx', [ReportController::class, 'formExcel']);
    Route::get('assignable-users', [PcrAssignmentController::class, 'assignableUsers']);
    Route::get('my-team', [TeamController::class, 'index'])->middleware($PMS_REVIEWERS);
    Route::get('people/{id}', [UserProfileController::class, 'show']);
    Route::get('people/{id}/pdf', [UserProfileController::class, 'pdf']);
    Route::post('my-profile', [UserProfileController::class, 'updateProfile']);
    Route::post('my-profile/{section}', [UserProfileController::class, 'storeSection']);
    Route::delete('my-profile/{section}/{id}', [UserProfileController::class, 'destroySection']);
    Route::get('workflow-settings', [WorkflowSettingController::class, 'index'])->middleware($PMS_ADMIN);
    Route::post('workflow-settings', [WorkflowSettingController::class, 'update'])->middleware($PMS_ADMIN);
    Route::post('workflow-settings/reset', [WorkflowSettingController::class, 'reset'])->middleware($PMS_ADMIN);
    Route::post('pcr-outputs/{id}/assign', [PcrAssignmentController::class, 'storeForOutput']);
    Route::post('pcr-indicators/{id}/assign', [PcrAssignmentController::class, 'storeForIndicator']);
    Route::post('pcr-forms/{id}/cascade', [PcrAssignmentController::class, 'cascade']);
    Route::delete('pcr-assignments/{childId}', [PcrAssignmentController::class, 'destroy']);
    Route::post('pcr-forms', [PcrFormController::class, 'store']);
    Route::post('pcr-forms/bulk', [PcrFormController::class, 'bulkStore']);
    Route::get('form-templates', [PcrFormController::class, 'templates']);
    Route::post('pcr-forms/{id}/apply-template', [PcrFormController::class, 'applyTemplate']);
    Route::post('pcr-forms/{id}/status', [PcrFormController::class, 'setStatus']);
    Route::delete('pcr-forms/{id}', [PcrFormController::class, 'destroy']);

    Route::post('pcr-outputs', [PcrOutputController::class, 'store']);
    Route::delete('pcr-outputs/{id}', [PcrOutputController::class, 'destroy']);

    Route::post('pcr-indicators', [PcrIndicatorController::class, 'store']);
    Route::get('pcr-indicators/{id}/rollup', [PcrDeliveryController::class, 'rollup']);
    Route::post('pcr-indicators/{id}/progress', [PcrIndicatorController::class, 'progress']);
    Route::delete('pcr-indicators/{id}', [PcrIndicatorController::class, 'destroy']);

    /* ------------------------------------------ Accomplishments & files */
    Route::post('pcr-accomplishments', [PcrAccomplishmentController::class, 'store']);
    Route::post('pcr-attachments', [PcrAccomplishmentController::class, 'upload']);
    Route::delete('pcr-attachments/{id}', [PcrAccomplishmentController::class, 'destroyAttachment']);

    /* ---------------------------------------------------- Review thread */
    Route::get('pcr-forms/{id}/comments', [PcrCommentController::class, 'index']);
    Route::get('pcr-forms/{id}/mentionables', [PcrCommentController::class, 'mentionables']);
    Route::post('pcr-comments', [PcrCommentController::class, 'store']);

    /* --------------------------------------------------- Notifications */
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
    Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);

    /* ----------------------------------------------------- QA: ratings */
    Route::post('pcr-ratings', [PcrRatingController::class, 'store'])->middleware($PMS_QA);
    Route::post('pcr-forms/{id}/finalize-rating', [PcrRatingController::class, 'finalize'])
        ->middleware($PMS_QA);

    /* ------------------------------------------------------- Reports */
    Route::get('reports/my-summary', [ReportController::class, 'mySummary']);
    Route::get('reports/summary', [ReportController::class, 'summary'])->middleware($PMS_REPORTS);
    Route::get('reports/summary/export', [ReportController::class, 'summaryExport'])->middleware($PMS_REPORTS);
    Route::get('reports/summary/pdf', [ReportController::class, 'summaryPdf'])->middleware($PMS_REPORTS);
    Route::get('reports/form/{id}/print', [ReportController::class, 'printForm']);

    /* --------------------------------------------------------- Audit */
    Route::get('audit-logs', [ReportController::class, 'auditLogs'])->middleware($PMS_ADMIN);
});
