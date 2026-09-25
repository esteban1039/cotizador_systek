import { test, expect } from '@playwright/test'
import { createHmac, randomUUID } from 'node:crypto'
import { authenticate, origin } from './auth-helper'

// Secrets are held only in memory. Never attach DOM snapshots, requests or videos.
process.env.PLAYWRIGHT_NO_COPY_PROMPT = '1'
test.use({ trace: 'off', screenshot: 'off', video: 'off' })
test.setTimeout(300000)

/** Independent RFC 6238 authenticator for the isolated E2E account only. */
function totp(secret: string, timestamp = Date.now()): string {
  const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'
  let bits = ''
  for (const character of secret.toUpperCase().replace(/=+$/, '')) {
    const value = alphabet.indexOf(character)
    if (value < 0) throw new Error('El secreto de prueba no tiene formato Base32.')
    bits += value.toString(2).padStart(5, '0')
  }
  const key = Buffer.from((bits.match(/.{8}/g) ?? []).map(byte => parseInt(byte, 2)))
  const counter = Buffer.alloc(8)
  counter.writeBigUInt64BE(BigInt(Math.floor(timestamp / 30000)))
  const digest = createHmac('sha1', key).update(counter).digest()
  const offset = digest[digest.length - 1]! & 15
  return ((digest.readUInt32BE(offset) & 0x7fffffff) % 1000000).toString().padStart(6, '0')
}

test('MFA exige segundo factor, impide reutilizar recuperación y permite desactivación verificada', async ({ page, context, playwright }) => {
  const admin = await playwright.request.newContext({ baseURL: origin })
  const credentials = { email: `e2e-mfa-${randomUUID()}@example.test`, password: randomUUID() + '-Aa7' }
  let userId: number | undefined
  try {
    await authenticate(admin)
    const created = await admin.post('/api/backend/users', {
      headers: { Origin: origin }, data: { ...credentials, name: 'MFA E2E aislado', role: 'quoter' },
    })
    expect(created.status()).toBe(201)
    userId = (await created.json()).data.id
    await authenticate(context.request, credentials)

    await page.goto('/cuenta')
    await page.getByLabel('Contraseña actual para configurar').fill(credentials.password)
    const [setup] = await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/backend/auth/mfa/setup')),
      page.getByRole('button', { name: 'Configurar verificación en dos pasos' }).click(),
    ])
    expect(setup.status()).toBe(200)
    expect(setup.headers()['cache-control']).toContain('no-store')
    const secret = await page.getByTestId('mfa-secret').inputValue()
    expect(typeof secret === 'string' && secret.length >= 16).toBe(true)
    const confirmationStep = Math.floor(Date.now() / 30000)
    await page.getByLabel('Código de la aplicación autenticadora').fill(totp(secret))
    const [confirmed] = await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/backend/auth/mfa/confirm')),
      page.getByRole('button', { name: 'Confirmar activación' }).click(),
    ])
    expect(confirmed.status()).toBe(200)
    await expect(page.getByRole('heading', { name: 'Guarda tus códigos de recuperación' })).toBeVisible()
    const recoveryCodes = await page.getByTestId('mfa-recovery-code').allTextContents()
    expect(Array.isArray(recoveryCodes) && recoveryCodes.length >= 2).toBe(true)
    const persisted = await page.evaluate(async () => {
      const content = [JSON.stringify(localStorage), JSON.stringify(sessionStorage)]
      for (const name of await caches.keys()) {
        const cache = await caches.open(name)
        for (const request of await cache.keys()) content.push(await (await cache.match(request))!.text())
      }
      return content.join('\n')
    })
    expect([secret, ...recoveryCodes].some(value => persisted.includes(value))).toBe(false)
    expect((await context.request.get('/api/backend/auth/me')).status()).toBe(401)
    // A reload must discard the one-time recovery screen and revoke access.
    await page.reload()
    await expect(page).toHaveURL(/\/login$/)
    await expect(page.getByTestId('mfa-recovery-code')).toHaveCount(0)
    await page.getByLabel('Correo electrónico').fill(credentials.email)
    await page.getByLabel('Contraseña', { exact: true }).fill(credentials.password)
    async function submitLogin() {
      const [response] = await Promise.all([
        page.waitForResponse(response => response.url().endsWith('/api/backend/auth/login')),
        page.getByRole('button', { name: 'Iniciar sesión' }).click(),
      ])
      return response
    }
    let passwordOnly = await submitLogin()
    if (passwordOnly.status() === 429) {
      await new Promise(resolve => setTimeout(resolve, 60000))
      passwordOnly = await submitLogin()
    }
    expect(passwordOnly.status()).toBe(422)
    expect((await context.cookies()).some(cookie => cookie.name === 'systek_session')).toBe(false)
    await expect(page.getByLabel('Código de verificación')).toBeVisible()
    await page.getByLabel('Código de verificación').fill(recoveryCodes[0]!)
    let recoveryLogin = await submitLogin()
    if (recoveryLogin.status() === 429) {
      await new Promise(resolve => setTimeout(resolve, 60000))
      await page.getByLabel('Código de verificación').fill(recoveryCodes[0]!)
      recoveryLogin = await submitLogin()
    }
    expect(recoveryLogin.status()).toBe(200)
    await expect(page).toHaveURL(origin + '/')
    const status = await context.request.get('/api/backend/auth/mfa')
    expect((await status.json()).enabled).toBe(true)
    const me = await context.request.get('/api/backend/auth/me')
    const serializedUser = JSON.stringify(await me.json())
    expect(serializedUser.includes(secret) || recoveryCodes.some(code => serializedUser.includes(code))).toBe(false)
    await page.getByRole('button', { name: 'Cerrar sesión' }).click()
    async function apiLogin(code: () => string) {
      const send = () => context.request.post('/api/backend/auth/login', {
        headers: { Origin: origin }, data: { ...credentials, code: code() },
      })
      let response = await send()
      if (response.status() === 429) {
        await new Promise(resolve => setTimeout(resolve, 60000))
        response = await send()
      }
      return response
    }
    const reused = await apiLogin(() => recoveryCodes[0]!)
    expect(reused.status()).toBe(422)
    expect((await context.request.get('/api/backend/auth/me')).status()).toBe(401)

    // Activation consumed its time step. Wait for a fresh real authenticator code.
    const nextStep = (confirmationStep + 1) * 30000 + 1100
    if (Date.now() < nextStep) await new Promise(resolve => setTimeout(resolve, nextStep - Date.now()))
    const verified = await apiLogin(() => totp(secret))
    expect(verified.status()).toBe(200)
    await page.goto('/cuenta')
    await page.getByText('Desactivar verificación en dos pasos', { exact: true }).click()
    await page.getByLabel('Contraseña actual para desactivar').fill(credentials.password)
    await page.getByLabel('Código de verificación para desactivar').fill(recoveryCodes[1]!)
    const [disabled] = await Promise.all([
      page.waitForResponse(response => response.url().endsWith('/api/backend/auth/mfa/disable')),
      page.getByRole('button', { name: 'Desactivar y cerrar sesiones' }).click(),
    ])
    expect(disabled.status()).toBe(200)
    await expect(page).toHaveURL(/\/login$/)
    expect((await context.request.get('/api/backend/auth/me')).status()).toBe(401)
    await authenticate(context.request, credentials)
    const finalStatus = await context.request.get('/api/backend/auth/mfa')
    expect((await finalStatus.json()).enabled).toBe(false)
  } finally {
    if (userId !== undefined) {
      const deactivated = await admin.patch(`/api/backend/users/${userId}`, {
        headers: { Origin: origin }, data: { active: false, role: 'quoter' },
      })
      expect(deactivated.status()).toBe(200)
    }
    await admin.dispose()
  }
})
