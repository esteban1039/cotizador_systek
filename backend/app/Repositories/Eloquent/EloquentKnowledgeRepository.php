<?php

namespace App\Repositories\Eloquent;

use App\Domain\DecimalMoney;
use App\Models\CatalogItem;
use App\Models\Quote;
use App\Models\QuoteAssistRequest;
use App\Models\QuoteFreeLineItem;
use App\Models\QuoteKnowledge;
use App\Models\User;
use App\Repositories\Contracts\KnowledgeRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class EloquentKnowledgeRepository implements KnowledgeRepository
{
    public function activeAdminId(string $email): ?int
    {
        $id = User::query()->where('email', strtolower($email))->where('role', 'admin')->where('active', true)->value('id');

        return $id === null ? null : (int) $id;
    }

    public function upsertFromQuote(array $entry): string
    {
        $existing = QuoteKnowledge::query()->where('source_root_quote_id', $entry['source_root_quote_id'])->lockForUpdate()->first();
        if ($existing !== null) {
            if ($existing->source_revision !== null && isset($entry['source_revision']) && (int) $existing->source_revision > (int) $entry['source_revision']) {
                return 'unchanged';
            }
            $entry['issued'] = $entry['issued'] || $existing->issued;
            $entry['trust'] = $entry['issued'] ? '1.10' : '1.00';
        }

        return $this->persist($existing, $entry, ['source_revision', 'issued', 'ai_assisted', 'human_edit_ratio']);
    }

    public function importDriveGroup(array $entry): string
    {
        $existing = QuoteKnowledge::query()->where('source', 'drive_import')->where('source_ref', $entry['source_ref'])->lockForUpdate()->first();

        return $this->persist($existing, $entry, []);
    }

    public function approvedSnapshots(): array
    {
        $best = [];
        foreach (Quote::query()->whereIn('status', ['approved', 'issued'])->orderBy('created_at')->cursor() as $quote) {
            $root = (string) ($quote->root_quote_id ?? $quote->id);
            $revision = (int) ($quote->revision_number ?? 1);
            $issued = $quote->status === 'issued';
            if (isset($best[$root]) && $best[$root]['revision'] > $revision) {
                $best[$root]['issued'] = $best[$root]['issued'] || $issued;

                continue;
            }
            $snapshot = json_decode((string) $quote->snapshot, true);
            $best[$root] = [
                'quote_id' => (string) $quote->id, 'root_id' => $root, 'revision' => $revision, 'issued' => $issued || ($best[$root]['issued'] ?? false),
                'snapshot' => is_array($snapshot) ? $snapshot : [],
            ];
        }

        return array_values($best);
    }

    public function catalogByIds(array $ids): array
    {
        $map = [];
        foreach (CatalogItem::query()->whereIn('id', $ids)->get(['id', 'sku', 'description']) as $item) {
            $map[(string) $item->id] = ['sku' => (string) $item->sku, 'description' => (string) $item->description];
        }

        return $map;
    }

    public function freeLineCatalog(string $quoteId): array
    {
        $map = [];
        $links = QuoteFreeLineItem::query()->where('quote_id', $quoteId)->get(['free_line_id', 'catalog_item_id']);
        $items = CatalogItem::query()->whereIn('id', $links->pluck('catalog_item_id'))->get(['id', 'sku', 'description'])->keyBy('id');
        foreach ($links as $link) {
            $item = $items[$link->catalog_item_id] ?? null;
            if ($item !== null) {
                $map[(string) $link->free_line_id] = ['sku' => (string) $item->sku, 'description' => (string) $item->description];
            }
        }

        return $map;
    }

    public function similar(string $text, ?string $family, int $limit, ?string $excludeRootId = null): array
    {
        $terms = $this->terms($text);
        if ($terms === [] || $limit < 1) {
            return [];
        }
        $columns = ['id', 'source', 'source_root_quote_id', 'family', 'requirement_text', 'scope', 'exclusions', 'lines', 'ai_assisted', 'captured_at'];
        $query = QuoteKnowledge::query()->where('status', 'active')
            ->when($excludeRootId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('source_root_quote_id')->orWhere('source_root_quote_id', '!=', $excludeRootId)));
        if (DB::connection()->getDriverName() === 'pgsql') {
            $score = "(0.6 * ts_rank_cd(search_vector, websearch_to_tsquery('spanish', quote_knowledge_unaccent(?)))"
                .' + 0.4 * similarity(requirement_text, ?)) * trust::float8'
                .' * (CASE WHEN family = ? THEN 1.15 ELSE 1.0 END)'
                .' * (1.0 - 0.15 * LEAST(1.0, EXTRACT(EPOCH FROM (now() - captured_at)) / 63072000.0))';
            $rows = $query->select($columns)->selectRaw("{$score} as score", [implode(' or ', $terms), mb_substr($text, 0, 500), (string) $family])
                ->orderByDesc('score')->limit($limit * 8)->get();
        } else {
            // Respaldo simple (§6.4): fracción de términos presentes × confianza × boost de familia.
            $rows = $query->select($columns)->addSelect('lines_text', 'trust')->get()->map(function (QuoteKnowledge $row) use ($terms, $family): QuoteKnowledge {
                $haystack = mb_strtolower($row->requirement_text.' '.($row->lines_text ?? ''));
                $hits = count(array_filter($terms, fn (string $t): bool => str_contains($haystack, $t)));
                $row->setAttribute('score', ($hits / count($terms)) * (float) $row->trust * ($row->family === $family ? 1.15 : 1.0));

                return $row;
            })->sortByDesc('score')->values();
        }
        $min = (float) config('ai_assistant.knowledge.min_score');
        $maxAi = (int) config('ai_assistant.knowledge.max_ai_assisted');
        $picked = [];
        $roots = [];
        $ai = $drive = $approved = 0;
        foreach ($rows as $row) {
            $score = (float) $row->getAttribute('score');
            if ($score < $min || count($picked) >= $limit) {
                continue;
            }
            $root = (string) ($row->source_root_quote_id ?? $row->id);
            $isDrive = $row->source === 'drive_import';
            if (isset($roots[$root]) || ($row->ai_assisted && $ai >= $maxAi) || ($isDrive && $drive * 2 > $approved)) {
                continue;
            }
            $roots[$root] = true;
            $ai += $row->ai_assisted ? 1 : 0;
            $isDrive ? $drive++ : $approved++;
            $picked[] = [
                'id' => (string) $row->id, 'source' => (string) $row->source, 'family' => (string) $row->family,
                'requirement_text' => (string) $row->requirement_text, 'scope' => $row->scope, 'exclusions' => $row->exclusions,
                'lines' => is_array($row->lines) ? $row->lines : [], 'ai_assisted' => (bool) $row->ai_assisted,
                'captured_at' => $row->captured_at->toIso8601String(), 'score' => $score,
            ];
        }

        return $picked;
    }

    public function suggestLines(array $fragments, ?string $family, int $perFragment, int $max): array
    {
        $fragments = array_values(array_filter($fragments, fn (string $f): bool => $this->terms($f) !== []));
        if ($fragments === [] || $perFragment < 1 || $max < 1) {
            return [];
        }
        // Puntuación en PHP (misma lógica en PostgreSQL y SQLite): cobertura de términos por prefijo + similitud de trigramas.
        $candidates = [];
        $rows = QuoteKnowledge::query()->where('status', 'active')->orderByDesc('captured_at')->limit(3000)->get(['source', 'family', 'lines', 'trust']);
        foreach ($rows as $row) {
            foreach (is_array($row->lines) ? $row->lines : [] as $line) {
                $description = is_array($line) && is_string($line['description'] ?? null) ? trim($line['description']) : '';
                if ($description === '') {
                    continue;
                }
                $candidates[] = [
                    'description' => $description, 'terms' => $this->terms($description), 'grams' => $this->trigrams($description),
                    'unit' => is_string($line['unit'] ?? null) ? $line['unit'] : null,
                    'family' => is_string($line['family'] ?? null) ? $line['family'] : (string) $row->family,
                    'quantity' => is_numeric($line['quantity'] ?? null) ? (string) $line['quantity'] : null,
                    'cents' => is_int($line['reference_price_cents'] ?? null) ? $line['reference_price_cents'] : null,
                    'currency' => ($line['currency'] ?? 'COP') === 'USD' ? 'USD' : 'COP',
                    'source' => $row->source === 'drive_import' ? 'drive_import' : 'approved_quote',
                    'trust' => (float) $row->trust, 'row_family' => (string) $row->family,
                ];
            }
        }
        $out = [];
        $total = 0;
        foreach ($fragments as $fragment) {
            $fTerms = $this->terms($fragment);
            $fGrams = $this->trigrams($fragment);
            $scored = [];
            foreach ($candidates as $c) {
                $hits = 0;
                foreach ($fTerms as $t) {
                    foreach ($c['terms'] as $d) {
                        if ($t === $d || (min(strlen($t), strlen($d)) >= 4 && substr($t, 0, 4) === substr($d, 0, 4)) || (strlen($t) >= 5 && strlen($d) >= 5 && levenshtein($t, $d) <= 1)) {
                            $hits++;
                            break;
                        }
                    }
                }
                if ($hits === 0) {
                    continue;
                }
                $score = (0.65 * ($hits / count($fTerms)) + 0.35 * $this->dice($fGrams, $c['grams'])) * (0.5 + $c['trust'] / 2) * ($family !== null && $c['row_family'] === $family ? 1.15 : 1.0);
                if ($score >= 0.2) {
                    $scored[] = [$score, $c];
                }
            }
            usort($scored, fn (array $a, array $b): int => $b[0] <=> $a[0]);
            $matches = [];
            foreach ($scored as [$score, $c]) {
                if (count($matches) >= $perFragment || $total + count($matches) >= $max) {
                    break;
                }
                foreach ($matches as $m) {
                    if ($this->dice($c['grams'], $m['_grams']) >= 0.85) {
                        continue 2;
                    }
                }
                $matches[] = [
                    'description' => $c['description'], 'unit' => $c['unit'], 'family' => $c['family'], 'quantity' => $c['quantity'],
                    'reference_price' => $c['cents'] === null ? null : DecimalMoney::format($c['cents']),
                    'currency' => $c['currency'], 'source' => $c['source'], 'score' => round($score, 4), '_grams' => $c['grams'],
                ];
            }
            $total += count($matches);
            $out[] = ['fragment' => $fragment, 'matches' => array_map(function (array $m): array {
                unset($m['_grams']);

                return $m;
            }, $matches)];
        }

        return $out;
    }

    /** @return list<string> */
    private function trigrams(string $text): array
    {
        $plain = ' '.implode(' ', $this->terms($text)).' ';
        $grams = [];
        for ($i = 0, $n = strlen($plain) - 2; $i < $n; $i++) {
            $grams[substr($plain, $i, 3)] = true;
        }

        return array_keys($grams);
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function dice(array $a, array $b): float
    {
        if ($a === [] || $b === []) {
            return 0.0;
        }

        return 2 * count(array_intersect($a, $b)) / (count($a) + count($b));
    }

    public function skuFrequencyForFamily(?string $family, int $limit): array
    {
        $counts = [];
        $rows = QuoteKnowledge::query()->where('status', 'active')->when($family !== null, fn ($q) => $q->where('family', $family))
            ->orderByDesc('captured_at')->limit(500)->get(['lines']);
        foreach ($rows as $row) {
            foreach (is_array($row->lines) ? $row->lines : [] as $line) {
                if (is_array($line) && is_string($line['sku'] ?? null) && $line['sku'] !== '') {
                    $counts[$line['sku']] = ($counts[$line['sku']] ?? 0) + 1;
                }
            }
        }
        arsort($counts);

        return array_slice(array_map('strval', array_keys($counts)), 0, max(0, $limit));
    }

    public function recordAssistRequest(array $row): void
    {
        QuoteAssistRequest::query()->create($row + ['created_at' => now()]);
    }

    public function approvedQuote(string $id): ?array
    {
        $quote = Quote::query()->whereKey($id)->whereIn('status', ['approved', 'issued'])->first();
        if ($quote === null) {
            return null;
        }
        $snapshot = json_decode((string) $quote->snapshot, true);

        return [
            'quote_id' => (string) $quote->id, 'root_id' => (string) ($quote->root_quote_id ?? $quote->id), 'revision' => (int) ($quote->revision_number ?? 1),
            'issued' => $quote->status === 'issued', 'snapshot' => is_array($snapshot) ? $snapshot : [],
        ];
    }

    public function markIssued(string $rootId): bool
    {
        $entry = QuoteKnowledge::query()->where('source_root_quote_id', $rootId)->lockForUpdate()->first();
        if ($entry === null) {
            return false;
        }
        $entry->forceFill(['issued' => true, 'trust' => '1.10'])->save();

        return true;
    }

    public function linkAssistRequest(string $requestId, int $userId, string $rootId): bool
    {
        return QuoteAssistRequest::query()->whereKey($requestId)->where('user_id', $userId)->whereNull('root_quote_id')
            ->where('created_at', '>=', now()->subHours(24))->update(['root_quote_id' => $rootId]) > 0;
    }

    public function assistRequestForRoot(string $rootId): ?array
    {
        $row = QuoteAssistRequest::query()->where('root_quote_id', $rootId)->orderByDesc('created_at')->first();

        return $row === null ? null : ['id' => (string) $row->id, 'proposed_lines' => array_values((array) $row->proposed_lines)];
    }

    public function recordAssistOutcome(string $requestId, array $counts): void
    {
        QuoteAssistRequest::query()->whereKey($requestId)->update([
            'kept_lines' => $counts['kept'], 'qty_changed_lines' => $counts['qty_changed'],
            'removed_lines' => $counts['removed'], 'added_lines' => $counts['added'], 'approved_at' => now(),
        ]);
    }

    public function paginate(array $filters): array
    {
        $perPage = 25;
        $query = QuoteKnowledge::query()
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['source']), fn ($q) => $q->where('source', $filters['source']))
            ->when(! empty($filters['family']), fn ($q) => $q->where('family', $filters['family']))
            ->when(! empty($filters['q']), function ($q) use ($filters) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower((string) $filters['q'])).'%';
                $q->where(fn ($w) => $w->whereRaw("lower(requirement_text) like ? escape '\\'", [$like])->orWhereRaw("lower(coalesce(lines_text, '')) like ? escape '\\'", [$like]));
            });
        $page = $query->orderByDesc('captured_at')->orderBy('id')->paginate($perPage, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));

        return [
            'data' => $page->getCollection()->map(fn (QuoteKnowledge $row): array => $this->present($row, false))->values()->all(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $perPage, 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ];
    }

    public function find(string $id): ?array
    {
        $row = QuoteKnowledge::query()->whereKey($id)->first();

        return $row === null ? null : $this->present($row, true);
    }

    public function lockForReview(string $id): ?array
    {
        $row = QuoteKnowledge::query()->whereKey($id)->lockForUpdate()->first();

        return $row === null ? null : [
            'status' => (string) $row->status, 'requirement_text' => (string) $row->requirement_text,
            'scope' => $row->scope, 'exclusions' => $row->exclusions,
            'scrub_flags' => array_values(array_map('strval', (array) $row->scrub_flags)),
        ];
    }

    public function setStatus(string $id, string $status, int $userId, string $reason): void
    {
        QuoteKnowledge::query()->whereKey($id)->update(['status' => $status, 'reviewed_by' => $userId, 'reviewed_at' => now(), 'review_reason' => $reason, 'updated_at' => now()]);
    }

    public function applyEdit(string $id, array $texts, array $scrubFlags, int $userId, string $reason): void
    {
        QuoteKnowledge::query()->whereKey($id)->update($texts + [
            'scrub_flags' => json_encode($scrubFlags), 'reviewed_by' => $userId, 'reviewed_at' => now(), 'review_reason' => $reason, 'updated_at' => now(),
        ]);
    }

    public function metrics(): array
    {
        $approvedRequests = QuoteAssistRequest::query()->whereNotNull('approved_at');
        $acceptance = static function ($query): array {
            $row = $query->selectRaw('count(*) as requests, coalesce(sum(kept_lines),0) as kept, coalesce(sum(kept_lines + qty_changed_lines + removed_lines),0) as proposed')->first();
            $proposed = (int) $row->proposed;

            return ['requests' => (int) $row->requests, 'kept_lines' => (int) $row->kept, 'proposed_lines' => $proposed, 'rate' => $proposed > 0 ? round((int) $row->kept / $proposed, 4) : null];
        };
        $totalRequests = QuoteAssistRequest::query()->count();
        $withPrecedents = QuoteAssistRequest::query()->where('precedents_sent', '>', 0)->count();
        $tokens = QuoteAssistRequest::query()->selectRaw('coalesce(sum(input_tokens),0) as input, coalesce(sum(output_tokens),0) as output')->first();

        $entries = QuoteKnowledge::query()->selectRaw('family, source, status, count(*) as total, max(captured_at) as freshest')->groupBy('family', 'source', 'status')->get();
        $groups = [];
        foreach ($entries as $row) {
            foreach (['family' => (string) $row->family, 'source' => (string) $row->source] as $dimension => $key) {
                $slot = &$groups[$dimension][$key];
                $slot ??= ['key' => $key, 'total' => 0, 'active' => 0, 'needs_review' => 0, 'excluded' => 0, 'freshest_at' => null];
                $slot['total'] += (int) $row->total;
                if (isset($slot[(string) $row->status])) {
                    $slot[(string) $row->status] += (int) $row->total;
                }
                $freshest = $row->freshest === null ? null : Carbon::parse($row->freshest)->toIso8601String();
                if ($freshest !== null && ($slot['freshest_at'] === null || $freshest > $slot['freshest_at'])) {
                    $slot['freshest_at'] = $freshest;
                }
                unset($slot);
            }
        }

        return [
            'acceptance' => $acceptance(clone $approvedRequests),
            'acceptance_with_precedents' => $acceptance((clone $approvedRequests)->where('precedents_sent', '>', 0)),
            'acceptance_without_precedents' => $acceptance((clone $approvedRequests)->where('precedents_sent', 0)),
            'coverage' => ['requests' => $totalRequests, 'with_precedents' => $withPrecedents, 'rate' => $totalRequests > 0 ? round($withPrecedents / $totalRequests, 4) : null],
            'tokens' => ['input' => (int) $tokens->input, 'output' => (int) $tokens->output],
            'entries' => ['total' => QuoteKnowledge::query()->count(), 'needs_review' => QuoteKnowledge::query()->where('status', 'needs_review')->count()],
            'by_family' => array_values($groups['family'] ?? []),
            'by_source' => array_values($groups['source'] ?? []),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(QuoteKnowledge $row, bool $detail): array
    {
        $lines = is_array($row->lines) ? $row->lines : [];
        $out = [
            'id' => (string) $row->id, 'source' => (string) $row->source, 'family' => (string) $row->family, 'status' => (string) $row->status,
            'requirement_text' => (string) $row->requirement_text, 'scope' => $row->scope, 'exclusions' => $row->exclusions,
            'trust' => (string) $row->trust, 'issued' => (bool) $row->issued, 'ai_assisted' => (bool) $row->ai_assisted,
            'scrub_flags' => array_values((array) $row->scrub_flags), 'lines_count' => count($lines),
            'captured_at' => $row->captured_at->toIso8601String(), 'reviewed_at' => $row->reviewed_at?->toIso8601String(), 'review_reason' => $row->review_reason,
        ];
        if ($detail) {
            $out['lines'] = array_map(function ($line): array {
                $line = is_array($line) ? $line : [];
                $cents = $line['reference_price_cents'] ?? null;
                $line['reference_price'] = is_int($cents) ? intdiv($cents, 100).'.'.str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT) : null;
                unset($line['reference_price_cents']);

                return $line;
            }, $lines);
        }

        return $out;
    }

    /**
     * Palabras de la consulta (sin acentos ni símbolos): evitan inyectar operadores en websearch_to_tsquery.
     *
     * @return list<string>
     */
    private function terms(string $text): array
    {
        $plain = strtr(mb_strtolower($text), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        preg_match_all('/[a-z0-9]{3,}/', $plain, $m);

        return array_slice(array_values(array_unique(array_diff($m[0], ['or', 'and', 'con', 'para', 'los', 'las', 'del', 'una', 'por']))), 0, 12);
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  list<string>  $extraComparable
     * @return 'created'|'updated'|'unchanged'
     */
    private function persist(?QuoteKnowledge $existing, array $entry, array $extraComparable): string
    {
        $status = $entry['risk'] === 'review' ? 'needs_review' : 'active';
        $values = [
            'source' => $entry['source'], 'source_root_quote_id' => $entry['source_root_quote_id'] ?? null,
            'source_revision' => $entry['source_revision'] ?? null, 'source_ref' => $entry['source_ref'] ?? null,
            'family' => $entry['family'], 'requirement_text' => $entry['requirement_text'],
            'scope' => $entry['scope'], 'exclusions' => $entry['exclusions'],
            'lines' => $entry['lines'], 'lines_text' => $entry['lines_text'],
            'status' => $status, 'trust' => $entry['trust'], 'issued' => $entry['issued'],
            'scrub_flags' => $entry['scrub_flags'],
        ];
        foreach (['ai_assisted', 'human_edit_ratio'] as $optional) {
            if (array_key_exists($optional, $entry)) {
                $values[$optional] = $entry[$optional];
            }
        }
        if ($existing === null) {
            QuoteKnowledge::query()->create(array_merge($values, ['id' => (string) Str::uuid(), 'captured_at' => now()]));

            return 'created';
        }
        if ($existing->reviewed_by !== null || $existing->status === 'excluded') {
            unset($values['status']);
        }
        $same = true;
        foreach (['family', 'requirement_text', 'scope', 'exclusions', 'lines', 'lines_text', 'scrub_flags', 'trust', ...$extraComparable] as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }
            $current = $existing->{$key};
            $same = $same && ($key === 'trust' ? (string) $current === (string) $values[$key] : ($key === 'human_edit_ratio' ? ($current === null ? null : (string) $current) == $values[$key] : $current == $values[$key]));
        }
        $same = $same && (! isset($values['status']) || $existing->status === $values['status']);
        if ($same) {
            return 'unchanged';
        }
        $existing->forceFill($values + ['captured_at' => now()])->save();

        return 'updated';
    }
}
