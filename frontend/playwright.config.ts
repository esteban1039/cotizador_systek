import { defineConfig, devices } from '@playwright/test'
// Login payloads and password fields must never be stored in diagnostic artifacts.
process.env.PLAYWRIGHT_NO_COPY_PROMPT = '1'
export default defineConfig({
  testDir: './tests',
  fullyParallel: false,
  workers: 1,
  timeout: 30000,
  use: { baseURL: 'http://127.0.0.1:3001', trace: 'off', screenshot: 'off', video: 'off' },
  projects: [
    { name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 1000 } } },
    { name: 'mobile', use: { ...devices['Pixel 7'], viewport: { width: 390, height: 844 } } },
  ],
  // Always start a controlled production build against the isolated E2E API.
  webServer: {
    command: 'node .output/server/index.mjs', url: 'http://127.0.0.1:3001', reuseExistingServer: false, timeout: 120000,
    env: { HOST: '127.0.0.1', PORT: '3001', NUXT_API_BASE: 'http://127.0.0.1:8002/api/v1' },
  },
})
