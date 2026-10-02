export type KnowledgeStatus = 'active' | 'needs_review' | 'excluded'
export type KnowledgeSource = 'approved_quote' | 'drive_import'
export interface KnowledgeLine { sku: string; description: string; unit: string; quantity: string; family: string; reference_price: string | null; currency: string }
export interface KnowledgeItem { id: string; source: KnowledgeSource; family: string | null; status: KnowledgeStatus; requirement_text: string; scope: string | null; exclusions: string | null; trust: string; issued: boolean; ai_assisted: boolean; scrub_flags: string[]; lines_count: number; captured_at: string; reviewed_at: string | null; review_reason: string | null; lines?: KnowledgeLine[] }
export interface KnowledgeList { data: KnowledgeItem[]; meta: { page: number; per_page: number; total: number; last_page: number } }
export interface KnowledgeFilters { status: string; source: string; family: string; q: string; page: number }
export interface KnowledgeAcceptance { requests: number; kept_lines: number; proposed_lines: number; rate: number | null }
export interface KnowledgeBreakdown { key: string; total: number; active: number; needs_review: number; excluded: number; freshest_at: string | null }
export interface KnowledgeMetrics {
  acceptance: KnowledgeAcceptance; acceptance_with_precedents: KnowledgeAcceptance; acceptance_without_precedents: KnowledgeAcceptance
  coverage: { requests: number; with_precedents: number; rate: number | null }
  tokens: { input: number; output: number }; entries: { total: number; needs_review: number }
  by_family: KnowledgeBreakdown[]; by_source: KnowledgeBreakdown[]
}
export interface KnowledgePatch { reason: string; status?: 'active' | 'excluded'; requirement_text?: string; scope?: string | null; exclusions?: string | null }
