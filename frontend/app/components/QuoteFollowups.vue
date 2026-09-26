<script setup lang="ts">
import type { FollowupChannel, FollowupOverview, FollowupType } from '../../shared/types'
import { dateLabel, errorMessages } from '~/utils/format'
const props = defineProps<{ quoteId: string }>()
const statusNames: Record<string, string> = { not_sent: 'No enviada', sent: 'Enviada', responded: 'Con respuesta', accepted: 'Aceptada', rejected: 'Rechazada' }
const typeNames: Record<FollowupType, string> = { sent: 'Envío registrado', response: 'Respuesta del cliente', accepted: 'Marcada como aceptada', rejected: 'Marcada como rechazada', note: 'Nota' }
const channelNames: Record<FollowupChannel, string> = { whatsapp: 'WhatsApp', email: 'Correo electrónico', in_person: 'En persona', other: 'Otro' }
const actionNames: Record<FollowupType, string> = { sent: 'Registrar envío', response: 'Registrar respuesta', accepted: 'Marcar aceptada', rejected: 'Marcar rechazada', note: 'Agregar nota' }
const { data: response, refresh } = await useFetch<{ data: FollowupOverview }>(() => `/api/backend/quotes/${props.quoteId}/followups`)
const overview = computed(() => response.value?.data)
const status = computed(() => overview.value?.commercial_status ?? 'not_sent')
const isFinal = computed(() => status.value === 'accepted' || status.value === 'rejected')
const actions = computed<FollowupType[]>(() => isFinal.value ? ['note'] : status.value === 'not_sent' ? ['sent', 'note'] : ['sent', 'response', 'accepted', 'rejected', 'note'])
function localNow(): string {
  const now = new Date()
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset())
  return now.toISOString().slice(0, 19)
}
const type = ref<FollowupType>('sent')
const channel = ref<FollowupChannel>('whatsapp')
const occurredAt = ref(localNow())
const maxAt = ref(localNow())
const note = ref('')
const confirming = ref(false)
const saving = ref(false)
const errors = ref<string[]>([])
watch(actions, (list) => { if (!list.includes(type.value)) type.value = list[0]! }, { immediate: true })
watch(type, () => { confirming.value = false })
const noteRequired = computed(() => type.value === 'rejected')
const needsConfirmation = computed(() => type.value === 'accepted' || type.value === 'rejected')
function submit() {
  if (saving.value) return
  if (needsConfirmation.value && !confirming.value) { confirming.value = true; return }
  void save()
}
async function save() {
  saving.value = true
  errors.value = []
  try {
    const trimmed = note.value.trim()
    await $fetch(`/api/backend/quotes/${props.quoteId}/followups`, {
      method: 'POST', retry: 0,
      body: { type: type.value, occurred_at: new Date(occurredAt.value).toISOString(), ...(type.value === 'sent' ? { channel: channel.value } : {}), ...(trimmed ? { note: trimmed } : {}) },
    })
    note.value = ''
    confirming.value = false
    occurredAt.value = localNow()
    maxAt.value = localNow()
    await refresh()
  } catch (error) { errors.value = errorMessages(error); confirming.value = false }
  finally { saving.value = false }
}
</script>
<template>
  <section class="panel followups" aria-label="Seguimiento comercial">
    <div class="followups-head"><h2>Seguimiento comercial</h2><span class="draft-badge" data-testid="commercial-status">{{ statusNames[status] }}</span></div>
    <p class="muted">El sistema no envía nada al cliente. El envío se hace por fuera (WhatsApp, correo, en persona u otro) y aquí solo se registra lo ocurrido.</p>
    <ol v-if="overview?.followups.length" class="followup-timeline">
      <li v-for="event in overview.followups" :key="event.id">
        <strong>{{ typeNames[event.type] || event.type }}<template v-if="event.channel"> · {{ channelNames[event.channel] || event.channel }}</template></strong>
        <small>{{ dateLabel(event.occurred_at) }} · {{ event.created_by || 'Usuario' }}</small>
        <p v-if="event.note" class="preserve-lines">{{ event.note }}</p>
      </li>
    </ol>
    <p v-else class="muted">Aún no hay eventos de seguimiento registrados.</p>
    <form v-if="overview?.can_record_followup" class="followup-form" @submit.prevent="submit">
      <h3>Registrar evento</h3>
      <p v-if="isFinal" class="muted">La cotización tiene un resultado final; solo se admiten notas.</p>
      <label>Acción<select v-model="type"><option v-for="action in actions" :key="action" :value="action">{{ actionNames[action] }}</option></select></label>
      <label v-if="type === 'sent'">Canal del envío<select v-model="channel" required><option v-for="(name, key) in channelNames" :key="key" :value="key">{{ name }}</option></select></label>
      <label>Fecha y hora del evento<input v-model="occurredAt" type="datetime-local" step="1" required :max="maxAt"></label>
      <label>Nota{{ noteRequired ? ' (obligatoria)' : ' (opcional)' }}<textarea v-model="note" maxlength="1000" rows="3" :required="noteRequired" placeholder="Qué pasó, qué acordaron…" /></label>
      <small class="muted">{{ note.length }}/1000</small>
      <p class="notice" role="note">La nota no admite números largos (8 o más dígitos seguidos, incluidas fechas numéricas pegadas como 20260925). No incluyas datos bancarios.</p>
      <div v-if="confirming" class="notice error" role="alert"><strong>{{ type === 'accepted' ? 'Vas a marcar la cotización como aceptada.' : 'Vas a marcar la cotización como rechazada.' }}</strong><p>Es un resultado final: después solo podrás agregar notas.</p></div>
      <div v-if="errors.length" class="notice error" role="alert"><p v-for="message in errors" :key="message">{{ message }}</p></div>
      <div class="followup-actions">
        <button v-if="confirming" type="button" class="button secondary" :disabled="saving" @click="confirming = false">Cancelar</button>
        <button class="button primary" :disabled="saving">{{ saving ? 'Guardando…' : confirming ? 'Confirmar resultado final' : actionNames[type] }}</button>
      </div>
    </form>
  </section>
</template>
<style scoped>
.followups{margin-top:22px;display:grid;gap:14px}.followups-head{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.followup-timeline{list-style:none;display:grid;gap:0;padding:0;margin:0}.followup-timeline li{display:grid;gap:4px;padding:12px 0;border-bottom:1px solid var(--line);font-size:12px;line-height:1.6;overflow-wrap:anywhere}.followup-timeline small{color:var(--muted)}.followup-form{display:grid;gap:12px;border-top:1px solid var(--line);padding-top:16px}.followup-form h3{font-size:13px}.followup-actions{display:flex;gap:10px;flex-wrap:wrap;justify-content:flex-end}
</style>
