export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const url = getRequestURL(event)
  // Solo hosts explícitos: localhost siempre (desarrollo) y los de NUXT_ALLOWED_HOSTS (p. ej. app.midominio.com).
  const allowedHosts = ['localhost', '127.0.0.1', '[::1]', ...String(config.allowedHosts ?? '').split(',').map(host => host.trim().toLowerCase()).filter(Boolean)]
  if (!allowedHosts.includes(url.hostname.toLowerCase())) throw createError({ statusCode: 403, message: 'Host no permitido.' })
  // Cabeceras hacia la API: secreto compartido (la API solo atiende al BFF) e IP real del cliente para el límite de intentos.
  const clientIp = getHeader(event, 'cf-connecting-ip') ?? getRequestIP(event)
  const upstream = (extra: Record<string, string>): Record<string, string> => ({
    ...extra,
    ...(config.bffSecret ? { 'X-BFF-Secret': String(config.bffSecret) } : {}),
    ...(clientIp ? { 'X-Forwarded-For': clientIp } : {}),
    'X-Forwarded-Proto': url.protocol.replace(':', ''),
  })
  const path = getRouterParam(event, 'path') ?? ''
  const method = getMethod(event)
  const uuid = '[0-9a-f-]{36}'
  const allowed = method === 'GET'
    ? new RegExp(`^(dashboard|clients|catalog|catalog/similar|clauses|quotes|quotes/line-suggestions|quotes/${uuid}|quotes/${uuid}/(pdf|official-pdf|followups)|admin/catalog|admin/history|admin/history/${uuid}|ai-knowledge|ai-knowledge/metrics|ai-knowledge/${uuid}|admin/clauses|admin/clauses/${uuid}|admin/company|users|audit|rules|auth/me|auth/mfa)$`).test(path)
    : method === 'POST'
      ? new RegExp(`^(admin/history/import|admin/history/${uuid}/review|clients|clients/${uuid}/(sites|contacts)|admin/catalog|admin/catalog/${uuid}/prices|admin/clauses|admin/clauses/${uuid}/versions|admin/company|rules|users|quotes/preview|quotes/assist|quotes|quotes/${uuid}/(submit|review|revisions|issue|followups)|auth/(login|logout|password|password/(forgot|reset)|mfa/(setup|confirm|disable)))$`).test(path)
      : method === 'PATCH' && new RegExp(`^(ai-knowledge/${uuid}|admin/catalog/${uuid}/active|admin/clauses/${uuid}|clients/${uuid}/tax-profile|users/[0-9]+)$`).test(path)
  if (!allowed) throw createError({ statusCode: 404 })
  if (['POST', 'PATCH', 'PUT'].includes(method)) {
    if (getHeader(event, 'origin') !== url.origin || !getHeader(event, 'content-type')?.startsWith('application/json')) throw createError({ statusCode: 403, message: 'Origen de solicitud no permitido.' })
  }
  let historyImportBody: unknown
  if (path === 'admin/history/import' && method === 'POST') {
    const raw = await readRawBody(event, false)
    if (!raw || raw.byteLength > 1024 * 1024) throw createError({ statusCode: 413, message: 'El archivo debe pesar como máximo 1 MB.' })
    try { historyImportBody = JSON.parse(new TextDecoder().decode(raw)) } catch { throw createError({ statusCode: 400, message: 'El archivo no contiene JSON válido.' }) }
  }
  setHeader(event, 'Cache-Control', 'no-store')
  const cookie = 'systek_session'
  // Rutas sin sesión: iniciar sesión y recuperar contraseña (nunca reenvían el token de la cookie).
  const anonymous = ['auth/login', 'auth/password/forgot', 'auth/password/reset'].includes(path)
  const token = anonymous ? undefined : getCookie(event, cookie)
  if (!anonymous && !token) throw createError({ statusCode: 401, message: 'Inicia sesión para continuar.' })
  try {
    if (method === 'GET' && new RegExp(`^quotes/${uuid}/(pdf|official-pdf)$`).test(path)) {
      const official = path.endsWith('/official-pdf')
      const pdf = await $fetch.raw<ArrayBuffer>(`${config.apiBase}/${path}`, {
        headers: upstream({ Authorization: `Bearer ${token}`, Accept: 'application/pdf, application/json' }),
        responseType: 'arrayBuffer', timeout: 45000, retry: 0,
      })
      if (!pdf.headers.get('content-type')?.startsWith('application/pdf') || !pdf._data || new TextDecoder().decode(pdf._data.slice(0, 5)) !== '%PDF-') throw createError({ statusCode: 502 })
      setResponseStatus(event, pdf.status)
      setHeader(event, 'Content-Type', 'application/pdf')
      const quoteId = path.split('/')[1]
      const disposition = pdf.headers.get('content-disposition') ?? ''
      const safeName = official
        ? disposition.match(/filename="?(COT-[0-9]{4}-[0-9]{4,6}-V[0-9]+\.pdf)"?(?:;|$)/)?.[1] ?? `oficial-${quoteId}.pdf`
        : disposition.match(new RegExp(`filename="?((?:quote-${quoteId}-v[0-9]+|COT-[0-9]{4}-[0-9]{4,6}-V[0-9]+)-borrador\\.pdf)"?(?:;|$)`))?.[1] ?? `borrador-${quoteId}.pdf`
      setHeader(event, 'Content-Disposition', `attachment; filename="${safeName}"`)
      setHeader(event, 'X-Content-Type-Options', 'nosniff')
      return new Uint8Array(pdf._data)
    }
    const response = await $fetch.raw<Record<string, any>>(`${config.apiBase}/${path}`, {
      method: method as 'GET' | 'POST' | 'PATCH',
      headers: upstream({ ...(token ? { Authorization: `Bearer ${token}` } : {}), Accept: 'application/json' }),
      query: method === 'GET' ? getQuery(event) : undefined,
      body: method !== 'GET' ? (historyImportBody ?? await readBody(event)) : undefined,
      timeout: path === 'quotes/assist' && method === 'POST' ? 35000 : 15000, retry: 0,
    })
    const result = response._data ?? {}
    setResponseStatus(event, response.status)
    if (path === 'auth/login') {
      if (!result.token) throw createError({ statusCode: 502 })
      setCookie(event, cookie, result.token, { httpOnly: true, sameSite: 'strict', secure: url.protocol === 'https:', path: '/', maxAge: 28800 })
      return { user: result.user }
    }
    if (['auth/logout', 'auth/password', 'auth/mfa/confirm', 'auth/mfa/disable'].includes(path)) deleteCookie(event, cookie, { path: '/' })
    return result
  } catch (error: unknown) {
    const failure = error as { statusCode?: number; status?: number; data?: ArrayBuffer | { message?: string; code?: string; errors?: Record<string, string[]> } }
    let details: { message?: string; code?: string; errors?: Record<string, string[]> } | undefined
    if (failure.data instanceof ArrayBuffer) {
      try { details = JSON.parse(new TextDecoder().decode(failure.data)) } catch { /* Return the sanitized default for non-JSON upstream errors. */ }
    } else details = failure.data
    const code = failure.statusCode ?? failure.status
    const assist = path === 'quotes/assist' && method === 'POST'
    const status = code && ([400, 401, 403, 404, 409, 413, 422, 429].includes(code) || (assist && code === 503)) ? code : 502
    if (status === 401 && !anonymous) deleteCookie(event, cookie, { path: '/' })
    throw createError({ statusCode: status, message: status === 502 ? 'No se pudo conectar con el cotizador. Vuelve a intentar.' : details?.message ?? 'No se pudo completar la solicitud.', data: { errors: details?.errors, ...(status === 403 && details?.code === 'mfa_enrollment_required' ? { code: details.code } : {}), ...(assist && status === 503 && details?.code === 'assistant_disabled' ? { code: details.code } : {}) } })
  }
})
