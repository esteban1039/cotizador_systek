import { chromium } from '@playwright/test'
import { fileURLToPath } from 'node:url'
const browser = await chromium.launch()
try {
  for (const size of [180, 192, 512]) {
    const page = await browser.newPage({ viewport: { width: size, height: size }, deviceScaleFactor: 1 })
    await page.setContent(`<style>body{margin:0}svg{display:block}</style><svg xmlns="http://www.w3.org/2000/svg" width="${size}" height="${size}" viewBox="0 0 512 512"><rect width="512" height="512" fill="#112d32"/><path d="M328 168H218c-59 0-59 88 0 88h76c59 0 59 88 0 88H184" fill="none" stroke="#f6f2e9" stroke-width="34" stroke-linecap="round"/><path d="m352 106 10 27 27 10-27 10-10 27-10-27-27-10 27-10Z" fill="#dfb86b"/></svg>`)
    await page.screenshot({ path: fileURLToPath(new URL(`../public/icons/icon-${size}.png`, import.meta.url)) })
    await page.close()
  }
} finally { await browser.close() }
