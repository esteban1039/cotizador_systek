import { test, expect } from '@playwright/test'
import { authenticate, origin } from './auth-helper'

test.setTimeout(90000)
test.use({ trace: 'off' })
test.beforeEach(async ({ context }) => { await authenticate(context.request) })

test('el proxy del asistente exige origen y solo admite POST', async ({ context }) => {
  const noOrigin = await context.request.post('/api/backend/quotes/assist', { data: { text: 'Instalar ocho cámaras IP en una bodega' } })
  expect(noOrigin.status()).toBe(403)
  const wrongMethod = await context.request.get('/api/backend/quotes/assist')
  expect(wrongMethod.status()).toBe(404)
  const patch = await context.request.patch('/api/backend/quotes/assist', { headers: { Origin: origin }, data: {} })
  expect(patch.status()).toBe(404)
})

test('con el asistente desactivado se informa y el editor no cambia', async ({ page }) => {
  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'Nueva cotización.' })).toBeVisible()
  await page.getByRole('button', { name: 'Usar asistente' }).click()
  await expect(page.getByText('No incluyas nombres, NIT, teléfonos, correos ni datos bancarios; el texto se envía a Anthropic (Claude).')).toBeVisible()
  const propose = page.getByRole('button', { name: 'Proponer borrador' })
  await page.getByLabel('Descripción de la necesidad').fill('corto')
  await expect(propose).toBeDisabled()
  await page.getByLabel('Descripción de la necesidad').fill('Instalar ocho cámaras IP en una bodega con grabador')
  await expect(propose).toBeEnabled()
  await propose.click()
  await expect(page.getByText('El asistente IA no está habilitado.')).toBeVisible()
  await expect(page.getByText('Propuesta generada por IA')).toHaveCount(0)
  await expect(page.getByText('0 partidas')).toBeVisible()
  await expect(page.getByText('Propuesto por IA')).toHaveCount(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})
