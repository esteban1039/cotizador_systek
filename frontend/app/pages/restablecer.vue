<script setup lang="ts">
const email = ref('')
const token = ref('')
const password = ref('')
const confirmation = ref('')
const code = ref('')
const needsCode = ref(false)
const busy = ref(false)
const done = ref(false)
const error = ref('')
onMounted(() => {
  // El enlace trae los datos en el fragmento (#), que no llega al servidor ni a los registros.
  const params = new URLSearchParams(location.hash.slice(1))
  email.value = params.get('email') ?? ''
  token.value = params.get('token') ?? ''
  history.replaceState(null, '', location.pathname)
})
onBeforeUnmount(() => { password.value = ''; confirmation.value = ''; code.value = ''; token.value = '' })
async function submit() {
  busy.value = true; error.value = ''
  try {
    await $fetch('/api/backend/auth/password/reset', { method: 'POST', body: { email: email.value, token: token.value, password: password.value, password_confirmation: confirmation.value, ...(code.value.trim() ? { code: code.value.trim() } : {}) } })
    password.value = ''; confirmation.value = ''; code.value = ''; token.value = ''
    done.value = true
  } catch (failure: any) {
    const errors = failure.data?.data?.errors ?? {}
    if (errors.code) { needsCode.value = true; code.value = '' }
    error.value = [...(errors.code ?? []), ...(errors.token ?? []), ...(errors.password ?? [])].join(' ') || failure.data?.message || 'No se pudo restablecer la contraseña. Vuelve a intentar.'
  } finally { busy.value = false }
}
</script>
<template>
  <section class="access-card panel">
    <p class="eyebrow">RECUPERAR ACCESO</p><h1>Nueva <span>contraseña</span></h1>
    <template v-if="done">
      <p class="notice" role="status">Contraseña restablecida. Ya puedes iniciar sesión con la nueva.</p>
      <NuxtLink class="button primary full-width" to="/login">Iniciar sesión</NuxtLink>
    </template>
    <p v-else-if="!token || !email" class="notice error" role="alert">El enlace está incompleto. <NuxtLink to="/olvide-contrasena">Solicita uno nuevo</NuxtLink>.</p>
    <form v-else class="field-stack access-form" @submit.prevent="submit">
      <p class="muted">Cuenta: {{ email }}. Usa al menos 12 caracteres.</p>
      <label>Contraseña nueva<input v-model="password" type="password" autocomplete="new-password" minlength="12" maxlength="128" required autofocus></label>
      <label>Confirma la contraseña<input v-model="confirmation" type="password" autocomplete="new-password" minlength="12" maxlength="128" required></label>
      <label v-if="needsCode">Código de verificación<input v-model="code" type="text" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" maxlength="64" required><span class="muted">Ingresa el código de tu aplicación autenticadora o un código de recuperación.</span></label>
      <p v-if="error" class="notice error" role="alert">{{ error }}</p>
      <button class="button primary full-width" :disabled="busy">{{ busy ? 'Guardando…' : 'Restablecer contraseña' }}</button>
    </form>
  </section>
</template>
