<script setup lang="ts">
import type { PaginatedQuotes } from '../../../shared/types'
import { money, dateLabel } from '~/utils/format'
useHead({ title: 'Borradores · JARVIS' })
const page = ref(1)
const { user } = useAuth()
const statusNames: Record<string, string> = { draft: 'Borrador', in_review: 'En revisión', approved: 'Aprobada internamente' }
const { data, error, status, refresh } = await useFetch<PaginatedQuotes>('/api/backend/quotes', { query: { page } })
</script>
<template>
  <div class="page-heading"><div><p class="eyebrow">TU ESPACIO DE TRABAJO</p><h1>Borradores<span>.</span></h1><p>Tus propuestas guardadas, listas para volver a consultar.</p></div><NuxtLink v-if="user?.role !== 'approver'" class="button primary" to="/"><AppIcon name="plus" />Nueva cotización</NuxtLink></div>
  <div v-if="error" class="notice error" role="alert"><p>No pudimos cargar los borradores.</p><button class="button secondary" @click="refresh()">Reintentar</button></div>
  <div v-else-if="status === 'pending'" class="panel empty-lines" role="status">Cargando borradores…</div>
  <div v-else-if="!data?.data.length" class="panel empty-lines"><span class="empty-icon"><AppIcon name="document" :size="32" /></span><h2>Tu primera propuesta está por llegar</h2><p>Guarda una cotización para encontrarla aquí.</p><NuxtLink v-if="user?.role !== 'approver'" to="/" class="button primary">Crear cotización<AppIcon name="arrow" /></NuxtLink></div>
  <template v-else><div class="list-caption"><span>{{ data.total }} {{ data.total === 1 ? 'borrador guardado' : 'borradores guardados' }}</span><span>Página {{ data.current_page }} de {{ data.last_page }}</span></div><div class="quote-list"><NuxtLink v-for="quote in data.data" :key="quote.id" :to="`/borradores/${quote.id}`" class="quote-list-card"><span class="quote-list-icon"><AppIcon name="document" :size="24" /></span><div class="quote-list-copy"><span class="draft-badge">{{ statusNames[quote.status] || quote.status }}</span><h2>{{ quote.client_name }}</h2><p v-if="quote.quote_number">{{ quote.quote_number }} · {{ quote.version_label }}</p><p>{{ quote.scope }}</p><small>Creado el {{ dateLabel(quote.created_at) }}</small></div><div class="quote-list-amount"><strong>{{ money(quote.total) }}</strong><span>COP</span></div><AppIcon name="arrow" /></NuxtLink></div><div class="pagination"><button class="button secondary" :disabled="page <= 1" @click="page--">Anterior</button><button class="button secondary" :disabled="page >= data.last_page" @click="page++">Siguiente</button></div></template>
</template>
