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

import { bpsToInput, parsePercent, parseRinggit, senToInput } from '@/lib/money'

describe('parseRinggit (what an admin types -> whole sen)', () => {
  it.each([
    ['12', 1200],
    ['12.5', 1250],
    ['12.50', 1250],
    ['1,234.50', 123450],
    ['RM 12', 1200],
    ['rm1,599.00', 159900],
    ['  7.05 ', 705],
    ['0.99', 99],
    ['0', 0],
    ['19.99', 1999],
    ['4602.00', 460200],
  ])('reads %j as %i sen', (text, sen) => {
    expect(parseRinggit(text)).toBe(sen)
  })

  it.each(['', '   ', 'abc', '-5', '12.345', '1.2.3', '1e5', 'RM', '12.', '.5', '$12', null, undefined])(
    'refuses %j',
    (text) => {
      expect(parseRinggit(text)).toBeNull()
    },
  )

  it('never goes through floating-point, so awkward amounts stay exact', () => {
    // 0.1 + 0.2 style trouble: these would be 1998.9999 / 4.35 * 100 = 434.99999 with floats.
    expect(parseRinggit('19.99')).toBe(1999)
    expect(parseRinggit('4.35')).toBe(435)
    expect(parseRinggit('1.15')).toBe(115)
    expect(parseRinggit('1000000.01')).toBe(100000001)
  })

  it('turns sen back into an editable box and round-trips', () => {
    expect(senToInput(123450)).toBe('1234.50')
    expect(senToInput(5)).toBe('0.05')
    expect(senToInput(100)).toBe('1.00')
    for (const sen of [0, 1, 99, 100, 101, 1999, 123456, 99999999]) expect(parseRinggit(senToInput(sen))).toBe(sen)
  })
})

describe('parsePercent (a percentage -> basis points)', () => {
  it.each([
    ['10', 1000],
    ['12.5', 1250],
    ['0.01', 1],
    ['100', 10000],
    ['10%', 1000],
    [' 7.25 % ', 725],
  ])('reads %j as %i basis points', (text, bps) => {
    expect(parsePercent(text)).toBe(bps)
  })

  it.each(['', '0', '0.00', '100.01', '101', '-5', '0.001', 'abc', '10.5.5', null])('refuses %j', (text) => {
    expect(parsePercent(text)).toBeNull()
  })

  it('shows basis points as a plain box value and round-trips', () => {
    expect(bpsToInput(1000)).toBe('10')
    expect(bpsToInput(1250)).toBe('12.5')
    expect(bpsToInput(1)).toBe('0.01')
    expect(bpsToInput(1005)).toBe('10.05')
    expect(bpsToInput(1010)).toBe('10.1')
    expect(bpsToInput(10000)).toBe('100')
    for (const bps of [1, 5, 10, 99, 100, 725, 1000, 1250, 9999, 10000]) expect(parsePercent(bpsToInput(bps))).toBe(bps)
  })
})
