import { describe, expect, it } from 'vitest'
import { et, normalizeLang } from '../src/i18n/exam'

describe('exam interface translations', () => {
  it('renders Sinhala strings with placeholders', () => {
    expect(et('si', 'question', { n: 3, t: 10 })).toBe('ප්‍රශ්නය 3 / 10')
    expect(et('si', 'submit')).toBe('යොමු කරන්න')
    expect(et('si', 'true')).toBe('සත්‍ය')
  })
  it('renders Tamil strings', () => {
    expect(et('ta', 'next')).toBe('அடுத்து')
    expect(et('ta', 'scoreOf', { s: 6, t: 9 })).toBe('6 / 9 புள்ளிகள்')
  })
  it('falls back to English for unknown or missing languages', () => {
    expect(normalizeLang(undefined)).toBe('en')
    expect(normalizeLang('fr')).toBe('en')
    expect(et(null, 'passMark', { p: 40 })).toBe('Pass mark 40%')
  })
})
