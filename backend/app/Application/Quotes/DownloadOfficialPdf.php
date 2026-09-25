<?php

namespace App\Application\Quotes;

use App\Domain\Audit;
use App\Models\User;
use App\Repositories\Contracts\QuoteEmissionRepository;
use App\Repositories\Contracts\QuoteRepository;

final class DownloadOfficialPdf
{
    public function __construct(private QuoteRepository $quotes, private QuoteEmissionRepository $emissions) {}

    /**
     * @return array{bytes: string, filename: string}
     */
    public function execute(string $id, User $actor): array
    {
        $record = $this->quotes->findVisible($id, $actor);
        abort_unless($record, 404);
        $emission = $this->emissions->forQuote($id);
        abort_unless($emission, 404, 'Esta cotización no tiene PDF oficial.');

        $bytes = $this->emissions->fileContent($emission['id']);
        if ($bytes === null || ! hash_equals($emission['pdf_sha256'], hash('sha256', $bytes))) {
            Audit::record($actor->id, 'quote.emission_integrity_failed', $id, ['emission_id' => $emission['id']]);
            abort(500, 'No se pudo entregar el documento.');
        }
        Audit::record($actor->id, 'quote.official_pdf_downloaded', $id, ['emission_id' => $emission['id'], 'pdf_sha256' => $emission['pdf_sha256']]);

        return ['bytes' => $bytes, 'filename' => $emission['filename']];
    }
}
