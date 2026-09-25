import { expect, test } from '@playwright/test'

test('ofrece instalación y responde sin conexión sin almacenar información privada', async ({ page, context }) => {
  await page.goto('/login')
  await expect(page.locator('link[rel="manifest"]')).toHaveAttribute('href', '/manifest.webmanifest')
  const manifest = await (await context.request.get('/manifest.webmanifest')).json()
  expect(manifest.display).toBe('standalone')
  for (const icon of manifest.icons) {
    const response = await context.request.get(icon.src)
    expect(response.ok()).toBeTruthy()
    expect(response.headers()['content-type']).toContain('image/png')
  }
  await page.evaluate(async () => {
    await navigator.serviceWorker.ready
    if (!navigator.serviceWorker.controller) await new Promise<void>(resolve => navigator.serviceWorker.addEventListener('controllerchange', () => resolve(), { once: true }))
  })
  await page.getByText('Instalar Systek en este dispositivo').click()
  await expect(page.getByText('En iPhone', { exact: false })).toBeVisible()
  await context.setOffline(true)
  await expect(page.getByRole('status')).toContainText('Sin conexión')
  expect(await page.evaluate(async () => {
    try { await fetch('/api/backend/auth/me'); return true } catch { return false }
  })).toBe(false)
  await page.reload()
  await expect(page.getByRole('heading', { name: 'Necesitas conexión' })).toBeVisible()
  expect(await page.evaluate(() => caches.keys())).toEqual([])
  await context.setOffline(false)
  await page.getByRole('link', { name: 'Volver a intentar' }).click()
  await expect(page).toHaveURL(/\/login$/)
})
