#!/usr/bin/env node
// PreToolUse(Bash): blocks secret reads and unsafe test runs; asks before destructive database/volume commands.
import { readFileSync } from 'node:fs'

const input = JSON.parse(readFileSync(0, 'utf8') || '{}')
const command = String(input.tool_input?.command ?? '')

function decide(permissionDecision, permissionDecisionReason) {
  process.stdout.write(JSON.stringify({ hookSpecificOutput: { hookEventName: 'PreToolUse', permissionDecision, permissionDecisionReason } }))
  process.exit(0)
}

const mentionsEnvFile = /(^|[\s'"=/:])\.env(?![\w.-])/.test(command)
const isEnvSetupCopy = /^\s*cp\s+(\S*\/)?\.env\.example\s+(\S*\/)?\.env\s*$/.test(command)
if (mentionsEnvFile && !isEnvSetupCopy) {
  decide('deny', 'Protección de secretos: no se leen ni manipulan archivos .env. Usa backend/.env.example para conocer las claves.')
}
if (/storage\/app\/private|local-admin-access\.txt|e2e-isolated-access\.json|e2e-admin-access\.json/.test(command)) {
  decide('deny', 'Protección de secretos: storage/app/private contiene contraseñas iniciales, accesos E2E, tokens y copias de base.')
}

const runsBackendTests = /artisan\s+test\b|vendor\/bin\/phpunit\b|composer\s+(run\s+)?test\b/.test(command)
const safeTestDatabase = /DB_CONNECTION=sqlite\b/.test(command) && /DB_DATABASE=:memory:/.test(command)
  || /DB_CONNECTION=pgsql\b/.test(command) && /DB_DATABASE=systek_test\b/.test(command)
if (runsBackendTests && !safeTestDatabase) {
  decide('deny', 'Las pruebas deben indicar la base explícitamente: docker compose run --rm -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: api php artisan test --compact (o pgsql + systek_test). Nunca la base systek.')
}

const destructive = [
  /migrate:(fresh|reset|refresh|rollback)\b/,
  /\bdb:wipe\b/,
  /\bdrop\s+(table|database|schema|index|column)\b/i,
  /\btruncate\b/i,
  /\bdropdb\b/,
  /\bdown\b[^|;&]*\s(-v|--volumes)\b/,
  /\bvolume\s+(rm|prune)\b/,
  /\bsystem\s+prune\b/,
  /\brm\s+-[a-z]*r[a-z]*\s[^|;&]*(backend\/database|backend\/storage|postgres)/,
]
if (destructive.some(pattern => pattern.test(command))) {
  decide('ask', 'Comando potencialmente destructivo sobre datos, esquema o volúmenes. Confirma el entorno (systek_test/systek_e2e) y que existe copia (scripts/backup-local-db.sh).')
}

process.exit(0)
