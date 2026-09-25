#!/usr/bin/env node
// Stop: Pint on changed PHP files, PHPUnit (SQLite) on changed test files, Nuxt typecheck when frontend changed.
// Silent summary on success; on failure only the relevant lines (~30). Blocks once so Claude fixes failures;
// never blocks when tools are unavailable. Skip with CLAUDE_SKIP_VALIDATION=1.
import { spawnSync } from 'node:child_process'
import { existsSync, readFileSync, rmSync } from 'node:fs'
import { join } from 'node:path'

const input = JSON.parse(readFileSync(0, 'utf8') || '{}')
const root = process.env.CLAUDE_PROJECT_DIR || process.cwd()
const session = String(input.session_id ?? 'default').replace(/[^\w-]/g, '')
const pendingFile = join(root, '.claude', '.state', `pending-${session}.txt`)
if (process.env.CLAUDE_SKIP_VALIDATION === '1' || !existsSync(pendingFile)) process.exit(0)

const changed = [...new Set(readFileSync(pendingFile, 'utf8').split('\n').filter(Boolean))].filter(path => existsSync(join(root, path)))
const phpFiles = changed.filter(path => path.startsWith('backend/')).map(path => path.slice('backend/'.length))
const testFiles = phpFiles.filter(path => /^tests\/.+Test\.php$/.test(path))
const frontendChanged = changed.some(path => path.startsWith('frontend/'))

const run = (cmd, args, cwd = root, timeout = 240000) => {
  const result = spawnSync(cmd, args, { cwd, encoding: 'utf8', timeout })
  return { ok: result.status === 0, output: `${result.stdout ?? ''}${result.stderr ?? ''}`.trim() }
}
// Keeps the lines that matter (errors, file:line, failed tests) and caps the output.
const relevant = (output, pattern, max = 30) => {
  const lines = output.split('\n').map(line => line.trimEnd()).filter(Boolean)
  const hits = lines.filter(line => pattern.test(line))
  const picked = (hits.length ? hits : lines.slice(-max)).slice(0, max)
  const hidden = (hits.length ? hits.length : lines.length) - picked.length
  return picked.join('\n') + (hidden > 0 ? `\n… (${hidden} líneas más)` : '')
}
const failures = []
const passed = []
const skipped = []

if (phpFiles.length > 0) {
  if (!run('docker', ['info'], root, 10000).ok) {
    skipped.push('Pint/PHPUnit (Docker no está disponible)')
  } else {
    const apiRunning = run('docker', ['compose', 'ps', '--status', 'running', '--services'], root, 15000).output.split('\n').includes('api')
    const base = apiRunning ? ['compose', 'exec', '-T'] : ['compose', 'run', '--rm']
    const pint = run('docker', [...base, 'api', 'vendor/bin/pint', '--test', ...phpFiles])
    if (pint.ok) passed.push(`Pint (${phpFiles.length})`)
    else failures.push(`Pint --test falló. Corrige con: docker compose run --rm api vendor/bin/pint ${phpFiles.join(' ')}\n${relevant(pint.output, /⨯|✗|FAIL|\.php/)}`)

    if (testFiles.length > 0) {
      const env = ['-e', 'DB_CONNECTION=sqlite', '-e', 'DB_DATABASE=:memory:']
      const tests = run('docker', [...base, ...env, 'api', 'php', 'artisan', 'test', '--compact', ...testFiles], root, 180000)
      if (tests.ok) passed.push(`PHPUnit (${testFiles.length} archivos de prueba)`)
      else failures.push(`PHPUnit falló (${testFiles.join(' ')}):\n${relevant(tests.output, /FAIL|⨯|✗|Failed|Error|Exception|expected|Expected|Actual|tests\/.+:\d+|app\/.+:\d+|Tests:/)}`)
    }
  }
}

if (frontendChanged) {
  if (!existsSync(join(root, 'frontend', 'node_modules'))) {
    skipped.push('typecheck (falta frontend/node_modules)')
  } else {
    const typecheck = run('npm', ['run', 'typecheck', '--prefix', 'frontend'])
    if (typecheck.ok) passed.push('typecheck')
    else failures.push(`npm run typecheck --prefix frontend falló:\n${relevant(typecheck.output, /error TS|\.(ts|vue):\d+|\.(ts|vue)\(\d+/)}`)
  }
}

const summary = [passed.length ? `OK: ${passed.join(', ')}` : '', skipped.length ? `Omitido: ${skipped.join(', ')}` : ''].filter(Boolean).join(' · ')

if (failures.length === 0) {
  if (skipped.length === 0) rmSync(pendingFile, { force: true })
  if (summary) process.stdout.write(JSON.stringify({ systemMessage: `Validación — ${summary}` }))
  process.exit(0)
}

if (input.stop_hook_active) {
  process.stdout.write(JSON.stringify({ systemMessage: `Validación con fallos pendientes:\n${failures.join('\n\n')}` }))
  process.exit(0)
}
process.stdout.write(JSON.stringify({ decision: 'block', reason: `Validación automática de archivos modificados:\n${failures.join('\n\n')}\n\nCorrige antes de terminar.` }))
