import { expect, test } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { authenticate, origin } from './auth-helper'

test.setTimeout(90000)
test.use({ trace: 'off' })
test.beforeEach(async ({ context }) => { await authenticate(context.request) })

test('muestra el tablero con cifras reales y abre el pendiente recién creado', async ({ page, context }, testInfo) => {
  const scope = `Pendiente ficticio del tablero ${randomUUID()}`
  const created = await context.request.post('/api/backend/quotes', {
    headers: { Origin: origin },
    data: {
      client_id: '00000000-0000-4000-8000-000000000001', site_id: '00000000-0000-4000-8000-000000000002',
      lines: [{ price_version_id: '00000000-0000-4000-8000-000000000004', quantity: '1', discount_bps: 0 }],
      family: 'cctv', scope, exclusions: 'Prueba automatizada.', payment_terms: 'Condiciones ficticias.', warranty: 'Garantía ficticia.',
      validity_terms: 'Esta propuesta tiene una vigencia de 15 días calendario.', validity_days: 15,
    },
  })
  expect(created.status()).toBe(201)
  const quote = (await created.json()).data
  const result = await context.request.get('/api/backend/dashboard')
  expect(result.status()).toBe(200)
  expect(result.headers()['cache-control']).toContain('no-store')
  const dashboard = (await result.json()).data
  expect(dashboard.scope).toBe('all')
  expect(dashboard.pending_quotes.some((pending: { id: string }) => pending.id === quote.id)).toBe(true)
  await page.goto('/inicio')
  await expect(page.getByTestId('dashboard-draft')).toHaveText(String(dashboard.counts.draft))
  await expect(page.getByText(scope, { exact: true })).toBeVisible()
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
  await page.screenshot({ path: testInfo.outputPath('tablero.png'), fullPage: true })
  await page.locator(`a[href="/borradores/${quote.id}"]`).click()
  await expect(page.getByText(scope, { exact: true })).toBeVisible()
  await expect(page).toHaveURL(new RegExp(`/borradores/${quote.id}$`))
})

test('recupera un error de carga y muestra el estado vacío', async ({ page }) => {
  await page.goto('/cuenta')
  let fails = true
  await page.route('**/api/backend/dashboard', route => route.fulfill({
    status: fails ? 502 : 200,
    contentType: 'application/json',
    body: JSON.stringify(fails ? { message: 'Fallo simulado' } : {
      data: { scope: 'all', as_of: '2026-09-19', counts: { total: 0, draft: 0, in_review: 0, approved: 0, expired: 0 }, pending_quotes: [] },
    }),
  }))
  await page.getByRole('link', { name: 'Inicio', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('No pudimos cargar el tablero')
  await expect(page.getByTestId('dashboard-draft')).toHaveCount(0)
  fails = false
  await page.getByRole('button', { name: 'Reintentar', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'No hay pendientes por ahora' })).toBeVisible()
  await expect(page.getByTestId('dashboard-draft')).toHaveText('0')
})
