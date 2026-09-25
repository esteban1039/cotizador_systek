// No application responses, credentials or quote data are persisted by this worker.
self.addEventListener('activate', event => event.waitUntil(self.clients.claim()))
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url)
  if (event.request.method !== 'GET' || event.request.mode !== 'navigate' || url.origin !== self.location.origin || url.pathname.startsWith('/api/')) return
  event.respondWith(fetch(event.request).catch(() => new Response(`<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Sin conexión · Systek</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#112d32;color:#fff;font:18px system-ui}main{max-width:32rem;padding:2rem}h1{font-size:2rem}p{line-height:1.6}a{display:inline-block;padding:.8rem 1.2rem;background:#fff;color:#112d32;border-radius:.5rem}</style></head>
<body><main><p>SYSTEK · JARVIS</p><h1>Necesitas conexión</h1><p>Conéctate a internet para consultar o guardar cotizaciones. Los cambios que no guardaste pueden haberse perdido al salir de la página.</p><a href="/">Volver a intentar</a></main></body></html>`, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8', 'Cache-Control': 'no-store' } })))
})
