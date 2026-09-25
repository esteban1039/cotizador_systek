import { spawn } from 'node:child_process'
import { fileURLToPath } from 'node:url'

const child = spawn(process.execPath, [fileURLToPath(new URL('../node_modules/nuxt/bin/nuxt.mjs', import.meta.url)), 'dev', '--host', '127.0.0.1', '--port', '3003'], {
  stdio: 'inherit', env: { ...process.env, NUXT_LOCAL_EDITOR_ENABLED: 'true' },
})
for (const signal of ['SIGINT', 'SIGTERM']) process.on(signal, () => child.kill(signal))
child.on('exit', code => process.exit(code ?? 0))
