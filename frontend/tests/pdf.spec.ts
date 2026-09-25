import { test, expect, type APIRequestContext } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { readFile } from 'node:fs/promises'
import { authenticate, origin } from './auth-helper'

test.setTimeout(90000)
test.use({ trace: 'off' })

async function create(request: APIRequestContext, path: string, data: Record<string, unknown>) {
  const response = await request.post(`/api/backend/${path}`, { headers: { Origin: origin }, data })
  expect(response.status()).toBe(201)
  return (await response.json()).data
}

function expectPdf(buffer: Buffer) {
  expect(buffer.subarray(0, 5).toString('ascii')).toBe('%PDF-')
  expect(buffer.length).toBeGreaterThan(1000)
  expect(buffer.subarray(-1024).toString('ascii')).toContain('%%EOF')
}

test('descarga un PDF privado identificado como borrador de su versión', async ({ page, context }, testInfo) => {
  await authenticate(context.request)
  const marker = randomUUID().slice(0, 8)
  const client = await create(context.request, 'clients', { name: `Cliente PDF ficticio ${marker}`, nit: null })
  const site = await create(context.request, `clients/${client.id}/sites`, { name: 'Sede ficticia PDF', city: 'Bogotá', address: 'Dirección de prueba' })
  const item = await create(context.request, 'admin/catalog', {
    sku: `PDF-E2E-${marker}`, description: `Servicio ficticio PDF ${marker}`, family: 'services', unit: 'servicio',
  })
  try {
    const price = await create(context.request, `admin/catalog/${item.id}/prices`, {
      price: '1000.00', cost: '500.00', tax_bps: 1900,
      valid_until: new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10),
      reason: 'Precio ficticio para prueba automatizada de PDF',
    })
    const quote = await create(context.request, 'quotes', {
      client_id: client.id, site_id: site.id, family: 'services',
      lines: [{ price_version_id: price.id, quantity: '2', discount_bps: 0 }],
      scope: `Documento ficticio de prueba PDF ${marker}.`, exclusions: 'Sin trabajos adicionales.',
      payment_terms: 'Condiciones ficticias de prueba.', warranty: 'Garantía ficticia de prueba.',
      validity_terms: 'Esta propuesta tiene una vigencia de 15 días calendario.', validity_days: 15,
    })
    const filename = quote.quote_number ? `${quote.quote_number}-${quote.version_label}-borrador.pdf` : `quote-${quote.id}-v1-borrador.pdf`
    const response = await context.request.get(`/api/backend/quotes/${quote.id}/pdf`)
    expect(response.status()).toBe(200)
    expect(response.headers()['content-type']).toContain('application/pdf')
    expect(response.headers()['content-disposition']).toContain(filename)
    expect(response.headers()['content-disposition']).toContain('attachment')
    expect(response.headers()['cache-control']).toContain('no-store')
    expectPdf(await response.body())

    await page.goto(`/borradores/${quote.id}`)
    await expect(page.getByRole('heading', { name: 'Versión 1', exact: true })).toBeVisible()
    await expect(page.getByText('Borrador interno: no válido para envío al cliente.', { exact: true })).toBeVisible()
    const button = page.getByRole('button', { name: 'Descargar PDF de borrador', exact: true })
    await expect(button).toBeEnabled()
    const downloadPromise = page.waitForEvent('download')
    await button.click()
    const download = await downloadPromise
    expect(download.suggestedFilename()).toBe(filename)
    expect(await download.failure()).toBeNull()
    const pdfPath = testInfo.outputPath(filename)
    await download.saveAs(pdfPath)
    expectPdf(await readFile(pdfPath))
    await expect(page.getByTestId('quote-total')).toHaveText('$ 2.380')
    await expect(button).toBeEnabled()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    await page.screenshot({ path: testInfo.outputPath('detalle-descarga-borrador.png'), fullPage: true })
    const unchanged = await context.request.get(`/api/backend/quotes/${quote.id}`)
    expect(unchanged.status()).toBe(200)
    const snapshot = (await unchanged.json()).data
    expect(snapshot.status).toBe('draft')
    expect(snapshot.revision_number).toBe(1)
    expect(snapshot.emission_allowed).toBe(false)
  } finally {
    const deactivated = await context.request.patch(`/api/backend/admin/catalog/${item.id}/active`, {
      headers: { Origin: origin }, data: { active: false },
    })
    expect(deactivated.status()).toBe(200)
  }
})
