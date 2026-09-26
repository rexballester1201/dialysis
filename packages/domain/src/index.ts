/**
 * Dialysis adequacy and fluid maths.
 *
 * Pure functions, no I/O, no framework. The same numbers are computed on the
 * bedside tablet (offline, to show the nurse a result immediately) and on the
 * server (authoritatively). Both import this file so they cannot drift apart.
 *
 * Every formula below is published and cited. Nothing here is estimated,
 * interpolated, or adjusted to make a number look reasonable.
 */

/** Interdialytic weight gain: what the patient put on since the last session. */
export function idwgKg(preWeightKg: number, dryWeightKg: number): number {
  return round(preWeightKg - dryWeightKg, 2)
}

/**
 * Urea reduction ratio, as a percentage.
 *
 * URR = (pre-BUN - post-BUN) / pre-BUN x 100
 *
 * Target >= 65%.
 *
 * Source: National Kidney Foundation KDOQI Clinical Practice Guideline for
 * Haemodialysis Adequacy: 2015 Update. Am J Kidney Dis. 2015;66(5):884-930.
 */
export function urrPct(preBun: number, postBun: number): number {
  if (preBun <= 0) {
    throw new RangeError('pre-dialysis BUN must be greater than zero')
  }

  return round(((preBun - postBun) / preBun) * 100, 1)
}

export interface KtvInput {
  /** Pre-dialysis blood urea nitrogen, same unit as postBun. */
  preBun: number
  /** Post-dialysis blood urea nitrogen, same unit as preBun. */
  postBun: number
  /** Session length in hours. */
  hours: number
  /** Ultrafiltration volume removed, in litres. */
  ufLitres: number
  /** Post-dialysis weight in kilograms. */
  postWeightKg: number
}

/**
 * Single-pool Kt/V by the second-generation Daugirdas equation.
 *
 *   Kt/V = -ln(R - 0.008 x t) + (4 - 3.5 x R) x UF / W
 *
 * where R = post-BUN / pre-BUN, t = hours, UF = litres removed, W = post weight.
 *
 * Target >= 1.2 for thrice-weekly haemodialysis.
 *
 * Source: Daugirdas JT. "Second generation logarithmic estimates of single-pool
 * variable volume Kt/V: an analysis of error." J Am Soc Nephrol. 1993;4(5):1205-1213.
 *
 * The BUN units cancel in the ratio, so mg/dL and mmol/L both work -- provided
 * both samples use the same one. Mixing them silently produces a plausible and
 * wrong number, which is why this throws rather than guesses.
 */
export function ktv({ preBun, postBun, hours, ufLitres, postWeightKg }: KtvInput): number {
  if (preBun <= 0 || postBun <= 0) {
    throw new RangeError('BUN values must be greater than zero')
  }

  if (postBun > preBun) {
    throw new RangeError('post-dialysis BUN exceeds pre-dialysis BUN; check the sample order')
  }

  if (hours <= 0) {
    throw new RangeError('session length must be greater than zero')
  }

  if (postWeightKg <= 0) {
    throw new RangeError('post-dialysis weight must be greater than zero')
  }

  const ratio = postBun / preBun

  return round(-Math.log(ratio - 0.008 * hours) + (4 - 3.5 * ratio) * (ufLitres / postWeightKg), 2)
}

/**
 * Ultrafiltration rate in mL/kg/h, normalised to post-dialysis (dry) weight.
 *
 * Source: Flythe JE, Kimmel SE, Brunelli SM. "Rapid fluid removal during dialysis
 * is associated with cardiovascular morbidity and mortality." Kidney Int.
 * 2011;79(2):250-257.
 */
export function ufRateMlPerKgPerHour(netUfMl: number, dryWeightKg: number, hours: number): number {
  if (dryWeightKg <= 0) {
    throw new RangeError('dry weight must be greater than zero')
  }

  if (hours <= 0) {
    throw new RangeError('session length must be greater than zero')
  }

  return round(netUfMl / dryWeightKg / hours, 1)
}

/**
 * The threshold above which fluid removal is associated with excess
 * cardiovascular mortality, from the Flythe 2011 analysis cited above.
 *
 * Exported as a named constant so a clinical cut-off is never a bare number
 * sitting in a component.
 */
export const UF_RATE_CONCERN_ML_KG_H = 13

export function ufRateIsOfConcern(rateMlPerKgPerHour: number): boolean {
  return rateMlPerKgPerHour > UF_RATE_CONCERN_ML_KG_H
}

/** Kt/V adequacy target for thrice-weekly HD (KDOQI 2015). */
export const KTV_TARGET = 1.2

/** URR adequacy target for thrice-weekly HD (KDOQI 2015). */
export const URR_TARGET_PCT = 65

function round(value: number, places: number): number {
  const factor = 10 ** places

  return Math.round(value * factor) / factor
}
