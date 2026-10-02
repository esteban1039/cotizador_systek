<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\Quotes\AssistantProposal;
use App\Domain\Quotes\ClauseText;
use App\Domain\Quotes\KnowledgeScrubber;
use App\Models\User;
use App\Repositories\Contracts\CatalogRepository;
use App\Repositories\Contracts\KnowledgeRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Solo lectura: lee el catálogo vigente, consulta al asistente y revalida la salida.
 * No guarda cotizaciones. La auditoría nunca incluye el texto ni la respuesta.
 */
final class ProposeQuoteDraft
{
    public function __construct(
        private CatalogRepository $catalog,
        private QuoteDraftAssistantClient $client,
        private AssistantProposal $validator,
        private KnowledgeRepository $knowledge,
        private KnowledgeScrubber $scrubber,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws AssistantDisabled
     * @throws AssistantFailed
     */
    public function execute(User $actor, string $text, ?string $family): array
    {
        $key = config('ai_assistant.api_key');
        if (! config('ai_assistant.enabled') || ! is_string($key) || $key === '') {
            throw new AssistantDisabled;
        }

        $requestId = (string) Str::uuid();
        $model = (string) config('ai_assistant.model');
        $useKnowledge = (bool) config('ai_assistant.knowledge.enabled');
        $retrieved = $useKnowledge ? $this->retrieve($text, $family) : [];
        $priority = [];
        foreach ($retrieved as $row) {
            foreach ($row['lines'] as $line) {
                if (is_array($line) && is_string($line['sku'] ?? null) && $line['sku'] !== '') {
                    $priority[$line['sku']] = true;
                }
            }
        }
        if ($useKnowledge) {
            $topFamily = $family ?? ($retrieved[0]['family'] ?? null);
            foreach ($this->safely(fn (): array => $this->knowledge->skuFrequencyForFamily($topFamily, 40), []) as $sku) {
                $priority[$sku] = true;
            }
        }
        $snapshot = $this->catalog->assistantCatalog(now()->toDateString(), $family, (int) config('ai_assistant.max_catalog_items'), array_map('strval', array_keys($priority)));
        $precedents = [];
        $sent = [];
        foreach ($retrieved as $i => $row) {
            $id = 'P'.($i + 1);
            $precedents[] = $this->precedent($row, $id);
            $sent[$id] = ['source' => $row['source'], 'captured_at' => $row['captured_at'], 'knowledge_id' => $row['id']];
        }
        $items = $snapshot['items'];
        $audit = [
            'input_chars' => mb_strlen($text), 'family_hint' => $family, 'catalog_items_sent' => count($items),
            'catalog_truncated' => $snapshot['truncated'], 'model' => $model,
        ] + ($useKnowledge ? ['precedents_sent' => count($precedents)] : []);
        $started = hrtime(true);
        $latency = fn (): int => (int) ((hrtime(true) - $started) / 1_000_000);

        try {
            $result = $this->client->propose(
                array_map(fn (array $i): array => ['sku' => $i['sku'], 'description' => $this->safeDescription($i['description']), 'family' => $i['family'], 'unit' => $i['unit']], $items),
                $text,
                $family,
                $precedents,
            );
        } catch (AssistantDisabled $e) {
            throw $e;
        } catch (AssistantFailed $e) {
            Audit::record($actor->id, 'quote.assist_requested', $requestId, $audit + [
                'input_tokens' => null, 'output_tokens' => null, 'latency_ms' => $latency(),
                'outcome' => $e->outcome, 'lines_proposed' => 0, 'lines_discarded' => 0,
            ]);
            throw $e;
        }

        $byKey = [];
        foreach ($items as $item) {
            $byKey[$item['sku']] = ['price_version_id' => $item['price_version_id'], 'description' => $item['description'], 'unit' => $item['unit'], 'family' => $item['family']];
        }
        $proposal = $this->validator->validate($result['input'], $byKey, $sent);
        Audit::record($actor->id, 'quote.assist_requested', $requestId, array_merge($audit, [
            'model' => $result['model'], 'input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens'],
            'latency_ms' => $latency(), 'outcome' => 'ok', 'lines_proposed' => count($proposal['lines']), 'lines_discarded' => $proposal['lines_discarded'],
        ]));

        if ($useKnowledge) {
            $this->safely(fn () => DB::transaction(fn () => $this->knowledge->recordAssistRequest([
                'id' => $requestId, 'user_id' => $actor->id, 'family' => $proposal['family'] ?? $family,
                'knowledge_ids' => array_column($sent, 'knowledge_id'),
                'proposed_lines' => array_map(fn (array $l): array => ['sku' => $l['sku'], 'quantity' => $l['quantity']], $proposal['lines']),
                'catalog_items_sent' => count($items), 'precedents_sent' => count($precedents), 'model' => $result['model'],
                'input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens'], 'latency_ms' => $latency(),
            ])), null);
        }

        return [
            'request_id' => $requestId, 'generated_by' => 'ai', 'model' => $result['model'],
            'family' => $proposal['family'], 'scope' => $proposal['scope'], 'exclusions' => $proposal['exclusions'],
            'lines' => $proposal['lines'], 'missing_information' => $proposal['missing_information'],
            'warnings' => $proposal['warnings'], 'catalog_truncated' => $snapshot['truncated'],
        ] + ($useKnowledge ? ['precedents_used' => $proposal['precedents_used']] : []);
    }

    /**
     * Un fallo de la base de conocimiento nunca rompe al asistente.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    private function safely(callable $callback, mixed $fallback): mixed
    {
        try {
            return $callback();
        } catch (Throwable) {
            return $fallback;
        }
    }

    /** @return list<array<string, mixed>> */
    private function retrieve(string $text, ?string $family): array
    {
        return $this->safely(fn (): array => $this->knowledge->similar($text, $family, (int) config('ai_assistant.knowledge.top_k')), []);
    }

    /**
     * Lista cerrada: sin precios, moneda ni cliente. Los textos se vuelven a depurar y se acotan a MAX_PRECEDENT_CHARS.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function precedent(array $row, string $id): array
    {
        $max = max(200, (int) config('ai_assistant.knowledge.max_precedent_chars'));
        $descMax = (int) config('ai_assistant.max_description_chars');
        $text = function (?string $value, int $cap): ?string {
            if ($value === null || trim($value) === '') {
                return null;
            }
            $clean = $this->scrubber->scrub($value);

            return $clean['risk'] === 'review' || $this->safeDescription($clean['text']) !== $clean['text'] ? null : mb_substr($clean['text'], 0, $cap);
        };
        $lines = [];
        foreach ($row['lines'] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $sku = is_string($line['sku'] ?? null) && preg_match('/^[A-Z0-9_-]{1,60}$/D', $line['sku']) === 1 ? $line['sku'] : null;
            $quantity = is_string($line['quantity'] ?? null) && preg_match('/^\d{1,5}(\.\d{1,3})?$/D', $line['quantity']) === 1 ? $line['quantity'] : null;
            $lines[] = [
                'sku' => $sku, 'description' => $text(is_string($line['description'] ?? null) ? $line['description'] : null, $descMax) ?? '(descripción omitida)',
                'unit' => is_string($line['unit'] ?? null) && in_array($line['unit'], ['unidad', 'metro', 'hora', 'servicio', 'licencia'], true) ? $line['unit'] : null, 'quantity' => $quantity,
            ];
        }
        $precedent = [
            'id' => $id, 'source' => $row['source'], 'family' => $row['family'],
            'requirement' => $text($row['requirement_text'], 400), 'scope' => $text($row['scope'], 400), 'exclusions' => $text($row['exclusions'], 300), 'lines' => $lines,
        ];
        while (mb_strlen((string) json_encode($precedent, JSON_UNESCAPED_UNICODE)) > $max && $precedent['lines'] !== []) {
            array_pop($precedent['lines']);
        }
        if (mb_strlen((string) json_encode($precedent, JSON_UNESCAPED_UNICODE)) > $max) {
            $precedent['scope'] = $precedent['exclusions'] = null;
            $precedent['requirement'] = $precedent['requirement'] === null ? null : mb_substr($precedent['requirement'], 0, 200);
        }

        return $precedent;
    }

    /** Una descripción con cuentas, correos o montos no sale hacia el proveedor (datos sucios del catálogo). */
    private function safeDescription(string $description): string
    {
        if (ClauseText::looksLikeBankAccount($description) || preg_match('/[^\s@]+@[^\s@]+|\$/u', $description) === 1) {
            return '(descripción omitida)';
        }

        return $description;
    }
}
