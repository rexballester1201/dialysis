<?php

declare(strict_types=1);

namespace App\Domain\Sync\Handlers;

use App\Domain\Core\Models\Staff;

interface OperationHandler
{
    /**
     * status is one of: applied | duplicate | conflict | rejected.
     *
     * @param  array<string, mixed>  $operation
     * @return array{op_uuid: string, status: string, server_id?: int|string, message?: string, server_state?: array<string, mixed>}
     */
    public function handle(array $operation, Staff $actor, string $opUuid): array;
}
