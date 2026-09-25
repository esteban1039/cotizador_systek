<script setup lang="ts">
const auth = useAuth()
const form = reactive({ current_password: '', password: '', password_confirmation: '' })
const busy = ref(false)
const error = ref('')
async function save() {
  busy.value = true; error.value = ''
  try {
    await $fetch('/api/backend/auth/password', { method: 'POST', body: form })
    auth.user.value = null
    await navigateTo('/login')
  } catch (failure: any) {
    if (failure.statusCode === 401) { auth.user.value = null; await navigateTo('/login'); return }
    error.value = Object.values(failure.data?.data?.errors ?? {}).flat().join(' ') || failure.data?.message || 'No se pudo cambiar la contraseña. Vuelve a intentar.'
  } finally { busy.value = false }
}
</script>
<template>
  <div class="page-heading"><div><p class="eyebrow">ACCESO PERSONAL</p><h1>Mi <span>cuenta</span></h1><p>{{ auth.user.value?.name }} · {{ auth.user.value?.email }}</p></div></div>
  <section class="panel access-account"><h2>Cambiar contraseña</h2><p class="muted">Al guardar se cerrarán tus sesiones. Vuelve a ingresar con la nueva contraseña.</p>
    <form class="field-stack access-form" @submit.prevent="save">
      <label>Contraseña actual<input v-model="form.current_password" type="password" autocomplete="current-password" required></label>
      <label>Nueva contraseña<input v-model="form.password" type="password" autocomplete="new-password" minlength="12" required></label>
      <label>Confirmar nueva contraseña<input v-model="form.password_confirmation" type="password" autocomplete="new-password" minlength="12" required></label>
      <p v-if="error" class="notice error" role="alert">{{ error }}</p><button class="button primary" :disabled="busy">{{ busy ? 'Guardando…' : 'Cambiar contraseña' }}</button>
    </form>
  </section>
  <AccountMfa />
</template>
