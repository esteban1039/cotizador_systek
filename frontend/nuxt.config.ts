export default defineNuxtConfig({
  compatibilityDate: '2026-09-18',
  devtools: { enabled: false },
  css: ['~/assets/css/main.css', '~/assets/css/access.css'],
  runtimeConfig: {
    apiBase: 'http://127.0.0.1:8000/api/v1',
    localEditorEnabled: false,
  },
  routeRules: {
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
