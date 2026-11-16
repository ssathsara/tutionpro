import type { SelectOption } from '../types'

const opts = (xs: string[]): SelectOption[] => xs.map((x) => ({ value: x, label: x }))

export const GRADES = opts(['Grade 6', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10', 'Grade 11', 'O/L', 'A/L', 'Other'])
export const MEDIUMS = opts(['Sinhala', 'Tamil', 'English'])
export const MODES: SelectOption[] = [
  { value: 'physical', label: 'Physical' }, { value: 'online', label: 'Online' }, { value: 'hybrid', label: 'Hybrid' },
]
export const GENDERS: SelectOption[] = [
  { value: 'male', label: 'Male' }, { value: 'female', label: 'Female' }, { value: 'other', label: 'Other' },
]
export const DAY_OPTIONS: SelectOption[] = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']
  .map((d, i) => ({ value: i, label: d }))
export const PAYMENT_METHODS: SelectOption[] = [
  { value: 'cash', label: 'Cash' }, { value: 'bank_transfer', label: 'Bank transfer' }, { value: 'card', label: 'Card' },
]
export const PHONE_HELP = 'Sri Lankan mobile, e.g. 0771234567'

export const EP = {
  batches: '/batches?all=1',
  teachers: '/teachers?all=1',
  subjects: '/subjects',
  students: '/students?all=1',
}
export const studentLabel = (r: any) => `${r.name} (${r.admission_no})`

/** Search-as-you-type student picker (works with any number of students). */
export const studentSearchField = (key: string, label: string, opts: { required?: boolean; multiple?: boolean } = {}) => ({
  key, label, type: 'search' as const, searchEndpoint: '/students', labelKey: studentLabel, ...opts,
})
