export type HistoryStatus = 'pending' | 'approved' | 'rejected'
export interface HistoryInput {
  source_id: string
  title: string
  source_url?: string | null
  client_name?: string | null
  client_nit?: string | null
  family?: string | null
  issued_on?: string | null
  source_text: string
}
export interface HistoryDocument extends HistoryInput {
  id: string
  status: HistoryStatus
  linked_client_id: string | null
  review_reason?: string | null
  reviewed_at?: string | null
  created_at: string
}
export interface HistoryCandidate { id: string; name: string; nit: string; match: 'nit' | 'name' }
export interface HistorySource {
  source_id: string
  document_id: string
  title: string
  source_url: string | null
  imported_by: number | null
  created_at: string
}
export interface HistoryDetail { data: HistoryDocument & { sources: HistorySource[] }; candidates: HistoryCandidate[] }
export interface HistoryPage { data: Omit<HistoryDocument, 'source_text'>[]; current_page: number; last_page: number; total: number }
export const historyStatuses: Record<HistoryStatus, string> = { pending: 'Pendiente', approved: 'Aprobado', rejected: 'Rechazado' }
export function safeHistoryUrl(value?: string | null): string | undefined {
  if (!value) return undefined
  try {
    const url = new URL(value)
    if (url.protocol === 'https:' && ['drive.google.com', 'docs.google.com'].includes(url.hostname) && !url.username && !url.password && !url.port) return url.href
  } catch { /* Untrusted source URL is displayed as text only. */ }
  return undefined
}
