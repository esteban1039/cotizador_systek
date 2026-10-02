<?php

namespace App\Application\Quotes;

interface QuoteDraftAssistantClient
{
    /**
     * @param  list<array{sku: string, description: string, family: string, unit: string}>  $catalog
     * @param  list<array<string, mixed>>  $precedents  ya sin dinero ni datos de cliente; ids P1..Pn
     * @return array{input: array<string, mixed>, model: string, input_tokens: int, output_tokens: int}
     *
     * @throws AssistantDisabled
     * @throws AssistantFailed
     */
    public function propose(array $catalog, string $text, ?string $family, array $precedents = []): array;
}
