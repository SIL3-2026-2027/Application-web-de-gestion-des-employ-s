<?php

use App\Http\Controllers\Api\AttendanceController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyDocumentController;
use App\Http\Controllers\Api\ContractController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentRequestController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\EmployeeImportController;
use App\Http\Controllers\Api\LeaveBalanceController;
use App\Http\Controllers\Api\LeaveRequestController;
use App\Http\Controllers\Api\LeaveSettingsController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrganisationController;
use App\Http\Controllers\Api\PasswordController;
use App\Http\Controllers\Api\PayrollController;
use App\Http\Controllers\Api\UserController;
use App\Http\Middleware\EnsureAccountIsUsable;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    // Mot de passe oublié : seul cas où un e-mail est envoyé (AUTH-02)
    Route::middleware('throttle:5,1')->group(function () {
        Route::post('forgot-password', [PasswordController::class, 'forgot']);
        Route::post('reset-password', [PasswordController::class, 'reset']);
    });

    Route::middleware(['auth:sanctum', EnsureAccountIsUsable::class])->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('me/password', [PasswordController::class, 'change']);

        Route::get('dashboard', DashboardController::class);

        // Notifications
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'markAllRead']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'markRead']);
        Route::get('notification-preferences', [NotificationController::class, 'preferences']);
        Route::put('notification-preferences', [NotificationController::class, 'updatePreferences']);

        // Employés
        Route::get('employees/options', [EmployeeController::class, 'options']);
        Route::get('employees/export', [EmployeeController::class, 'export']);
        Route::middleware('role:super-admin|rh')->group(function () {
            Route::get('employees/import/template', [EmployeeImportController::class, 'template']);
            Route::post('employees/import/preview', [EmployeeImportController::class, 'preview']);
            Route::post('employees/import', [EmployeeImportController::class, 'store']);
        });
        Route::post('employees/{employee}/archive', [EmployeeController::class, 'archive']);
        Route::get('employees/{employee}/history', [EmployeeController::class, 'history']);
        Route::apiResource('employees', EmployeeController::class)->except('destroy');

        // Contrats et avenants
        Route::get('contracts/expiring', [ContractController::class, 'expiring']);
        Route::get('employees/{employee}/contracts', [ContractController::class, 'index']);
        Route::post('employees/{employee}/contracts', [ContractController::class, 'store']);
        Route::put('contracts/{contract}', [ContractController::class, 'update']);
        Route::delete('contracts/{contract}', [ContractController::class, 'destroy']);
        Route::post('contracts/{contract}/amendments', [ContractController::class, 'amend']);
        Route::post('contracts/{contract}/end', [ContractController::class, 'end']);
        Route::get('contracts/{contract}/pdf', [ContractController::class, 'pdf']);
        Route::get('contracts/{contract}/certificate', [ContractController::class, 'certificate']);
        Route::post('contracts/{contract}/document', [ContractController::class, 'uploadDocument']);
        Route::get('contracts/{contract}/document', [ContractController::class, 'document']);

        // Présences et temps de travail
        Route::get('attendance/me', [AttendanceController::class, 'me']);
        Route::post('attendance/clock-in', [AttendanceController::class, 'clockIn']);
        Route::post('attendance/clock-out', [AttendanceController::class, 'clockOut']);
        Route::get('attendance/timesheet', [AttendanceController::class, 'timesheet']);
        Route::get('attendance/team', [AttendanceController::class, 'team']);
        Route::get('overtime-requests', [AttendanceController::class, 'overtimeIndex']);
        Route::post('overtime-requests', [AttendanceController::class, 'overtimeStore']);
        Route::post('overtime-requests/{overtime}/decide', [AttendanceController::class, 'overtimeDecide']);
        Route::get('attendance-corrections', [AttendanceController::class, 'correctionIndex']);
        Route::post('attendance-corrections', [AttendanceController::class, 'correctionStore']);
        Route::post('attendance-corrections/{correction}/decide', [AttendanceController::class, 'correctionDecide']);
        Route::get('work-schedules', [AttendanceController::class, 'schedules']);

        // Documents : coffre-fort, demandes d'attestation, documents de l'entreprise
        Route::get('employees/{employee}/documents', [DocumentController::class, 'index']);
        Route::get('documents/{document}/download', [DocumentController::class, 'download']);
        Route::get('document-categories', [DocumentController::class, 'categories']);
        Route::get('document-requests', [DocumentRequestController::class, 'index']);
        Route::post('document-requests', [DocumentRequestController::class, 'store']);
        Route::get('company-documents', [CompanyDocumentController::class, 'index']);
        Route::get('company-documents/{companyDocument}/download', [CompanyDocumentController::class, 'download']);
        Route::post('company-documents/{companyDocument}/acknowledge', [CompanyDocumentController::class, 'acknowledge']);

        // Congés et absences
        Route::get('leave-types', [LeaveSettingsController::class, 'types']);
        Route::get('public-holidays', [LeaveSettingsController::class, 'holidays']);
        Route::get('leave-calendar', [LeaveRequestController::class, 'calendar']);
        Route::post('leave-requests/preview', [LeaveRequestController::class, 'preview']);
        Route::post('leave-requests/{leaveRequest}/approve', [LeaveRequestController::class, 'approve']);
        Route::post('leave-requests/{leaveRequest}/reject', [LeaveRequestController::class, 'reject']);
        Route::post('leave-requests/{leaveRequest}/cancel', [LeaveRequestController::class, 'cancel']);
        Route::get('leave-requests/{leaveRequest}/attachment', [LeaveRequestController::class, 'attachment']);
        Route::apiResource('leave-requests', LeaveRequestController::class)->only(['index', 'store', 'show']);
        Route::get('employees/{employee}/leave-balances', [LeaveBalanceController::class, 'index']);
        Route::post('employees/{employee}/leave-adjustments', [LeaveBalanceController::class, 'adjust']);

        // Structure de l'entreprise : lecture pour tous
        Route::get('departments', [OrganisationController::class, 'departments']);
        Route::get('positions', [OrganisationController::class, 'positions']);
        Route::get('sites', [OrganisationController::class, 'sites']);

        // Structure de l'entreprise : écriture RH / admin
        Route::middleware('role:super-admin|rh')->group(function () {
            // Comptes utilisateurs : inscription par l'administration uniquement (ADM-01)
            Route::get('users', [UserController::class, 'index']);
            Route::get('users/employees-without-account', [UserController::class, 'employeesWithoutAccount']);
            Route::post('users', [UserController::class, 'store']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::put('users/{user}/password', [UserController::class, 'setPassword']);
            Route::post('users/{user}/activate', [UserController::class, 'activate']);
            Route::post('users/{user}/deactivate', [UserController::class, 'deactivate']);

            // Paie
            Route::prefix('payroll')->group(function () {
                Route::get('periods', [PayrollController::class, 'periods']);
                Route::get('rubrics', [PayrollController::class, 'rubrics']);
                Route::post('rubrics', [PayrollController::class, 'storeRubric']);
                Route::put('rubrics/{rubric}', [PayrollController::class, 'updateRubric']);
                Route::delete('rubrics/{rubric}', [PayrollController::class, 'destroyRubric']);
                Route::get('recurring', [PayrollController::class, 'recurring']);
                Route::post('recurring', [PayrollController::class, 'storeRecurring']);
                Route::put('recurring/{recurring}', [PayrollController::class, 'updateRecurring']);
                Route::delete('recurring/{recurring}', [PayrollController::class, 'destroyRecurring']);
                Route::put('items/{item}', [PayrollController::class, 'updateItem']);
                Route::delete('items/{item}', [PayrollController::class, 'destroyItem']);

                Route::where(['month' => '\d{4}-(0[1-9]|1[0-2])'])->group(function () {
                    Route::get('periods/{month}', [PayrollController::class, 'show']);
                    Route::post('periods/{month}/prepare', [PayrollController::class, 'prepare']);
                    Route::post('periods/{month}/items', [PayrollController::class, 'storeItem']);
                    Route::post('periods/{month}/close', [PayrollController::class, 'close']);
                    Route::post('periods/{month}/reopen', [PayrollController::class, 'reopen']);
                    Route::get('periods/{month}/export', [PayrollController::class, 'export']);
                    Route::post('periods/{month}/payslips', [PayrollController::class, 'payslips']);
                });
            });

            // Horaires de travail
            Route::post('work-schedules', [AttendanceController::class, 'storeSchedule']);
            Route::put('work-schedules/{schedule}', [AttendanceController::class, 'updateSchedule']);
            Route::delete('work-schedules/{schedule}', [AttendanceController::class, 'destroySchedule']);

            // Documents (gestion RH)
            Route::post('employees/{employee}/documents', [DocumentController::class, 'store']);
            Route::post('employees/{employee}/documents/generate', [DocumentController::class, 'generate']);
            Route::get('documents/expiring', [DocumentController::class, 'expiring']);
            Route::put('documents/{document}', [DocumentController::class, 'update']);
            Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
            Route::post('document-categories', [DocumentController::class, 'storeCategory']);
            Route::put('document-categories/{category}', [DocumentController::class, 'updateCategory']);
            Route::delete('document-categories/{category}', [DocumentController::class, 'destroyCategory']);
            Route::post('document-requests/{documentRequest}/fulfill', [DocumentRequestController::class, 'fulfill']);
            Route::post('document-requests/{documentRequest}/refuse', [DocumentRequestController::class, 'refuse']);
            Route::post('company-documents', [CompanyDocumentController::class, 'store']);
            Route::delete('company-documents/{companyDocument}', [CompanyDocumentController::class, 'destroy']);
            Route::get('company-documents/{companyDocument}/readers', [CompanyDocumentController::class, 'readers']);

            Route::post('departments', [OrganisationController::class, 'storeDepartment']);
            Route::put('departments/{department}', [OrganisationController::class, 'updateDepartment']);
            Route::delete('departments/{department}', [OrganisationController::class, 'destroyDepartment']);

            Route::post('positions', [OrganisationController::class, 'storePosition']);
            Route::put('positions/{position}', [OrganisationController::class, 'updatePosition']);
            Route::delete('positions/{position}', [OrganisationController::class, 'destroyPosition']);

            Route::post('sites', [OrganisationController::class, 'storeSite']);
            Route::put('sites/{site}', [OrganisationController::class, 'updateSite']);
            Route::delete('sites/{site}', [OrganisationController::class, 'destroySite']);

            Route::post('leave-types', [LeaveSettingsController::class, 'storeType']);
            Route::put('leave-types/{leaveType}', [LeaveSettingsController::class, 'updateType']);
            Route::delete('leave-types/{leaveType}', [LeaveSettingsController::class, 'destroyType']);
            Route::post('public-holidays', [LeaveSettingsController::class, 'storeHoliday']);
            Route::post('public-holidays/generate', [LeaveSettingsController::class, 'generateHolidays']);
            Route::delete('public-holidays/{publicHoliday}', [LeaveSettingsController::class, 'destroyHoliday']);
        });
    });
});
