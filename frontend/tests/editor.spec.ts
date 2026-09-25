import { test, expect } from '@playwright/test'
import { authenticate } from './auth-helper'

test.setTimeout(90000)
test.use({ trace: 'off' })
test.beforeEach(async ({ context }) => { await authenticate(context.request) })

test('calcula, actualiza y rechaza cantidades inválidas sin mostrar totales antiguos', async ({ page }) => {
  const browserErrors: string[] = []
  page.on('pageerror', error => browserErrors.push(error.message))
  await page.goto('/')
  await expect(page.getByRole('heading', { name: 'Nueva cotización.' })).toBeVisible()
  await expect(page.getByRole('combobox', { name: 'Sede *', exact: true })).toBeDisabled()
  await page.getByRole('button', { name: 'Probar ejemplo CCTV' }).click()
  await expect(page.getByTestId('quote-total')).toHaveText('$ 1.904.000')
  await page.getByLabel('Cantidad partida 1', { exact: true }).fill('3')
  await expect(page.getByTestId('quote-total')).toHaveText('$ 714.000')
  await page.getByLabel('Descuento partida 1', { exact: true }).fill('10')
  await expect(page.getByTestId('quote-total')).toHaveText('$ 642.600')
  await page.getByLabel('Cantidad partida 1', { exact: true }).fill('0')
  await expect(page.getByRole('alert')).toContainText('cantidades mayores que cero')
  await expect(page.getByTestId('quote-total')).toHaveText('—')
  await expect(page.getByRole('button', { name: 'Guardar borrador' })).toBeDisabled()
  expect(browserErrors).toEqual([])
})

test('guarda, vuelve a abrir y consulta el borrador persistido', async ({ page }) => {
  await page.goto('/')
  await page.getByRole('button', { name: 'Probar ejemplo CCTV' }).click()
  await page.getByRole('textbox', { name: 'Alcance *', exact: true }).fill('Prueba de interfaz: ocho cámaras de demostración.')
  await expect(page.getByTestId('quote-total')).toHaveText('$ 1.904.000')
  await page.getByRole('button', { name: 'Guardar borrador' }).click()
  await expect(page).toHaveURL(/\/borradores\/[0-9a-f-]{36}$/)
  await expect(page.getByRole('heading', { name: 'Tu borrador está listo.' })).toBeVisible()
  const savedUrl = page.url()
  await page.reload()
  await expect(page.getByTestId('quote-total')).toHaveText('$ 1.904.000')
  await expect(page.getByText('Prueba de interfaz: ocho cámaras de demostración.', { exact: true })).toBeVisible()
  await page.getByRole('link', { name: 'Todos los borradores' }).click()
  await expect(page.getByRole('heading', { name: 'Borradores.' })).toBeVisible()
  await page.locator(`a[href="${new URL(savedUrl).pathname}"]`).click()
  await expect(page).toHaveURL(savedUrl)
})

test('añade y elimina partidas y mantiene la pantalla dentro del ancho visible', async ({ page }, testInfo) => {
  await page.goto('/')
  await page.getByRole('combobox', { name: 'Producto o servicio', exact: true }).selectOption('00000000-0000-4000-8000-000000000004')
  await page.getByRole('button', { name: 'Añadir', exact: true }).click()
  await expect(page.getByTestId('quote-total')).toHaveText('$ 238.000')
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  await page.screenshot({ path: testInfo.outputPath('editor.png'), fullPage: true })
  await page.getByRole('button', { name: 'Eliminar partida 1' }).click()
  await expect(page.getByText('Una buena propuesta empieza aquí')).toBeVisible()
  await expect(page.getByTestId('quote-total')).toHaveText('—')
})

test('muestra errores del servidor y no permite guardar un precio rechazado', async ({ page }) => {
  await page.route('**/api/backend/quotes/preview', route => route.fulfill({
    status: 422, contentType: 'application/json',
    body: JSON.stringify({ message: 'Revisa los datos.', data: { errors: { 'lines.0.price_version_id': ['El precio debe estar aprobado y vigente, y el ítem activo.'] } } }),
  }))
  await page.goto('/')
  await page.getByRole('button', { name: 'Probar ejemplo CCTV' }).click()
  await expect(page.getByRole('alert')).toContainText('aprobado y vigente')
  await expect(page.getByRole('button', { name: 'Guardar borrador' })).toBeDisabled()
})

test('oculta errores internos y permite reintentar una carga fallida', async ({ page }) => {
  await page.route('**/api/backend/clients', route => route.fulfill({ status: 502, contentType: 'application/json', body: '{}' }))
  await page.goto('/borradores')
  await page.getByRole('link', { name: 'Nueva cotización', exact: true }).first().click()
  await expect(page.getByRole('alert')).toContainText('No pudimos cargar los datos.')
  await page.unroute('**/api/backend/clients')
  await page.getByRole('button', { name: 'Reintentar' }).click()
  await expect(page.getByRole('alert')).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Probar ejemplo CCTV' })).toBeVisible()
})

test('protege el proxy de solicitudes de escritura de otro origen', async ({ request }) => {
  const response = await request.post('/api/backend/quotes/preview', { headers: { Origin: 'https://otro-sitio.example' }, data: { lines: [] } })
  expect(response.status()).toBe(403)
  const forbidden = await request.get('/api/backend/system/anything')
  expect(forbidden.status()).toBe(404)
})
