import { describe, expect, it } from 'vitest'

import {
  idwgKg,
  KTV_TARGET,
  ktv,
  UF_RATE_CONCERN_ML_KG_H,
  ufRateIsOfConcern,
  ufRateMlPerKgPerHour,
  URR_TARGET_PCT,
  urrPct,
} from './index'

/**
 * These are the same worked examples pinned in the server's AdequacyTest.
 *
 * The two implementations exist because the tablet has to show a nurse a result
 * without waiting for a round trip, while the server's number is the one that is
 * stored. That only works if they agree, so both are held to the same arithmetic
 * here and in apps/api/tests/Feature/AdequacyTest.php.
 *
 *   pre-BUN 60, post-BUN 20, 4.0 h, 3.0 L removed, 70 kg post weight
 *   R    = 20/60           = 0.33333
 *   Kt/V = -ln(0.33333 - 0.008 x 4) + (4 - 3.5 x 0.33333) x (3.0/70)
 *        = 1.19955 + 0.12143  = 1.32
 *   URR  = (60-20)/60 x 100   = 66.7%
 */
describe('the Daugirdas worked example', () => {
  it('matches the server, to the digit', () => {
    expect(ktv({ preBun: 60, postBun: 20, hours: 4.0, ufLitres: 3.0, postWeightKg: 70 })).toBe(1.32)
    expect(urrPct(60, 20)).toBe(66.7)
  })
})

describe('published targets', () => {
  it('are the KDOQI 2015 figures', () => {
    expect(KTV_TARGET).toBe(1.2)
    expect(URR_TARGET_PCT).toBe(65)
  })
})

describe('ultrafiltration rate', () => {
  it('normalises to dry weight per hour', () => {
    expect(ufRateMlPerKgPerHour(3000, 60, 4)).toBe(12.5)
    expect(ufRateMlPerKgPerHour(3400, 60, 4)).toBe(14.2)
  })

  it('flags the Flythe threshold', () => {
    expect(UF_RATE_CONCERN_ML_KG_H).toBe(13)
    expect(ufRateIsOfConcern(12.5)).toBe(false)
    expect(ufRateIsOfConcern(14.2)).toBe(true)
  })
})

describe('interdialytic weight gain', () => {
  it('is the gain over the dry weight, to two places', () => {
    expect(idwgKg(60.4, 57.5)).toBe(2.9)
  })
})

describe('impossible inputs', () => {
  it('throw rather than returning a plausible number', () => {
    // Samples the wrong way round.
    expect(() => ktv({ preBun: 20, postBun: 60, hours: 4, ufLitres: 3, postWeightKg: 70 })).toThrow(RangeError)
    // A zero-length session.
    expect(() => ktv({ preBun: 60, postBun: 20, hours: 0, ufLitres: 3, postWeightKg: 70 })).toThrow(RangeError)
    // Dividing by a pre-BUN of zero.
    expect(() => urrPct(0, 0)).toThrow(RangeError)
    expect(() => ufRateMlPerKgPerHour(3000, 0, 4)).toThrow(RangeError)
  })
})
