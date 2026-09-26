import type { QuoteAssistProposal } from '../../shared/types'
import { errorMessages } from '~/utils/format'

export function useQuoteAssist() {
  const loading = ref(false)
  const errors = ref<string[]>([])
  const disabled = ref(false)
  async function propose(text: string, family?: string): Promise<QuoteAssistProposal | null> {
    if (loading.value) return null
    loading.value = true
    errors.value = []
    disabled.value = false
    try {
      const response = await $fetch<{ data: QuoteAssistProposal }>('/api/backend/quotes/assist', { method: 'POST', body: { text, ...(family ? { family } : {}) }, retry: 0 })
      return response.data
    } catch (error) {
      const status = (error as { statusCode?: number; status?: number }).statusCode ?? (error as { status?: number }).status
      if (status === 503) { disabled.value = true; errors.value = ['El asistente IA no está habilitado.'] }
      else errors.value = errorMessages(error)
      return null
    } finally { loading.value = false }
  }
  return { loading, errors, disabled, propose }
}
