/**
 * Display helpers for billing.
 *
 * Money arrives from the server as a DECIMAL(12,2) string -- "6350.00" -- and is
 * formatted here as a string. It is never parsed into a number: the server did
 * the arithmetic in bcmath, and a float on the way to the screen is how a
 * displayed total ends up a centavo away from the stored one.
 */

const SYMBOL: Record<string, string> = {
  PHP: '₱',
}

/** "6350.00" -> "₱6,350.00"; "-500.00" -> "−₱500.00". Anything unexpected is shown verbatim, not guessed at. */
export function money(value: string | null | undefined, currency = 'PHP'): string {
  if (value === null || value === undefined || value === '') return '—'

  const match = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(value)
  const whole = match?.[2]

  if (match === null || whole === undefined) return value

  const sign = match[1] ?? ''
  const fraction = match[3] ?? ''
  const grouped = whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')
  const prefix = SYMBOL[currency] ?? `${currency} `

  return `${sign === '-' ? '−' : ''}${prefix}${grouped}.${fraction.padEnd(2, '0')}`
}

/**
 * What an officer may type as an amount: digits, optionally two decimals.
 * Mirrors the server's `decimal:0,2`; the server still decides.
 */
export function isAmount(value: string): boolean {
  return /^\d+(\.\d{1,2})?$/.test(value.trim())
}

/** An ISO timestamp with its offset, shown in the browser's own zone. */
export function stamp(iso: string | null): string {
  if (iso === null) return '—'

  const at = new Date(iso)

  if (Number.isNaN(at.getTime())) return '—'

  const pad = (n: number) => String(n).padStart(2, '0')

  return `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())} ${pad(at.getHours())}:${pad(at.getMinutes())}`
}

/**
 * An instant as a time of day on the unit's clock.
 *
 * The water gate counts the unit's day, so the screen that feeds it shows the
 * unit's time -- a desk PC set to another zone would otherwise show a 05:30
 * check at some other hour, on what looks like another day.
 */
export function timeIn(iso: string | null, timeZone: string): string {
  if (iso === null) return '—'

  const at = new Date(iso)

  if (Number.isNaN(at.getTime())) return '—'

  try {
    return new Intl.DateTimeFormat('en-GB', { timeZone, hour: '2-digit', minute: '2-digit', hour12: false }).format(at)
  } catch {
    // An unknown zone name: fall back to the device's clock rather than show nothing.
    return stamp(iso).slice(11)
  }
}

/** Today in the browser's zone, as YYYY-MM-DD -- what a date input wants. */
export function today(): string {
  return isoDate(new Date())
}

export function daysAgo(days: number): string {
  const at = new Date()
  at.setDate(at.getDate() - days)

  return isoDate(at)
}

function isoDate(at: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')

  return `${at.getFullYear()}-${pad(at.getMonth() + 1)}-${pad(at.getDate())}`
}
