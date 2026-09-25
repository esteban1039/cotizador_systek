<?php

namespace App\Application\Quotes;

use App\Domain\Quotes\SnapshotCompatibility;
use App\Models\User;
use App\Repositories\Contracts\CompanyRepository;
use App\Repositories\Contracts\QuoteRepository;

final class GenerateQuotePdf
{
    public function __construct(private QuoteRepository $quotes, private CompanyRepository $companies, private QuotePdfRenderer $renderer) {}

    public function execute(string $id, User $actor): array
    {
        $record = $this->quotes->findVisible($id, $actor);
        abort_unless($record, 404);
        abort_if($record->status === 'issued', 409, 'La cotización ya fue emitida; descarga el PDF oficial.');
        $snapshot = json_decode($record->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $snapshot = SnapshotCompatibility::normalize($snapshot, $record);
        $quote = $this->renderer->quoteData($record, $snapshot);

        $profile = $this->companies->currentProfile();
        // Membrete público del borrador: nunca firmante ni datos bancarios.
        $quote['issuer'] = [
            'legal_name' => $profile['legal_name'] ?? null,
            'trade_name' => $profile['trade_name'] ?? null,
            'nit' => $profile['nit'] ?? null,
            'address' => $profile['address'] ?? null,
            'phone' => $profile['phone'] ?? null,
            'email' => $profile['email'] ?? null,
            'website' => $profile['website'] ?? null,
        ];

        $bytes = $this->renderer->draft($quote);

        $baseName = $quote['quote_number']
            ? $quote['quote_number'].'-'.$quote['version_label']
            : 'quote-'.$record->id.'-v'.$quote['revision_number'];

        return [
            'bytes' => $bytes,
            'filename' => $baseName.'-borrador.pdf',
        ];
    }
}
