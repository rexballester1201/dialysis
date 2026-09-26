<?php

declare(strict_types=1);

use App\Domain\Billing\Models\BenefitPeriod;
use App\Domain\Billing\Models\BenefitProgram;
use App\Domain\Billing\Models\Claim;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Clinical\Models\HdPrescription;
use App\Domain\Clinical\Models\LabOrder;
use App\Domain\Clinical\Models\LabResult;
use App\Domain\Clinical\Models\MedicationAdministration;
use App\Domain\Clinical\Models\SerologyResult;
use App\Domain\Clinical\Models\SessionEvent;
use App\Domain\Clinical\Models\SessionNote;
use App\Domain\Clinical\Models\SessionVital;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Models\Staff;
use App\Domain\Ops\Models\DialyzerUnit;
use App\Domain\Ops\Models\WaterDailyLog;
use App\Support\Auditing\Auditable;
use App\Support\Database\HasGeneratedColumns;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Model integrity
|--------------------------------------------------------------------------
| A cheap sweep over every domain model.
|
| It exists because a broken class file once survived a full green suite: the
| models with no controller yet -- Invoice, Claim, SerologyResult -- were never
| loaded by anything, so nothing noticed. These assertions cost milliseconds and
| would have caught it.
*/

uses(TestCase::class, RefreshDatabase::class);

/** @return list<class-string<Model>> */
function domainModels(): array
{
    return [
        Patient::class,
        Staff::class,
        TreatmentSession::class,
        SessionVital::class,
        SessionEvent::class,
        SessionNote::class,
        MedicationAdministration::class,
        HdPrescription::class,
        LabOrder::class,
        LabResult::class,
        SerologyResult::class,
        Invoice::class,
        Claim::class,
        BenefitProgram::class,
        BenefitPeriod::class,
        DialyzerUnit::class,
        WaterDailyLog::class,
    ];
}

it('can instantiate every domain model against a real table', function () {
    foreach (domainModels() as $class) {
        $model = new $class;

        expect($model->getTable())->toBeString()
            ->and(Schema::hasTable($model->getTable()))
            ->toBeTrue("{$class} maps to a table that does not exist: {$model->getTable()}");
    }
});

it('declares every generated column the database actually has', function () {
    // CLAUDE.md lists 15 generated columns. Any model mapping to a table that has
    // one must declare it, or Eloquent will eventually try to write it -- and the
    // value will be missing from the response of the row it just created.
    $generatedByTable = [];

    foreach (
        DB::table('information_schema.columns')
            ->where('table_schema', DB::connection()->getDatabaseName())
            ->where(function ($query): void {
                $query->where('extra', 'like', '%STORED GENERATED%')
                    ->orWhere('extra', 'like', '%VIRTUAL GENERATED%');
            })
            // Aliased: MySQL 8 returns information_schema column names
            // uppercased, so $row->table_name would not exist.
            ->get(['table_name as tbl', 'column_name as col']) as $column
    ) {
        $generatedByTable[$column->tbl][] = $column->col;
    }

    foreach (domainModels() as $class) {
        $model = new $class;
        $expected = $generatedByTable[$model->getTable()] ?? [];

        if ($expected === []) {
            continue;
        }

        expect(in_array(HasGeneratedColumns::class, class_uses_recursive($class), true))
            ->toBeTrue("{$class} maps to a table with generated columns but does not use HasGeneratedColumns.");

        sort($expected);
        $declared = $model->generatedColumns();
        sort($declared);

        expect($declared)->toBe($expected, "{$class} does not declare every generated column on {$model->getTable()}.");
    }
});

it('audits every model that carries clinical or financial consequence', function () {
    $mustBeAudited = [
        Patient::class,
        TreatmentSession::class,
        HdPrescription::class,
        LabOrder::class,
        LabResult::class,
        MedicationAdministration::class,
        SerologyResult::class,
        Claim::class,
        Invoice::class,
    ];

    foreach ($mustBeAudited as $class) {
        expect(in_array(Auditable::class, class_uses_recursive($class), true))
            ->toBeTrue("{$class} changes a clinical or financial fact and must be audited.");
    }
});

it('never exposes a credential through an audited payload', function () {
    foreach (domainModels() as $class) {
        if (! in_array(Auditable::class, class_uses_recursive($class), true)) {
            continue;
        }

        expect((new $class)->auditExclude())
            ->toContain('password')
            ->toContain('clinical_pin_hash');
    }
});
