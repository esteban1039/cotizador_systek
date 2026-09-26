<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Domain\Quotes\AssistantProposal;
use App\Models\User;
use App\Repositories\Contracts\CatalogRepository;
use Illuminate\Support\Str;

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
        $snapshot = $this->catalog->assistantCatalog(now()->toDateString(), $family, (int) config('ai_assistant.max_catalog_items'));
        $items = $snapshot['items'];
        $audit = [
            'input_chars' => mb_strlen($text), 'family_hint' => $family, 'catalog_items_sent' => count($items),
            'catalog_truncated' => $snapshot['truncated'], 'model' => $model,
        ];
        $started = hrtime(true);
        $latency = fn (): int => (int) ((hrtime(true) - $started) / 1_000_000);

        try {
            $result = $this->client->propose(
                array_map(fn (array $i): array => ['sku' => $i['sku'], 'description' => $i['description'], 'family' => $i['family'], 'unit' => $i['unit']], $items),
                $text,
                $family,
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
        $proposal = $this->validator->validate($result['input'], $byKey);
        Audit::record($actor->id, 'quote.assist_requested', $requestId, array_merge($audit, [
            'model' => $result['model'], 'input_tokens' => $result['input_tokens'], 'output_tokens' => $result['output_tokens'],
            'latency_ms' => $latency(), 'outcome' => 'ok', 'lines_proposed' => count($proposal['lines']), 'lines_discarded' => $proposal['lines_discarded'],
        ]));

        return [
            'request_id' => $requestId, 'generated_by' => 'ai', 'model' => $result['model'],
            'family' => $proposal['family'], 'scope' => $proposal['scope'], 'exclusions' => $proposal['exclusions'],
            'lines' => $proposal['lines'], 'missing_information' => $proposal['missing_information'],
            'warnings' => $proposal['warnings'], 'catalog_truncated' => $snapshot['truncated'],
        ];
    }
}
