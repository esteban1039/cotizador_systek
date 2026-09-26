export default defineNuxtRouteMiddleware(async (to) => {
  const auth = useAuth()
  if (['/login', '/olvide-contrasena', '/restablecer'].includes(to.path)) return
  try { await auth.load() } catch { return }
  if (!auth.user.value) return navigateTo('/login')
  if (auth.user.value.mfa_enrollment_required && to.path !== '/cuenta') return navigateTo('/cuenta')
  const role = auth.user.value.role
  if (['/catalogo', '/usuarios', '/reglas', '/auditoria', '/historicos', '/empresa', '/clausulas'].some(path => to.path.startsWith(path)) && role !== 'admin') return navigateTo('/borradores')
  if ((to.path === '/' || to.path.startsWith('/clientes')) && role === 'approver') return navigateTo('/borradores')
})
