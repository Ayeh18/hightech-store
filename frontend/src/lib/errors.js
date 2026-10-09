import { parseApiError } from '@/lib/api'

/** The one sentence to show for a failed request: the server's first specific reason, else its general message. */
export function firstError(error) {
  const parsed = parseApiError(error)
  return Object.values(parsed.errors).flat()[0] ?? parsed.message
}
