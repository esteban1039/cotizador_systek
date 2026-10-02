<script setup lang="ts">
import type { FreeLineDraft, LineSuggestionMatch, QuoteAssistProposal, QuoteAssistWarning } from '../../shared/types'
import { money } from '~/utils/format'
const props = defineProps<{ hasContent: boolean; family: string; skipped: number; canAddFree: boolean }>()
const emit = defineEmits<{ apply: [proposal: QuoteAssistProposal]; 'add-free': [draft: FreeLineDraft] }>()
const suggestions = useLineSuggestions()
const added = ref<Set<string>>(new Set())
const freeUnits = ['unidad', 'metro', 'hora', 'servicio', 'licencia']
const searchValid = computed(() => text.value.trim().length >= 3)
const sourceNames: Record<string, string> = { approved_quote: 'cotización aprobada', drive_import: 'histórico Drive' }
const hasPrice = (match: LineSuggestionMatch) => match.currency === 'COP' && !!match.reference_price
function priceLabel(match: LineSuggestionMatch) {
  if (!match.reference_price) return 'Sin precio de referencia'
  return match.currency === 'COP' ? money(match.reference_price) : `${match.currency} ${match.reference_price}`
}
async function searchBase() {
  if (suggestions.loading.value || !searchValid.value) return
  added.value = new Set()
  await suggestions.search(text.value, props.family || undefined)
}
function addSuggestion(match: LineSuggestionMatch, id: string) {
  if (!props.canAddFree || added.value.has(id)) return
  const quantity = match.quantity && /^\d{1,6}(\.\d{1,2})?$/.test(match.quantity) && Number(match.quantity) > 0 ? match.quantity : '1'
  const unit = match.unit && freeUnits.includes(match.unit) ? match.unit : 'unidad'
  emit('add-free', { description: match.description.trim().slice(0, 255), unit, quantity, price: hasPrice(match) ? match.reference_price! : '' })
  added.value = new Set(added.value).add(id)
}
const { loading, errors, propose } = useQuoteAssist()
const open = ref(false)
const text = ref('')
const proposal = ref<QuoteAssistProposal | null>(null)
const valid = computed(() => text.value.trim().length >= 10 && text.value.length <= 4000)
const warningNames: Record<string, string> = {
  unknown_sku: 'Se descartó un producto que no existe en el catálogo vigente',
  duplicate_sku: 'Se descartó un producto repetido',
  invalid_quantity: 'Se descartó una partida por cantidad inválida',
  invalid_family: 'La familia propuesta no es válida y se ignoró',
  family_mismatch: 'Una partida es de una familia distinta a la propuesta',
  sensitive_text_removed: 'Se eliminó un texto que parecía contener datos sensibles',
}
function warningText(warning: QuoteAssistWarning) {
  const name = warningNames[warning.code] ?? 'Aviso del asistente'
  return warning.sku ? `${name} (${warning.sku}).` : `${name}.`
}
async function submit() {
  if (loading.value || !valid.value) return
  if (props.hasContent && !window.confirm('El editor ya tiene partidas o alcance. La propuesta de la IA reemplazará las partidas, el alcance y las exclusiones actuales y, si cambia la familia, también las cláusulas seleccionadas. ¿Continuar?')) return
  if (searchValid.value) { added.value = new Set(); void suggestions.search(text.value, props.family || undefined) }
  const result = await propose(text.value.trim(), props.family || undefined)
  if (!result) return
  proposal.value = result
  emit('apply', result)
}
</script>
<template>
  <section class="panel assist-panel" aria-label="Asistente IA">
    <div class="assist-head">
      <div><h2>Asistente IA</h2><p class="muted">Describe la necesidad y obtén un borrador de partidas, alcance y exclusiones.</p></div>
      <button type="button" class="button secondary" :aria-expanded="open" aria-controls="assist-body" @click="open = !open">{{ open ? 'Ocultar asistente' : 'Usar asistente' }}</button>
    </div>
    <div v-show="open" id="assist-body" class="assist-body">
      <label>Descripción de la necesidad
        <textarea v-model="text" rows="4" maxlength="4000" placeholder="Ej.: instalar 8 cámaras IP en una bodega con grabador y cableado…" />
      </label>
      <small class="muted">{{ text.length }}/4000 · mínimo 10 caracteres</small>
      <p class="notice privacy-note" role="note">No incluyas nombres, NIT, teléfonos, correos ni datos bancarios; el texto se envía a Anthropic (Claude).</p>
      <div v-if="errors.length" class="notice error" role="alert"><p v-for="message in errors" :key="message">{{ message }}</p></div>
      <div class="assist-actions"><button type="button" class="button secondary" :disabled="suggestions.loading.value || !searchValid" :aria-busy="suggestions.loading.value" @click="searchBase">{{ suggestions.loading.value ? 'Buscando…' : 'Buscar en la base' }}</button><button type="button" class="button primary" :disabled="loading || !valid" :aria-busy="loading" @click="submit">{{ loading ? 'Generando propuesta…' : 'Proponer borrador' }}</button></div>
      <div v-if="suggestions.errors.value.length" class="notice error" role="alert"><p v-for="message in suggestions.errors.value" :key="message">{{ message }}</p></div>
      <section v-if="suggestions.searched.value" class="assist-result" aria-label="Partidas parecidas de la base de conocimiento" aria-live="polite">
        <strong>Partidas parecidas de la base de conocimiento</strong>
        <p class="notice">Son solo referencias. El aprobador validará el precio antes de crear el ítem.</p>
        <p v-if="!suggestions.groups.value.length" class="muted">No encontramos partidas parecidas.</p>
        <p v-if="!canAddFree && suggestions.groups.value.length" class="muted">Alcanzaste el máximo de líneas libres; no se pueden agregar más.</p>
        <div v-for="(group, gi) in suggestions.groups.value" :key="gi" class="sugg-group">
          <h3>{{ group.fragment }}</h3>
          <p v-if="!group.matches.length" class="muted">Sin coincidencias para este fragmento.</p>
          <ul v-else class="sugg-list">
            <li v-for="(match, mi) in group.matches" :key="mi">
              <div class="sugg-info"><strong>{{ match.description }}</strong>
                <small class="muted">{{ match.unit ?? 'unidad' }} · {{ priceLabel(match) }} · {{ sourceNames[match.source] ?? 'origen desconocido' }}</small>
                <small v-if="!hasPrice(match)" class="sugg-warn">{{ match.currency !== 'COP' && match.reference_price ? 'El precio está en otra moneda: no se prellena; escríbelo tú en pesos.' : 'No hay precio de referencia: escríbelo tú.' }}</small>
              </div>
              <button type="button" class="button secondary" :disabled="!canAddFree || added.has(`${gi}-${mi}`)" @click="addSuggestion(match, `${gi}-${mi}`)">{{ added.has(`${gi}-${mi}`) ? 'Agregada' : 'Agregar' }}</button>
            </li>
          </ul>
        </div>
      </section>
      <div v-if="proposal" class="assist-result">
        <div class="notice" role="status"><strong>Propuesta generada por IA: revísala antes de guardar.</strong><p>Cliente, sede, contacto y cláusulas quedan a tu criterio. Los totales se calculan con el catálogo vigente.</p></div>
        <p v-if="proposal.precedents_used?.length" class="notice">Basada en {{ proposal.precedents_used.length }} {{ proposal.precedents_used.length === 1 ? 'cotización aprobada' : 'cotizaciones aprobadas' }}.</p>
        <div v-if="proposal.catalog_truncated" class="notice">El catálogo era muy grande y se envió recortado: la propuesta puede omitir productos. Añade manualmente lo que falte.</div>
        <div v-if="skipped" class="notice">{{ skipped }} {{ skipped === 1 ? 'partida propuesta ya no está' : 'partidas propuestas ya no están' }} en el catálogo cargado y no se añadió.</div>
        <ul v-if="proposal.warnings.length" class="assist-list"><li v-for="(warning, index) in proposal.warnings" :key="index">{{ warningText(warning) }}</li></ul>
        <div v-if="proposal.missing_information.length"><strong>Información que falta:</strong><ul class="assist-list"><li v-for="(item, index) in proposal.missing_information" :key="index">{{ item }}</li></ul></div>
      </div>
    </div>
  </section>
</template>
<style scoped>
.privacy-note{background:#f3f7f2;border:1px solid #dce6df;color:#4c6660;font-weight:500}
.assist-panel{margin-bottom:22px;display:grid;gap:14px}.assist-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}.assist-body,.assist-result{display:grid;gap:12px}.assist-actions{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}.sugg-group h3{margin:0 0 6px;font-size:14px;overflow-wrap:anywhere}.sugg-list{list-style:none;margin:0;padding:0;display:grid;gap:8px}.sugg-list li{display:flex;gap:10px;align-items:center;justify-content:space-between;border:1px solid #dce6df;border-radius:8px;padding:8px 10px}.sugg-info{display:grid;gap:2px;min-width:0;overflow-wrap:anywhere}.sugg-warn{color:#8a5a00}@media(max-width:600px){.sugg-list li{flex-direction:column;align-items:stretch}.assist-actions .button{flex:1}}.assist-list{margin:6px 0 0;padding-left:18px;font-size:12px;line-height:1.6;overflow-wrap:anywhere}
</style>
