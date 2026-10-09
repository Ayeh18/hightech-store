import { afterEach, describe, expect, it, vi } from 'vitest'
import { dayOnly, fromShopInput, humanizeAction, number, shopDate, shortDay, toShopInput, whenFull } from '@/lib/format'

afterEach(() => vi.useRealTimers())

describe('shop time (Malaysia, UTC+8)', () => {
  it('turns what an admin types into an exact moment, whatever their own computer is set to', () => {
    expect(fromShopInput('2026-10-20T10:00')).toBe('2026-10-20T02:00:00.000Z')
    expect(fromShopInput('2026-10-20T00:30')).toBe('2026-10-19T16:30:00.000Z')
    expect(fromShopInput('')).toBeNull()
  })

  it('shows a moment as Malaysian time in a date-time box', () => {
    expect(toShopInput('2026-10-20T02:00:00.000Z')).toBe('2026-10-20T10:00')
    expect(toShopInput('2026-10-19T16:00:00Z')).toBe('2026-10-20T00:00') // midnight is 00:00, never "24:00"
    expect(toShopInput(null)).toBe('')
  })

  it('round-trips', () => {
    for (const local of ['2026-01-01T00:00', '2026-10-20T10:30', '2026-12-31T23:59'])
      expect(toShopInput(fromShopInput(local))).toBe(local)
  })

  it("names the shop's date, which can be a day ahead of UTC", () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date('2026-10-19T17:00:00Z')) // 01:00 on the 20th in Kuala Lumpur
    expect(shopDate(0)).toBe('2026-10-20')
    expect(shopDate(1)).toBe('2026-10-19')
    expect(shopDate(29)).toBe('2026-09-21')
  })
})

describe('reading dates and numbers', () => {
  it('writes a shop day without letting a timezone move it', () => {
    expect(shortDay('2026-10-05')).toBe('5 Oct')
    expect(shortDay('2026-01-31')).toBe('31 Jan')
    expect(shortDay('2026-12-01')).toBe('1 Dec')
  })

  it('shows dates in Malaysian time, and a dash when there is none', () => {
    expect(whenFull('2026-10-20T02:00:00Z')).toContain('10:00')
    expect(dayOnly('2026-10-19T17:00:00Z')).toContain('20')
    expect(whenFull(null)).toBe('—')
    expect(dayOnly(undefined)).toBe('—')
  })

  it('says an audit action in words', () => {
    expect(humanizeAction('order.status_changed')).toBe('Order status changed')
    expect(humanizeAction('auth.login')).toBe('Auth login')
  })

  it('groups thousands', () => {
    expect(number(1234567)).toBe('1,234,567')
  })
})
