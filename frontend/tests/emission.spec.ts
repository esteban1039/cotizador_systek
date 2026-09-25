import { test, expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { authenticate, origin } from './auth-helper'

test.setTimeout(90000)
test.use({ trace: 'off' })
test.beforeEach(async ({ context }) => { await authenticate(context.request) })

test('el proxy solo admite las rutas de emisión con UUID, origen y JSON', async ({ context }) => {
  const id = randomUUID()
  const noOrigin = await context.request.post(`/api/backend/quotes/${id}/issue`, { data: { reason: 'Prueba sin origen' } })
  expect(noOrigin.status()).toBe(403)
  const badPath = await context.request.post('/api/backend/quotes/no-es-uuid/issue', { headers: { Origin: origin }, data: { reason: 'Prueba ruta inválida' } })
  expect(badPath.status()).toBe(404)
  const wrongMethod = await context.request.get(`/api/backend/quotes/${id}/issue`)
  expect(wrongMethod.status()).toBe(404)
  const patch = await context.request.patch(`/api/backend/quotes/${id}/official-pdf`, { headers: { Origin: origin }, data: {} })
  expect(patch.status()).toBe(404)
  const notIssued = await context.request.get(`/api/backend/quotes/${id}/official-pdf`)
  expect(notIssued.status()).toBe(404)
})

test('empresa: el interruptor de autorización adicional se publica con su motivo', async ({ page }) => {
  await page.goto('/empresa')
  const toggle = page.getByRole('switch', { name: 'Exigir autorización adicional al emitir' })
  await expect(toggle).toBeVisible()
  await expect(page.getByText('La aprobación interna siempre es obligatoria.', { exact: false })).toBeVisible()
  const initial = await toggle.isChecked()
  await toggle.setChecked(!initial)
  await page.getByLabel('Motivo', { exact: true }).first().fill('Cambio de política de emisión de prueba')
  await page.getByRole('button', { name: 'Publicar versión' }).click()
  await expect(page.getByText('Se publicó una nueva versión de la empresa emisora.')).toBeVisible()
  await page.reload()
  await expect(toggle).toHaveJSProperty('checked', !initial)
  // Restaura el valor original para no afectar otras pruebas.
  await toggle.setChecked(initial)
  await page.getByLabel('Motivo', { exact: true }).first().fill('Restaura política de emisión de prueba')
  await page.getByRole('button', { name: 'Publicar versión' }).click()
  await expect(page.getByText('Se publicó una nueva versión de la empresa emisora.')).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
