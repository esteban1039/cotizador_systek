import { test, expect, type APIRequestContext } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { authenticate, origin } from './auth-helper'

test.setTimeout(90000)
test.use({ trace: 'off' })
test.beforeEach(async ({ context }) => { await authenticate(context.request) })

async function readQuote(request: APIRequestContext, id: string) {
  const response = await request.get(`/api/backend/quotes/${id}`)
  expect(response.status()).toBe(200)
  return (await response.json()).data
}

function snapshot(quote: Awaited<ReturnType<typeof readQuote>>) {
  return {
    id: quote.id, scope: quote.scope, lines: quote.lines, totals: quote.totals,
    client_id: quote.client_id, site_id: quote.site_id,
    exclusions: quote.exclusions, payment_terms: quote.payment_terms,
    warranty: quote.warranty, valid_until: quote.valid_until, created_at: quote.created_at,
  }
}

test('crea una revisión inmutable y navega entre las versiones guardadas', async ({ page, context }) => {
  const marker = randomUUID().slice(0, 8)
  const originalScope = `Alcance original de prueba ${marker}: ocho cámaras.`
  const revisedScope = `Alcance revisado de prueba ${marker}: tres cámaras.`
  await page.goto('/')
  await page.getByRole('button', { name: 'Probar ejemplo CCTV' }).click()
  await expect(page.getByLabel('Cantidad partida 1', { exact: true })).toHaveValue('8')
  await page.getByRole('textbox', { name: 'Alcance *', exact: true }).fill(originalScope)
  await expect(page.getByTestId('quote-total')).toHaveText('$ 1.904.000')
  await page.getByRole('button', { name: 'Guardar borrador', exact: true }).click()
  await expect(page).toHaveURL(/\/borradores\/[0-9a-f-]{36}$/)
  const originalPath = new URL(page.url()).pathname
  const originalId = originalPath.split('/').at(-1)!
  const original = snapshot(await readQuote(context.request, originalId))

  await page.getByRole('link', { name: 'Crear nueva revisión', exact: true }).click()
  await expect(page).toHaveURL(new RegExp(`\\?revise=${originalId}$`))
  await expect(page.getByRole('textbox', { name: 'Alcance *', exact: true })).toHaveValue(originalScope)
  await expect(page.getByLabel('Cantidad partida 1', { exact: true })).toHaveValue('8')
  await page.getByRole('textbox', { name: 'Alcance *', exact: true }).fill(revisedScope)
  await page.getByLabel('Cantidad partida 1', { exact: true }).fill('3')
  await expect(page.getByTestId('quote-total')).toHaveText('$ 714.000')
  await page.getByRole('button', { name: 'Guardar nueva revisión', exact: true }).click()
  await expect(page).toHaveURL(/\/borradores\/[0-9a-f-]{36}$/)
  const revisedPath = new URL(page.url()).pathname
  expect(revisedPath).not.toBe(originalPath)
  const revised = await readQuote(context.request, revisedPath.split('/').at(-1)!)
  expect(revised.revision_number).toBe(2)
  expect(revised.previous_quote_id).toBe(originalId)
  expect(revised.revisions.map((revision: { id: string }) => revision.id)).toEqual(expect.arrayContaining([originalId, revised.id]))
  expect(revised.scope).toBe(revisedScope)
  expect(revised.lines[0].quantity).toBe('3')
  expect(snapshot(await readQuote(context.request, originalId))).toEqual(original)
  await expect(page.getByText(revisedScope, { exact: true })).toBeVisible()
  await expect(page.getByTestId('quote-total')).toHaveText('$ 714.000')

  await page.locator(`a[href="${originalPath}"]`).click()
  await expect(page).toHaveURL(origin + originalPath)
  await expect(page.getByText(originalScope, { exact: true })).toBeVisible()
  await expect(page.getByTestId('quote-total')).toHaveText('$ 1.904.000')
  await page.locator(`a[href="${revisedPath}"]`).click()
  await expect(page.getByText(revisedScope, { exact: true })).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})

async function createRecord(request: APIRequestContext, path: string, data: Record<string, unknown>) {
  const response = await request.post(`/api/backend/${path}`, { headers: { Origin: origin }, data })
  expect(response.status()).toBe(201)
  return (await response.json()).data
}

test('exige aceptar el precio vigente para revisar una partida histórica', async ({ page, context }) => {
  const marker = randomUUID().slice(0, 8)
  const client = await createRecord(context.request, 'clients', { name: `Cliente revisión E2E ${marker}`, nit: null })
  const site = await createRecord(context.request, `clients/${client.id}/sites`, { name: 'Sede de prueba', city: 'Bogotá', address: 'Dirección ficticia' })
  const item = await createRecord(context.request, 'admin/catalog', {
    sku: `REV-E2E-${marker}`, description: `Servicio ficticio revisión ${marker}`, family: 'services', unit: 'servicio',
  })
  try {
    const priceInput = {
      cost: '500.00', tax_bps: 1900, valid_until: new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10),
      reason: 'Precio ficticio para prueba automatizada de revisiones',
    }
    const firstPrice = await createRecord(context.request, `admin/catalog/${item.id}/prices`, { ...priceInput, price: '1000.00' })
    const original = await createRecord(context.request, 'quotes', {
      client_id: client.id, site_id: site.id, lines: [{ price_version_id: firstPrice.id, quantity: '2', discount_bps: 0 }],
      scope: `Alcance ficticio de prueba ${marker}`, exclusions: 'Sin trabajos adicionales.',
      family: 'services', payment_terms: 'Condiciones ficticias de prueba.', warranty: 'Garantía ficticia de prueba.',
      validity_terms: 'Esta propuesta tiene una vigencia de 15 días calendario.', validity_days: 15,
    })
    const originalSnapshot = snapshot(await readQuote(context.request, original.id))
    const secondPrice = await createRecord(context.request, `admin/catalog/${item.id}/prices`, { ...priceInput, price: '1200.00' })

    await page.goto(`/borradores/${original.id}`)
    await expect(page.getByTestId('quote-total')).toHaveText('$ 2.380')
    await page.getByRole('link', { name: 'Crear nueva revisión', exact: true }).click()
    await expect(page.getByRole('alert')).toContainText('ya no están vigentes')
    await expect(page.getByRole('button', { name: 'Guardar nueva revisión', exact: true })).toBeDisabled()
    await expect(page.getByLabel('Cantidad partida 1', { exact: true })).toHaveValue('2')
    await page.getByRole('button', { name: 'Usar precio actual partida 1', exact: true }).click()
    await expect(page.getByTestId('quote-total')).toHaveText('$ 2.856')
    await page.getByRole('button', { name: 'Guardar nueva revisión', exact: true }).click()
    await expect(page).toHaveURL(/\/borradores\/[0-9a-f-]{36}$/)
    const revised = await readQuote(context.request, new URL(page.url()).pathname.split('/').at(-1)!)
    expect(revised.revision_number).toBe(2)
    expect(revised.lines[0].price_version_id).toBe(secondPrice.id)
    expect(revised.lines[0].quantity).toBe('2')
    expect(snapshot(await readQuote(context.request, original.id))).toEqual(originalSnapshot)
  } finally {
    const deactivated = await context.request.patch(`/api/backend/admin/catalog/${item.id}/active`, {
      headers: { Origin: origin }, data: { active: false },
    })
    expect(deactivated.status()).toBe(200)
  }
})
