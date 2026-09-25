<script setup lang="ts">
import { historyStatuses, type HistoryInput, type HistoryPage } from '#shared/history'
import { errorMessages } from '~/utils/format'
useHead({ title: 'Históricos · JARVIS' })
const page = ref(1), q = ref(''), status = ref(''), search = ref('')
const { data, error, pending, refresh } = await useFetch<HistoryPage>('/api/backend/admin/history', { query: { page, q, status } })
const records = ref<HistoryInput[]>([]), filename = ref(''), errors = ref<string[]>([]), success = ref(''), importing = ref(false), reading = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
let selectionVersion = 0
async function selectFile(event: Event) {
  const version = ++selectionVersion
  records.value = []; filename.value = ''; errors.value = []; success.value = ''
  const file = (event.target as HTMLInputElement).files?.[0]
  if (!file) { reading.value = false; return }
  reading.value = true
  try {
    if (file.size > 1024 * 1024) throw new Error('El archivo debe pesar como máximo 1 MB.')
    const parsed: unknown = JSON.parse(await file.text())
    if (!parsed || typeof parsed !== 'object' || !('records' in parsed) || !Array.isArray(parsed.records) || !parsed.records.length || parsed.records.length > 20) throw new Error('Incluye entre 1 y 20 registros dentro de records.')
    for (const record of parsed.records) {
      if (!record || typeof record !== 'object' || typeof record.source_id !== 'string' || !record.source_id.trim() || typeof record.title !== 'string' || !record.title.trim() || typeof record.source_text !== 'string' || !record.source_text.trim() || record.source_text.length > 20000) throw new Error('Cada registro requiere source_id, title y source_text (hasta 20.000 caracteres).')
    }
    if (version !== selectionVersion) return
    records.value = parsed.records; filename.value = file.name
  } catch (failure) {
    if (version === selectionVersion) errors.value = [failure instanceof SyntaxError ? 'El archivo no contiene JSON válido.' : (failure as Error).message]
  } finally { if (version === selectionVersion) reading.value = false }
}
async function importRecords() {
  if (importing.value || reading.value || !records.value.length) return
  importing.value = true; errors.value = []; success.value = ''
  try {
    const result = await $fetch<{ imported: unknown[]; duplicates: unknown[] }>('/api/backend/admin/history/import', { method: 'POST', body: { records: records.value } })
    success.value = `${result.imported.length} registros importados. ${result.duplicates.length} duplicados omitidos.`
    records.value = []; filename.value = ''; if (fileInput.value) fileInput.value.value = ''; page.value = 1; await refresh()
  } catch (failure) { errors.value = errorMessages(failure) }
  finally { importing.value = false }
}
function filter() { page.value = 1; q.value = search.value.trim() }
function filterStatus() { page.value = 1 }
</script>
<template>
  <AdminShell title="Históricos" description="Importa documentos de referencia y revisa su relación con clientes existentes." :errors="errors" :success="success">
    <p class="admin-error">Histórico: no es precio vigente. La revisión no crea clientes, precios ni cotizaciones.</p>
    <section class="admin-card">
      <h2>Importar archivo JSON</h2>
      <p class="admin-muted">Hasta 20 registros por archivo, 1 MB y 20.000 caracteres de texto por registro. Los enlaces de origen opcionales solo admiten Google Drive o Google Docs por HTTPS. Seleccionar el archivo solo prepara una vista previa.</p>
      <p><a href="/historicos-ejemplo.json" download>Descargar ejemplo JSON ficticio</a></p>
      <div class="admin-form admin-section"><label>Archivo JSON<input ref="fileInput" type="file" accept=".json,application/json" :disabled="importing" @change="selectFile"></label></div>
      <p v-if="reading" role="status">Leyendo archivo…</p>
      <div v-if="records.length" class="admin-section" data-testid="history-preview">
        <h3>Vista previa: {{ records.length }} registros</h3><p class="admin-muted">{{ filename }}</p>
        <ol><li v-for="(record,index) in records" :key="index">{{ record.title }}</li></ol>
        <button class="button primary" :disabled="importing || reading" @click="importRecords">{{ importing ? 'Importando…' : 'Importar registros' }}</button>
      </div>
    </section>
    <section class="admin-card admin-section" :aria-busy="pending">
      <h2>Bandeja de revisión</h2>
      <form class="admin-form" @submit.prevent="filter"><label>Buscar históricos<input v-model="search" type="search" maxlength="200" placeholder="Título, origen o cliente"></label><label>Estado<select v-model="status" @change="filterStatus"><option value="">Todos</option><option v-for="(label,key) in historyStatuses" :key="key" :value="key">{{ label }}</option></select></label><button class="button secondary" :disabled="pending">Buscar</button></form>
      <div v-if="error" class="admin-error admin-section" role="alert">No pudimos cargar los históricos. <button class="button secondary" @click="refresh()">Reintentar</button></div>
      <p class="admin-muted admin-section">{{ !error && !pending ? (data?.total ?? 0) : '—' }} registros</p>
      <p v-if="!pending && !error && !data?.data.length" class="admin-muted">No hay históricos con estos filtros.</p>
      <div v-if="!error && !pending" class="admin-list admin-section"><article v-for="record in data?.data" :key="record.id" class="admin-record"><h3><NuxtLink :to="`/historicos/${record.id}`">{{ record.title }}</NuxtLink></h3><p class="admin-muted">{{ historyStatuses[record.status] }} · {{ record.client_name || 'Sin cliente declarado' }}</p><p class="admin-muted">Origen: {{ record.source_id }} · {{ record.issued_on || 'Sin fecha declarada' }}</p></article></div>
      <nav class="pagination" aria-label="Páginas de históricos"><button class="button secondary" :disabled="pending || page <= 1" @click="page--">Anterior</button><span>{{ page }} / {{ data?.last_page || 1 }}</span><button class="button secondary" :disabled="pending || page >= (data?.last_page || 1)" @click="page++">Siguiente</button></nav>
    </section>
  </AdminShell>
</template>
