import { test, expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { adminCredentials, authenticate, origin } from './auth-helper'

test.setTimeout(90000)
test.use({ trace: 'off', screenshot: 'off' })

test('inicia sesión y protege cookie, cuenta y cierre de sesión', async ({ page, context }) => {
  await page.goto('/')
  await expect(page).toHaveURL(/\/login$/)
  const credentials = adminCredentials()
  await page.getByLabel('Correo electrónico').fill(credentials.email)
  await page.getByLabel('Contraseña', { exact: true }).fill(credentials.password)
  const [loginResponse] = await Promise.all([
    page.waitForResponse(response => response.url().endsWith('/api/backend/auth/login')),
    page.getByRole('button', { name: 'Iniciar sesión' }).click(),
  ])
  // The mobile project can start within the desktop project's real rate-limit window.
  if (loginResponse.status() === 429) {
    await new Promise(resolve => setTimeout(resolve, 60000))
    await page.getByRole('button', { name: 'Iniciar sesión' }).click()
  }
  await expect(page).toHaveURL(origin + '/')
  const cookies = await context.cookies()
  const session = cookies.find(cookie => cookie.name === 'systek_session')
  expect(Boolean(session?.httpOnly)).toBe(true)
  expect(session?.sameSite).toBe('Strict')
  expect(await page.evaluate(() => document.cookie.includes('systek_session'))).toBe(false)
  const me = await context.request.get('/api/backend/auth/me')
  expect(me.status()).toBe(200)
  expect(Object.hasOwn(await me.json(), 'token')).toBe(false)
  await page.getByRole('link', { name: 'Mi cuenta' }).click()
  await expect(page.getByRole('heading', { name: 'Cambiar contraseña' })).toBeVisible()
  await page.getByRole('button', { name: 'Cerrar sesión' }).click()
  await expect(page).toHaveURL(/\/login$/)
  expect((await context.request.get('/api/backend/auth/me')).status()).toBe(401)
})

test('crea un cliente con sede y contacto', async ({ page, context }) => {
  await authenticate(context.request)
  const suffix = randomUUID().slice(0, 8)
  await page.goto('/clientes')
  await page.getByLabel('Razón social').fill(`Cliente E2E ${suffix}`)
  await page.getByRole('button', { name: 'Crear cliente', exact: true }).click()
  await expect(page.getByLabel('Nombre de sede')).toBeVisible()
  await page.getByLabel('Nombre de sede').fill(`Sede ${suffix}`)
  await page.getByLabel('Ciudad', { exact: true }).fill('Bogotá')
  await page.getByLabel('Dirección (opcional)').fill('Calle de prueba 10')
  await page.getByRole('button', { name: 'Guardar sede' }).click()
  await expect(page.getByText(`Sede ${suffix}`, { exact: true })).toBeVisible()
  await page.getByLabel('Nombre del contacto').fill(`Contacto ${suffix}`)
  await page.getByLabel('Correo electrónico').fill(`${suffix}@example.test`)
  await page.getByRole('button', { name: 'Guardar contacto' }).click()
  await expect(page.getByText(`Contacto ${suffix} · ${suffix}@example.test`)).toBeVisible()
  await page.reload()
  await expect(async () => {
    await page.getByRole('combobox', { name: 'Selecciona un cliente', exact: true }).selectOption({ label: `Cliente E2E ${suffix} · Sin NIT` })
    await expect(page.getByText(`Sede ${suffix}`, { exact: true })).toBeVisible()
  }).toPass()
})

test('publica dos versiones propias y conserva el historial del catálogo', async ({ page, context }) => {
  await authenticate(context.request)
  const sku = `E2E-${randomUUID().slice(0, 8)}`
  await page.goto('/catalogo')
  await page.getByLabel('SKU', { exact: true }).fill(sku)
  await page.getByLabel('Descripción', { exact: true }).fill(`Servicio de prueba ${sku}`)
  await page.getByRole('combobox', { name: 'Familia', exact: true }).selectOption({ index: 1 })
  await page.getByRole('combobox', { name: 'Unidad', exact: true }).selectOption('unidad')
  await page.getByRole('button', { name: 'Crear ítem', exact: true }).click()
  await expect(page.getByLabel('Precio de venta (COP)')).toBeVisible()
  const until = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10)
  for (const amount of ['1000', '1200']) {
    await page.getByLabel('Precio de venta (COP)').fill(amount)
    await page.getByLabel('Costo (COP)').fill('500')
    await page.getByLabel('Impuesto (%)').fill('19')
    await page.getByLabel('Vigente hasta', { exact: true }).fill(until)
    await page.getByLabel('Motivo del cambio').fill(`Publicación de prueba ${amount}`)
    await page.getByRole('button', { name: 'Publicar nueva versión' }).click()
    await expect(page.getByLabel('Precio de venta (COP)')).toHaveValue('')
  }
  await expect(page.getByRole('cell', { name: 'Histórico', exact: true })).toHaveCount(1)
  await expect(page.getByRole('cell', { name: 'Aprobado', exact: true })).toHaveCount(1)
  await page.getByRole('button', { name: 'Desactivar ítem', exact: true }).click()
  await expect(page.getByRole('button', { name: 'Activar ítem', exact: true })).toBeVisible()
})

test('cotizador tiene acceso comercial y rechaza administración', async ({ page, context }) => {
  await authenticate(context.request)
  const credentials = { email: `e2e-${randomUUID()}@example.test`, password: randomUUID() + '-Aa7' }
  const created = await context.request.post('/api/backend/users', { headers: { Origin: origin }, data: { ...credentials, name: 'Cotizador E2E', role: 'quoter' } })
  expect(created.ok()).toBe(true)
  const userId = (await created.json()).data.id
  await context.clearCookies()
  await authenticate(context.request, credentials)
  await page.goto('/catalogo')
  await expect(page).toHaveURL(/\/borradores$/)
  await expect(page.getByRole('link', { name: 'Catálogo', exact: true })).toHaveCount(0)
  await expect(page.getByRole('link', { name: 'Clientes', exact: true })).toBeVisible()
  expect((await context.request.get('/api/backend/users')).status()).toBe(403)
  expect((await context.request.get('/api/backend/admin/catalog')).status()).toBe(403)
  await authenticate(context.request)
  const deactivated = await context.request.patch(`/api/backend/users/${userId}`, { headers: { Origin: origin }, data: { active: false, role: 'quoter' } })
  expect(deactivated.status()).toBe(200)
})

test('aprobador devuelve una cotización con observaciones sin poder cotizar', async ({ page, context }) => {
  await authenticate(context.request)
  await page.goto('/')
  await page.getByRole('button', { name: 'Probar ejemplo CCTV' }).click()
  await expect(page.getByTestId('quote-total')).toHaveText('$ 1.904.000')
  await page.getByRole('button', { name: 'Guardar borrador' }).click()
  await expect(page).toHaveURL(/\/borradores\/[0-9a-f-]{36}$/)
  const detail = new URL(page.url()).pathname
  await page.getByLabel('Motivo de la decisión').fill('Solicito revisión de prueba E2E')
  await page.getByRole('button', { name: 'Enviar a revisión', exact: true }).click()
  await expect(page.getByText('En revisión · No emitido', { exact: true })).toBeVisible()
  const credentials = { email: `e2e-review-${randomUUID()}@example.test`, password: randomUUID() + '-Aa7' }
  const created = await context.request.post('/api/backend/users', { headers: { Origin: origin }, data: { ...credentials, name: 'Aprobador E2E', role: 'approver' } })
  expect(created.ok()).toBe(true)
  const userId = (await created.json()).data.id
  await context.clearCookies()
  await authenticate(context.request, credentials)
  await page.goto('/')
  await expect(page).toHaveURL(/\/borradores$/)
  await expect(page.getByRole('link', { name: 'Nueva cotización', exact: true })).toHaveCount(0)
  await page.goto(detail)
  await page.getByLabel('Motivo de la decisión').fill('Corregir alcance según visita de prueba')
  await page.getByRole('button', { name: 'Devolver con observaciones', exact: true }).click()
  await expect(page.getByText('Borrador · No emitido', { exact: true })).toBeVisible()
  await expect(page.getByText('Corregir alcance según visita de prueba', { exact: true })).toBeVisible()
  await authenticate(context.request)
  expect((await context.request.patch(`/api/backend/users/${userId}`, { headers: { Origin: origin }, data: { active: false, role: 'approver' } })).ok()).toBe(true)
})
