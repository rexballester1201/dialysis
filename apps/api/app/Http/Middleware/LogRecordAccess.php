<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Clinical\Models\TreatmentSession;
use App\Domain\Core\Models\Patient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Records WHO OPENED WHOSE CHART, which is a different question from who
 * changed what, and the one PH/MY/ID privacy regulators actually ask.
 *
 * Applied to every route that resolves a Patient, so it cannot be forgotten
 * on a new endpoint. Writes after the response is sent.
 */
final class LogRecordAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        if ($response->getStatusCode() >= 400) {
            return;
        }

        $patient = $request->route('patient');
        $patientId = $patient instanceof Patient ? $patient->id : null;

        if ($patientId === null) {
            $session = $request->route('session');
            $patientId = $session instanceof TreatmentSession ? $session->patient_id : null;
        }

        if ($patientId === null) {
            return;
        }

        DB::table('record_access_logs')->insert([
            'accessed_at' => now(),
            'actor_id' => $request->user()?->id,
            'patient_id' => $patientId,
            'context' => $request->route()?->getName() ?? $request->path(),
            'ip_address' => $request->ip(),
        ]);
    }
}
