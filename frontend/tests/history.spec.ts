import { test, expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { authenticate, origin } from './auth-helper'

test.setTimeout(90000)
test.use({ trace: 'off', screenshot: 'off', video: 'off' })

test('archivo JSON inválido no habilita importación ni conserva una vista previa anterior', async ({ page, context }) => {
  await authenticate(context.request)
  await page.goto('/historicos')
  const importRequests: string[] = []
  page.on('request', request => { if (request.url().endsWith('/admin/history/import')) importRequests.push(request.method()) })
  const valid = { records: [{ source_id: randomUUID(), title: 'Vista previa ficticia', source_text: 'Solo contenido ficticio.' }] }
  await page.getByLabel('Archivo JSON', { exact: true }).setInputFiles({
    name: 'valido.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(valid)),
  })
  await expect(page.getByTestId('history-preview')).toBeVisible()
  await page.getByLabel('Archivo JSON', { exact: true }).setInputFiles({
    name: 'invalido.json', mimeType: 'application/json', buffer: Buffer.from('{invalido'),
  })
  await expect(page.getByRole('alert')).toContainText('El archivo no contiene JSON válido.')
  await expect(page.getByTestId('history-preview')).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Importar registros', exact: true })).toHaveCount(0)
  expect(importRequests).toEqual([])
})

test('importa una referencia ficticia, conserva la fuente y omite duplicados', async ({ page, context }) => {
  await authenticate(context.request)
  const suffix = randomUUID()
  const record = {
    source_id: `history-e2e-${suffix}`, title: `Antecedente ficticio ${suffix}`,
    source_url: 'https://docs.google.com/document/d/referencia-ficticia-e2e/edit',
    client_name: 'Cliente de demostración', family: 'cctv', issued_on: '2020-01-15',
    source_text: `Contenido ficticio ${suffix}. <script>window.historySourceExecuted=true</script> Precio antiguo no vigente.`,
  }
  const file = { name: 'antecedente-ficticio.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify({ records: [record] })) }
  await page.goto('/historicos')
  await page.getByLabel('Archivo JSON', { exact: true }).setInputFiles(file)
  await expect(page.getByTestId('history-preview')).toContainText(record.title)
  const previewOnly = await context.request.get('/api/backend/admin/history', { params: { q: record.source_id } })
  expect((await previewOnly.json()).total).toBe(0)
  await page.getByRole('button', { name: 'Importar registros', exact: true }).click()
  await expect(page.getByText('1 registros importados. 0 duplicados omitidos.', { exact: true })).toBeVisible()
  await page.getByRole('link', { name: record.title, exact: true }).click()
  await expect(page).toHaveURL(/\/historicos\/[0-9a-f-]{36}$/)
  const id = new URL(page.url()).pathname.split('/').at(-1)!
  await expect(page.getByText(record.source_text, { exact: true })).toBeVisible()
  expect(await page.evaluate(() => 'historySourceExecuted' in window)).toBe(false)
  const detail = await context.request.get(`/api/backend/admin/history/${id}`)
  expect(detail.headers()['cache-control']).toContain('no-store')
  expect((await detail.json()).data.source_text).toBe(record.source_text)
  await expect(page.getByRole('link', { name: `Abrir origen ${record.source_id}`, exact: true })).toHaveAttribute('href', record.source_url)
  await expect(page.getByRole('button', { name: 'Aprobar referencia' })).toBeDisabled()
  await page.getByRole('combobox', { name: 'Cliente vinculado', exact: true }).selectOption('00000000-0000-4000-8000-000000000001')
  await page.getByLabel('Motivo de la decisión', { exact: true }).fill('Referencia ficticia verificada manualmente en prueba aislada.')
  await page.getByRole('button', { name: 'Aprobar referencia', exact: true }).click()
  await expect(page.getByRole('heading', { name: 'Revisión completada' })).toBeVisible()
  await expect(page.getByText('Cliente vinculado: Cliente de demostración', { exact: true })).toBeVisible()
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Revisión completada' })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Aprobar referencia' })).toHaveCount(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)

  await page.getByRole('link', { name: 'Volver a históricos', exact: true }).click()
  await page.getByLabel('Archivo JSON', { exact: true }).setInputFiles(file)
  await expect(page.getByTestId('history-preview')).toContainText(record.title)
  await page.getByRole('button', { name: 'Importar registros', exact: true }).click()
  await expect(page.getByText('0 registros importados. 1 duplicados omitidos.', { exact: true })).toBeVisible()
  const repeated = await context.request.get('/api/backend/admin/history', { params: { q: record.source_id } })
  const result = await repeated.json()
  expect(result.total).toBe(1)
  expect(result.data[0].id).toBe(id)
  expect(result.data[0].status).toBe('approved')
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
})

test('cotizador no puede consultar, importar ni revisar antecedentes', async ({ page, context }) => {
  await authenticate(context.request)
  const credentials = { email: `history-e2e-${randomUUID()}@example.test`, password: randomUUID() + '-Aa7' }
  const created = await context.request.post('/api/backend/users', {
    headers: { Origin: origin }, data: { ...credentials, name: 'Cotizador ficticio de antecedentes', role: 'quoter' },
  })
  expect(created.status()).toBe(201)
  const userId = (await created.json()).data.id
  try {
    await context.clearCookies()
    await authenticate(context.request, credentials)
    await page.goto('/historicos')
    await expect(page).toHaveURL(/\/borradores$/)
    await expect(page.locator('a[href="/historicos"]')).toHaveCount(0)
    const responses = [
      await context.request.get('/api/backend/admin/history'),
      await context.request.get(`/api/backend/admin/history/${randomUUID()}`),
      await context.request.post('/api/backend/admin/history/import', { headers: { Origin: origin }, data: { records: [] } }),
      await context.request.post(`/api/backend/admin/history/${randomUUID()}/review`, {
        headers: { Origin: origin }, data: { decision: 'approved', reason: 'Revisión ficticia' },
      }),
    ]
    for (const response of responses) {
      expect(response.status()).toBe(403)
      expect(response.headers()['cache-control']).toContain('no-store')
    }
  } finally {
    await authenticate(context.request)
    const deactivated = await context.request.patch(`/api/backend/users/${userId}`, {
      headers: { Origin: origin }, data: { active: false, role: 'quoter' },
    })
    expect(deactivated.status()).toBe(200)
  }
})
