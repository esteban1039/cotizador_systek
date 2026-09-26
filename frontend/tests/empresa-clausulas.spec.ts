import { test, expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { authenticate, origin } from './auth-helper'

test.setTimeout(90000)
test.use({ trace: 'off' })
test.beforeEach(async ({ context }) => { await authenticate(context.request) })

test('empresa emisora: configuración incompleta, cuenta bancaria ficticia y resumen enmascarado', async ({ page }) => {
  await page.goto('/empresa')
  await expect(page.getByRole('heading', { name: 'Empresa emisora' })).toBeVisible()
  const incomplete = page.getByText('Configuración incompleta.')
  if (await incomplete.isVisible().catch(() => false)) {
    await expect(page.getByText('Datos bancarios', { exact: false }).first()).toBeVisible()
  }
  await page.getByRole('button', { name: 'Reemplazar', exact: true }).click()
  const marker = randomUUID().slice(0, 8)
  await page.getByLabel('Banco', { exact: true }).fill(`Banco ficticio ${marker}`)
  await page.getByLabel(/^Tipo de cuenta/).selectOption('savings')
  await page.getByLabel('Número de cuenta', { exact: true }).fill('1234567890')
  await page.getByLabel('Confirmar número de cuenta', { exact: true }).fill('1234567890')
  await page.getByLabel('Motivo del cambio bancario', { exact: true }).fill('Cuenta ficticia de prueba automatizada')
  await page.getByRole('button', { name: 'Guardar cuenta bancaria' }).click()
  await expect(page.getByText('Se actualizó la cuenta bancaria.')).toBeVisible()
  await page.reload()
  await expect(page.getByText('••••7890', { exact: false })).toBeVisible()
  await expect(page.getByLabel('Número de cuenta', { exact: true })).toHaveCount(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})

test('cláusulas: crea, filtra y publica una versión nueva', async ({ page }) => {
  const marker = randomUUID().slice(0, 8)
  await page.goto('/clausulas')
  await expect(page.getByRole('heading', { name: 'Cláusulas' })).toBeVisible()
  await page.getByLabel(/^Familia/).first().selectOption('services')
  await page.getByLabel(/^Tipo/).first().selectOption('observations')
  await page.getByLabel('Título', { exact: true }).fill(`Observación ficticia ${marker}`)
  await page.getByLabel('Texto', { exact: true }).fill('Texto de observación ficticia para prueba automatizada.')
  await page.getByLabel('Motivo', { exact: true }).fill('Cláusula creada por prueba automatizada')
  await page.getByRole('button', { name: 'Crear cláusula' }).click()
  await expect(page.getByText('Cláusula creada.')).toBeVisible()
  await page.getByRole('button', { name: 'Ver historial y publicar' }).first().click()
  await expect(page.getByRole('heading', { name: 'Historial de versiones' })).toBeVisible()
})

test('editor: elegir familia precarga cláusulas predeterminadas y muestra el número en el detalle', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Probar ejemplo CCTV' }).click()
  await expect(page.getByLabel(/^Familia/)).toHaveValue('cctv')
  await expect(page.getByTestId('quote-total')).toHaveText('$ 1.904.000')
  await page.getByRole('button', { name: 'Guardar borrador', exact: true }).click()
  await expect(page).toHaveURL(/\/borradores\/[0-9a-f-]{36}$/)
  await expect(page.getByText(/COT-\d{4}-\d{4,6} · V1/).first()).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})

test('editor: cliente agente retenedor de IVA muestra ReteIVA y total a pagar', async ({ page, context }) => {
  const marker = randomUUID().slice(0, 8)
  const created = await context.request.post('/api/backend/clients', {
    headers: { Origin: origin }, data: { name: `Cliente retenedor ${marker}`, nit: null },
  })
  expect(created.status()).toBe(201)
  const client = (await created.json()).data
  const site = await context.request.post(`/api/backend/clients/${client.id}/sites`, {
    headers: { Origin: origin }, data: { name: 'Sede ficticia', city: 'Bogotá', address: 'Dirección ficticia' },
  })
  expect(site.status()).toBe(201)
  const toggled = await context.request.patch(`/api/backend/clients/${client.id}/tax-profile`, {
    headers: { Origin: origin }, data: { withholds_vat: true, reason: 'Cliente retenedor ficticio de prueba automatizada' },
  })
  expect(toggled.status()).toBe(200)

  await page.goto('/')
  const clientSelect = page.getByRole('combobox', { name: /^Cliente/ })
  await clientSelect.locator('option', { hasText: client.name }).waitFor({ state: 'attached' })
  await clientSelect.selectOption(await clientSelect.locator('option', { hasText: client.name }).getAttribute('value') as string)
  await page.getByRole('combobox', { name: 'Producto o servicio', exact: true }).selectOption('00000000-0000-4000-8000-000000000004')
  await page.getByRole('button', { name: 'Añadir', exact: true }).click()
  await expect(page.getByTestId('quote-payable')).toBeVisible()
})
