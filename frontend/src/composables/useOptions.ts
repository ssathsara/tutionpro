import { ref } from 'vue'
import { api } from '../api/client'
import type { SelectOption } from '../types'

const cache = new Map<string, Promise<any[]>>()

/** Fetch a list endpoint once per session-page and map it to select options. */
export function fetchList(endpoint: string, fresh = false): Promise<any[]> {
  if (fresh || !cache.has(endpoint)) {
    cache.set(endpoint, api.get(endpoint).then((r) => (Array.isArray(r.data) ? r.data : r.data.data ?? [])).catch((e) => {
      cache.delete(endpoint)
      throw e
    }))
  }
  return cache.get(endpoint)!
}

export function clearOptionsCache() { cache.clear() }

export function toOptions(rows: any[], valueKey = 'id', labelKey: string | ((r: any) => string) = 'name'): SelectOption[] {
  return rows.map((r) => ({ value: r[valueKey], label: typeof labelKey === 'function' ? labelKey(r) : r[labelKey] }))
}

export function useOptions(endpoint: string, valueKey = 'id', labelKey: string | ((r: any) => string) = 'name') {
  const options = ref<SelectOption[]>([])
  const rows = ref<any[]>([])
  fetchList(endpoint).then((r) => { rows.value = r; options.value = toOptions(r, valueKey, labelKey) }).catch(() => {})
  return { options, rows }
}
