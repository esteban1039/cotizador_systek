export default defineNuxtPlugin(() => {
  const expired = useState<boolean>('session-expired', () => false)
  globalThis.$fetch = $fetch.create({
    onResponseError({ request, response }) {
      if (response.status === 401 && String(request).startsWith('/api/backend/') && !String(request).endsWith('/auth/login')) expired.value = true
    },
  })
})
