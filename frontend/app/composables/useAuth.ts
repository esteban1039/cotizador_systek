export interface AuthUser { id: number; name: string; email: string; role: 'admin' | 'quoter' | 'approver'; active: boolean; mfa_enabled?: boolean; mfa_required?: boolean; mfa_enrollment_required?: boolean }
export function useAuth() {
  const expired = useState<boolean>('session-expired', () => false)
  const user = useState<AuthUser | null>('auth-user', () => null)
  const error = useState<string>('auth-error', () => '')
  const request = useRequestFetch()
  async function load() {
    error.value = ''
    try { user.value = (await request<{ data: AuthUser }>('/api/backend/auth/me')).data }
    catch (failure: any) {
      if (failure.statusCode === 401 || failure.status === 401) user.value = null
      else { error.value = 'No se pudo verificar tu sesión. Comprueba la conexión y vuelve a intentar.'; throw failure }
    }
    return user.value
  }
  async function login(email: string, password: string, code?: string) {
    const result = await $fetch<{ user: AuthUser }>('/api/backend/auth/login', { method: 'POST', body: { email, password, ...(code ? { code } : {}) } })
    user.value = result.user; error.value = ''; expired.value = false
  }
  async function logout() {
    try { await $fetch('/api/backend/auth/logout', { method: 'POST', body: {} }) }
    catch (failure: any) { if (failure.statusCode !== 401 && failure.status !== 401) throw failure }
    user.value = null
    await navigateTo('/login')
  }
  return { user, error, load, login, logout }
}
