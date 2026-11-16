export type Role = 'super_admin' | 'institute_admin' | 'teacher' | 'student' | 'parent'

export interface Institute {
  id: number
  name: string
  slug: string
  email?: string
  phone?: string
  address?: string
  city?: string
  plan?: string
  is_active?: boolean
}

export interface User {
  id: number
  name: string
  email: string
  phone?: string | null
  role: Role
  institute_id: number | null
  institute?: Institute | null
  student_id?: number | null
  teacher_id?: number | null
}

export interface Paginated<T> {
  data: T[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export type FieldType =
  | 'text' | 'email' | 'password' | 'number' | 'date' | 'time' | 'datetime-local' | 'month'
  | 'textarea' | 'select' | 'multiselect' | 'search' | 'checkbox' | 'file' | 'url' | 'tel'

export interface SelectOption { value: string | number; label: string }

export interface FieldDef {
  key: string
  label: string
  type?: FieldType
  required?: boolean
  options?: SelectOption[]
  /** Load options from an endpoint; value from `valueKey`, label from `labelKey` (default id/name) */
  optionsEndpoint?: string
  valueKey?: string
  labelKey?: string | ((row: any) => string)
  placeholder?: string
  /** type "search": endpoint queried as you type (supports ?search= and ?ids=) */
  searchEndpoint?: string
  /** type "search": allow picking several values */
  multiple?: boolean
  /** Only show on create */
  createOnly?: boolean
  help?: string
}

export interface ColumnDef {
  key: string
  label: string
  format?: (value: any, row: any) => string
}
