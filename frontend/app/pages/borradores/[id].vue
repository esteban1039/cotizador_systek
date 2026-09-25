<script setup lang="ts">
import type { ReviewedQuote } from '../../../shared/types'
import { families, clauseTypes } from '#shared/admin'
import { money, cents, dateLabel, errorMessages } from '~/utils/format'
const route = useRoute()
const { user } = useAuth()
const reason = ref('')
const downloading = ref(false)
const downloadErrors = ref<string[]>([])
async function downloadDraft() {
  if (downloading.value || !quote.value) return
  downloading.value = true
  downloadErrors.value = []
  try {
    const blob = await $fetch<Blob>(`/api/backend/quotes/${quote.value.id}/pdf`, { responseType: 'blob', timeout: 50000, retry: 0 })
    if (!blob.type.startsWith('application/pdf')) throw new Error('Invalid PDF response')
    const objectUrl = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = objectUrl
    anchor.download = quote.value.quote_number ? `${quote.value.quote_number}-${quote.value.version_label}-borrador.pdf` : `quote-${quote.value.id}-v${quote.value.revision_number || 1}-borrador.pdf`
    document.body.appendChild(anchor)
    anchor.click()
    anchor.remove()
    setTimeout(() => URL.revokeObjectURL(objectUrl), 1000)
  } catch (failure: unknown) {
    const error = failure as { data?: Blob | unknown; statusCode?: number }
    if (error.data instanceof Blob) {
      try { error.data = JSON.parse(await error.data.text()) } catch { error.data = undefined }
    }
    downloadErrors.value = errorMessages(error)
  } finally { downloading.value = false }
}
const actionErrors = ref<string[]>([])
const pending = ref(false)
const statusNames: Record<string, string> = { draft: 'Borrador', in_review: 'En revisión', approved: 'Aprobada internamente' }
async function transition(action: 'submit' | 'approve' | 'return') {
  if (pending.value) return
  pending.value = true
  actionErrors.value = []
  try {
    await $fetch(`/api/backend/quotes/${route.params.id}/${action === 'submit' ? 'submit' : 'review'}`, {
      method: 'POST', retry: 0, body: { reason: reason.value, ...(action === 'submit' ? {} : { decision: action }) },
    })
    reason.value = ''
    await refresh()
  } catch (error) { actionErrors.value = errorMessages(error) }
  finally { pending.value = false }
}
const { data: response, error, refresh } = await useFetch<{ data: ReviewedQuote }>(`/api/backend/quotes/${route.params.id}`)
const quote = computed(() => response.value?.data)
useHead({ title: 'Detalle del borrador · JARVIS' })
</script>
<template>
  <NuxtLink class="back-link" to="/borradores"><AppIcon name="back" :size="16" />Todos los borradores</NuxtLink>
  <div v-if="error" class="notice error" role="alert"><h1>No pudimos abrir el borrador</h1><p>Comprueba que el enlace sea correcto y el cotizador esté disponible.</p><button class="button secondary" @click="refresh()">Reintentar</button></div>
  <template v-else-if="quote">
    <div class="page-heading"><div><p class="eyebrow">PROPUESTA GUARDADA</p><h1>Tu borrador está listo<span>.</span></h1><p v-if="quote.quote_number">{{ quote.quote_number }} · {{ quote.version_label }} · {{ families[quote.family as keyof typeof families] || quote.family }}</p><p v-else>Estos son los datos y valores que quedaron guardados.</p></div><span class="saved-badge"><AppIcon name="check" :size="16" />Guardado</span></div>
    <section class="panel draft-download" aria-label="PDF de borrador"><div class="revision-actions"><div><h2>PDF de borrador interno</h2><p class="muted">Borrador interno: no válido para envío al cliente.</p><p class="muted">La aprobación comercial interna no autoriza su emisión ni envío.</p></div><button type="button" class="button secondary" :disabled="downloading" @click="downloadDraft">{{ downloading ? 'Preparando PDF…' : 'Descargar PDF de borrador' }}</button></div><div v-if="downloadErrors.length" class="notice error" role="alert"><p v-for="message in downloadErrors" :key="message">{{ message }}</p><button class="button secondary" :disabled="downloading" @click="downloadDraft">Reintentar descarga</button></div></section>
    <section class="panel revision-navigation" aria-label="Historial de versiones"><div class="revision-actions"><h2>Versión {{ quote.revision_number || 1 }}</h2><NuxtLink v-if="quote.can_revise" :to="`/?revise=${quote.id}`" class="button secondary">Crear nueva revisión</NuxtLink></div><p class="muted">Cada versión conserva sus precios, condiciones y revisión comercial.</p><nav v-if="quote.revisions?.length" class="revision-links" aria-label="Versiones de la cotización"><NuxtLink v-for="version in quote.revisions" :key="version.id" :to="`/borradores/${version.id}`" :aria-current="version.id === quote.id ? 'page' : undefined">Versión {{ version.revision_number }} · {{ statusNames[version.status] || version.status }}</NuxtLink></nav></section>
    <div class="editor-layout">
      <div class="editor-fields"><section class="panel"><div class="document-topline"><span class="eyebrow">{{ quote.issuer?.legal_name || 'SYSTEK COMPANY' }}</span><span class="draft-badge">{{ statusNames[quote.status] || quote.status }} · No emitido</span></div><h2 class="quote-client-name">{{ quote.client_name || 'Cliente registrado' }}</h2><p class="muted">{{ quote.site_name || 'Sede registrada' }}</p><div class="document-meta"><span>Creado<strong>{{ dateLabel(quote.created_at) }}</strong></span><span>Vigencia hasta<strong>{{ dateLabel(quote.valid_until) }}</strong></span><span>Referencia interna<strong>{{ quote.quote_number || quote.id.slice(0, 8).toUpperCase() }} · {{ quote.version_label }}</strong></span></div><div class="document-section"><h3>Alcance</h3><p class="preserve-lines">{{ quote.scope }}</p></div><div class="document-section"><h3>Partidas</h3><div v-for="(line, index) in quote.lines" :key="index" class="saved-line"><div><strong>{{ line.description }}</strong><p>{{ line.quantity }} {{ line.unit }} × {{ cents(line.price_cents) }}</p><small>Descuento {{ line.discount_bps / 100 }} % · Impuesto {{ line.tax_bps / 100 }} %</small></div><strong>{{ money(line.amounts.total) }}</strong></div></div><div class="document-section"><h3>Exclusiones</h3><p class="preserve-lines">{{ quote.exclusions }}</p></div><div class="field-grid document-section"><div><h3>Forma de pago</h3><p class="preserve-lines">{{ quote.payment_terms }}</p></div><div><h3>Garantía</h3><p class="preserve-lines">{{ quote.warranty }}</p></div></div><div class="field-grid document-section"><div><h3>Vigencia</h3><p class="preserve-lines">{{ quote.validity_terms }}</p></div><div><h3>Observaciones</h3><p class="preserve-lines">{{ quote.observations || 'Sin observaciones.' }}</p></div></div><div v-if="quote.clauses?.length" class="document-section"><h3>Procedencia de cláusulas</h3><ul class="clause-provenance"><li v-for="clause in quote.clauses" :key="clause.type">{{ clauseTypes[clause.type as keyof typeof clauseTypes] || clause.type }}: «{{ clause.title }}» v{{ clause.version }}<span v-if="clause.modified"> · Modificada</span></li></ul></div></section></div>
      <aside class="summary-column"><div class="summary-panel"><span class="eyebrow">RESUMEN GUARDADO</span><h2>Total de la propuesta</h2><QuoteTotals :calculation="quote" /><div class="review-note"><AppIcon name="shield" /><p>La emisión y el envío permanecen deshabilitados en esta etapa.</p></div><section v-if="quote.can_submit || quote.can_review" class="review-controls">
  <h3>{{ quote.can_submit ? 'Solicitar revisión' : 'Revisión comercial' }}</h3>
  <div v-if="quote.approval_errors.length" class="notice error"><p v-for="message in quote.approval_errors" :key="message">{{ message }}</p></div>
  <div v-if="quote.review_flags.length" class="notice"><strong>Excepciones que debes revisar</strong><p v-for="flag in quote.review_flags" :key="flag">{{ flag }}</p></div>
  <form @submit.prevent="transition(quote.can_submit ? 'submit' : 'approve')">
    <label>Motivo de la decisión<textarea v-model="reason" required minlength="5" maxlength="2000" rows="3" placeholder="Describe la solicitud, las correcciones o la justificación de aprobación…" /></label>
    <div v-if="actionErrors.length" role="alert" class="notice error"><p v-for="message in actionErrors" :key="message">{{ message }}</p></div>
    <button v-if="quote.can_submit" class="button primary full-width" :disabled="pending || !!quote.approval_errors.length">{{ pending ? 'Procesando…' : 'Enviar a revisión' }}</button>
    <template v-else><button class="button primary full-width" :disabled="pending || !!quote.approval_errors.length">Aprobar revisión comercial</button><button type="button" class="button secondary full-width" :disabled="pending || reason.trim().length < 5" @click="transition('return')">Devolver con observaciones</button></template>
  </form>
</section>
<section v-if="quote.reviews.length" class="review-history"><h3>Historial de revisión</h3><article v-for="(review, index) in quote.reviews" :key="index"><strong>{{ review.user_name }} · {{ review.decision === 'submitted' ? 'Solicitó revisión' : review.decision === 'approve' ? 'Aprobó' : 'Devolvió' }}</strong><p>{{ review.reason }}</p><small>{{ dateLabel(review.created_at) }}</small></article></section>
<div class="pending-checks"><h3>Pendiente antes de emitir</h3><p v-for="blocker in quote.blockers" :key="blocker"><span>○</span>{{ blocker }}</p></div><NuxtLink v-if="user?.role !== 'approver'" to="/" class="button secondary full-width"><AppIcon name="plus" />Nueva cotización</NuxtLink></div></aside>
    </div>
  </template>
</template>

<style scoped>
.revision-navigation,.draft-download{margin-bottom:22px}.draft-download .muted{line-height:1.7}.revision-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.revision-links{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}.revision-links a{padding:8px 12px;border:1px solid var(--line);border-radius:6px;font-size:12px}.revision-links a[aria-current="page"]{color:var(--teal);background:#eff4ef}

.review-controls,.review-history{margin:22px 0;border-top:1px solid var(--line);padding-top:18px}.review-controls h3,.review-history h3{font-size:13px;margin-bottom:13px}.review-controls button{margin-top:10px}.review-history article{font-size:11px;line-height:1.8;padding:12px 0;border-bottom:1px solid var(--line)}.review-history small{color:var(--muted)}
.clause-provenance{list-style:none;display:grid;gap:8px;font-size:12px;line-height:1.6;color:var(--muted)}
</style>
