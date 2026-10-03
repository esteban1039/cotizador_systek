<script setup lang="ts">
import { errorMessages } from '~/utils/format'

const emit = defineEmits<{ created: [payload: { clientId: string; siteId: string; name: string; warning?: string }] }>()
const open = ref(false)
const pending = ref(false)
const errors = ref<string[]>([])
const form = reactive({ name: '', nit: '', siteName: '', siteCity: '' })
const nameInput = ref<HTMLInputElement | null>(null)
const trigger = ref<HTMLButtonElement | null>(null)
const dialog = ref<HTMLElement | null>(null)

async function show() {
  Object.assign(form, { name: '', nit: '', siteName: '', siteCity: '' })
  errors.value = []
  open.value = true
  await nextTick()
  nameInput.value?.focus()
}
function close() {
  if (pending.value) return
  open.value = false
  nextTick(() => trigger.value?.focus())
}
function trap(event: KeyboardEvent) {
  if (event.key === 'Escape') { event.stopPropagation(); close(); return }
  if (event.key !== 'Tab' || !dialog.value) return
  const items = Array.from(dialog.value.querySelectorAll<HTMLElement>('input,button:not([disabled])'))
  const first = items[0], last = items[items.length - 1]
  if (!first || !last) return
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus() }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus() }
}
const siteHalf = computed(() => !!form.siteName.trim() !== !!form.siteCity.trim())
async function submit() {
  if (pending.value) return
  errors.value = []
  if (siteHalf.value) { errors.value = ['Para crear la sede indica nombre y ciudad, o deja ambos vacíos.']; return }
  pending.value = true
  let clientId = ''
  try {
    const created = await $fetch<{ data: { id: string } }>('/api/backend/clients', { method: 'POST', retry: 0, body: { name: form.name.trim(), nit: form.nit.trim() || null } })
    clientId = created.data.id
    let siteId = ''
    if (form.siteName.trim()) {
      try {
        const site = await $fetch<{ data: { id: string } }>(`/api/backend/clients/${clientId}/sites`, { method: 'POST', retry: 0, body: { name: form.siteName.trim(), city: form.siteCity.trim() } })
        siteId = site.data.id
      } catch (error) {
        // El cliente ya existe: se selecciona igual y se avisa de la sede.
        emit('created', { clientId, siteId: '', name: form.name.trim(), warning: `No se pudo crear la sede: ${errorMessages(error).join(' ')} Puedes agregarla desde Clientes.` })
        open.value = false
        return
      }
    }
    emit('created', { clientId, siteId, name: form.name.trim() })
    open.value = false
    nextTick(() => trigger.value?.focus())
  } catch (error) {
    errors.value = errorMessages(error)
  } finally { pending.value = false }
}
</script>

<template>
  <button ref="trigger" type="button" class="button secondary quick-create-trigger" @click="show">Nuevo cliente</button>
  <Teleport to="body">
    <div v-if="open" class="quick-create-backdrop" @click.self="close" @keydown="trap">
      <div ref="dialog" class="quick-create-dialog" role="dialog" aria-modal="true" aria-labelledby="quick-client-title">
        <h2 id="quick-client-title">Nuevo cliente</h2>
        <p class="muted">Se guardará de inmediato y quedará seleccionado en la cotización. No pierdes lo que has montado.</p>
        <form class="quick-create-form" @submit.prevent="submit">
          <label>Razón social <span class="required">*</span><input ref="nameInput" v-model="form.name" required maxlength="255" autocomplete="off"></label>
          <label>NIT (opcional)<input v-model="form.nit" inputmode="numeric" maxlength="30" autocomplete="off"></label>
          <fieldset class="quick-create-site"><legend>Sede (opcional)</legend>
            <label>Nombre de la sede<input v-model="form.siteName" maxlength="255" autocomplete="off"></label>
            <label>Ciudad<input v-model="form.siteCity" maxlength="255" autocomplete="off"></label>
          </fieldset>
          <div v-if="errors.length" class="notice error" role="alert"><p v-for="message in errors" :key="message">{{ message }}</p></div>
          <div class="quick-create-actions"><button type="button" class="button secondary" :disabled="pending" @click="close">Cancelar</button><button class="button primary" :disabled="pending || !form.name.trim()">{{ pending ? 'Guardando…' : 'Crear cliente' }}</button></div>
        </form>
      </div>
    </div>
  </Teleport>
</template>
