<script setup lang="ts">
import { historyStatuses, safeHistoryUrl, type HistoryDetail } from '#shared/history'
import { errorMessages } from '~/utils/format'
const route = useRoute()
const endpoint = computed(() => `/api/backend/admin/history/${encodeURIComponent(String(route.params.id))}`)
const { data, error, pending, refresh } = await useFetch<HistoryDetail>(endpoint)
const { data: clients, error: clientsError, refresh: refreshClients } = await useFetch<{ data: { id: string; name: string; nit: string | null }[] }>('/api/backend/clients')
const selected = ref(''), reason = ref(''), busy = ref(false), errors = ref<string[]>([]), success = ref('')
const sourceUrl = computed(() => safeHistoryUrl(data.value?.data.source_url))
const linkedClient = computed(() => clients.value?.data.find(client => client.id === data.value?.data.linked_client_id))
useHead({ title: computed(() => `${data.value?.data.title || 'Histórico'} · JARVIS`) })
async function review(decision: 'approved' | 'rejected') {
  if (busy.value || data.value?.data.status !== 'pending') return
  errors.value = []; success.value = ''
  if (!reason.value.trim()) { errors.value = ['Escribe el motivo de la decisión.']; return }
  if (decision === 'approved' && !selected.value) { errors.value = ['Selecciona explícitamente un cliente existente.']; return }
  busy.value = true
  try {
    await $fetch(`${endpoint.value}/review`, { method: 'POST', body: { decision, client_id: decision === 'approved' ? selected.value : null, reason: reason.value.trim() } })
    success.value = decision === 'approved' ? 'Referencia histórica aprobada.' : 'Referencia histórica rechazada.'
    await refresh()
  } catch (failure) { errors.value = errorMessages(failure); await refresh() }
  finally { busy.value = false }
}
</script>
<template>
  <AdminShell :title="data?.data.title || 'Detalle histórico'" description="Contrasta el contenido y decide manualmente si corresponde a un cliente existente." :errors="errors" :success="success">
    <p><NuxtLink to="/historicos">Volver a históricos</NuxtLink></p>
    <p class="admin-error admin-section">Histórico: no es precio vigente. Aprobar esta referencia no crea precios ni cotizaciones.</p>
    <div v-if="error" class="admin-error" role="alert">No pudimos cargar este histórico. <button class="button secondary" @click="refresh()">Reintentar</button></div>
    <div v-if="data && !error && !pending" class="admin-grid" :aria-busy="pending">
      <section class="admin-card"><h2>Documento de origen</h2><p><strong>{{ historyStatuses[data.data.status] }}</strong></p><dl class="history-metadata"><dt>Identificador de origen</dt><dd>{{ data.data.source_id }}</dd><dt>Cliente declarado</dt><dd>{{ data.data.client_name || 'No informado' }}</dd><dt>NIT declarado</dt><dd>{{ data.data.client_nit || 'No informado' }}</dd><dt>Fecha declarada</dt><dd>{{ data.data.issued_on || 'No informada' }}</dd><dt>Familia</dt><dd>{{ data.data.family || 'No informada' }}</dd></dl><p v-if="sourceUrl && !data.data.sources?.length"><a :href="sourceUrl" target="_blank" rel="noopener noreferrer">Abrir documento de origen</a></p><p v-else-if="data.data.source_url && !data.data.sources?.length" class="admin-muted">Enlace no habilitado: {{ data.data.source_url }}</p><section v-if="data.data.sources?.length" class="admin-section"><h3>Referencias originales</h3><p class="admin-muted">Se conservan los orígenes que contienen el mismo texto histórico.</p><ul class="history-sources"><li v-for="source in data.data.sources" :key="source.source_id"><strong>{{ source.title }}</strong><p class="admin-muted">Origen: {{ source.source_id }}</p><p v-if="safeHistoryUrl(source.source_url)"><a :href="safeHistoryUrl(source.source_url)" target="_blank" rel="noopener noreferrer">Abrir origen {{ source.source_id }}</a></p><p v-else-if="source.source_url" class="admin-muted">Enlace no habilitado: {{ source.source_url }}</p><p class="admin-muted">Registrado: {{ source.created_at }}</p></li></ul></section><h3 class="admin-section">Texto fuente</h3><pre class="history-source" tabindex="0" aria-label="Texto fuente">{{ data.data.source_text }}</pre></section>
      <section class="admin-card">
        <template v-if="data.data.status === 'pending'"><h2>Revisión manual</h2><p class="admin-muted">Las coincidencias exactas orientan la revisión. Ningún cliente se vincula automáticamente.</p><ul v-if="data.candidates.length"><li v-for="candidate in data.candidates" :key="candidate.id">{{ candidate.name }} · {{ candidate.nit || 'Sin NIT' }} — coincide {{ candidate.match === 'nit' ? 'NIT' : 'nombre' }}</li></ul><p v-else class="admin-muted">No hay coincidencias exactas. Puedes elegir un cliente del directorio tras verificar la información, o rechazar la referencia.</p>
          <div v-if="clientsError" class="admin-error" role="alert">No pudimos cargar el directorio. <button class="button secondary" @click="refreshClients()">Reintentar directorio</button></div>
          <form class="admin-form admin-section" @submit.prevent="review('approved')"><label>Cliente vinculado<select v-model="selected" :disabled="busy || !!clientsError"><option value="">Seleccionar cliente…</option><option v-for="client in clients?.data" :key="client.id" :value="client.id">{{ client.name }} · {{ client.nit || 'Sin NIT' }}</option></select></label><label>Motivo de la decisión<textarea v-model="reason" required maxlength="2000" rows="4" :disabled="busy" /></label><p class="admin-muted">La decisión quedará registrada y no se puede sobrescribir desde esta bandeja.</p><div class="admin-actions"><button class="button primary" :disabled="busy || !selected || !!clientsError">Aprobar referencia</button><button class="button secondary" type="button" :disabled="busy" @click="review('rejected')">Rechazar referencia</button></div></form>
        </template>
        <template v-else><h2>Revisión completada</h2><p>Estado: {{ historyStatuses[data.data.status] }}</p><p v-if="data.data.linked_client_id">Cliente vinculado: {{ linkedClient?.name || data.data.linked_client_id }}</p><p class="admin-muted">{{ data.data.reviewed_at }}</p><h3 class="admin-section">Motivo de la decisión</h3><p class="history-reason">{{ data.data.review_reason }}</p><p class="admin-muted admin-section">Este registro ya fue revisado. Su decisión se conserva sin cambios.</p></template>
      </section>
    </div>
  </AdminShell>
</template>
<style scoped>
.history-sources{padding-left:20px;display:grid;gap:16px;overflow-wrap:anywhere}.history-metadata{display:grid;gap:6px;margin-top:20px;overflow-wrap:anywhere}.history-metadata dt{font-weight:600;margin-top:8px}.history-metadata dd{margin:0;color:var(--muted)}.history-source{max-height:520px;overflow:auto;padding:16px;background:#f6f8fa;border-radius:8px;line-height:1.65;white-space:pre-wrap;overflow-wrap:anywhere}.history-reason{white-space:pre-wrap;overflow-wrap:anywhere}
</style>
