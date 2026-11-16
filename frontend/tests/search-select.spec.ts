import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { api } from '../src/api/client'
import SearchSelect from '../src/components/SearchSelect.vue'

const label = (r: any) => `${r.name} (${r.admission_no})`

describe('SearchSelect (large-list student picker)', () => {
  afterEach(() => { vi.restoreAllMocks(); vi.useRealTimers() })

  it('searches the server as you type and selects a result', async () => {
    vi.useFakeTimers()
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [{ id: 4999, name: 'Student 4999', admission_no: 'L04999' }] } })
    const w = mount(SearchSelect, { props: { endpoint: '/students', labelKey: label, modelValue: null } })

    await w.find('input').trigger('focus')
    await w.find('input').setValue('4999')
    vi.advanceTimersByTime(300)
    await flushPromises()

    expect(get).toHaveBeenLastCalledWith('/students', { params: { search: '4999', per_page: 20 } })
    await w.find('li[role="option"]').trigger('mousedown')
    expect(w.emitted('update:modelValue')!.at(-1)).toEqual([4999])
    expect(w.text()).toContain('Student 4999 (L04999)')
  })

  it('loads labels for saved values by id (multiple)', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [{ id: 7, name: 'Kavya', admission_no: 'ST7' }, { id: 9, name: 'Arun', admission_no: 'ST9' }] } })
    const w = mount(SearchSelect, { props: { endpoint: '/students', labelKey: label, multiple: true, modelValue: [7, 9] } })
    await flushPromises()
    expect(get).toHaveBeenCalledWith('/students', { params: { ids: '7,9', per_page: 2 } })
    expect(w.findAll('.chip')).toHaveLength(2)

    await w.find('.chip button').trigger('click')
    expect(w.emitted('update:modelValue')!.at(-1)).toEqual([[9]])
  })
})
