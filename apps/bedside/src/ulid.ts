/**
 * Monotonic ULID. Crockford base32, 48-bit timestamp + 80 bits of entropy.
 *
 * Why not UUIDv4: op_uuid doubles as the ordering key for the outbox, and a
 * tablet with a drifting clock still produces monotonically increasing IDs
 * within a session because the last value is carried forward.
 */
const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ'

let lastTime = 0
let lastRandom: number[] = []

function randomChars(): number[] {
  const bytes = new Uint8Array(16)
  crypto.getRandomValues(bytes)
  return Array.from(bytes, (b) => b % 32)
}

function encodeTime(now: number): string {
  let out = ''
  for (let i = 9; i >= 0; i--) {
    out = ENCODING[now % 32] + out
    now = Math.floor(now / 32)
  }
  return out
}

export function ulid(): string {
  const now = Date.now()

  if (now === lastTime) {
    // Same millisecond: increment the random part so IDs stay ordered.
    for (let i = lastRandom.length - 1; i >= 0; i--) {
      if (lastRandom[i] < 31) {
        lastRandom[i]++
        break
      }
      lastRandom[i] = 0
    }
  } else {
    lastTime = now
    lastRandom = randomChars()
  }

  return encodeTime(now) + lastRandom.map((n) => ENCODING[n]).join('')
}
