<?php

declare(strict_types=1);

namespace App\Domain\Clinical\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The catalogue of tests a result may be filed against.
 *
 * Two ranges, and the difference is clinical rather than cosmetic:
 *
 *   ref_low / ref_high        the laboratory's reference interval for a general
 *                             population -- what "abnormal" means on a report
 *   target_low / target_high  where a dialysis patient should sit, which is
 *                             frequently outside the general interval
 *
 * Haemoglobin is the clearest case: 12-16 g/dL is the general reference, but the
 * target for a dialysed patient is 10-11.5. A haemoglobin of 11 is "low" against
 * the reference and exactly right for the patient. Conflating the two produces a
 * screen full of red that everybody learns to ignore, so the system reports them
 * separately and never merges them.
 *
 * Reference data, not a clinical record: no audit trail, no timestamps, and the
 * primary key is the code itself.
 */
class LabTestRef extends Model
{
    protected $table = 'lab_test_refs';

    protected $primaryKey = 'code';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    /**
     * Decimals stay strings. A reference range compared against a value that
     * round-tripped through a float is a flag that is occasionally wrong at the
     * boundary, and the boundary is the only place the flag matters.
     */
    protected $casts = [
        'sort_order' => 'integer',
    ];
}
