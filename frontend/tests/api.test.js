import { describe, expect, it } from 'vitest'
import { parseApiError } from '@/lib/api'

describe('parseApiError', () => {
  it('reads Laravel validation errors', () => {
    const error = { response: { status: 422, data: { message: 'Invalid.', errors: { email: ['Taken'] } } } }
    expect(parseApiError(error)).toEqual({ message: 'Invalid.', errors: { email: ['Taken'] } })
  })

  it('hides server details on 5xx', () => {
    const parsed = parseApiError({ response: { status: 500, data: '<html>stack trace</html>' } })
    expect(parsed.message).toMatch(/server had a problem/i)
    expect(parsed.errors).toEqual({})
  })

  it('handles network failures', () => {
    expect(parseApiError(new Error('Network Error')).message).toMatch(/unable to reach/i)
  })
})
