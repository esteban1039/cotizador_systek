import { test, expect, request as playwrightRequest, type APIRequestContext } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { mkdirSync } from 'node:fs'
import { authenticate, origin } from './auth-helper'

test.setTimeout(90000)
test.use({ trace: 'off' })
test.beforeEach(async ({ context }) => { await authenticate(context.request) })

const shots = process.env.E2E_SCREENSHOT_DIR || 'test-results/visual'

async function call(api: APIRequestContext, method: 'post' | 'patch', path: string, data: Record<string, unknown>, expected: number[]) {
  const response = await api[method](`/api/backend/${path}`, { headers: { Origin: origin }, data, timeout: 20000 })
  expect(expected, `${method.toUpperCase()} ${path} → ${response.status()}`).toContain(response.status())
  return response
}

// Flujo completo con cuentas y datos ficticios, solo en el entorno aislado systek_e2e.
test('aprobar con otro usuario, emitir, descargar el PDF oficial y registrar seguimiento', async ({ page, context }, info) => {
  mkdirSync(shots, { recursive: true })
  const mobile = info.project.name === 'mobile'
  const marker = randomUUID().slice(0, 8)
  const admin = context.request
  const profile = {
    legal_name: 'Systek Company S.A.S.', nit: '901704107-1', address: 'Dirección ficticia de prueba', phone: '3000000000',
    email: 'prueba@e2e.invalid', website: 'https://example.com', signer_name: 'Firmante Ficticio', signer_title: 'Gerente',
  }

  await call(admin, 'post', 'rules', { family: 'cctv', minimum_margin_bps: 0, max_discount_bps: 10000, review_above: '999999999.00', reason: 'Regla ficticia de prueba automatizada' }, [200])
  await call(admin, 'post', 'admin/company', {
    ...profile, emission_requires_authorization: false, reason: 'Configuración ficticia de prueba automatizada',
    bank_account: { bank_name: `Banco ficticio ${marker}`, account_type: 'savings', account_number: '1234567890', account_number_confirmation: '1234567890' },
  }, [201])

  const approverCredentials = { email: `aprobador-${marker}@e2e.invalid`, password: `Ficticia-${randomUUID()}` }
  await call(admin, 'post', 'users', { name: `Aprobador ${marker}`, ...approverCredentials, role: 'approver' }, [201])
  const approver = await playwrightRequest.newContext({ baseURL: origin })
  try {
    await authenticate(approver, approverCredentials)

    await page.goto('/')
    await page.getByRole('button', { name: 'Probar ejemplo CCTV' }).click()
    await page.getByRole('button', { name: 'Guardar borrador', exact: true }).click()
    await expect(page).toHaveURL(/\/borradores\/[0-9a-f-]{36}$/)
    const id = new URL(page.url()).pathname.split('/').at(-1)!

    await call(admin, 'post', `quotes/${id}/submit`, { reason: 'Lista para revisión de prueba automatizada' }, [200])
    await call(approver, 'post', `quotes/${id}/review`, { decision: 'approve', reason: 'Aprobada por prueba automatizada' }, [200])

    await page.goto(`/borradores/${id}`)
    const issueButton = page.getByRole('button', { name: 'Emitir oficialmente', exact: true })
    if (!(await issueButton.isVisible({ timeout: 10000 }).catch(() => false))) {
      const detail = await (await admin.get(`/api/backend/quotes/${id}`)).json()
      throw new Error(`Sin botón de emitir: status=${detail.data.status} can_issue=${detail.data.can_issue} blockers=${JSON.stringify(detail.data.issue_blockers)}`)
    }
    await issueButton.click()
    await expect(page.getByRole('dialog')).toBeVisible()
    await page.screenshot({ path: `${shots}/${info.project.name}-1-confirmar-emision.png`, fullPage: true })
    await page.getByLabel('Motivo de la emisión').fill('Emisión de prueba automatizada')
    await page.getByRole('button', { name: 'Emitir de forma irreversible' }).click()
    await expect(page.getByRole('region', { name: 'Emisión oficial' })).toBeVisible()
    await expect(page.getByRole('button', { name: 'Descargar PDF oficial' })).toBeVisible()
    await expect(page.getByTestId('commercial-status')).toBeVisible()
    await page.screenshot({ path: `${shots}/${info.project.name}-2-emitida.png`, fullPage: true })

    const pdf = await admin.get(`/api/backend/quotes/${id}/official-pdf`)
    expect(pdf.status()).toBe(200)
    expect(pdf.headers()['content-type']).toContain('application/pdf')
    expect((await pdf.body()).subarray(0, 4).toString()).toBe('%PDF')
    expect((await admin.get(`/api/backend/quotes/${id}/pdf`)).status()).toBe(409)

    await page.getByLabel('Acción').selectOption('sent')
    await page.getByLabel('Canal del envío').selectOption('whatsapp')
    await page.getByLabel(/^Nota/).fill('Enviada por prueba automatizada')
    await page.getByRole('button', { name: 'Registrar envío' }).click()
    await expect(page.getByTestId('commercial-status'), await page.locator('[role=alert], .notice.error').allTextContents().then(t => `Alertas: ${t.join(' | ')}`)).toHaveText('Enviada')
    await page.screenshot({ path: `${shots}/${info.project.name}-3-seguimiento.png`, fullPage: true })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    if (mobile) expect(await page.evaluate(() => window.innerWidth)).toBeLessThan(500)
  } catch (error) {
    console.log('FALLO_EN_FLUJO', String(error).slice(0, 600))
    throw error
  } finally {
    await call(admin, 'post', 'admin/company', { ...profile, emission_requires_authorization: true, reason: 'Restaurar la autorización de emisión tras la prueba' }, [201])
    await approver.dispose()
  }
})
