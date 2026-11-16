import { reactive } from 'vue'

export interface Toast { id: number; message: string; type: 'success' | 'error' | 'info' }

export const toasts = reactive<Toast[]>([])
let seq = 0

export function toast(message: string, type: Toast['type'] = 'success', ms = 3500) {
  const id = ++seq
  toasts.push({ id, message, type })
  setTimeout(() => {
    const i = toasts.findIndex((t) => t.id === id)
    if (i >= 0) toasts.splice(i, 1)
  }, ms)
}
