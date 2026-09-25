<script setup lang="ts">
import { families, clauseTypes } from '#shared/admin'
import type { AdminClause, AdminClauseDetail } from '../../shared/types'
import { errorMessages, dateLabel } from '~/utils/format'
useHead({ title: 'Cláusulas · JARVIS' })

const familyFilter = ref('')
const typeFilter = ref('')
const includeInactive = ref(false)
const { data, error, refresh, pending: listPending } = await useFetch<{ data: AdminClause[] }>('/api/backend/admin/clauses', {
  query: computed(() => ({ family: familyFilter.value || undefined, type: typeFilter.value || undefined, include_inactive: includeInactive.value ? 1 : undefined })),
})

const errors = ref<string[]>([])
const success = ref('')
const pending = ref(false)

const created = reactive({ family: '', type: '', title: '', body: '', is_default: false, reason: '' })
async function create() {
  if (pending.value) return
  pending.value = true; errors.value = []; success.value = ''
  try {
    await $fetch('/api/backend/admin/clauses', { method: 'POST', body: created })
    Object.assign(created, { family: '', type: '', title: '', body: '', is_default: false, reason: '' })
    success.value = 'Cláusula creada.'
    await refresh()
  } catch (e) { errors.value = errorMessages(e) }
  finally { pending.value = false }
}

const selected = ref('')
const detail = ref<AdminClauseDetail | null>(null)
const detailError = ref('')
async function loadDetail() {
  detail.value = null
  detailError.value = ''
  if (!selected.value) return
  try { detail.value = (await $fetch<{ data: AdminClauseDetail }>(`/api/backend/admin/clauses/${selected.value}`)).data }
  catch { detailError.value = 'No pudimos cargar el historial de esta cláusula.' }
}
watch(selected, loadDetail)

const newVersionBody = ref('')
const versionReason = ref('')
watch(detail, current => { newVersionBody.value = current?.current_version.body ?? '' })
async function publishVersion() {
  if (pending.value || !selected.value) return
  pending.value = true; errors.value = []; success.value = ''
  try {
    await $fetch(`/api/backend/admin/clauses/${selected.value}/versions`, { method: 'POST', body: { body: newVersionBody.value, reason: versionReason.value } })
    versionReason.value = ''
    success.value = 'Nueva versión publicada.'
    await refresh(); await loadDetail()
  } catch (e) { errors.value = errorMessages(e) }
  finally { pending.value = false }
}

const patchReason = ref('')
async function patch(changes: { is_default?: boolean; active?: boolean }) {
  if (pending.value || !selected.value) return
  pending.value = true; errors.value = []; success.value = ''
  try {
    await $fetch(`/api/backend/admin/clauses/${selected.value}`, { method: 'PATCH', body: { ...changes, reason: patchReason.value || 'Actualización de configuración de cláusula' } })
    success.value = 'Cláusula actualizada.'
    patchReason.value = ''
    await refresh(); await loadDetail()
  } catch (e) { errors.value = errorMessages(e) }
  finally { pending.value = false }
}
</script>
<template>
  <AdminShell title="Cláusulas" description="Administra el texto de alcance, pago, garantía, vigencia y observaciones por familia." :errors="errors" :success="success">
    <div v-if="error" class="admin-error" role="alert">No pudimos cargar las cláusulas. <button class="button secondary" @click="refresh()">Reintentar</button></div>
    <div class="admin-grid">
      <section class="admin-card">
        <h2>Nueva cláusula</h2>
        <form class="admin-form" @submit.prevent="create">
          <label>Familia<select v-model="created.family" required><option value="">Seleccionar…</option><option v-for="(label, key) in families" :key="key" :value="key">{{ label }}</option></select></label>
          <label>Tipo<select v-model="created.type" required><option value="">Seleccionar…</option><option v-for="(label, key) in clauseTypes" :key="key" :value="key">{{ label }}</option></select></label>
          <label>Título<input v-model="created.title" required minlength="3" maxlength="120"></label>
          <label>Texto<textarea v-model="created.body" required rows="5" maxlength="5000" placeholder="Usa {vigencia_dias} solo en cláusulas de vigencia." /></label>
          <label class="admin-check"><input v-model="created.is_default" type="checkbox">Predeterminada de la familia y tipo</label>
          <label>Motivo<textarea v-model="created.reason" required minlength="5" maxlength="1000" /></label>
          <button class="button primary" :disabled="pending">Crear cláusula</button>
        </form>
      </section>
      <section class="admin-card">
        <h2>Filtrar</h2>
        <div class="admin-form">
          <label>Familia<select v-model="familyFilter"><option value="">Todas</option><option v-for="(label, key) in families" :key="key" :value="key">{{ label }}</option></select></label>
          <label>Tipo<select v-model="typeFilter"><option value="">Todos</option><option v-for="(label, key) in clauseTypes" :key="key" :value="key">{{ label }}</option></select></label>
          <label class="admin-check"><input v-model="includeInactive" type="checkbox">Incluir inactivas</label>
        </div>
        <p v-if="!listPending && !data?.data.length" class="admin-muted admin-section">No hay cláusulas con este filtro.</p>
        <div class="admin-list admin-section">
          <article v-for="clause in data?.data" :key="clause.id" class="admin-record">
            <h3>{{ clause.title }} <span v-if="clause.current_version.origin === 'initial_draft'" class="draft-badge">Redacción inicial propuesta — pendiente de validación de Systek</span></h3>
            <p class="admin-muted">{{ families[clause.family as keyof typeof families] || clause.family }} · {{ clauseTypes[clause.type as keyof typeof clauseTypes] || clause.type }} · v{{ clause.current_version.version }} · {{ clause.active ? 'Activa' : 'Inactiva' }} <template v-if="clause.is_default"> · Predeterminada</template></p>
            <button class="button secondary" :disabled="pending" @click="selected = clause.id">Ver historial y publicar</button>
          </article>
        </div>
      </section>
    </div>
    <section v-if="selected" class="admin-card admin-section">
      <div v-if="detailError" class="admin-error" role="alert">{{ detailError }}</div>
      <template v-if="detail">
        <div class="admin-actions"><h2>{{ detail.title }}</h2><span>{{ detail.active ? 'Activa' : 'Inactiva' }}</span><span v-if="detail.is_default">Predeterminada</span></div>
        <p class="admin-muted">{{ families[detail.family as keyof typeof families] || detail.family }} · {{ clauseTypes[detail.type as keyof typeof clauseTypes] || detail.type }}</p>
        <div class="admin-actions admin-section">
          <label>Motivo del cambio<textarea v-model="patchReason" minlength="5" maxlength="1000" /></label>
        </div>
        <div class="admin-actions">
          <button class="button secondary" :disabled="pending || detail.is_default || !detail.active" @click="patch({ is_default: true })">Marcar predeterminada</button>
          <button class="button secondary" :disabled="pending" @click="patch({ active: !detail.active })">{{ detail.active ? 'Desactivar' : 'Activar' }}</button>
        </div>
        <div class="admin-form admin-section">
          <h3>Publicar nueva versión</h3>
          <label>Texto vigente<textarea v-model="newVersionBody" required rows="5" maxlength="5000" /></label>
          <label>Motivo<textarea v-model="versionReason" required minlength="5" maxlength="1000" /></label>
          <button class="button primary" :disabled="pending" @click="publishVersion">Publicar versión</button>
        </div>
        <div class="admin-section">
          <h3>Historial de versiones</h3>
          <div class="admin-table-wrap"><table class="admin-table"><thead><tr><th>Versión</th><th>Estado</th><th>Origen</th><th>Publicado por</th><th>Fecha</th></tr></thead><tbody><tr v-for="version in detail.versions" :key="version.id"><td>{{ version.version }}</td><td>{{ version.status === 'current' ? 'Vigente' : 'Histórica' }}</td><td>{{ version.origin === 'initial_draft' ? 'Redacción inicial' : 'Admin' }}</td><td>{{ version.published_by_name || 'Sistema' }}</td><td>{{ dateLabel(version.created_at) }}</td></tr></tbody></table></div>
        </div>
      </template>
    </section>
  </AdminShell>
</template>
