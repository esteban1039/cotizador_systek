import type { LineSuggestionGroup } from '../../shared/types'
import { errorMessages } from '~/utils/format'

export function useLineSuggestions() {
  const loading = ref(false)
  const errors = ref<string[]>([])
  const groups = ref<LineSuggestionGroup[]>([])
  const searched = ref(false)
  let sequence = 0
  async function search(text: string, family?: string) {
    const q = text.trim().slice(0, 1000)
    if (q.length < 3) return
    const current = ++sequence
    loading.value = true
    errors.value = []
    try {
      const response = await $fetch<{ data: LineSuggestionGroup[] }>('/api/backend/quotes/line-suggestions', { query: { q, ...(family ? { family } : {}) }, retry: 0 })
      if (current !== sequence) return
      groups.value = response.data
      searched.value = true
    } catch (error) {
      if (current !== sequence) return
      groups.value = []
      const status = (error as { statusCode?: number; status?: number }).statusCode ?? (error as { status?: number }).status
      errors.value = status === 429 ? ['Demasiadas búsquedas seguidas. Espera un momento e inténtalo de nuevo.'] : errorMessages(error)
    } finally { if (current === sequence) loading.value = false }
  }
  return { loading, errors, groups, searched, search }
}
