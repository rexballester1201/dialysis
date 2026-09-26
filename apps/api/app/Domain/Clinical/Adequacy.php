<?php

declare(strict_types=1);

namespace App\Domain\Clinical;

use App\Support\Exceptions\DomainRuleException;

/**
 * Dialysis adequacy maths, server side.
 *
 * The deliberate twin of packages/domain/src/index.ts. The tablet computes these
 * so a nurse sees a result at the chair without waiting for a round trip; the
 * server computes them again because a derived value that arrives from a client
 * is a value nobody has checked. Kt/V drives whether a prescription gets
 * lengthened -- a chart that records a number inconsistent with its own inputs
 * is a chart that will be believed and is wrong.
 *
 * Both implementations use the same published formulas, and the same worked
 * example is pinned in both test suites.
 *
 * Every formula and threshold below is cited. Nothing here is estimated.
 */
final class Adequacy
{
    /**
     * Single-pool Kt/V target for thrice-weekly haemodialysis.
     *
     * Source: NKF KDOQI Clinical Practice Guideline for Hemodialysis Adequacy,
     * 2015 Update. Am J Kidney Dis. 2015;66(5):884-930.
     */
    public const KTV_TARGET = 1.2;

    /** URR target, same source. */
    public const URR_TARGET_PCT = 65.0;

    /**
     * Ultrafiltration rate above which cardiovascular mortality rises.
     *
     * Source: Flythe JE, Kimmel SE, Brunelli SM. "Rapid fluid removal during
     * dialysis is associated with cardiovascular morbidity and mortality."
     * Kidney Int. 2011;79(2):250-257.
     */
    public const UF_RATE_CONCERN_ML_KG_H = 13.0;

    /**
     * Urea reduction ratio, as a percentage.
     *
     * URR = (pre-BUN - post-BUN) / pre-BUN x 100
     */
    public static function urrPct(float $preBun, float $postBun): float
    {
        if ($preBun <= 0) {
            throw new DomainRuleException('Pre-dialysis BUN must be greater than zero to compute URR.');
        }

        return round(($preBun - $postBun) / $preBun * 100, 1);
    }

    /**
     * Single-pool Kt/V by the second-generation Daugirdas equation.
     *
     *   Kt/V = -ln(R - 0.008 x t) + (4 - 3.5 x R) x UF / W
     *
     * where R = post-BUN / pre-BUN, t = hours, UF = litres removed, W = post weight.
     *
     * Source: Daugirdas JT. "Second generation logarithmic estimates of
     * single-pool variable volume Kt/V: an analysis of error."
     * J Am Soc Nephrol. 1993;4(5):1205-1213.
     *
     * The BUN units cancel in the ratio, so mg/dL and mmol/L both work provided
     * both samples use the same one. Mixing them produces a plausible and wrong
     * number, which is why every input is bounds-checked rather than trusted.
     */
    public static function ktv(
        float $preBun,
        float $postBun,
        float $hours,
        float $ufLitres,
        float $postWeightKg,
    ): float {
        if ($preBun <= 0 || $postBun <= 0) {
            throw new DomainRuleException('BUN values must be greater than zero to compute Kt/V.');
        }

        if ($postBun > $preBun) {
            throw new DomainRuleException(
                'Post-dialysis BUN exceeds the pre-dialysis value; check which sample is which.'
            );
        }

        if ($hours <= 0) {
            throw new DomainRuleException('Session length must be greater than zero to compute Kt/V.');
        }

        if ($postWeightKg <= 0) {
            throw new DomainRuleException('Post-dialysis weight must be greater than zero to compute Kt/V.');
        }

        $ratio = $postBun / $preBun;
        $inner = $ratio - 0.008 * $hours;

        if ($inner <= 0) {
            // Physically impossible: a very short session with a very low ratio.
            // Returning a number here would be inventing one.
            throw new DomainRuleException(
                'These values do not produce a valid Kt/V; check the BUN samples and the session length.'
            );
        }

        return round(-log($inner) + (4 - 3.5 * $ratio) * ($ufLitres / $postWeightKg), 2);
    }

    /** Ultrafiltration rate in mL/kg/h, normalised to post-dialysis weight. */
    public static function ufRateMlPerKgPerHour(float $netUfMl, float $dryWeightKg, float $hours): float
    {
        if ($dryWeightKg <= 0) {
            throw new DomainRuleException('Dry weight must be greater than zero to compute the UF rate.');
        }

        if ($hours <= 0) {
            throw new DomainRuleException('Session length must be greater than zero to compute the UF rate.');
        }

        return round($netUfMl / $dryWeightKg / $hours, 1);
    }

    public static function ktvMeetsTarget(float $ktv): bool
    {
        return $ktv >= self::KTV_TARGET;
    }

    public static function urrMeetsTarget(float $urrPct): bool
    {
        return $urrPct >= self::URR_TARGET_PCT;
    }

    public static function ufRateIsOfConcern(float $rateMlPerKgPerHour): bool
    {
        return $rateMlPerKgPerHour > self::UF_RATE_CONCERN_ML_KG_H;
    }
}
