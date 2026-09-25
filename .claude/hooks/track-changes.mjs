#!/usr/bin/env node
// PostToolUse(Edit|Write): records changed source files so the Stop hook validates only what changed.
import { appendFileSync, mkdirSync, readFileSync } from 'node:fs'
import { join, relative } from 'node:path'

const input = JSON.parse(readFileSync(0, 'utf8') || '{}')
const root = process.env.CLAUDE_PROJECT_DIR || process.cwd()
const filePath = input.tool_input?.file_path ?? input.tool_response?.filePath
if (!filePath) process.exit(0)

const path = relative(root, filePath)
const tracked = /^backend\/(app|bootstrap|config|database|routes|tests)\/.+\.php$/.test(path)
  || /^frontend\/(app|server|shared|tests)\/.+\.(ts|vue|mjs)$/.test(path)
  || /^frontend\/(nuxt|playwright)\.config\.ts$/.test(path)
if (!tracked) process.exit(0)

const stateDir = join(root, '.claude', '.state')
mkdirSync(stateDir, { recursive: true })
const session = String(input.session_id ?? 'default').replace(/[^\w-]/g, '')
appendFileSync(join(stateDir, `pending-${session}.txt`), `${path}\n`)
