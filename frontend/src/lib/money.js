/*
 * Money is always whole sen (RM 1.00 = 100). These helpers never use floating-point division on the
 * amount, so "RM 4,602.00" can never become "RM 4,601.9999".
 */

/** 460250 -> "RM 4,602.50" */
export function formatSen(sen) {
  const negative = sen < 0
  const abs = Math.abs(sen)
  const ringgit = Math.trunc(abs / 100).toLocaleString('en-MY')
  const cents = String(abs % 100).padStart(2, '0')
  return `${negative ? '-' : ''}RM ${ringgit}.${cents}`
}

/** Sum of unit price (sen) x quantity for a list of { unitSen, quantity }. */
export const sumSen = (lines) => lines.reduce((total, line) => total + line.unitSen * line.quantity, 0)

/**
 * What an admin types into a price box -> whole sen. "1,234.50" -> 123450, "RM 12" -> 1200, "12.5" -> 1250.
 * Only digits with up to two decimals are accepted (no negatives, no "12.345"); anything else gives null so the form can
 * say so. Built from the digits as text, never through floating-point, so 19.99 can never become 1998.9999.
 */
export function parseRinggit(text) {
  const clean = String(text ?? '')
    .trim()
    .replace(/^RM\s*/i, '')
    .replaceAll(',', '')
  const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(clean)

  if (!match) return null

  return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0') || 0)
}

/** 123450 -> "1234.50": the plain form for an editable box (no "RM", no thousands separator). */
export const senToInput = (sen) => `${Math.trunc(sen / 100)}.${String(sen % 100).padStart(2, '0')}`

/**
 * What an admin types for a percentage -> basis points (1% = 100): "10" -> 1000, "12.5" -> 1250, "0.01" -> 1.
 * Up to two decimals, between 0.01 and 100; anything else gives null. Digits only, no floating-point.
 */
export function parsePercent(text) {
  const match = /^(\d+)(?:\.(\d{1,2}))?$/.exec(
    String(text ?? '')
      .trim()
      .replace(/%$/, '')
      .trim(),
  )
  if (!match) return null

  const bps = Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0') || 0)

  return bps >= 1 && bps <= 10000 ? bps : null
}

/** 1250 -> "12.5", 1000 -> "10": the plain form for an editable box. */
export const bpsToInput = (bps) =>
  `${Math.trunc(bps / 100)}${
    bps % 100
      ? `.${String(bps % 100)
          .padStart(2, '0')
          .replace(/0$/, '')}`
      : ''
  }`
