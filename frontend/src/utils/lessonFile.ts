import { api, errorMessage } from '../api/client'
import { toast } from '../stores/toast'

/**
 * Opens a lesson attachment. Files are private, so they are fetched with the user's
 * login token and shown from a temporary in-browser (blob) URL that can't be shared.
 */
export async function openLessonFile(lesson: { id: number; file_name?: string | null }) {
  // Open the tab synchronously (inside the click) so popup blockers allow it.
  const tab = window.open('', '_blank')
  try {
    const res = await api.get(`/lessons/${lesson.id}/file`, { responseType: 'blob' })
    const url = URL.createObjectURL(res.data as Blob)
    const viewable = /^(application\/pdf|image\/)/.test((res.data as Blob).type)
    if (tab && viewable) {
      tab.location.href = url
    } else {
      tab?.close()
      const a = document.createElement('a')
      a.href = url
      a.download = lesson.file_name ?? 'attachment'
      document.body.appendChild(a)
      a.click()
      a.remove()
    }
    setTimeout(() => URL.revokeObjectURL(url), 60_000)
  } catch (e: any) {
    tab?.close()
    // Error bodies arrive as a Blob because of responseType; read the JSON message out of it.
    let message = errorMessage(e)
    try { message = JSON.parse(await e?.response?.data?.text?.())?.message ?? message } catch { /* keep default */ }
    toast(message, 'error')
  }
}
