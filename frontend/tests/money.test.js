import { describe, expect, it } from 'vitest'
import { formatSen, sumSen } from '@/lib/money'

describe('money', () => {
  it('formats whole sen as ringgit with thousands separators', () => {
    expect(formatSen(460200)).toBe('RM 4,602.00')
    expect(formatSen(460250)).toBe('RM 4,602.50')
    expect(formatSen(5)).toBe('RM 0.05')
    expect(formatSen(0)).toBe('RM 0.00')
    expect(formatSen(1234567890)).toBe('RM 12,345,678.90')
    expect(formatSen(-199)).toBe('-RM 1.99')
  })

  it('adds prices without floating point drift', () => {
    // 0.1 + 0.2 === 0.30000000000000004 in floating point; in sen it is exactly 30.
    expect(
      sumSen([
        { unitSen: 10, quantity: 1 },
        { unitSen: 20, quantity: 1 },
      ]),
    ).toBe(30)
    expect(
      sumSen([
        { unitSen: 69900, quantity: 2 },
        { unitSen: 12345, quantity: 3 },
      ]),
    ).toBe(176835)
  })
})
