<script setup lang="ts">
const auth = useAuth()
const email = ref('')
const password = ref('')
const code = ref('')
const needsCode = ref(false)
const codeInput = ref<HTMLInputElement | null>(null)
const busy = ref(false)
const error = ref('')
watch(email, () => { needsCode.value = false; code.value = ''; error.value = '' })
onBeforeUnmount(() => { password.value = ''; code.value = '' })
async function submit() {
  busy.value = true; error.value = ''
  try {
    await auth.login(email.value, password.value, code.value.trim())
    password.value = ''; code.value = ''
    await navigateTo(auth.user.value?.mfa_enrollment_required ? '/cuenta' : auth.user.value?.role === 'approver' ? '/borradores' : '/')
  } catch (failure: any) {
    const codeErrors = failure.data?.data?.errors?.code
    if (codeErrors) {
      needsCode.value = true
      error.value = codeErrors.join(' ')
      code.value = ''
      await nextTick()
      codeInput.value?.focus()
    } else error.value = failure.data?.message ?? 'No se pudo iniciar sesión. Revisa tus datos y vuelve a intentar.'
  }
  finally { busy.value = false }
}
</script>
<template>
  <section class="access-card panel">
    <p class="eyebrow">ESPACIO COMERCIAL</p><h1>Bienvenido a <span>JARVIS</span></h1>
    <p class="muted">Inicia sesión con tu cuenta Systek para preparar y revisar cotizaciones.</p>
    <form class="field-stack access-form" @submit.prevent="submit">
      <label>Correo electrónico<input v-model="email" type="email" autocomplete="username" required autofocus></label>
      <label>Contraseña<input v-model="password" type="password" autocomplete="current-password" required></label>
      <label v-if="needsCode">Código de verificación<input ref="codeInput" v-model="code" type="text" autocomplete="one-time-code" autocapitalize="off" spellcheck="false" maxlength="64" required aria-describedby="mfa-login-help"><span id="mfa-login-help" class="muted">Ingresa el código de tu aplicación autenticadora o un código de recuperación.</span></label>
      <p v-if="error" class="notice error" role="alert">{{ error }}</p>
      <button class="button primary full-width" :disabled="busy">{{ busy ? 'Ingresando…' : 'Iniciar sesión' }}</button>
    </form>
  </section>
</template>
