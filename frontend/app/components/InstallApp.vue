<script setup lang="ts">
interface InstallPrompt extends Event {
  prompt: () => Promise<void>
  userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>
}
const prompt = shallowRef<InstallPrompt | null>(null)
const installed = ref(false)
const offline = ref(false)
const error = ref('')
const capture = (event: Event) => { event.preventDefault(); prompt.value = event as InstallPrompt }
const markInstalled = () => { installed.value = true; prompt.value = null }
const connection = () => { offline.value = !navigator.onLine }
onMounted(() => {
  installed.value = window.matchMedia('(display-mode: standalone)').matches || Boolean((navigator as Navigator & { standalone?: boolean }).standalone)
  connection()
  window.addEventListener('beforeinstallprompt', capture)
  window.addEventListener('appinstalled', markInstalled)
  window.addEventListener('online', connection)
  window.addEventListener('offline', connection)
})
onBeforeUnmount(() => {
  window.removeEventListener('beforeinstallprompt', capture)
  window.removeEventListener('appinstalled', markInstalled)
  window.removeEventListener('online', connection)
  window.removeEventListener('offline', connection)
})
async function install() {
  const pending = prompt.value
  if (!pending) return
  prompt.value = null
  error.value = ''
  try {
    await pending.prompt()
    if ((await pending.userChoice).outcome === 'accepted') installed.value = true
  } catch { error.value = 'Usa el menú del navegador para instalar la aplicación.' }
}
</script>
<template>
  <div v-if="offline" class="notice error" role="status">Sin conexión. Mantén esta página abierta para conservar los datos que estás preparando. Necesitas conexión para guardarlos.</div>
  <details v-if="!installed" class="install-app">
    <summary>Instalar Systek en este dispositivo</summary>
    <p>Abre el menú del navegador y elige «Instalar aplicación» o «Añadir a pantalla de inicio». En iPhone, usa Compartir → Añadir a pantalla de inicio desde Safari.</p>
    <p>Necesitas conexión para trabajar con tus cotizaciones.</p>
    <button v-if="prompt" class="button secondary" @click="install">Instalar aplicación</button>
    <p v-if="error" role="status">{{ error }}</p>
  </details>
</template>
<style scoped>
.install-app { margin: 1rem 2rem; color: #36534f; font-size: .875rem; }
summary { cursor: pointer; }
p { max-width: 48rem; line-height: 1.6; }
</style>
