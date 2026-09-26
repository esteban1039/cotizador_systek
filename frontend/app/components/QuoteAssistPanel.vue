<script setup lang="ts">
import type { QuoteAssistProposal, QuoteAssistWarning } from '../../shared/types'
const props = defineProps<{ hasContent: boolean; family: string; skipped: number }>()
const emit = defineEmits<{ apply: [proposal: QuoteAssistProposal] }>()
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
      <div class="assist-actions"><button type="button" class="button primary" :disabled="loading || !valid" :aria-busy="loading" @click="submit">{{ loading ? 'Generando propuesta…' : 'Proponer borrador' }}</button></div>
      <div v-if="proposal" class="assist-result">
        <div class="notice" role="status"><strong>Propuesta generada por IA: revísala antes de guardar.</strong><p>Cliente, sede, contacto y cláusulas quedan a tu criterio. Los totales se calculan con el catálogo vigente.</p></div>
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
.assist-panel{margin-bottom:22px;display:grid;gap:14px}.assist-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap}.assist-body,.assist-result{display:grid;gap:12px}.assist-actions{display:flex;justify-content:flex-end}.assist-list{margin:6px 0 0;padding-left:18px;font-size:12px;line-height:1.6;overflow-wrap:anywhere}
</style>
