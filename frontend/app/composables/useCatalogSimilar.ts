import type { SimilarItem } from '../../shared/types'

// Sugerencias de ítems parecidos mientras se escribe una línea libre (debounce, mínimo 3 caracteres).
export function useCatalogSimilar(delay = 400) {
  const results = ref<SimilarItem[]>([])
  const loading = ref(false)
  const failed = ref(false)
  let timer: ReturnType<typeof setTimeout> | undefined
  let controller: AbortController | undefined
  let current = 0
  function clear() {
    current++
    clearTimeout(timer)
    controller?.abort()
    results.value = []
    loading.value = false
    failed.value = false
  }
  function search(text: string, family?: string) {
    const q = text.trim().slice(0, 120)
    clear()
    if (q.length < 3) return
    const id = current
    timer = setTimeout(async () => {
      controller = new AbortController()
      loading.value = true
      try {
        const response = await $fetch<{ data: SimilarItem[] }>('/api/backend/catalog/similar', { query: { q, ...(family ? { family } : {}) }, signal: controller.signal, retry: 0 })
        if (id === current) results.value = response.data
      } catch { if (id === current) failed.value = true }
      finally { if (id === current) loading.value = false }
    }, delay)
  }
  onBeforeUnmount(clear)
  return { results, loading, failed, search, clear }
}
