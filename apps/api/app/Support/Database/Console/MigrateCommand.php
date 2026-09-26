<?php

declare(strict_types=1);

namespace App\Support\Database\Console;

use App\Support\Database\BaselineSchema;
use Illuminate\Database\Connection;
use Illuminate\Database\Console\Migrations\MigrateCommand as BaseMigrateCommand;

/**
 * `migrate`, told where to find a portable copy of the baseline when testing.
 *
 * RefreshDatabase runs `migrate:fresh` with its own fixed option set, and a
 * trait method always beats an inherited one, so Tests\TestCase has no override
 * point. CommandStarting is no help either: it is only dispatched by the console
 * kernel's Symfony reroute, which $this->artisan() never sets up.
 *
 * Overriding the lookup itself is the one hook that works for both entry points.
 * It is registered only while running tests, so production `php artisan migrate`
 * resolves the framework's own command and behaves exactly as before.
 *
 * @see BaselineSchema for why the copy exists at all.
 */
final class MigrateCommand extends BaseMigrateCommand
{
    /**
     * @param  Connection  $connection
     */
    protected function schemaPath($connection)
    {
        if ($this->option('schema-path')) {
            return parent::schemaPath($connection);
        }

        $default = parent::schemaPath($connection);

        // Only stand in for the hand-written baseline. A real `schema:dump`
        // artefact creates its own migrations table and needs no help.
        return $default === database_path(BaselineSchema::SOURCE)
            ? BaselineSchema::portablePath()
            : $default;
    }
}
