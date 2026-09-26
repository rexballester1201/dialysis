<?php

declare(strict_types=1);

use App\Domain\Billing\Http\ClaimController;
use App\Domain\Billing\Http\InvoiceController;
use App\Domain\Clinical\Http\DryWeightController;
use App\Domain\Clinical\Http\LabController;
use App\Domain\Clinical\Http\PrescriptionController;
use App\Domain\Clinical\Http\SerologyController;
use App\Domain\Clinical\Http\SessionController;
use App\Domain\Core\Http\AuthController;
use App\Domain\Core\Http\HealthController;
use App\Domain\Core\Http\PatientController;
use App\Domain\Core\Http\SettingsController;
use App\Domain\Ops\Http\BoardController;
use App\Domain\Ops\Http\MachineController;
use App\Domain\Ops\Http\ReportController;
use App\Domain\Ops\Http\ReuseController;
use App\Domain\Ops\Http\StandingScheduleController;
use App\Domain\Ops\Http\StockController;
use App\Domain\Ops\Http\WaterLogController;
use App\Domain\Sync\Http\SyncController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
| Auth: Laravel Sanctum. The bedside PWA holds a device-scoped personal access
| token with the `bedside` ability and a 12-hour TTL; desktop clients use the
| same flow with broader abilities. A nurse re-authenticates within a shift by
| clinical PIN, which re-issues the token without a full password round trip.
|
| Every patient-scoped route sits behind `log.access`, which records who opened
| whose chart after the response has been sent. It is applied to the group so it
| cannot be forgotten on a new endpoint.
|
| routes/api.php.bundle carries the full intended surface for later phases
| (ops, billing, reports).
*/

Route::prefix('v1')->group(function (): void {
    Route::get('health', HealthController::class)->name('health');

    Route::post('auth/login', [AuthController::class, 'login'])
        ->middleware('throttle:5,1')->name('auth.login');

    Route::post('auth/pin-unlock', [AuthController::class, 'pinUnlock'])
        ->middleware('throttle:10,1')->name('auth.pin-unlock');

    Route::middleware(['auth:sanctum', 'device', 'log.access'])->group(function (): void {
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

        // ---- offline sync (bedside PWA) ---------------------------------
        Route::get('sync/bootstrap', [SyncController::class, 'bootstrap'])->name('sync.bootstrap');
        Route::post('sync', [SyncController::class, 'store'])->name('sync.store')
            ->middleware('throttle:60,1');

        // ---- the session lifecycle ---------------------------------------
        // check-in -> start -> end -> nurse signs -> physician signs -> locked.
        // After the lock the only way in is the amendment path.
        Route::get('sessions', [SessionController::class, 'index'])->name('sessions.index');
        Route::get('sessions/{session}', [SessionController::class, 'show'])->name('sessions.show');
        Route::post('sessions/{session}/check-in', [SessionController::class, 'checkIn'])->name('sessions.check-in');
        Route::post('sessions/{session}/start', [SessionController::class, 'start'])->name('sessions.start');
        Route::post('sessions/{session}/end', [SessionController::class, 'end'])->name('sessions.end');

        // ---- the flow sheet -----------------------------------------------
        Route::get('sessions/{session}/vitals', [SessionController::class, 'vitals'])->name('sessions.vitals.index');
        Route::post('sessions/{session}/vitals', [SessionController::class, 'appendVital'])->name('sessions.vitals.store');
        Route::get('sessions/{session}/events', [SessionController::class, 'events'])->name('sessions.events.index');
        Route::post('sessions/{session}/events', [SessionController::class, 'appendEvent'])->name('sessions.events.store');
        Route::post('sessions/{session}/medications', [SessionController::class, 'administer'])->name('sessions.meds.store');

        // ---- attestation ----------------------------------------------------
        Route::post('sessions/{session}/sign/nurse', [SessionController::class, 'signNurse'])->name('sessions.sign.nurse');
        Route::post('sessions/{session}/sign/physician', [SessionController::class, 'signPhysician'])->name('sessions.sign.physician');
        Route::post('sessions/{session}/amend', [SessionController::class, 'amend'])->name('sessions.amend');

        // ---- billing -----------------------------------------------------------
        // Invariant 6: a session is billed at most once. BenefitLedger refuses a
        // duplicate and names the claim that already has it; the claim_sessions
        // primary key stops one that arrives any other way.
        //
        // No rate and no cap appear in code. Both come from effective-dated rows
        // in benefit_programs -- they have already moved twice.
        Route::get('claims', [ClaimController::class, 'index'])->name('claims.index');
        Route::get('claims/{claim}', [ClaimController::class, 'show'])->name('claims.show');
        Route::post('claims/{claim}/status', [ClaimController::class, 'transition'])->name('claims.status');
        Route::post('claims/{claim}/remittance', [ClaimController::class, 'remit'])->name('claims.remit');
        Route::get('claims-outstanding', [ClaimController::class, 'outstanding'])->name('claims.outstanding');
        Route::get('patients/{patient}/claimable', [ClaimController::class, 'claimable'])->name('claims.claimable');
        Route::post('patients/{patient}/claims', [ClaimController::class, 'generate'])->name('claims.generate');

        // What the patient owes once the payer's share is taken off. A package
        // with no-balance billing leaves nothing to charge, which is the point
        // of that flag.
        Route::get('invoices', [InvoiceController::class, 'index'])->name('invoices.index');
        Route::get('invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
        Route::post('invoices/{invoice}/payments', [InvoiceController::class, 'pay'])->name('invoices.pay');
        Route::post('invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
        // Frees the invoice's sessions to be invoiced again; refused while any
        // money sits on it.
        Route::post('invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
        Route::get('patients/{patient}/invoiceable', [InvoiceController::class, 'invoiceable'])->name('invoices.invoiceable');
        Route::post('patients/{patient}/invoices', [InvoiceController::class, 'store'])->name('invoices.store');

        // ---- ops -------------------------------------------------------------
        // Water compliance gates the first treatment of the day (invariant 9);
        // dialyzer reuse is refused at the point of issue (invariant 8). Both
        // still have their detective views, read by dialysis:check-controls.
        Route::get('water-logs', [WaterLogController::class, 'index'])->name('water.index');
        Route::post('water-logs', [WaterLogController::class, 'store'])->name('water.store');

        Route::get('patients/{patient}/dialyzers', [ReuseController::class, 'index'])->name('reuse.index');
        Route::get('dialyzers/{dialyzer}/history', [ReuseController::class, 'history'])->name('reuse.history');
        Route::post('dialyzers/{dialyzer}/reprocess', [ReuseController::class, 'reprocess'])->name('reuse.reprocess');

        Route::get('machines', [MachineController::class, 'index'])->name('machines.index');
        Route::get('machines/{machine}', [MachineController::class, 'show'])->name('machines.show');
        Route::post('machines/{machine}/disinfection', [MachineController::class, 'disinfect'])->name('machines.disinfect');
        Route::post('machines/{machine}/maintenance', [MachineController::class, 'maintain'])->name('machines.maintain');

        // Lot balances are only ever moved by the stock_transactions_ai trigger,
        // so the count and the movement history cannot drift apart.
        Route::get('stock', [StockController::class, 'index'])->name('stock.index');
        Route::get('stock/items/{item}/lots', [StockController::class, 'lots'])->name('stock.lots');
        Route::post('stock/receipts', [StockController::class, 'receive'])->name('stock.receive');
        Route::post('stock/issues', [StockController::class, 'issue'])->name('stock.issue');

        // ---- reports ----------------------------------------------------------
        // quality reads the nightly rollup, not v_monthly_quality: the view
        // materialises every session before it answers (CLAUDE.md rule 7).
        // ?live=1 goes to the view for anyone checking a figure they will act on.
        Route::get('reports/quality', [ReportController::class, 'quality'])->name('reports.quality');
        Route::get('reports/cohort-violations', [ReportController::class, 'cohortViolations'])->name('reports.cohort');
        Route::get('reports/water-exceptions', [ReportController::class, 'waterExceptions'])->name('reports.water');
        Route::get('reports/dialyzer-exceptions', [ReportController::class, 'dialyzerExceptions'])->name('reports.dialyzers');
        Route::get('reports/utilisation', [ReportController::class, 'utilisation'])->name('reports.utilisation');

        // ---- labs ------------------------------------------------------------
        // Monthly bloods. Ordering commits the unit to a venepuncture and is
        // checked against the role in the controller; filing follows the
        // patient's own policy.
        Route::get('lab-tests', [LabController::class, 'catalogue'])->name('labs.catalogue');
        Route::get('patients/{patient}/lab-orders', [LabController::class, 'orders'])->name('labs.orders');
        Route::post('patients/{patient}/lab-orders', [LabController::class, 'order'])->name('labs.order');
        Route::post('lab-orders/{order}/status', [LabController::class, 'transition'])->name('labs.order-status');
        Route::get('patients/{patient}/lab-results', [LabController::class, 'results'])->name('labs.results');
        Route::post('patients/{patient}/lab-results', [LabController::class, 'fileResults'])->name('labs.file-results');

        // ---- unit settings ---------------------------------------------------
        // Administrator only. Three of these change what the system will let a
        // nurse do to a patient, so each one audits itself -- none of these
        // tables has a model, so the Auditable trait cannot see them.
        Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
        Route::patch('settings/facility', [SettingsController::class, 'updateFacility'])->name('settings.facility');
        Route::put('settings/stations/{station}/cohorts', [SettingsController::class, 'setStationCohorts'])->name('settings.station-cohorts');
        Route::post('settings/benefit-programs', [SettingsController::class, 'addBenefitProgram'])->name('settings.benefit-programs');
        Route::patch('settings/medications/{medication}/high-alert', [SettingsController::class, 'setHighAlert'])->name('settings.high-alert');
        Route::put('settings/staff/{staff}/roles', [SettingsController::class, 'setStaffRoles'])->name('settings.staff-roles');

        // ---- the board -------------------------------------------------------
        Route::get('board', [BoardController::class, 'index'])->name('board.index');
        Route::post('board/generate', [BoardController::class, 'generate'])->name('board.generate');

        // ---- registry ---------------------------------------------------------
        Route::get('patients', [PatientController::class, 'index'])->name('patients.index');
        Route::post('patients', [PatientController::class, 'store'])->name('patients.store');
        Route::get('patients/{patient}', [PatientController::class, 'show'])->name('patients.show');
        Route::patch('patients/{patient}', [PatientController::class, 'update'])->name('patients.update');
        Route::delete('patients/{patient}', [PatientController::class, 'destroy'])->name('patients.destroy');

        // Status is its own endpoint so the history row is never optional.
        Route::post('patients/{patient}/status', [PatientController::class, 'changeStatus'])
            ->name('patients.status');

        Route::get('patients/{patient}/prescriptions', [PrescriptionController::class, 'index'])->name('patients.rx.index');
        Route::post('patients/{patient}/prescriptions', [PrescriptionController::class, 'store'])->name('patients.rx.store');

        Route::get('patients/{patient}/schedule', [StandingScheduleController::class, 'index'])->name('patients.schedule.index');
        Route::post('patients/{patient}/schedule', [StandingScheduleController::class, 'store'])->name('patients.schedule.store');
        Route::get('patients/{patient}/available-stations', [BoardController::class, 'availableStations'])->name('patients.available-stations');

        Route::get('patients/{patient}/serology', [SerologyController::class, 'index'])->name('patients.serology.index');
        Route::post('patients/{patient}/serology', [SerologyController::class, 'store'])->name('patients.serology.store');

        Route::get('patients/{patient}/dry-weights', [DryWeightController::class, 'index'])->name('patients.dry-weights.index');
        Route::post('patients/{patient}/dry-weights', [DryWeightController::class, 'store'])->name('patients.dry-weights.store');
    });
});
