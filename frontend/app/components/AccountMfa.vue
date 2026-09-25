<script setup lang="ts">
const auth = useAuth()
const enabled = ref(false)
const statusRequired = ref(false)
const required = computed(() => Boolean(auth.user.value?.mfa_required || statusRequired.value))
const remaining = ref(0)
const loaded = ref(false)
const busy = ref(false)
const error = ref('')
const password = ref('')
const code = ref('')
// Secrets stay in this mounted component: no useState, async-data payload or persistent storage.
const secret = ref('')
const expiresAt = ref('')
const recoveryCodes = ref<string[]>([])
const saved = ref(false)
const copied = ref(false)
const secretInput = ref<HTMLInputElement | null>(null)
const recoveryHeading = ref<HTMLElement | null>(null)
const codeInput = ref<HTMLInputElement | null>(null)
function clearInputs() { password.value = ''; code.value = '' }
function message(failure: any) {
  return Object.values(failure.data?.data?.errors ?? {}).flat().join(' ') || failure.data?.message || 'No se pudo actualizar la verificación. Vuelve a intentar.'
}
async function handleFailure(failure: any) {
  if (failure.statusCode === 401 || failure.status === 401) {
    auth.user.value = null
    await navigateTo('/login')
  } else error.value = message(failure)
}
async function load() {
  busy.value = true; error.value = ''
  try {
    const status = await $fetch<{ enabled: boolean; required: boolean; recovery_codes_remaining: number }>('/api/backend/auth/mfa')
    statusRequired.value = status.required; enabled.value = status.enabled; remaining.value = status.recovery_codes_remaining; loaded.value = true
  } catch (failure) { await handleFailure(failure) }
  finally { busy.value = false }
}
async function setup() {
  busy.value = true; error.value = ''
  try {
    const result = await $fetch<{ secret: string; expires_at: string }>('/api/backend/auth/mfa/setup', { method: 'POST', body: { current_password: password.value } })
    secret.value = result.secret; expiresAt.value = result.expires_at; clearInputs()
    await nextTick(); codeInput.value?.focus()
  } catch (failure) { await handleFailure(failure) }
  finally { busy.value = false }
}
async function confirm() {
  busy.value = true; error.value = ''
  try {
    const result = await $fetch<{ recovery_codes: string[] }>('/api/backend/auth/mfa/confirm', { method: 'POST', body: { code: code.value.trim() } })
    recoveryCodes.value = result.recovery_codes; secret.value = ''; expiresAt.value = ''; clearInputs()
    // Preserve this screen until codes are saved; all sessions are already revoked.
    await nextTick(); recoveryHeading.value?.focus()
  } catch (failure) { await handleFailure(failure) }
  finally { busy.value = false }
}
async function disable() {
  busy.value = true; error.value = ''
  try {
    await $fetch('/api/backend/auth/mfa/disable', { method: 'POST', body: { current_password: password.value, code: code.value.trim() } })
    clearInputs(); auth.user.value = null; await navigateTo('/login')
  } catch (failure) { await handleFailure(failure) }
  finally { busy.value = false }
}
async function copyCodes() {
  error.value = ''
  try { await navigator.clipboard.writeText(recoveryCodes.value.join('\n')); copied.value = true }
  catch { error.value = 'No se pudo copiar. Selecciona los códigos y guárdalos manualmente.' }
}
function cancelSetup() { secret.value = ''; expiresAt.value = ''; clearInputs(); error.value = '' }
async function finish() { recoveryCodes.value = []; auth.user.value = null; await navigateTo('/login') }
onMounted(load)
onBeforeUnmount(() => { clearInputs(); secret.value = ''; recoveryCodes.value = [] })
</script>
<template>
  <section class="panel access-account mfa-panel" aria-labelledby="mfa-title" :aria-busy="busy">
    <h2 id="mfa-title">Verificación en dos pasos</h2>
    <p v-if="required && !recoveryCodes.length" data-testid="mfa-required-notice" class="notice">{{ enabled ? 'Tu rol requiere verificación en dos pasos. Debe permanecer activada.' : 'Tu rol requiere verificación en dos pasos. Actívala para poder acceder a las cotizaciones y demás funciones.' }}</p>
    <template v-if="recoveryCodes.length">
      <h3 ref="recoveryHeading" tabindex="-1">Guarda tus códigos de recuperación</h3>
      <p class="muted">La verificación quedó activada y se cerraron todas tus sesiones. Estos códigos se muestran una sola vez. Guarda una copia en un lugar privado; cada código permite un acceso y se utiliza una sola vez.</p>
      <ul class="mfa-codes" aria-label="Códigos de recuperación"><li v-for="item in recoveryCodes" :key="item"><code data-testid="mfa-recovery-code">{{ item }}</code></li></ul>
      <button type="button" class="button secondary" @click="copyCodes">{{ copied ? 'Códigos copiados' : 'Copiar códigos' }}</button>
      <p class="muted">Para volver a ingresar, espera a que cambie el código de tu aplicación autenticadora o usa un código de recuperación.</p>
      <label class="mfa-check"><input v-model="saved" type="checkbox">Guardé mis códigos de recuperación</label>
      <button type="button" class="button primary" :disabled="!saved" @click="finish">Volver a iniciar sesión</button>
    </template>
    <template v-else-if="!loaded">
      <p class="muted">{{ busy ? 'Consultando estado…' : 'No se pudo consultar el estado de verificación.' }}</p>
      <button v-if="!busy" type="button" class="button secondary" @click="load">Reintentar</button>
    </template>
    <template v-else-if="secret">
      <p class="muted">En tu aplicación autenticadora, agrega una cuenta con clave manual y selecciona códigos basados en tiempo (TOTP). Usa tu correo como nombre de cuenta.</p>
      <label class="mfa-key">Clave de configuración<input ref="secretInput" :value="secret" data-testid="mfa-secret" readonly autocomplete="off" spellcheck="false" @focus="secretInput?.select()"></label>
      <p class="muted">No compartas esta clave. La preparación vence {{ new Date(expiresAt).toLocaleTimeString('es-CO', { hour: '2-digit', minute: '2-digit' }) }}. Puedes cancelar y empezar de nuevo.</p>
      <form class="field-stack" @submit.prevent="confirm">
        <label>Código de la aplicación autenticadora<input ref="codeInput" v-model="code" type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required></label>
        <p class="muted">Al confirmar se cerrarán todas tus sesiones y verás tus códigos de recuperación.</p>
        <button class="button primary" :disabled="busy">{{ busy ? 'Confirmando…' : 'Confirmar activación' }}</button>
        <button type="button" class="button secondary" :disabled="busy" @click="cancelSetup">Cancelar configuración</button>
      </form>
    </template>
    <template v-else-if="enabled">
      <p class="muted">Activada. Tienes {{ remaining }} códigos de recuperación disponibles.</p>
      <details v-if="!required"><summary>Desactivar verificación en dos pasos</summary>
        <p class="muted">Al desactivarla se cerrarán todas tus sesiones. Tu próximo acceso solo requerirá la contraseña.</p>
        <form class="field-stack access-form" @submit.prevent="disable">
          <label>Contraseña actual para desactivar<input v-model="password" type="password" autocomplete="current-password" required></label>
          <label>Código de verificación para desactivar<input v-model="code" type="text" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" maxlength="64" required><span class="muted">Código de la aplicación autenticadora o de recuperación.</span></label>
          <button class="button secondary" :disabled="busy">{{ busy ? 'Desactivando…' : 'Desactivar y cerrar sesiones' }}</button>
        </form>
      </details>
    </template>
    <template v-else>
      <p class="muted">Sin activar. Añade un código de tu aplicación autenticadora al iniciar sesión. {{ required ? 'Es obligatorio para tu rol.' : 'Recomendado especialmente para administradores y aprobadores.' }}</p>
      <form class="field-stack access-form" @submit.prevent="setup">
        <label>Contraseña actual para configurar<input v-model="password" type="password" autocomplete="current-password" required></label>
        <button class="button primary" :disabled="busy">{{ busy ? 'Preparando…' : 'Configurar verificación en dos pasos' }}</button>
      </form>
    </template>
    <p v-if="error" class="notice error" role="alert">{{ error }}</p>
  </section>
</template>
<style scoped>
.mfa-panel { margin-top: 24px; display: grid; gap: 18px; }
.mfa-key { display: grid; gap: 8px; }
.mfa-key input { width: 100%; min-width: 0; font-family: monospace; font-size: 14px; }
.mfa-codes { list-style: none; padding: 16px; margin: 0; background: var(--bg); border: 1px solid var(--line); border-radius: 8px; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
.mfa-codes code { overflow-wrap: anywhere; }
.mfa-check { display: flex; align-items: center; gap: 12px; font-size: 14px; }
.mfa-check input { width: 20px; min-height: 20px; flex: none; }
summary { cursor: pointer; margin-bottom: 16px; }
@media (max-width: 400px) { .mfa-codes { grid-template-columns: 1fr; } }
</style>
