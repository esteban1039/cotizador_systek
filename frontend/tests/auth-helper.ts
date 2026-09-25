import { readFileSync, statSync } from 'node:fs'
import type { APIRequestContext } from '@playwright/test'
export const origin = 'http://127.0.0.1:3001'
export function adminCredentials() {
  const path = process.env.E2E_CREDENTIALS_PATH || new URL('../../backend/storage/app/private/e2e-isolated-access.json', import.meta.url)
  let credentials: { email?: unknown; password?: unknown }
  try {
    if ((statSync(path).mode & 0o077) !== 0) throw new Error('Permisos inseguros')
    credentials = JSON.parse(readFileSync(path, 'utf8'))
  } catch {
    throw new Error('Configura E2E_CREDENTIALS_PATH con un archivo privado 0600 o ejecuta systek:create-e2e-admin.')
  }
  const { email, password } = credentials
  if (typeof email !== 'string' || typeof password !== 'string' || !email || !password) {
    throw new Error('El archivo privado E2E debe contener email y password.')
  }
  return { email, password }
}
export async function authenticate(request: APIRequestContext, credentials = adminCredentials()) {
  let result = await request.post('/api/backend/auth/login', { headers: { Origin: origin }, data: credentials })
  // A full two-device suite can exceed the real login rate limit. Respect its window.
  if (result.status() === 429) {
    await new Promise(resolve => setTimeout(resolve, 60000))
    result = await request.post('/api/backend/auth/login', { headers: { Origin: origin }, data: credentials })
  }
  if (result.status() !== 200) throw new Error(`Inicio de sesión falló (${result.status()}).`)
}
