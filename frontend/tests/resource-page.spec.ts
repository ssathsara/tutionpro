import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { api } from '../src/api/client'
import ResourcePage from '../src/components/ResourcePage.vue'

const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/', component: { template: '<div />' } }] })

function mountPage(props: Record<string, any> = {}) {
  return mount(ResourcePage, {
    props: {
      title: 'Subjects',
      endpoint: '/subjects',
      entityName: 'subject',
      columns: [{ key: 'name', label: 'Name' }, { key: 'fee', label: 'Fee', format: (v: number) => `Rs. ${v}` }],
      fields: [{ key: 'name', label: 'Name', required: true }],
      canCreate: true,
      canEdit: true,
      ...props,
    },
    global: { plugins: [router], stubs: { Teleport: true } },
  })
}

describe('ResourcePage', () => {
  afterEach(() => vi.restoreAllMocks())

  it('loads and renders paginated rows with formatters', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: { data: [{ id: 1, name: 'Physics', fee: 3000 }], total: 1, last_page: 1, current_page: 1 } })
    const w = mountPage()
    await flushPromises()
    expect(get).toHaveBeenCalledWith('/subjects', { params: { page: 1 } })
    expect(w.text()).toContain('Physics')
    expect(w.text()).toContain('Rs. 3000')
    expect(w.text()).toContain('1 record')
  })

  it('shows field validation errors returned by the API', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: [] })
    vi.spyOn(api, 'post').mockRejectedValue({ response: { status: 422, data: { message: 'Invalid', errors: { name: ['The name has already been taken.'] } } } })
    const w = mountPage()
    await flushPromises()
    expect(w.text()).toContain('No subjects found')

    await w.find('button.btn-primary').trigger('click')
    await w.find('input#f-name').setValue('Physics')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(w.text()).toContain('The name has already been taken.')
  })

  it('submits the form and reloads', async () => {
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: [] })
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { id: 5, name: 'Chemistry' } })
    const w = mountPage()
    await flushPromises()
    await w.find('button.btn-primary').trigger('click')
    await w.find('input#f-name').setValue('Chemistry')
    await w.find('form').trigger('submit')
    await flushPromises()
    expect(post).toHaveBeenCalledWith('/subjects', { name: 'Chemistry' })
    expect(get).toHaveBeenCalledTimes(2)
    expect(w.emitted('saved')).toBeTruthy()
  })
})
