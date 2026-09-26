import { test, expect } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { authenticate, origin } from './auth-helper'

test.setTimeout(90000)
test.use({ trace: 'off' })

test('el proxy de seguimiento exige sesión, UUID, método permitido y origen', async ({ context }) => {
  const id = randomUUID()
  const anonymous = await context.request.get(`/api/backend/quotes/${id}/followups`)
  expect(anonymous.ok()).toBe(false)
  await authenticate(context.request)
  const noOrigin = await context.request.post(`/api/backend/quotes/${id}/followups`, { data: { type: 'note', occurred_at: new Date().toISOString() } })
  expect(noOrigin.status()).toBe(403)
  const badPath = await context.request.post('/api/backend/quotes/no-es-uuid/followups', { headers: { Origin: origin }, data: {} })
  expect(badPath.status()).toBe(404)
  const badGet = await context.request.get('/api/backend/quotes/no-es-uuid/followups')
  expect(badGet.status()).toBe(404)
  const patch = await context.request.patch(`/api/backend/quotes/${id}/followups`, { headers: { Origin: origin }, data: {} })
  expect(patch.status()).toBe(404)
  const del = await context.request.delete(`/api/backend/quotes/${id}/followups`, { headers: { Origin: origin } })
  expect(del.status()).toBe(404)
})
