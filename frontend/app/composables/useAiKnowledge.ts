import type { KnowledgeFilters, KnowledgeItem, KnowledgeList, KnowledgeMetrics, KnowledgePatch } from '../../shared/knowledge'
import { errorMessages } from '~/utils/format'

export function useAiKnowledge() {
  const filters = reactive<KnowledgeFilters>({ status: '', source: '', family: '', q: '', page: 1 })
  const query = computed(() => Object.fromEntries(Object.entries(filters).filter(([key, value]) => value !== '' && !(key === 'page' && value === 1))))
  const list = useFetch<KnowledgeList>('/api/backend/ai-knowledge', { query, watch: [query] })
  const metrics = useFetch<{ data: KnowledgeMetrics }>('/api/backend/ai-knowledge/metrics')
  const detail = ref<KnowledgeItem | null>(null)
  const errors = ref<string[]>([])
  const success = ref('')
  const busy = ref(false)
  async function open(id: string) {
    errors.value = []; success.value = ''
    try { detail.value = (await $fetch<{ data: KnowledgeItem }>(`/api/backend/ai-knowledge/${id}`)).data } catch (error) { errors.value = errorMessages(error) }
  }
  async function patch(id: string, body: KnowledgePatch): Promise<boolean> {
    if (busy.value) return false
    busy.value = true; errors.value = []; success.value = ''
    try {
      detail.value = (await $fetch<{ data: KnowledgeItem }>(`/api/backend/ai-knowledge/${id}`, { method: 'PATCH', body })).data
      success.value = 'Cambio guardado.'
      await Promise.all([list.refresh(), metrics.refresh()])
      return true
    } catch (error) { errors.value = errorMessages(error); return false } finally { busy.value = false }
  }
  return { filters, list, metrics, detail, errors, success, busy, open, patch }
}
