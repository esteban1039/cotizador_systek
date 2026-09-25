export interface Site { id: string; name: string; city: string; address?: string }
export interface Client { id: string; name: string; nit?: string; is_demo: boolean; sites: Site[]; withholds_vat?: boolean }
export interface CatalogItem { id: string; sku: string; description: string; family: string; unit: string; is_demo: boolean; price_version_id: string; price_cents: number | string; tax_bps: number; valid_until: string }
export interface LineInput { price_version_id: string; quantity: string; discount_bps: number }
export interface Amounts { gross: string; discount: string; subtotal: string; tax: string; total: string; cost?: string; vat_withholding?: string; payable?: string }
export interface PricedLine extends LineInput { catalog_item_id: string; description: string; unit: string; family: string; is_demo: boolean; price_cents: number; cost_cents?: number; tax_bps: number; amounts: Amounts }
export interface VatWithholding { applied: boolean; rate_bps: number; basis: string }
export interface Calculation { currency: string; lines: PricedLine[]; totals: Amounts; profit?: string; vat_withholding?: VatWithholding }
export interface ClauseProvenance { type: string; clause_id: string; clause_version_id: string; version: number; family: string; title: string; body_hash: string; modified: boolean }
export type ClauseVersionSelection = Partial<Record<'scope_base' | 'exclusions' | 'payment' | 'warranty' | 'validity' | 'observations', string | null>>
export interface QuoteInput { client_id: string; site_id: string; family: string; lines: LineInput[]; scope: string; exclusions: string; payment_terms: string; warranty: string; validity_terms: string; observations: string | null; validity_days: number; clause_versions?: ClauseVersionSelection }
export interface QuoteIssuer { complete: boolean; missing: string[]; legal_name: string | null; trade_name: string | null; nit: string | null; address: string | null; phone: string | null; email: string | null; website: string | null }
export interface Quote extends Calculation, Omit<QuoteInput, 'lines' | 'clause_versions'> { id: string; quote_number: string | null; version_label: string; status: string; client_name?: string; site_name?: string; clauses: ClauseProvenance[]; issuer?: QuoteIssuer; emission_allowed: boolean; blockers: string[]; created_at: string; valid_until: string }
export interface QuoteListItem { id: string; quote_number: string | null; version_label: string; client_name: string; scope: string; total: string; payable?: string; created_at: string; valid_until: string; status: string }
export interface PaginatedQuotes { data: QuoteListItem[]; current_page: number; last_page: number; total: number }
export interface DashboardQuote { id: string; quote_number: string | null; version_label?: string; revision_number: number; client_name: string; scope: string; status: 'draft' | 'in_review'; created_at: string; valid_until: string | null; is_expired: boolean }
export interface Dashboard { scope: 'own' | 'all'; as_of: string; counts: { total: number; draft: number; in_review: number; approved: number; expired: number }; pending_quotes: DashboardQuote[] }

export interface QuoteReview { decision: string; reason: string; user_name: string; created_at: string }
export interface QuoteRevision { id: string; revision_number: number; status: string; created_at: string }
export interface ReviewedQuote extends Quote { can_revise: boolean; root_quote_id: string; previous_quote_id: string | null; revision_number: number; revisions: QuoteRevision[]; created_by: number | null; can_submit: boolean; can_review: boolean; approval_errors: string[]; review_flags: string[]; reviews: QuoteReview[] }

// Empresa emisora y cláusulas (iteración 11).
export interface ClauseOption { clause_id: string; clause_version_id: string; family: string; type: string; title: string; is_default: boolean; version: number; origin: 'initial_draft' | 'admin'; body: string }
export interface AdminClauseVersion { id: string; version: number; status: string; body: string; origin: 'initial_draft' | 'admin'; reason?: string; published_by_name?: string | null; created_at: string }
export interface AdminClause { id: string; family: string; type: string; title: string; is_default: boolean; active: boolean; is_demo: boolean; versions_count: number; updated_at: string; current_version: AdminClauseVersion }
export interface AdminClauseDetail extends AdminClause { versions: AdminClauseVersion[] }
export interface BankAccountSummary { bank_name: string; account_type: 'savings' | 'checking'; account_number_masked: string; has_holder: boolean }
export interface CompanyProfile {
  configured: boolean; complete: boolean; missing: string[]; version: number | null; origin: 'initial_load' | 'admin' | null
  legal_name: string | null; trade_name: string | null; nit: string | null; address: string | null; phone: string | null
  email: string | null; website: string | null; signer_name: string | null; signer_title: string | null
  bank_account_configured: boolean; bank_account_summary: BankAccountSummary | null
  updated_at: string | null; updated_by_name: string | null
}
