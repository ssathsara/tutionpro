import { describe, expect, it } from 'vitest'
import { embedUrl, lkr, titleCase } from '../src/utils/format'

describe('format helpers', () => {
  it('formats Sri Lankan rupees', () => {
    expect(lkr(3500)).toBe('Rs. 3,500.00')
    expect(lkr('1250.5')).toBe('Rs. 1,250.50')
    expect(lkr(null)).toBe('Rs. 0.00')
  })
  it('title-cases snake case', () => {
    expect(titleCase('bank_transfer')).toBe('Bank Transfer')
    expect(titleCase(null)).toBe('')
  })
  it('builds video embed URLs', () => {
    expect(embedUrl('https://www.youtube.com/watch?v=WUvTyaaNkzM')).toBe('https://www.youtube.com/embed/WUvTyaaNkzM')
    expect(embedUrl('https://youtu.be/WUvTyaaNkzM')).toBe('https://www.youtube.com/embed/WUvTyaaNkzM')
    expect(embedUrl('https://vimeo.com/123456')).toBe('https://player.vimeo.com/video/123456')
    expect(embedUrl('https://example.com/video.mp4')).toBeNull()
  })
})
