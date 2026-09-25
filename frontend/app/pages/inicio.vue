<script setup lang="ts">
import type { Dashboard } from '../../shared/types'
import { dateLabel } from '~/utils/format'

useHead({ title: 'Inicio · JARVIS' })
const { user } = useAuth()
const { data, error, status, refresh } = await useFetch<{ data: Dashboard }>('/api/backend/dashboard')
const dashboard = computed(() => data.value?.data)
const statusNames = { draft: 'Borrador', in_review: 'En revisión' }
const metrics = [
  { key: 'draft', label: 'Borradores', description: 'Por preparar y enviar a revisión', icon: 'document' },
  { key: 'in_review', label: 'En revisión', description: 'Pendientes de decisión interna', icon: 'clock' },
  { key: 'approved', label: 'Aprobadas internamente', description: 'Con revisión interna aprobada', icon: 'check' },
  { key: 'expired', label: 'Vencidas', description: 'Con fecha de vigencia superada', icon: 'clock' },
] as const
</script>

<template>
  <div class="page-heading">
    <div><p class="eyebrow">TU ESPACIO DE TRABAJO</p><h1>Inicio<span>.</span></h1><p>Una vista de tus pendientes y de la revisión comercial interna.</p></div>
    <NuxtLink v-if="user?.role !== 'approver'" class="button primary" to="/"><AppIcon name="plus" />Nueva cotización</NuxtLink>
  </div>

  <div v-if="error" class="notice error" role="alert"><p>No pudimos cargar el tablero.</p><button class="button secondary" @click="refresh()">Reintentar</button></div>
  <div v-else-if="status === 'pending'" class="panel empty-lines" role="status">Cargando pendientes comerciales…</div>
  <template v-else-if="dashboard">
    <p class="dashboard-context">{{ dashboard.scope === 'own' ? 'Tus cotizaciones' : 'Cotizaciones de todo el equipo' }} · Al {{ dateLabel(dashboard.as_of) }} · {{ dashboard.counts.total }} {{ dashboard.counts.total === 1 ? 'versión guardada' : 'versiones guardadas' }}</p>
    <dl class="dashboard-metrics" aria-label="Resumen comercial">
      <div v-for="metric in metrics" :key="metric.key" class="panel dashboard-metric" :class="{ 'dashboard-expired': metric.key === 'expired' }">
        <dt><span>{{ metric.label }}</span><AppIcon :name="metric.icon" /></dt>
        <dd :data-testid="`dashboard-${metric.key}`">{{ dashboard.counts[metric.key] }}</dd>
        <p>{{ metric.description }}</p>
      </div>
    </dl>
    <p class="dashboard-note">La vigencia vencida puede coincidir con cualquier estado. Las cifras incluyen las revisiones guardadas; la aprobación es interna.</p>

    <section class="dashboard-pending" aria-labelledby="pending-heading">
      <div class="dashboard-section-heading"><div><h2 id="pending-heading">Pendientes recientes</h2><p>Últimos borradores y propuestas en revisión.</p></div><NuxtLink class="button secondary" to="/borradores">Ver borradores<AppIcon name="arrow" /></NuxtLink></div>
      <div v-if="!dashboard.pending_quotes.length" class="panel empty-lines">
        <span class="empty-icon"><AppIcon name="check" :size="32" /></span>
        <h2>No hay pendientes por ahora</h2>
        <p>{{ dashboard.counts.total ? 'Puedes consultar tus propuestas en Borradores.' : 'Las cotizaciones guardadas aparecerán aquí para continuar su revisión.' }}</p>
        <NuxtLink v-if="!dashboard.counts.total && user?.role !== 'approver'" class="button primary" to="/">Crear cotización<AppIcon name="arrow" /></NuxtLink>
      </div>
      <div v-else class="quote-list">
        <NuxtLink v-for="quote in dashboard.pending_quotes" :key="quote.id" :to="`/borradores/${quote.id}`" class="quote-list-card">
          <span class="quote-list-icon"><AppIcon name="document" :size="24" /></span>
          <div class="quote-list-copy">
            <div class="dashboard-badges"><span class="draft-badge">{{ statusNames[quote.status] }}</span><span v-if="quote.is_expired" class="draft-badge dashboard-expired-badge">Vigencia vencida</span></div>
            <h2>{{ quote.client_name }}</h2><p v-if="quote.quote_number">{{ quote.quote_number }} · {{ quote.version_label || `V${quote.revision_number}` }}</p><p>{{ quote.scope }}</p>
            <small>Revisión {{ quote.revision_number }} · Creada el {{ dateLabel(quote.created_at) }} · {{ quote.valid_until ? `Vigencia hasta ${dateLabel(quote.valid_until)}` : 'Vigencia no disponible' }}</small>
          </div>
          <AppIcon name="arrow" />
        </NuxtLink>
      </div>
    </section>
  </template>
</template>

<style scoped>
.dashboard-context{font-size:12px;color:var(--muted);line-height:1.7;margin-bottom:16px}
.dashboard-metrics{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px;margin:0}
.dashboard-metric{padding:22px 18px}
.dashboard-metric dt{display:flex;align-items:center;justify-content:space-between;gap:10px;min-height:34px;font-size:12px;font-weight:600}
.dashboard-metric dt svg{color:var(--teal)}
.dashboard-metric dd{margin:14px 0 7px;font:750 34px 'Manrope',var(--font);color:var(--teal);font-variant-numeric:tabular-nums}
.dashboard-metric p,.dashboard-note{font-size:11px;line-height:1.7;color:var(--muted)}
.dashboard-expired dd,.dashboard-expired dt svg{color:#9a6932}
.dashboard-note{margin-top:13px}
.dashboard-pending{margin-top:32px}
.dashboard-section-heading{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}
.dashboard-section-heading p{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.7}
.dashboard-badges{display:flex;gap:7px;flex-wrap:wrap}
.dashboard-expired-badge{background:#fff3e3;color:#91632f}
.quote-list{grid-template-columns:minmax(0,1fr)}
.quote-list-copy h2{overflow-wrap:anywhere}
.quote-list-copy small{line-height:1.8}
@media(max-width:1100px){.dashboard-metrics{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:640px){.dashboard-metrics{gap:10px}.dashboard-metric{padding:16px 13px}.dashboard-metric dt{font-size:11px;align-items:flex-start}.dashboard-metric dt svg{width:17px;height:17px}.dashboard-metric dd{font-size:29px}.dashboard-metric p{font-size:10px}.dashboard-section-heading{align-items:flex-start;flex-wrap:wrap}.dashboard-pending{margin-top:25px}.quote-list-card>svg{display:none}.quote-list-copy small{font-size:10px}.dashboard-context{font-size:11px}}
</style>
