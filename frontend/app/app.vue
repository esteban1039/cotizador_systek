<script setup lang="ts">
const route = useRoute()
const publicPage = computed(() => ['/login', '/olvide-contrasena', '/restablecer'].includes(route.path))
const expired = useState<boolean>('session-expired', () => false)
const { user, error, load, logout } = useAuth()
const sessionError = ref('')
const retrying = ref(false)
async function retry() {
  retrying.value = true
  try {
    await load()
    if (!user.value) await navigateTo('/login')
    else if (user.value.mfa_enrollment_required) await navigateTo('/cuenta')
    else if ((['/catalogo', '/usuarios', '/reglas', '/auditoria', '/historicos', '/empresa', '/clausulas', '/conocimiento-ia'].some(path => route.path.startsWith(path)) && user.value.role !== 'admin') || ((route.path === '/' || route.path.startsWith('/clientes')) && user.value.role === 'approver')) await navigateTo('/borradores')
  } catch {} finally { retrying.value = false }
}
async function signOut() {
  sessionError.value = ''
  try { await logout() } catch { sessionError.value = 'No se pudo cerrar la sesión. Vuelve a intentar.' }
}
</script>
<template>
  <div class="app-shell">
    <a class="skip-link" href="#main">Ir al contenido</a>
    <aside v-if="!publicPage" class="sidebar">
      <NuxtLink :to="user?.mfa_enrollment_required ? '/cuenta' : '/'" class="brand" :aria-label="user?.mfa_enrollment_required ? 'Systek, mi cuenta' : 'Systek, nueva cotización'"><span class="brand-mark">s<span>✦</span></span><span>systek<small>COMPANY</small></span></NuxtLink>
      <div class="workspace-label">ESPACIO COMERCIAL</div>
      <nav aria-label="Navegación principal">
        <template v-if="!user?.mfa_enrollment_required">
        <NuxtLink to="/inicio" :class="{ active: route.path === '/inicio' }"><AppIcon name="grid" />Inicio</NuxtLink>
        <NuxtLink v-if="user?.role !== 'approver'" to="/" :class="{ active: route.path === '/' }"><AppIcon name="plus" />Nueva cotización</NuxtLink>
        <NuxtLink to="/borradores" :class="{ active: route.path.startsWith('/borradores') }"><AppIcon name="document" />Borradores</NuxtLink>
        <NuxtLink v-if="user && user.role !== 'approver'" to="/clientes" :class="{ active: route.path.startsWith('/clientes') }">Clientes</NuxtLink>
        <template v-if="user?.role === 'admin'">
          <NuxtLink to="/catalogo" :class="{ active: route.path.startsWith('/catalogo') }">Catálogo</NuxtLink>
          <NuxtLink to="/usuarios" :class="{ active: route.path.startsWith('/usuarios') }">Usuarios</NuxtLink>
          <NuxtLink to="/reglas" :class="{ active: route.path.startsWith('/reglas') }">Reglas comerciales</NuxtLink>
          <NuxtLink to="/historicos" :class="{ active: route.path.startsWith('/historicos') }">Históricos</NuxtLink>
          <NuxtLink to="/empresa" :class="{ active: route.path.startsWith('/empresa') }">Empresa</NuxtLink>
          <NuxtLink to="/clausulas" :class="{ active: route.path.startsWith('/clausulas') }">Cláusulas</NuxtLink>
          <NuxtLink to="/conocimiento-ia" :class="{ active: route.path.startsWith('/conocimiento-ia') }">Conocimiento IA</NuxtLink>
          <NuxtLink to="/auditoria" :class="{ active: route.path.startsWith('/auditoria') }">Auditoría</NuxtLink>
        </template>
        </template>
        <NuxtLink to="/cuenta" :class="{ active: route.path === '/cuenta' }">Mi cuenta</NuxtLink>
      </nav>
      <p v-if="user?.mfa_enrollment_required" data-testid="mfa-enrollment-notice" class="notice">Activa la verificación en dos pasos en Mi cuenta para acceder al espacio comercial.</p>
      <div class="sidebar-note"><span class="spark">✧</span><strong>El detalle hace la diferencia.</strong><p>Propuestas claras, desde la primera partida.</p></div>
      <div class="workspace-user"><span class="avatar">ST</span><span>{{ user?.name }}<small>{{ user?.role === 'admin' ? 'Administrador' : user?.role === 'approver' ? 'Aprobador' : 'Cotizador' }}</small></span><span class="status-dot" /></div>
      <button class="access-logout" @click="signOut">Cerrar sesión</button>
      <p v-if="sessionError" class="access-session-error" role="alert">{{ sessionError }}</p>
    </aside>
    <div class="main-shell" :class="{ 'access-public': publicPage }">
      <header class="topbar"><span class="product-name">JARVIS <span>/</span> Cotizador</span><span class="demo-badge"><span />Modo de prueba</span></header>
      <ClientOnly><InstallApp /></ClientOnly>
      <main id="main"><div v-if="expired && !publicPage" class="notice error" role="alert">Tu sesión venció. Conserva los datos que estás preparando y vuelve a iniciar sesión. <NuxtLink class="button secondary" to="/login">Iniciar sesión</NuxtLink></div><div v-if="error && !publicPage" class="notice error" role="alert">{{ error }}<br><button class="button secondary" :disabled="retrying" @click="retry">Reintentar</button></div><NuxtPage v-else /></main>
      <footer class="page-footer">Systek Company <span>Claridad en cada propuesta.</span></footer>
    </div>
  </div>
</template>
