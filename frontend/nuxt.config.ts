export default defineNuxtConfig({
  compatibilityDate: '2026-09-18',
  devtools: { enabled: false },
  css: ['~/assets/css/main.css', '~/assets/css/access.css'],
  runtimeConfig: {
    apiBase: 'http://127.0.0.1:8000/api/v1',
    // Hosts adicionales permitidos (separados por comas); localhost siempre se admite.
    allowedHosts: '',
    // Secreto compartido con la API (BFF_SHARED_SECRET del backend). Solo por variable de entorno del servidor.
    bffSecret: '',
  },
  routeRules: {
    // Cabeceras de seguridad. La CSP se agrega aparte tras probarla con la PWA (Nuxt hidrata con scripts en línea).
    '/**': { headers: {
      'X-Frame-Options': 'DENY',
      'X-Content-Type-Options': 'nosniff',
      'Referrer-Policy': 'strict-origin-when-cross-origin',
      'Permissions-Policy': 'camera=(), microphone=(), geolocation=()',
      'Strict-Transport-Security': 'max-age=31536000',
    } },
    '/sw.js': { headers: { 'cache-control': 'no-cache' } },
    '/manifest.webmanifest': { headers: { 'cache-control': 'no-cache' } },
  },
  app: {
    head: {
      title: 'JARVIS · Cotizador Systek',
      link: [{ rel: 'manifest', href: '/manifest.webmanifest' }, { rel: 'apple-touch-icon', href: '/icons/icon-180.png' }],
      htmlAttrs: { lang: 'es' },
      meta: [{ name: 'theme-color', content: '#112d32' }, { name: 'description', content: 'Espacio de trabajo para preparar cotizaciones Systek.' }],
    },
  },
})
