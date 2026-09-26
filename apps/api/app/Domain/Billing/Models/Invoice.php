<?php

declare(strict_types=1);

namespace App\Domain\Billing\Models;

use App\Support\Auditing\Auditable;
use App\Support\Database\HasGeneratedColumns;
use Illuminate\Database\Eloquent\Model;

/**
 * Audited from the first write. No controller in Phase 0.
 */
class Invoice extends Model
{
    use Auditable;
    use HasGeneratedColumns;

    protected $table = 'invoices';

    protected $guarded = ['id'];

    /**
     * Routed by invoice number. Same reasoning as Claim: the baseline gives
     * invoices no ULID, and invoice_no is unique and is what the patient's own
     * copy quotes.
     */
    public function getRouteKeyName(): string
    {
        return 'invoice_no';
    }

    /**
     * Maintained by MySQL. Listing it here keeps Eloquent from ever writing it,
     * which MySQL rejects outright.
     *
     * @var list<string>
     */
    protected array $generated = ['balance'];

    protected $casts = [
        'issued_on' => 'date',
        'due_on' => 'date',
        'subtotal' => 'decimal:2',
        'discount' => 'decimal:2',
        'tax' => 'decimal:2',
        'payer_covered' => 'decimal:2',
        'patient_due' => 'decimal:2',
        'amount_paid' => 'decimal:2',
        'balance' => 'decimal:2',
    ];
}
