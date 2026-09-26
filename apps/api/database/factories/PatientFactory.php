<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Core\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * @extends Factory<Patient>
 */
final class PatientFactory extends Factory
{
    protected $model = Patient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mrn' => 'MRN-'.$this->faker->unique()->numberBetween(10000, 99999),
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'birth_date' => $this->faker->dateTimeBetween('-85 years', '-18 years')->format('Y-m-d'),
            'sex' => $this->faker->randomElement(['male', 'female']),
            'status' => 'active',
            // full_name is a generated column. Never set it here: MySQL rejects
            // any write to one.
        ];
    }

    /**
     * HBsAg reactive, which puts this patient in the `hbv` cohort and therefore
     * restricts which chair and machine they may use.
     *
     * The cohort is not a column -- it is derived from the latest result per
     * marker through v_patient_cohort -- so the state writes the serology that
     * produces it rather than setting a flag.
     */
    public function hbvReactive(): self
    {
        return $this->afterCreating(function (Patient $patient): void {
            DB::table('serology_results')->insert([
                'patient_id' => $patient->id,
                'marker' => 'hbsag',
                'result' => 'reactive',
                'specimen_date' => now()->subMonths(2)->toDateString(),
            ]);
        });
    }

    /** Explicitly clean: a non-reactive HBsAg on record, not merely absent. */
    public function cleanCohort(): self
    {
        return $this->afterCreating(function (Patient $patient): void {
            DB::table('serology_results')->insert([
                'patient_id' => $patient->id,
                'marker' => 'hbsag',
                'result' => 'non_reactive',
                'specimen_date' => now()->subMonths(2)->toDateString(),
            ]);
        });
    }

    public function deceased(): self
    {
        return $this->state(fn (): array => [
            'status' => 'deceased',
            'status_changed_on' => now()->subDays(10)->toDateString(),
        ]);
    }
}
