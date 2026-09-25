export default defineNuxtPlugin(() => {
  if ('serviceWorker' in navigator && window.isSecureContext) {
    navigator.serviceWorker.register('/sw.js', { scope: '/', updateViaCache: 'none' }).catch(() => {
      // The regular web application remains usable if registration is unavailable.
    })
  }
})
