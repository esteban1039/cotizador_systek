<script setup lang="ts">
const email = ref('')
const busy = ref(false)
const sent = ref(false)
const error = ref('')
async function submit() {
  busy.value = true; error.value = ''
  try {
    await $fetch('/api/backend/auth/password/forgot', { method: 'POST', body: { email: email.value } })
    sent.value = true
  } catch (failure: any) {
    error.value = failure.data?.message ?? 'No se pudo enviar la solicitud. Vuelve a intentar.'
  } finally { busy.value = false }
}
</script>
<template>
  <section class="access-card panel">
    <p class="eyebrow">RECUPERAR ACCESO</p><h1>Restablece tu <span>contraseña</span></h1>
    <p v-if="sent" class="notice" role="status">Si el correo está registrado, te enviamos un enlace para restablecer la contraseña. Vale 30 minutos; revisa también la carpeta de spam.</p>
    <form v-else class="field-stack access-form" @submit.prevent="submit">
      <p class="muted">Escribe el correo de tu cuenta Systek y te enviaremos un enlace.</p>
      <label>Correo electrónico<input v-model="email" type="email" autocomplete="username" required autofocus></label>
      <p v-if="error" class="notice error" role="alert">{{ error }}</p>
      <button class="button primary full-width" :disabled="busy">{{ busy ? 'Enviando…' : 'Enviar enlace' }}</button>
    </form>
    <NuxtLink class="muted" to="/login">Volver a iniciar sesión</NuxtLink>
  </section>
</template>
