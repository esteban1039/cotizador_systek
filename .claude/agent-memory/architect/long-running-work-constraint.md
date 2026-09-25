---
name: long-running-work-constraint
description: No long-running work inside HTTP requests (API = single-process artisan serve, queues sync, proxy 15 s); use artisan commands + read-only UI; Drive import design choices
metadata:
  type: project
---

Long-running jobs (Drive import, bulk extraction) run as artisan commands, never behind a UI button, while the API runs on `php artisan serve --no-reload` (single process) with `QUEUE_CONNECTION=sync` and the Nuxt proxy times out at 15 s. The UI only shows read-only status (runs/ledger). A button becomes possible only if a `database` queue + worker container is approved (the `jobs` table exists).

**Why:** a blocking request would freeze the whole API for every user; decided in the Iteration 12 design (docs/diseno-iteracion-12.md, 2026-09-23).

**How to apply:** for any heavy/external task, design a resumable, idempotent command with a ledger table and limits (--max-*), a Cache::lock, and read-only GET endpoints. Iteration 12 also proposed (pending user approval): Google service account with drive.readonly shared on the folder only, smalot/pdfparser + own DOCX reader via ext-zip, extraction in an isolated child process, bank data redacted at ingestion, changes API deferred (only startPageToken stored). Verify against the code before relying on it.
