<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Billing\Models\Claim;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Policies\ClaimPolicy;
use App\Domain\Billing\Policies\InvoicePolicy;
use App\Domain\Clinical\Models\HdPrescription;
use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Clinical\Policies\PrescriptionPolicy;
use App\Domain\Clinical\Policies\TreatmentSessionPolicy;
use App\Domain\Core\Models\Patient;
use App\Domain\Core\Policies\PatientPolicy;
use App\Domain\Ops\Models\DialyzerUnit;
use App\Domain\Ops\Policies\DialyzerUnitPolicy;
use App\Domain\Ops\Policies\WaterPolicy;
use App\Domain\Sync\Handlers\AppendEventHandler;
use App\Domain\Sync\Handlers\AppendVitalHandler;
use App\Domain\Sync\Handlers\UpsertSessionHandler;
use App\Domain\Sync\SyncBatchProcessor;
use App\Support\Database\Console\MigrateCommand;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\MigrateCommand as BaseMigrateCommand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\SchemaLoaded;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Policies are registered explicitly.
     *
     * Laravel's auto-discovery looks for App\Policies\FooPolicy next to
     * App\Models\Foo. This codebase groups models and policies by domain module
     * instead, so discovery finds nothing and every policy must be named here.
     * A silently undiscovered policy on clinical software fails open.
     *
     * @var array<class-string, class-string>
     */
    private const POLICIES = [
        Patient::class => PatientPolicy::class,
        TreatmentSession::class => TreatmentSessionPolicy::class,
        HdPrescription::class => PrescriptionPolicy::class,
        DialyzerUnit::class => DialyzerUnitPolicy::class,
        Claim::class => ClaimPolicy::class,
        Invoice::class => InvoicePolicy::class,
    ];

    public function register(): void
    {
        // The operation-type map is the sync protocol's registry. Adding a type
        // here also means adding a client_uuid column with a UNIQUE index on its
        // target table -- that column is the whole idempotency story (CLAUDE.md).
        $this->app->singleton(SyncBatchProcessor::class, fn ($app): SyncBatchProcessor => new SyncBatchProcessor([
            'session.upsert' => $app->make(UpsertSessionHandler::class),
            'vital.append' => $app->make(AppendVitalHandler::class),
            'event.append' => $app->make(AppendEventHandler::class),
        ]));

        $this->usePortableBaselineWhenTesting();
    }

    /**
     * Load the baseline through a connection-agnostic copy while testing.
     *
     * The baseline contains `USE dialysis;`, which overrides the --database flag
     * Laravel passes to the mysql client. Left alone, the test run would build
     * its 66 tables in the development database and dialysis_test would stay
     * empty -- silently, and while scribbling over development data.
     *
     * Registered only under tests, so production migrate is untouched.
     */
    private function usePortableBaselineWhenTesting(): void
    {
        if (! $this->app->runningUnitTests()) {
            return;
        }

        // extend(), not singleton(). MigrationServiceProvider is deferred: it
        // registers when `migrate` is first resolved, which is after this runs,
        // and a plain rebind here would simply be overwritten. An extender is
        // kept across that rebind and applied at resolution time.
        $this->app->extend(
            BaseMigrateCommand::class,
            fn ($command, $app): MigrateCommand => new MigrateCommand($app['migrator'], $app[Dispatcher::class]),
        );
    }

    public function boot(): void
    {
        // Eager-load explicitly; a lazy load inside a flow-sheet loop is a
        // bedside stall. Left off in production so a missed one is not an outage.
        Model::preventLazyLoading(! $this->app->isProduction());

        // No "data" envelope. The sync endpoints the bedside PWA already talks to
        // return their payload at the top level (body.results, data.sessions), and
        // one API that wraps half its responses is worse than either convention.
        JsonResource::withoutWrapping();

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        // Water compliance authorises an act, not a model, so it is a pair of
        // gates rather than a policy. Recording a check is an attestation that
        // someone actually tested the water.
        Gate::define('water.view', [WaterPolicy::class, 'view']);
        Gate::define('water.record', [WaterPolicy::class, 'record']);

        $this->ensureMigrationRepositoryAfterSchemaLoad();
    }

    /**
     * Recreate the migration ledger after the baseline schema is loaded.
     *
     * Laravel's MigrateCommand::loadSchemaState() drops the `migrations` table
     * before executing the schema file, because a dump produced by `schema:dump`
     * recreates that table and re-inserts its rows. Our baseline is hand-written
     * and owns only the 66 clinical tables -- it deliberately knows nothing about
     * Laravel's bookkeeping -- so after the load there is no ledger and the
     * migrator dies reading it.
     *
     * Creating it here keeps `php artisan migrate` working on a fresh database
     * without editing database/schema/mysql-schema.sql, which is the validated
     * definition of record and stays byte-identical to what was tested on MySQL.
     */
    private function ensureMigrationRepositoryAfterSchemaLoad(): void
    {
        Event::listen(function (SchemaLoaded $event): void {
            $repository = $this->app->make(Migrator::class)->getRepository();

            if (! $repository->repositoryExists()) {
                $repository->createRepository();
            }
        });
    }
}
