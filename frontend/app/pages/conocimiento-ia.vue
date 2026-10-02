<script setup lang="ts">
import { families } from '#shared/admin'
import { money } from '~/utils/format'
import type { KnowledgeAcceptance, KnowledgePatch } from '../../shared/knowledge'
useHead({ title: 'Conocimiento IA · JARVIS' })
const { filters, list, metrics, detail, errors, success, busy, open, patch } = useAiKnowledge()
const statusLabel: Record<string, string> = { active: 'Activa', needs_review: 'Por revisar', excluded: 'Excluida' }
const sourceLabel: Record<string, string> = { approved_quote: 'Cotización aprobada', drive_import: 'Importación histórica' }
const reason = ref(''), edit = reactive({ requirement_text: '', scope: '', exclusions: '' }), search = ref('')
const m = computed(() => metrics.data.value?.data)
const pct = (rate: number | null | undefined) => rate == null ? 'Sin datos' : `${(rate * 100).toFixed(1).replace('.', ',')} %`
const acc = (a?: KnowledgeAcceptance) => a ? `${pct(a.rate)} (${a.kept_lines}/${a.proposed_lines} partidas, ${a.requests} solicitudes)` : '—'
const family = (key: string | null) => key ? (families as Record<string, string>)[key] ?? key : 'Sin familia'
const when = (v: string) => new Intl.DateTimeFormat('es-CO', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'America/Bogota' }).format(new Date(v))
watch(detail, d => { reason.value = ''; edit.requirement_text = d?.requirement_text ?? ''; edit.scope = d?.scope ?? ''; edit.exclusions = d?.exclusions ?? '' })
watch(() => [filters.status, filters.source, filters.family], () => { filters.page = 1 })
function applySearch() { filters.q = search.value.trim(); filters.page = 1 }
const reasonOk = computed(() => reason.value.trim().length >= 3 && reason.value.trim().length <= 500)
async function toggle() { if (detail.value && reasonOk.value && await patch(detail.value.id, { reason: reason.value.trim(), status: detail.value.status === 'excluded' ? 'active' : 'excluded' })) reason.value = '' }
async function saveText() {
  const d = detail.value; if (!d || !reasonOk.value) return
  const body: KnowledgePatch = { reason: reason.value.trim() }
  if (edit.requirement_text !== d.requirement_text) body.requirement_text = edit.requirement_text
  if (edit.scope !== (d.scope ?? '')) body.scope = edit.scope || null
  if (edit.exclusions !== (d.exclusions ?? '')) body.exclusions = edit.exclusions || null
  if (Object.keys(body).length === 1) { errors.value = ['No hay cambios de texto para guardar.']; return }
  if (await patch(d.id, body)) reason.value = ''
}
</script>
<template>
  <AdminShell title="Conocimiento IA" description="Revisa los precedentes que alimentan al asistente y excluye los que no deben usarse." :errors="errors" :success="success">
    <div v-if="list.error.value || metrics.error.value" class="admin-error" role="alert">No pudimos cargar el conocimiento. <button class="button secondary" @click="list.refresh(); metrics.refresh()">Reintentar</button></div>
    <section v-if="m" class="admin-grid admin-section" aria-label="Métricas">
      <article class="admin-card"><h2>Aceptación del asistente</h2><p class="admin-muted">General: {{ acc(m.acceptance) }}</p><p class="admin-muted">Con precedentes: {{ acc(m.acceptance_with_precedents) }}</p><p class="admin-muted">Sin precedentes: {{ acc(m.acceptance_without_precedents) }}</p></article>
      <article class="admin-card"><h2>Cobertura y uso</h2><p class="admin-muted">Solicitudes con precedentes: {{ pct(m.coverage.rate) }} ({{ m.coverage.with_precedents }}/{{ m.coverage.requests }})</p><p class="admin-muted">Entradas: {{ m.entries.total }} · Por revisar: {{ m.entries.needs_review }}</p><p class="admin-muted">Tokens: {{ m.tokens.input }} entrada · {{ m.tokens.output }} salida</p></article>
      <article class="admin-card"><h2>Por familia</h2><p v-for="b in m.by_family" :key="b.key" class="admin-muted">{{ family(b.key) }}: {{ b.total }} ({{ b.active }} activas, {{ b.needs_review }} por revisar, {{ b.excluded }} excluidas)</p></article>
      <article class="admin-card"><h2>Por origen</h2><p v-for="b in m.by_source" :key="b.key" class="admin-muted">{{ sourceLabel[b.key] ?? b.key }}: {{ b.total }} ({{ b.active }} activas, {{ b.needs_review }} por revisar, {{ b.excluded }} excluidas)<template v-if="b.freshest_at"> · última {{ when(b.freshest_at) }}</template></p></article>
    </section>
    <section class="admin-card admin-section" :aria-busy="list.pending.value">
      <h2>Entradas de conocimiento</h2>
      <form class="admin-form" @submit.prevent="applySearch">
        <label>Estado<select v-model="filters.status"><option value="">Todos</option><option v-for="(l, k) in statusLabel" :key="k" :value="k">{{ l }}</option></select></label>
        <label>Origen<select v-model="filters.source"><option value="">Todos</option><option v-for="(l, k) in sourceLabel" :key="k" :value="k">{{ l }}</option></select></label>
        <label>Familia<select v-model="filters.family"><option value="">Todas</option><option v-for="(l, k) in families" :key="k" :value="k">{{ l }}</option></select></label>
        <label>Buscar en el requerimiento<input v-model="search" maxlength="100"></label>
        <button class="button secondary">Buscar</button>
      </form>
      <p class="admin-muted">{{ list.data.value?.meta.total ?? 0 }} entradas</p>
      <p v-if="!list.data.value?.data.length && !list.pending.value" class="admin-muted">No hay entradas con estos filtros.</p>
      <div class="admin-list admin-section">
        <article v-for="e in list.data.value?.data" :key="e.id" class="admin-record">
          <h3>{{ statusLabel[e.status] }} · {{ family(e.family) }}</h3>
          <p>{{ e.requirement_text.slice(0, 200) }}{{ e.requirement_text.length > 200 ? '…' : '' }}</p>
          <p class="admin-muted">{{ sourceLabel[e.source] }} · {{ e.lines_count }} partidas · Confianza {{ e.trust }}<template v-if="e.issued"> · Emitida</template><template v-if="e.ai_assisted"> · Asistida por IA</template> · {{ when(e.captured_at) }}</p>
          <p v-if="e.scrub_flags.length" class="admin-muted">Banderas de depuración: {{ e.scrub_flags.join(', ') }}</p>
          <button class="button secondary" :aria-label="`Ver detalle de la entrada ${e.id}`" @click="open(e.id)">Ver detalle</button>
        </article>
      </div>
      <nav class="pagination" aria-label="Páginas de conocimiento"><button class="button secondary" :disabled="list.pending.value || filters.page <= 1" @click="filters.page--">Anterior</button><span class="admin-muted">{{ filters.page }} / {{ list.data.value?.meta.last_page || 1 }}</span><button class="button secondary" :disabled="list.pending.value || filters.page >= (list.data.value?.meta.last_page || 1)" @click="filters.page++">Siguiente</button></nav>
    </section>
    <section v-if="detail" class="admin-card admin-section" aria-label="Detalle de la entrada">
      <div class="admin-actions"><h2>Detalle · {{ statusLabel[detail.status] }}</h2><button class="button secondary" @click="detail = null">Cerrar</button></div>
      <p class="admin-muted">{{ sourceLabel[detail.source] }} · {{ family(detail.family) }} · Confianza {{ detail.trust }}<template v-if="detail.reviewed_at"> · Revisada {{ when(detail.reviewed_at) }}</template></p>
      <p v-if="detail.review_reason" class="admin-muted">Último motivo: {{ detail.review_reason }}</p>
      <p v-if="detail.scrub_flags.length" class="admin-muted">Banderas de depuración: {{ detail.scrub_flags.join(', ') }}</p>
      <div v-if="detail.lines?.length" class="admin-table-wrap"><table class="admin-table"><thead><tr><th>SKU</th><th>Descripción</th><th>Unidad</th><th>Cantidad</th><th>Familia</th><th>Precio de referencia</th></tr></thead><tbody><tr v-for="l in detail.lines" :key="l.sku + l.description"><td>{{ l.sku }}</td><td>{{ l.description }}</td><td>{{ l.unit }}</td><td>{{ l.quantity }}</td><td>{{ family(l.family) }}</td><td>{{ l.reference_price === null ? '—' : money(l.reference_price) }} <span class="admin-muted">{{ l.currency }}</span></td></tr></tbody></table></div>
      <form class="admin-form admin-section" @submit.prevent="saveText">
        <label>Requerimiento<textarea v-model="edit.requirement_text" required maxlength="4000" /></label>
        <label>Alcance<textarea v-model="edit.scope" maxlength="4000" /></label>
        <label>Exclusiones<textarea v-model="edit.exclusions" maxlength="4000" /></label>
        <label>Motivo del cambio (obligatorio, 3 a 500 caracteres)<textarea v-model="reason" minlength="3" maxlength="500" /></label>
        <p class="admin-muted">El texto se vuelve a depurar al guardar. No incluyas nombres de clientes ni datos personales.</p>
        <div class="admin-actions">
          <button class="button primary" :disabled="busy || !reasonOk">Guardar texto</button>
          <button type="button" class="button secondary" :disabled="busy || !reasonOk" @click="toggle">{{ detail.status === 'excluded' ? 'Activar entrada' : 'Excluir entrada' }}</button>
        </div>
      </form>
    </section>
  </AdminShell>
</template>
