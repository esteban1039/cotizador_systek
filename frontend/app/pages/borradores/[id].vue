<script setup lang="ts">
import type { ReviewedQuote } from '../../../shared/types'
import { families, clauseTypes } from '#shared/admin'
import { money, cents, dateLabel, errorMessages } from '~/utils/format'
const route = useRoute()
const { user } = useAuth()
const reason = ref('')
const downloading = ref(false)
const downloadErrors = ref<string[]>([])
async function downloadPdf(official = false) {
  if (downloading.value || !quote.value) return
  downloading.value = true
  downloadErrors.value = []
  try {
    const blob = await $fetch<Blob>(`/api/backend/quotes/${quote.value.id}/${official ? 'official-pdf' : 'pdf'}`, { responseType: 'blob', timeout: 50000, retry: 0 })
    if (!blob.type.startsWith('application/pdf')) throw new Error('Invalid PDF response')
    const objectUrl = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = objectUrl
    anchor.download = official && quote.value.emission ? quote.value.emission.filename : quote.value.quote_number ? `${quote.value.quote_number}-${quote.value.version_label}-borrador.pdf` : `quote-${quote.value.id}-v${quote.value.revision_number || 1}-borrador.pdf`
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
const downloadDraft = () => downloadPdf(false)
const issueOpen = ref(false)
const issueReason = ref('')
const issuing = ref(false)
const issueErrors = ref<string[]>([])
function openIssue() { issueReason.value = ''; issueErrors.value = []; issueOpen.value = true }
async function issueQuote() {
  if (issuing.value || issueReason.value.trim().length < 5) return
  issuing.value = true
  issueErrors.value = []
  try {
    await $fetch(`/api/backend/quotes/${route.params.id}/issue`, { method: 'POST', retry: 0, timeout: 60000, body: { reason: issueReason.value.trim() } })
    issueOpen.value = false
    await refresh()
  } catch (error) { issueErrors.value = errorMessages(error) }
  finally { issuing.value = false }
}
const payableTotal = computed(() => quote.value ? (quote.value.totals.payable ?? quote.value.totals.total) : '0')
const actionErrors = ref<string[]>([])
const pending = ref(false)
const statusNames: Record<string, string> = { draft: 'Borrador', in_review: 'En revisión', approved: 'Aprobada internamente', issued: 'Emitida' }
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
    <div class="page-heading"><div><p class="eyebrow">{{ quote.status === 'issued' ? 'PROPUESTA EMITIDA' : 'PROPUESTA GUARDADA' }}</p><h1>{{ quote.status === 'issued' ? 'Cotización emitida' : 'Tu borrador está listo' }}<span>.</span></h1><p v-if="quote.quote_number">{{ quote.quote_number }} · {{ quote.version_label }} · {{ families[quote.family as keyof typeof families] || quote.family }}</p><p v-else>Estos son los datos y valores que quedaron guardados.</p></div><span class="saved-badge"><AppIcon name="check" :size="16" />Guardado</span></div>
    <section v-if="quote.status !== 'issued'" class="panel draft-download" aria-label="PDF de borrador"><div class="revision-actions"><div><h2>PDF de borrador interno</h2><p class="muted">Borrador interno: no válido para envío al cliente.</p><p class="muted">La aprobación comercial interna no autoriza su emisión ni envío.</p></div><button type="button" class="button secondary" :disabled="downloading" @click="downloadDraft">{{ downloading ? 'Preparando PDF…' : 'Descargar PDF de borrador' }}</button></div><div v-if="downloadErrors.length" class="notice error" role="alert"><p v-for="message in downloadErrors" :key="message">{{ message }}</p><button class="button secondary" :disabled="downloading" @click="downloadDraft">Reintentar descarga</button></div></section>
    <QuoteFollowups v-if="quote.status === 'issued'" :quote-id="quote.id" />
    <section class="panel revision-navigation" aria-label="Historial de versiones"><div class="revision-actions"><h2>Versión {{ quote.revision_number || 1 }}</h2><NuxtLink v-if="quote.can_revise" :to="`/?revise=${quote.id}`" class="button secondary">Crear nueva revisión</NuxtLink></div><p class="muted">Cada versión conserva sus precios, condiciones y revisión comercial.</p><nav v-if="quote.revisions?.length" class="revision-links" aria-label="Versiones de la cotización"><NuxtLink v-for="version in quote.revisions" :key="version.id" :to="`/borradores/${version.id}`" :aria-current="version.id === quote.id ? 'page' : undefined">Versión {{ version.revision_number }} · {{ statusNames[version.status] || version.status }}</NuxtLink></nav></section>
    <div class="editor-layout">
      <div class="editor-fields"><section class="panel"><div class="document-topline"><span class="eyebrow">{{ quote.issuer?.legal_name || 'SYSTEK COMPANY' }}</span><span class="draft-badge">{{ statusNames[quote.status] || quote.status }}<template v-if="quote.status !== 'issued'"> · No emitido</template></span></div><h2 class="quote-client-name">{{ quote.client_name || 'Cliente registrado' }}</h2><p class="muted">{{ quote.site_name || 'Sede registrada' }}</p><div class="document-meta"><span>Creado<strong>{{ dateLabel(quote.created_at) }}</strong></span><span>Vigencia hasta<strong>{{ dateLabel(quote.valid_until) }}</strong></span><span>Referencia interna<strong>{{ quote.quote_number || quote.id.slice(0, 8).toUpperCase() }} · {{ quote.version_label }}</strong></span></div><div class="document-section"><h3>Alcance</h3><p class="preserve-lines">{{ quote.scope }}</p></div><div class="document-section"><h3>Partidas</h3><div v-for="(line, index) in quote.lines" :key="index" class="saved-line"><div><strong>{{ line.description }}</strong><p>{{ line.quantity }} {{ line.unit }} × {{ cents(line.price_cents) }}</p><small>Descuento {{ line.discount_bps / 100 }} % · Impuesto {{ line.tax_bps / 100 }} %</small></div><strong>{{ money(line.amounts.total) }}</strong></div></div><div class="document-section"><h3>Exclusiones</h3><p class="preserve-lines">{{ quote.exclusions }}</p></div><div class="field-grid document-section"><div><h3>Forma de pago</h3><p class="preserve-lines">{{ quote.payment_terms }}</p></div><div><h3>Garantía</h3><p class="preserve-lines">{{ quote.warranty }}</p></div></div><div class="field-grid document-section"><div><h3>Vigencia</h3><p class="preserve-lines">{{ quote.validity_terms }}</p></div><div><h3>Observaciones</h3><p class="preserve-lines">{{ quote.observations || 'Sin observaciones.' }}</p></div></div><div v-if="quote.clauses?.length" class="document-section"><h3>Procedencia de cláusulas</h3><ul class="clause-provenance"><li v-for="clause in quote.clauses" :key="clause.type">{{ clauseTypes[clause.type as keyof typeof clauseTypes] || clause.type }}: «{{ clause.title }}» v{{ clause.version }}<span v-if="clause.modified"> · Modificada</span></li></ul></div></section></div>
      <aside class="summary-column"><div class="summary-panel"><span class="eyebrow">RESUMEN GUARDADO</span><h2>Total de la propuesta</h2><QuoteTotals :calculation="quote" /><div v-if="quote.status !== 'issued'" class="review-note"><AppIcon name="shield" /><p>El envío al cliente permanece deshabilitado; la emisión oficial exige aprobación interna previa.</p></div>
<section v-if="quote.emission" class="emission-panel" aria-label="Emisión oficial"><h3>Emisión oficial <span class="draft-badge">{{ quote.emission.superseded_at ? 'Reemplazada' : 'Emitida' }}</span></h3><p v-if="quote.emission.superseded_at" class="muted">Reemplazada por la versión V{{ quote.emission.superseded_by_revision }}.</p><p class="muted">{{ quote.emission.quote_number }} · {{ quote.emission.version_label }}</p><p class="muted">Emitida el {{ dateLabel(quote.emission.issued_at) }} por {{ quote.emission.issued_by || 'Usuario' }}</p><p class="muted">Huella SHA-256: <code data-testid="pdf-hash" :title="quote.emission.pdf_sha256">{{ quote.emission.pdf_sha256.slice(0, 12) }}…</code></p><button type="button" class="button primary full-width" :disabled="downloading" @click="downloadPdf(true)">{{ downloading ? 'Preparando PDF…' : 'Descargar PDF oficial' }}</button><div v-if="downloadErrors.length" class="notice error" role="alert"><p v-for="message in downloadErrors" :key="message">{{ message }}</p></div></section>
<section v-else-if="quote.status === 'approved'" class="emission-panel" aria-label="Emitir oficialmente"><h3>Emisión oficial</h3><div v-if="quote.issue_blockers.length" class="notice" role="status"><strong>No se puede emitir todavía</strong><p v-for="blocker in quote.issue_blockers" :key="blocker">{{ blocker }}</p></div><button v-if="quote.can_issue" type="button" class="button primary full-width" @click="openIssue">Emitir oficialmente</button></section><section v-if="quote.can_submit || quote.can_review" class="review-controls">
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
<div v-if="quote.status !== 'issued' && quote.blockers.length" class="pending-checks"><h3>Pendiente antes de emitir</h3><p v-for="blocker in quote.blockers" :key="blocker"><span>○</span>{{ blocker }}</p></div><NuxtLink v-if="user?.role !== 'approver'" to="/" class="button secondary full-width"><AppIcon name="plus" />Nueva cotización</NuxtLink></div></aside>
    </div>
    <div v-if="issueOpen" class="issue-overlay" @keydown.esc="issueOpen = false">
      <form class="issue-dialog" role="dialog" aria-modal="true" aria-labelledby="issue-title" @submit.prevent="issueQuote">
        <h2 id="issue-title">Confirmar emisión oficial</h2>
        <p class="notice error"><strong>Esta acción es irreversible.</strong> Se generará el PDF oficial con número consecutivo y quedará registrada en la auditoría.</p>
        <dl class="issue-summary"><div><dt>Número</dt><dd>{{ quote.quote_number }}</dd></div><div><dt>Versión</dt><dd>{{ quote.version_label }}</dd></div><div><dt>Total a pagar</dt><dd>{{ money(payableTotal) }} COP</dd></div></dl>
        <label>Motivo de la emisión<textarea v-model="issueReason" required minlength="5" maxlength="1000" rows="3" autofocus placeholder="Indica por qué se emite esta versión…" /></label>
        <div v-if="issueErrors.length" role="alert" class="notice error"><p v-for="message in issueErrors" :key="message">{{ message }}</p></div>
        <div class="revision-actions"><button type="button" class="button secondary" :disabled="issuing" @click="issueOpen = false">Cancelar</button><button class="button primary" :disabled="issuing || issueReason.trim().length < 5">{{ issuing ? 'Emitiendo…' : 'Emitir de forma irreversible' }}</button></div>
      </form>
    </div>
  </template>
</template>

<style scoped>
.revision-navigation,.draft-download{margin-bottom:22px}.draft-download .muted{line-height:1.7}.revision-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.revision-links{display:flex;flex-wrap:wrap;gap:10px;margin-top:16px}.revision-links a{padding:8px 12px;border:1px solid var(--line);border-radius:6px;font-size:12px}.revision-links a[aria-current="page"]{color:var(--teal);background:#eff4ef}

.review-controls,.review-history{margin:22px 0;border-top:1px solid var(--line);padding-top:18px}.review-controls h3,.review-history h3{font-size:13px;margin-bottom:13px}.review-controls button{margin-top:10px}.review-history article{font-size:11px;line-height:1.8;padding:12px 0;border-bottom:1px solid var(--line)}.review-history small{color:var(--muted)}
.emission-panel{margin:22px 0;border-top:1px solid var(--line);padding-top:18px;display:grid;gap:10px}.emission-panel h3{font-size:13px;display:flex;align-items:center;justify-content:space-between;gap:8px}.emission-panel p{font-size:12px;line-height:1.6;overflow-wrap:anywhere}
.issue-overlay{position:fixed;inset:0;background:rgba(20,24,22,.55);display:flex;align-items:center;justify-content:center;padding:16px;z-index:50;overflow-y:auto}.issue-dialog{background:#fff;border-radius:8px;padding:24px;width:min(520px,100%);display:grid;gap:14px;max-height:100%;overflow-y:auto}.issue-summary{display:grid;gap:8px}.issue-summary div{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid var(--line);padding-bottom:6px;font-size:13px}.issue-summary dt{color:var(--muted)}.issue-summary dd{font-weight:700;margin:0}
.clause-provenance{list-style:none;display:grid;gap:8px;font-size:12px;line-height:1.6;color:var(--muted)}
</style>
