import { ref } from 'vue'

export type ThemeMode = 'system' | 'light' | 'dark'

const KEY = 'tuitionpro_theme'
const ORDER: ThemeMode[] = ['system', 'light', 'dark']

function read(): ThemeMode {
  try {
    const v = localStorage.getItem(KEY)
    return v === 'light' || v === 'dark' ? v : 'system'
  } catch { return 'system' }
}

export const themeMode = ref<ThemeMode>(read())

/** Apply the mode to <html data-theme>. "system" removes the attribute so the OS preference wins. */
export function applyTheme(mode: ThemeMode = themeMode.value) {
  const root = document.documentElement
  if (mode === 'system') root.removeAttribute('data-theme')
  else root.setAttribute('data-theme', mode)
}

export function setTheme(mode: ThemeMode) {
  themeMode.value = mode
  try {
    if (mode === 'system') localStorage.removeItem(KEY)
    else localStorage.setItem(KEY, mode)
  } catch { /* storage unavailable: still applies for this visit */ }
  applyTheme(mode)
}

export function cycleTheme() {
  setTheme(ORDER[(ORDER.indexOf(themeMode.value) + 1) % ORDER.length])
}
