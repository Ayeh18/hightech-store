/** Dates and times as Malaysians read them, for tables and cards. `iso` is what the API sends. */
const make = (options) => {
  const formatter = new Intl.DateTimeFormat('en-MY', { timeZone: 'Asia/Kuala_Lumpur', ...options })
  return (iso) => (iso ? formatter.format(new Date(iso)) : '—')
}

export const whenFull = make({ dateStyle: 'medium', timeStyle: 'short' }) // 12 Oct 2026, 4:00 pm
export const dayOnly = make({ dateStyle: 'medium' }) // 12 Oct 2026

/** "2026-10-12" (a shop day, no time) -> "12 Oct". Parsed as text so the day cannot slip by a timezone. */
export function shortDay(date) {
  const [, month, day] = date.split('-').map(Number)
  return `${day} ${['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][month - 1]}`
}

/** 1234567 -> "1,234,567" */
export const number = (n) => Number(n).toLocaleString('en-MY')

/**
 * Date-time boxes work in the shop's own time (Malaysia is always UTC+8, with no summer time), whatever the admin's computer
 * is set to. `<input type="datetime-local">` gives "2026-10-20T10:00"; the API wants an exact moment.
 */
export const fromShopInput = (local) => (local ? new Date(`${local}:00+08:00`).toISOString() : null)

/** An ISO moment from the API -> "2026-10-20T10:00" in shop time, for a datetime-local box. */
export function toShopInput(iso) {
  if (!iso) return ''
  const parts = Object.fromEntries(
    new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Kuala_Lumpur',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
      hour: '2-digit',
      minute: '2-digit',
      hourCycle: 'h23',
    })
      .formatToParts(new Date(iso))
      .map((p) => [p.type, p.value]),
  )

  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`
}

/** Today's date in the shop's time, as "2026-10-20" (what a date box and the reports API use), `daysAgo` days back. */
export function shopDate(daysAgo = 0) {
  const now = new Date(Date.now() - daysAgo * 86_400_000)

  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Kuala_Lumpur' }).format(now)
}

/** What is wrong with a report period ("2026-10-01" to "2026-10-20"), or '' when it can be asked for. */
export function periodProblem(from, to) {
  if (!from || !to) return 'Choose both dates.'

  return from > to ? 'The start date must not be after the end date.' : ''
}

/** 33.3 -> "33.3%". The API sends null when there is nothing to take a percentage of: that is shown as a dash. */
export const percent = (value) => (value === null || value === undefined ? '—' : `${number(value)}%`)

/** "order.status_changed" -> "Order status changed" */
export const humanizeAction = (action) => {
  const words = action.replaceAll('.', ' ').replaceAll('_', ' ')

  return words.charAt(0).toUpperCase() + words.slice(1)
}
