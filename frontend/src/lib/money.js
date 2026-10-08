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
