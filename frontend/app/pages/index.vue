<script setup lang="ts">
import type { QuoteAssistProposal, Calculation, CatalogItem, Client, ClauseOption, ClauseVersionSelection, LineInput, FreeLineUnit, SimilarItem, Quote, ReviewedQuote } from '../../shared/types'
import { families, clauseTypes } from '#shared/admin'
import { cents, centsToDecimal, errorMessages } from '~/utils/format'

definePageMeta({ key: route => route.fullPath })
const route = useRoute()
const revising = route.query.revise !== undefined
const sourceId = typeof route.query.revise === 'string' ? route.query.revise : ''
const validSource = /^[0-9a-f-]{36}$/.test(sourceId)
const request = useRequestFetch()
const { data: sourceResponse, error: sourceError, refresh: refreshSource } = await useAsyncData(`revision-source-${sourceId}`, () => revising && validSource ? request<{ data: ReviewedQuote }>(`/api/backend/quotes/${sourceId}`) : Promise.resolve(null))
const source = computed(() => sourceResponse.value?.data)
const sourceReady = computed(() => !revising || (!!source.value?.can_revise && validSource && !sourceError.value))
useHead({ title: revising ? 'Nueva revisión · JARVIS' : 'Nueva cotización · JARVIS' })
const { data: clientsResponse, error: clientsError, refresh: refreshClients } = await useFetch<{ data: Client[] }>('/api/backend/clients')
const { data: catalogResponse, error: catalogError, refresh: refreshCatalog } = await useFetch<{ data: CatalogItem[] }>('/api/backend/catalog')
const clients = computed(() => clientsResponse.value?.data ?? [])
const catalog = computed(() => catalogResponse.value?.data ?? [])
const editorReady = ref(false)
const clientId = ref('')
const siteId = ref('')
const selectedClient = computed(() => clients.value.find(client => client.id === clientId.value))
const sites = computed(() => selectedClient.value?.sites ?? [])
watch(clientId, () => { siteId.value = '' })
const selectedItem = ref('')
let nextKey = 1
const rows = ref<{ key: number; item: CatalogItem; quantity: string; discount: string }[]>([])
// Líneas libres: ítems que aún no existen en el catálogo; se crean al aprobar. El precio lo escribe siempre el cotizador.
type FreeRow = { key: number; description: string; unit: FreeLineUnit; quantity: string; discount: string; price: string; cost: string; tax: number }
const freeUnits: FreeLineUnit[] = ['unidad', 'metro', 'hora', 'servicio', 'licencia']
const freeRows = ref<FreeRow[]>([])
const maxFree = 20
const similar = useCatalogSimilar()
const activeFree = ref<number | null>(null)
const lineCount = computed(() => rows.value.length + freeRows.value.length)
function addFree() {
  if (freeRows.value.length >= maxFree || lineCount.value >= 100) return
  freeRows.value.push({ key: nextKey++, description: '', unit: 'unidad', quantity: '1', discount: '0', price: '', cost: '', tax: 1900 })
}
function removeFree(index: number) { freeRows.value.splice(index, 1); activeFree.value = null; similar.clear() }
function onFreeDescription(row: FreeRow) { activeFree.value = row.key; similar.search(row.description, family.value || undefined) }
const similarNotice = ref('')
function useSimilar(row: FreeRow, suggestion: SimilarItem) {
  const item = catalog.value.find(candidate => candidate.price_version_id === suggestion.price_version_id)
  if (!item) { similarNotice.value = 'Ese ítem no está en el catálogo cargado. Actualiza la página e inténtalo de nuevo.'; return }
  similarNotice.value = ''
  rows.value.push({ key: nextKey++, item: { ...item }, quantity: row.quantity, discount: row.discount })
  freeRows.value.splice(freeRows.value.findIndex(candidate => candidate.key === row.key), 1)
  activeFree.value = null
  similar.clear()
}
const terms = reactive({ scope: '', exclusions: '', payment_terms: '', warranty: '', validity_terms: '', observations: '', validity_days: 15 })
const family = ref('')

// Cláusulas por familia: precarga la predeterminada de cada tipo; el usuario puede elegir otra o texto libre.
type ClauseFieldKey = 'scope_base' | 'exclusions' | 'payment' | 'warranty' | 'validity' | 'observations'
const clauseFieldMap: Record<ClauseFieldKey, 'scope' | 'exclusions' | 'payment_terms' | 'warranty' | 'validity_terms' | 'observations'> = { scope_base: 'scope', exclusions: 'exclusions', payment: 'payment_terms', warranty: 'warranty', validity: 'validity_terms', observations: 'observations' }
const clauseTypeKeys = Object.keys(clauseTypes) as ClauseFieldKey[]
const clauseOptions = ref<ClauseOption[]>([])
const clauseSelection = reactive<Record<ClauseFieldKey, string | null>>({ scope_base: null, exclusions: null, payment: null, warranty: null, validity: null, observations: null })
const lastLoadedText = reactive<Record<ClauseFieldKey, string>>({ scope_base: '', exclusions: '', payment: '', warranty: '', validity: '', observations: '' })
const versionChanged = reactive<Record<ClauseFieldKey, string | null>>({ scope_base: null, exclusions: null, payment: null, warranty: null, validity: null, observations: null })
function optionsFor(type: ClauseFieldKey) { return clauseOptions.value.filter(option => option.type === type) }
function renderBody(body: string, days: number) { return body.replace(/\{vigencia_dias\}/g, String(days)) }
function isModified(type: ClauseFieldKey) { return !!clauseSelection[type] && terms[clauseFieldMap[type]] !== lastLoadedText[type] }
function applyClauseText(type: ClauseFieldKey, option: ClauseOption) {
  const rendered = type === 'validity' ? renderBody(option.body, terms.validity_days) : option.body
  terms[clauseFieldMap[type]] = rendered
  lastLoadedText[type] = rendered
}
function onClauseSelect(type: ClauseFieldKey) {
  versionChanged[type] = null
  const id = clauseSelection[type]
  const option = clauseOptions.value.find(candidate => candidate.clause_version_id === id)
  if (option) applyClauseText(type, option)
}
function reloadClauseText(type: ClauseFieldKey) { onClauseSelect(type) }
async function loadClausesForFamily(value: string) {
  if (!value) { clauseOptions.value = []; return }
  try {
    const response = await $fetch<{ data: ClauseOption[] }>('/api/backend/clauses', { query: { family: value } })
    clauseOptions.value = response.data
  } catch { clauseOptions.value = []; return }
  for (const type of clauseTypeKeys) {
    const fieldKey = clauseFieldMap[type]
    const current = terms[fieldKey]
    const untouched = !current || current === lastLoadedText[type]
    if (!untouched) continue
    const options = optionsFor(type)
    const preferred = options.find(option => option.is_default) ?? options[0]
    if (preferred) { clauseSelection[type] = preferred.clause_version_id; applyClauseText(type, preferred) }
  }
}
let familyLoading = false
watch(family, async (value, previous) => {
  if (familyLoading) return
  if (previous && value !== previous) {
    if (!window.confirm('Cambiar de familia limpia las cláusulas seleccionadas de la familia anterior. ¿Continuar?')) {
      familyLoading = true; family.value = previous; await nextTick(); familyLoading = false; return
    }
    for (const type of clauseTypeKeys) { clauseSelection[type] = null; versionChanged[type] = null }
  }
  await loadClausesForFamily(value)
})
watch(() => terms.validity_days, days => {
  if (!clauseSelection.validity || isModified('validity')) return
  const option = clauseOptions.value.find(candidate => candidate.clause_version_id === clauseSelection.validity)
  if (option) applyClauseText('validity', option)
})
function clauseVersionsPayload(): ClauseVersionSelection { return Object.fromEntries(clauseTypeKeys.map(type => [type, clauseSelection[type]])) as ClauseVersionSelection }

const preview = ref<Calculation | null>(null)
const calculating = ref(false)
const previewErrors = ref<string[]>([])
const saveErrors = ref<string[]>([])
const saving = ref(false)
const dirty = ref(false)
watch([clientId, siteId, rows, freeRows, terms, family], () => { dirty.value = true; saveErrors.value = [] }, { deep: true })
const clientComplete = computed(() => !!clientId.value && !!siteId.value)
const termsComplete = computed(() => !!family.value && [terms.scope, terms.exclusions, terms.payment_terms, terms.warranty, terms.validity_terms].every(value => value.trim()) && terms.validity_days >= 1 && terms.validity_days <= 90)
const completion = computed(() => Number(clientComplete.value) + Number(!!preview.value) + Number(termsComplete.value))
const otherFamilyLines = computed(() => rows.value.filter(row => family.value && row.item.family !== family.value).length)

function unavailable(row: { item: CatalogItem }) { return !catalog.value.some(item => item.price_version_id === row.item.price_version_id) }
function replacement(row: { item: CatalogItem }) { return catalog.value.find(item => item.id === row.item.id) }
function replacePrice(row: { item: CatalogItem }) {
  const current = replacement(row)
  if (current) row.item = { ...current }
}
const unavailablePrices = computed(() => rows.value.some(unavailable))
async function prefillSource() {
  if (!source.value || !sourceReady.value) return
  const original = source.value
  clientId.value = original.client_id
  await nextTick()
  siteId.value = original.site_id
  familyLoading = true
  family.value = original.family ?? ''
  await nextTick()
  familyLoading = false
  Object.assign(terms, { scope: original.scope, exclusions: original.exclusions, payment_terms: original.payment_terms, warranty: original.warranty, validity_terms: original.validity_terms ?? '', observations: original.observations ?? '', validity_days: original.validity_days })
  rows.value = []
  freeRows.value = []
  for (const line of original.lines) {
    const quantity = String(line.quantity)
    const discount = String(line.discount_bps / 100)
    // Libre sin vincular: se edita como libre. Libre vinculada: pasa a ser línea de catálogo con el precio del ítem creado.
    if (line.line_type === 'free' && !line.linked_item) {
      freeRows.value.push({ key: nextKey++, description: line.description, unit: line.unit as FreeLineUnit, quantity, discount, price: centsToDecimal(line.price_cents), cost: line.cost_cents !== undefined ? centsToDecimal(line.cost_cents) : '', tax: line.tax_bps })
      continue
    }
    const linked = line.line_type === 'free' ? line.linked_item : undefined
    const priceVersionId = linked?.price_version_id ?? line.price_version_id ?? ''
    const catalogItemId = linked?.catalog_item_id ?? line.catalog_item_id ?? ''
    rows.value.push({
      key: nextKey++, quantity, discount,
      item: { id: catalogItemId, price_version_id: priceVersionId, description: line.description, unit: line.unit, family: line.family, is_demo: line.is_demo, price_cents: line.price_cents, tax_bps: line.tax_bps, sku: linked?.sku ?? catalog.value.find(item => item.id === catalogItemId)?.sku ?? 'Versión original', valid_until: original.valid_until },
    })
  }
  if (family.value) {
    await loadClausesForFamily(family.value)
    for (const provenance of original.clauses ?? []) {
      const type = provenance.type as ClauseFieldKey
      if (!clauseFieldMap[type]) continue
      const match = clauseOptions.value.find(option => option.clause_id === provenance.clause_id && option.type === type)
      if (!match) continue
      clauseSelection[type] = match.clause_version_id
      lastLoadedText[type] = type === 'validity' ? renderBody(match.body, terms.validity_days) : match.body
      versionChanged[type] = match.clause_version_id !== provenance.clause_version_id ? provenance.title : null
    }
  }
  await nextTick()
  dirty.value = false
}
async function retrySource() { await refreshSource(); await prefillSource() }
onMounted(async () => { await prefillSource(); editorReady.value = true })
function addItem() {
  const item = catalog.value.find(item => item.price_version_id === selectedItem.value)
  if (!item || lineCount.value >= 100) return
  rows.value.push({ key: nextKey++, item, quantity: '1', discount: '0' })
  selectedItem.value = ''
}
function parseQuantityDiscount(row: { quantity: string; discount: string }): { quantity: string; discount_bps: number } | null {
  const quantity = row.quantity.trim().replace(',', '.')
  const discount = row.discount.trim().replace(',', '.')
  if (!/^\d{1,5}(\.\d{1,3})?$/.test(quantity) || !/[1-9]/.test(quantity) || !/^\d{1,3}(\.\d{1,2})?$/.test(discount)) return null
  const [whole = '0', fraction = ''] = discount.split('.')
  const bps = Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
  return bps > 10000 ? null : { quantity, discount_bps: bps }
}
function inputLines(): LineInput[] | null {
  const lines: LineInput[] = []
  for (const row of rows.value) {
    const parsed = parseQuantityDiscount(row)
    if (!parsed) return null
    lines.push({ price_version_id: row.item.price_version_id, ...parsed })
  }
  for (const row of freeRows.value) {
    const parsed = parseQuantityDiscount(row)
    const description = row.description.trim()
    const price = row.price.trim().replace(',', '.')
    const cost = row.cost.trim().replace(',', '.')
    if (!parsed || description.length < 5 || description.length > 255) return null
    if (!/^\d{1,9}(\.\d{1,2})?$/.test(price) || !/[1-9]/.test(price) || !/^\d{1,9}(\.\d{1,2})?$/.test(cost)) return null
    lines.push({ type: 'free', description, unit: row.unit, quantity: parsed.quantity, price, cost, tax_bps: row.tax as 0 | 500 | 1900, discount_bps: parsed.discount_bps, confirmed_new: true })
  }
  return lines.length ? lines : null
}
let timer: ReturnType<typeof setTimeout> | undefined
let pending: AbortController | undefined
let revision = 0
watch([rows, freeRows, clientId], () => {
  const current = ++revision
  clearTimeout(timer)
  pending?.abort()
  preview.value = null
  previewErrors.value = []
  calculating.value = false
  if (!lineCount.value) return
  timer = setTimeout(async () => {
    if (unavailablePrices.value) { previewErrors.value = ['Hay precios originales que ya no están vigentes. Selecciona explícitamente el precio actual o elimina la partida antes de guardar.']; return }
    const lines = inputLines()
    if (!lines) { previewErrors.value = ['Revisa las partidas: cantidades mayores que cero (hasta 3 decimales), descuentos entre 0 y 100 %, y en las líneas libres descripción de 5 a 255 caracteres, precio mayor que cero y costo (0 o más).']; return }
    pending = new AbortController()
    calculating.value = true
    try {
      const response = await $fetch<{ data: Calculation }>('/api/backend/quotes/preview', { method: 'POST', body: { lines, client_id: clientId.value || undefined }, signal: pending.signal, retry: 0 })
      if (current === revision) preview.value = response.data
    } catch (error) {
      if (current === revision) previewErrors.value = errorMessages(error)
    } finally {
      if (current === revision) calculating.value = false
    }
  }, 350)
}, { deep: true, flush: 'sync' })
onBeforeUnmount(() => { revision++; clearTimeout(timer); pending?.abort() })

async function loadExample() {
  if (!editorReady.value) return
  const client = clients.value.find(client => client.is_demo)
  const camera = catalog.value.find(item => item.sku === 'DEMO-CAM-IP')
  if (!client || !camera) return
  clientId.value = client.id
  await nextTick()
  siteId.value = client.sites[0]?.id ?? ''
  rows.value = [{ key: nextKey++, item: camera, quantity: '8', discount: '0' }]
  terms.validity_days = 15
  family.value = 'cctv'
  await loadClausesForFamily('cctv')
}
async function save() {
  if (!editorReady.value || saving.value || !sourceReady.value || unavailablePrices.value) return
  saveErrors.value = []
  const lines = inputLines()
  if (!lines || !clientComplete.value || !termsComplete.value) {
    saveErrors.value = ['Completa el cliente, la sede, la familia, las partidas y las condiciones antes de guardar.']
    return
  }
  saving.value = true
  try {
    const response = await $fetch<{ data: Quote }>(revising ? `/api/backend/quotes/${sourceId}/revisions` : '/api/backend/quotes', {
      method: 'POST', retry: 0,
      body: { client_id: clientId.value, site_id: siteId.value, family: family.value, lines, ...terms, observations: terms.observations || null, clause_versions: clauseVersionsPayload(), ...(assistRequestId.value ? { assist_request_id: assistRequestId.value } : {}) },
    })
    dirty.value = false
    await navigateTo(`/borradores/${response.data.id}`)
  } catch (error) {
    saveErrors.value = errorMessages(error)
  } finally { saving.value = false }
}
function confirmLeave() { return !dirty.value || window.confirm('Tienes cambios sin guardar. ¿Quieres salir del editor?') }
onBeforeRouteLeave(confirmLeave)
onBeforeRouteUpdate(confirmLeave)
function confirmUnload(event: BeforeUnloadEvent) { if (dirty.value) event.preventDefault() }
onMounted(() => window.addEventListener('beforeunload', confirmUnload))
onBeforeUnmount(() => window.removeEventListener('beforeunload', confirmUnload))
const { user: authUser } = useAuth()
const assistAllowed = computed(() => !revising && ['admin', 'quoter'].includes(authUser.value?.role ?? ''))
const assistHasContent = computed(() => lineCount.value > 0 || !!terms.scope.trim() || !!terms.exclusions.trim())
const assistSkipped = ref(0)
// Instantánea de lo propuesto por la IA: la etiqueta desaparece cuando el usuario edita el campo.
const aiProposed = reactive<{ family: string | null; scope: string | null; exclusions: string | null; quantities: Record<number, string> }>({ family: null, scope: null, exclusions: null, quantities: {} })
const aiFamily = computed(() => aiProposed.family !== null && family.value === aiProposed.family)
const aiScope = computed(() => aiProposed.scope !== null && terms.scope === aiProposed.scope)
const aiExclusions = computed(() => aiProposed.exclusions !== null && terms.exclusions === aiProposed.exclusions)
function aiLine(row: { key: number; quantity: string; discount: string }) { return aiProposed.quantities[row.key] === row.quantity && row.discount === '0' }
const assistRequestId = ref<string | null>(null)
async function applyAssist(proposal: QuoteAssistProposal) {
  assistRequestId.value = proposal.request_id
  const nextRows: typeof rows.value = []
  let skipped = 0
  for (const line of proposal.lines) {
    const item = catalog.value.find(candidate => candidate.price_version_id === line.price_version_id)
    if (!item || nextRows.length >= 100) { skipped++; continue }
    nextRows.push({ key: nextKey++, item: { ...item }, quantity: line.quantity, discount: '0' })
  }
  assistSkipped.value = skipped
  aiProposed.family = null; aiProposed.scope = null; aiProposed.exclusions = null; aiProposed.quantities = {}
  const target = proposal.family && Object.keys(families).includes(proposal.family) ? proposal.family : family.value
  if (target && target !== family.value) {
    familyLoading = true
    if (family.value) for (const type of clauseTypeKeys) { clauseSelection[type] = null; versionChanged[type] = null }
    family.value = target
    await nextTick()
    familyLoading = false
    await loadClausesForFamily(target)
    aiProposed.family = target
  }
  if (proposal.scope) { terms.scope = proposal.scope; clauseSelection.scope_base = null; aiProposed.scope = proposal.scope }
  if (proposal.exclusions) { terms.exclusions = proposal.exclusions; clauseSelection.exclusions = null; aiProposed.exclusions = proposal.exclusions }
  rows.value = nextRows
  freeRows.value = []
  for (const row of nextRows) aiProposed.quantities[row.key] = row.quantity
}
const exampleAvailable = computed(() => clients.value.some(client => client.is_demo) && catalog.value.some(item => item.sku === 'DEMO-CAM-IP'))
</script>

<template>
  <div class="page-heading">
    <div><p class="eyebrow">DE LA IDEA A LA PROPUESTA</p><h1>{{ revising ? 'Nueva revisión' : 'Nueva cotización' }}<span>.</span></h1><p>{{ revising ? 'Prepara una nueva versión conservando la cotización original.' : 'Construye una propuesta clara, partida por partida.' }}</p></div>
    <button v-if="!revising && exampleAvailable && !rows.length" type="button" class="button secondary" :disabled="!editorReady" @click="loadExample"><AppIcon name="camera" />Probar ejemplo CCTV</button>
  </div>
  <div v-if="clientsError || catalogError" class="notice error" role="alert"><strong>No pudimos cargar los datos.</strong><p>Comprueba que el cotizador esté disponible e intenta de nuevo.</p><button class="button secondary" @click="refreshClients(); refreshCatalog()">Reintentar</button></div>
  <div v-if="revising && !sourceReady" class="notice error" role="alert"><strong>No pudimos abrir la cotización para revisarla.</strong><p>Comprueba el enlace y que tengas permiso para crear una nueva revisión.</p><button v-if="validSource" class="button secondary" @click="retrySource">Reintentar revisión</button></div>
  <div v-if="revising && sourceReady" class="notice"><strong>Revisión de la versión {{ source?.revision_number }}.</strong> Los datos originales se conservarán. Esta copia se guardará como un nuevo borrador y requerirá su propia revisión comercial. <NuxtLink :to="`/borradores/${sourceId}`">Ver original</NuxtLink></div>
  <div class="demo-notice"><span class="notice-symbol">i</span><p><strong>Estás en un espacio de prueba.</strong> Los precios de demostración no tienen validez comercial.</p></div>
  <QuoteAssistPanel v-if="assistAllowed" :has-content="assistHasContent" :family="family" :skipped="assistSkipped" @apply="applyAssist" />
  <form class="editor-layout" @submit.prevent="save">
    <fieldset class="editor-fields" :disabled="!editorReady || saving || !sourceReady">
      <section class="panel" aria-labelledby="client-title">
        <div class="section-heading"><span class="step-number" :class="{ complete: clientComplete }"><AppIcon v-if="clientComplete" name="check" :size="16" /><template v-else>01</template></span><div><h2 id="client-title">Cliente, sede y familia</h2><p>¿Para quién y sobre qué familia vamos a cotizar?</p></div><AppIcon class="section-icon" name="user" /></div>
        <div class="field-grid">
          <label>Cliente <span class="required">*</span><select v-model="clientId" required><option value="" disabled>Selecciona un cliente</option><option v-for="client in clients" :key="client.id" :value="client.id">{{ client.name }}</option></select></label>
          <label>Sede <span class="required">*</span><select v-model="siteId" required :disabled="!clientId"><option value="" disabled>Selecciona una sede</option><option v-for="site in sites" :key="site.id" :value="site.id">{{ site.name }} · {{ site.city }}</option></select></label>
          <label>Familia <span class="required">*</span><span v-if="aiFamily" class="draft-badge">Propuesto por IA</span><select v-model="family" required><option value="" disabled>Selecciona una familia</option><option v-for="(label, key) in families" :key="key" :value="key">{{ label }}</option></select></label>
        </div>
        <p v-if="!clients.length && !clientsError" class="empty-message">Aún no hay clientes registrados. Carga los datos de demostración para comenzar.</p>
        <p v-else-if="clientId && !sites.length" class="empty-message">Este cliente todavía no tiene sedes registradas.</p>
        <p v-if="selectedClient?.withholds_vat" class="empty-message">Agente retenedor de IVA: se calculará ReteIVA.</p>
      </section>
      <section class="panel" aria-labelledby="lines-title">
        <div class="section-heading"><span class="step-number" :class="{ complete: !!preview }"><AppIcon v-if="preview" name="check" :size="16" /><template v-else>02</template></span><div><h2 id="lines-title">Partidas de la propuesta</h2><p>Productos y servicios del catálogo vigente.</p></div><span class="counter">{{ lineCount }} {{ lineCount === 1 ? 'partida' : 'partidas' }}</span></div>
        <div class="add-line"><label class="grow"><span class="sr-only">Producto o servicio</span><select v-model="selectedItem" :disabled="!catalog.length || lineCount >= 100"><option value="">Selecciona un producto o servicio…</option><option v-for="item in catalog" :key="item.price_version_id" :value="item.price_version_id">{{ item.description }} · {{ cents(item.price_cents) }}</option></select></label><button type="button" class="button secondary" :disabled="!selectedItem || lineCount >= 100" @click="addItem"><AppIcon name="plus" />Añadir</button><button type="button" class="button secondary" :disabled="freeRows.length >= maxFree || lineCount >= 100" @click="addFree"><AppIcon name="plus" />Línea libre</button></div>
        <div v-if="!lineCount" class="empty-lines"><span class="empty-icon"><AppIcon name="document" :size="28" /></span><h3>Una buena propuesta empieza aquí</h3><p>{{ catalog.length ? 'Añade la primera partida desde el catálogo.' : 'No hay precios vigentes. Actualiza el catálogo para continuar.' }}</p></div>
        <div v-if="otherFamilyLines" class="notice">{{ otherFamilyLines }} {{ otherFamilyLines === 1 ? 'partida es' : 'partidas son' }} de una familia distinta a la seleccionada.</div>
        <div v-for="(row, index) in rows" :key="row.key" class="line-card">
          <div class="line-description"><span class="line-index">{{ String(index + 1).padStart(2, '0') }}</span><div><strong>{{ row.item.description }}</strong><span>{{ row.item.sku }} <b>·</b> {{ row.item.unit }} <b>·</b> Impuesto {{ row.item.tax_bps / 100 }} % <b v-if="family && row.item.family !== family">· Otra familia</b> <b v-if="aiLine(row)" class="draft-badge">Propuesto por IA</b></span></div><button type="button" class="icon-button danger" :aria-label="`Eliminar partida ${index + 1}`" @click="rows.splice(index, 1)"><AppIcon name="trash" :size="18" /></button></div>
          <div v-if="unavailable(row)" class="notice error"><p>El precio original de {{ cents(row.item.price_cents) }} ya no está disponible para una nueva cotización. La versión original se conserva sin cambios.</p><button v-if="replacement(row)" type="button" class="button secondary" :aria-label="`Usar precio actual partida ${index + 1}`" @click="replacePrice(row)">Usar precio actual · {{ cents(replacement(row)!.price_cents) }}</button><p v-else>No hay un precio vigente para este ítem. Puedes eliminar la partida y elegir otro producto del catálogo.</p></div>
          <div class="line-fields"><label :for="`quantity-${row.key}`">Cantidad<input :id="`quantity-${row.key}`" v-model="row.quantity" :aria-label="`Cantidad partida ${index + 1}`" inputmode="decimal" required maxlength="9"></label><label :for="`discount-${row.key}`">Descuento %<input :id="`discount-${row.key}`" v-model="row.discount" :aria-label="`Descuento partida ${index + 1}`" inputmode="decimal" required maxlength="6"></label><div class="unit-price"><span>Precio unitario</span><strong>{{ cents(row.item.price_cents) }}</strong></div></div>
        </div>
        <div v-for="(row, index) in freeRows" :key="row.key" class="line-card free-line">
          <div class="line-description"><span class="line-index">L{{ index + 1 }}</span><div><strong>Línea libre</strong><span>No existe en el catálogo todavía</span></div><button type="button" class="icon-button danger" :aria-label="`Eliminar línea libre ${index + 1}`" @click="removeFree(index)"><AppIcon name="trash" :size="18" /></button></div>
          <div class="notice" role="status"><p>Se creará un ítem nuevo en el catálogo al aprobar. El precio y el costo los escribes tú; no se rellenan automáticamente.</p></div>
          <div class="field-stack"><label>Descripción <span class="required">*</span><input v-model="row.description" required minlength="5" maxlength="255" autocomplete="off" :aria-label="`Descripción línea libre ${index + 1}`" @input="onFreeDescription(row)"></label>
            <div v-if="activeFree === row.key && (similar.results.value.length || similar.loading.value || similar.failed.value)" class="similar-box" aria-live="polite">
              <p v-if="similar.loading.value" class="empty-message">Buscando ítems parecidos…</p>
              <p v-else-if="similar.failed.value" class="empty-message">No pudimos buscar ítems parecidos.</p>
              <template v-else><strong>Ítems parecidos en el catálogo</strong><ul><li v-for="suggestion in similar.results.value" :key="suggestion.id"><span>{{ suggestion.description }} <small>{{ suggestion.sku }} · {{ suggestion.unit }}</small></span><button type="button" class="button secondary" @click="useSimilar(row, suggestion)">Usar este ítem</button></li></ul></template>
            </div>
            <p v-if="similarNotice" class="empty-message">{{ similarNotice }}</p>
          </div>
          <div class="line-fields free-fields">
            <label>Unidad<select v-model="row.unit"><option v-for="unit in freeUnits" :key="unit" :value="unit">{{ unit }}</option></select></label>
            <label>Cantidad<input v-model="row.quantity" :aria-label="`Cantidad línea libre ${index + 1}`" inputmode="decimal" required maxlength="9"></label>
            <label>Precio unitario (COP)<input v-model="row.price" :aria-label="`Precio línea libre ${index + 1}`" inputmode="decimal" required maxlength="12" placeholder="0.00"></label>
            <label>Costo unitario (COP)<input v-model="row.cost" :aria-label="`Costo línea libre ${index + 1}`" inputmode="decimal" required maxlength="12" placeholder="0.00"></label>
            <label>IVA<select v-model.number="row.tax"><option :value="0">0 %</option><option :value="500">5 %</option><option :value="1900">19 %</option></select></label>
            <label>Descuento %<input v-model="row.discount" :aria-label="`Descuento línea libre ${index + 1}`" inputmode="decimal" required maxlength="6"></label>
          </div>
        </div>
        <div v-if="previewErrors.length" class="notice error" role="alert"><p v-for="message in previewErrors" :key="message">{{ message }}</p></div>
        <p class="field-note"><AppIcon name="shield" :size="14" />Precios vigentes. Los impuestos se calculan por partida.</p>
      </section>
      <section class="panel" aria-labelledby="terms-title">
        <div class="section-heading"><span class="step-number" :class="{ complete: termsComplete }"><AppIcon v-if="termsComplete" name="check" :size="16" /><template v-else>03</template></span><div><h2 id="terms-title">Alcance y condiciones</h2><p>Deja claro qué incluye tu propuesta. Elige la familia para precargar cláusulas.</p></div></div>
        <div v-for="type in clauseTypeKeys" :key="type" class="field-stack">
          <label>{{ clauseTypes[type] }} <span v-if="type !== 'observations'" class="required">*</span> <span v-if="(type === 'scope_base' && aiScope) || (type === 'exclusions' && aiExclusions)" class="draft-badge">Propuesto por IA</span>
            <textarea v-model="terms[clauseFieldMap[type]]" :required="type !== 'observations'" :maxlength="type === 'scope_base' || type === 'exclusions' || type === 'observations' ? 5000 : 1000" rows="3" :disabled="!family" />
          </label>
          <div class="add-line">
            <label class="grow"><span class="sr-only">Cláusula de {{ clauseTypes[type] }}</span>
              <select v-model="clauseSelection[type]" :disabled="!family" @change="onClauseSelect(type)">
                <option :value="null">Sin cláusula (texto libre)</option>
                <option v-for="option in optionsFor(type)" :key="option.clause_version_id" :value="option.clause_version_id">{{ option.title }}{{ option.is_default ? ' (predeterminada)' : '' }}</option>
              </select>
            </label>
            <button v-if="clauseSelection[type]" type="button" class="button secondary" @click="reloadClauseText(type)">Cargar cláusula</button>
          </div>
          <p v-if="isModified(type)" class="empty-message">Modificada respecto al texto de la cláusula.</p>
          <div v-if="versionChanged[type]" class="notice">Hay una versión nueva de «{{ versionChanged[type] }}». <button type="button" class="button secondary" @click="reloadClauseText(type)">Usar texto vigente</button></div>
        </div>
        <label class="validity-field">Vigencia de la propuesta <span class="required">*</span><div class="input-suffix"><input v-model.number="terms.validity_days" aria-label="Vigencia en días" type="number" min="1" max="90" step="1" required><span>días</span></div></label>
      </section>
    </fieldset>
    <aside class="summary-column">
      <div class="summary-panel"><div class="summary-heading"><span class="eyebrow">TU PROPUESTA</span><span class="draft-badge">Borrador</span></div><h2>Resumen de cotización</h2><p class="summary-client">{{ selectedClient?.name || 'Selecciona un cliente para comenzar' }}</p><div class="progress-bar" :aria-label="`${completion} de 3 secciones completas`"><span :style="{ width: `${completion / 3 * 100}%` }" /></div><p class="progress-caption">{{ completion }} de 3 secciones completas</p><QuoteTotals :calculation="preview" :busy="calculating" /><div class="review-note"><AppIcon name="shield" :size="18" /><p>Antes de compartir, la propuesta debe pasar por revisión y aprobación.</p></div><div v-if="saveErrors.length" class="notice error" role="alert"><p v-for="message in saveErrors" :key="message">{{ message }}</p></div><button type="submit" class="button primary full-width" :disabled="!editorReady || saving || !sourceReady || unavailablePrices || !preview || calculating || !!clientsError || !!catalogError">{{ saving ? 'Guardando borrador…' : revising ? 'Guardar nueva revisión' : 'Guardar borrador' }}<AppIcon name="arrow" :size="18" /></button><p class="save-caption">Guardar no envía la propuesta al cliente.</p></div>
      <div class="help-card"><AppIcon name="clock" :size="20" /><div><strong>Todo empieza con un borrador</strong><p>Podrás consultar tus propuestas guardadas desde Borradores.</p></div></div>
    </aside>
  </form>
</template>

<style scoped>
.free-line{background:#fbfcfb;padding:19px 14px;border-radius:8px;margin-top:10px}.free-fields{grid-template-columns:repeat(3,1fr);padding-left:0}.similar-box{border:1px solid #ecf0ed;border-radius:7px;padding:10px 12px;background:#fff;font-size:12px}.similar-box ul{list-style:none;display:grid;gap:8px;margin:8px 0 0;padding:0}.similar-box li{display:flex;justify-content:space-between;align-items:center;gap:10px}.similar-box small{color:var(--muted)}
@media(max-width:640px){.free-fields{grid-template-columns:1fr 1fr}.similar-box li{flex-direction:column;align-items:stretch}}
</style>
