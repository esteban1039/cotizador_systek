import { expect, test } from '@playwright/test'
import { authenticate } from './auth-helper'

test.setTimeout(90000)
test.use({ trace: 'off', screenshot: 'off', video: 'off' })

test('la política pendiente redirige a cuenta y oculta la navegación comercial', async ({ page, context }) => {
  await authenticate(context.request)
  await page.goto('/cuenta')
  await expect(page.getByRole('button', { name: 'Configurar verificación en dos pasos', exact: true })).toBeVisible()
  await page.route('**/api/backend/auth/me', route => route.fulfill({ json: { data: {
    id: 1, name: 'Administrador ficticio', email: 'policy@example.test', role: 'admin', active: true,
    mfa_enabled: false, mfa_required: true, mfa_enrollment_required: true,
  } } }))
  await page.route('**/api/backend/auth/mfa', route => route.fulfill({ json: { enabled: false, required: true, recovery_codes_remaining: 0 } }))
  let businessRequests = 0
  await page.route('**/api/backend/dashboard', route => { businessRequests++; return route.fulfill({ status: 403, json: { code: 'mfa_enrollment_required' } }) })
  await page.getByRole('link', { name: 'Inicio', exact: true }).click()
  await expect(page).toHaveURL(/\/cuenta$/)
  await expect(page.getByTestId('mfa-required-notice')).toBeVisible()
  for (const href of ['/inicio', '/', '/borradores', '/clientes', '/usuarios', '/historicos']) {
    await expect(page.locator(`nav a[href="${href}"]`)).toHaveCount(0)
  }
  await expect(page.getByRole('link', { name: 'Mi cuenta', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Cerrar sesión', exact: true })).toBeVisible()
  expect(businessRequests).toBe(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true)
})

test('MFA obligatorio activado conserva recuperación y no ofrece desactivación', async ({ page, context }) => {
  await authenticate(context.request)
  await page.route('**/api/backend/auth/mfa', route => route.fulfill({ json: { enabled: true, required: true, recovery_codes_remaining: 8 } }))
  await page.goto('/cuenta')
  await expect(page.getByText('Activada. Tienes 8 códigos de recuperación disponibles.', { exact: true })).toBeVisible()
  await expect(page.getByTestId('mfa-required-notice')).toBeVisible()
  await expect(page.getByText('Desactivar verificación en dos pasos', { exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Desactivar y cerrar sesiones', exact: true })).toHaveCount(0)
})
