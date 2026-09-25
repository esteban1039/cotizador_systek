<?php

namespace App\Repositories\Eloquent;

use App\Models\CompanyVersion;
use App\Models\QuoteEmission;
use App\Models\QuoteEmissionFile;
use App\Models\User;
use App\Repositories\Contracts\QuoteEmissionRepository;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Collection;

final class EloquentQuoteEmissionRepository implements QuoteEmissionRepository
{
    public function create(array $attributes, string $pdfBytes): void
    {
        QuoteEmission::query()->create($attributes);
        QuoteEmissionFile::query()->create(['emission_id' => $attributes['id'], 'content' => base64_encode($pdfBytes)]);
    }

    public function forQuote(string $quoteId): ?array
    {
        $emission = QuoteEmission::query()->where('quote_id', $quoteId)->first();

        return $emission ? $this->present($emission) : null;
    }

    public function activeForRoot(string $rootId): Collection
    {
        return QuoteEmission::query()->where('root_quote_id', $rootId)->whereNull('superseded_at')->get()
            ->map(fn (QuoteEmission $emission): array => $this->present($emission))->values();
    }

    public function markSuperseded(string $emissionId, string $byEmissionId): void
    {
        QuoteEmission::query()->whereKey($emissionId)->update(['superseded_at' => now(), 'superseded_by' => $byEmissionId]);
    }

    public function fileContent(string $emissionId): ?string
    {
        try {
            $file = QuoteEmissionFile::query()->whereKey($emissionId)->first();
            $bytes = $file ? base64_decode((string) $file->content, true) : false;
        } catch (DecryptException) {
            return null;
        }

        return $bytes === false ? null : $bytes;
    }

    /**
     * Sin banco: solo `bank_summary` enmascarado se guarda, y no se expone.
     *
     * @return array<string, mixed>
     */
    private function present(QuoteEmission $emission): array
    {
        $issuer = $emission->issued_by ? User::query()->whereKey($emission->issued_by)->value('name') : null;
        $company = CompanyVersion::query()->whereKey($emission->company_version_id)->value('version');
        $supersededBy = $emission->superseded_by
            ? QuoteEmission::query()->whereKey($emission->superseded_by)->value('revision_number')
            : null;

        return [
            'id' => $emission->id,
            'quote_id' => $emission->quote_id,
            'root_quote_id' => $emission->root_quote_id,
            'quote_number' => $emission->quote_number,
            'revision_number' => $emission->revision_number,
            'version_label' => 'V'.$emission->revision_number,
            'issued_at' => $emission->issued_at?->toIso8601String(),
            'issued_by' => $issuer,
            'filename' => $emission->filename,
            'pdf_sha256' => $emission->pdf_sha256,
            'snapshot_sha256' => $emission->snapshot_sha256,
            'company_version' => $company === null ? null : (int) $company,
            'superseded_at' => $emission->superseded_at?->toIso8601String(),
            'superseded_by_revision' => $supersededBy === null ? null : (int) $supersededBy,
        ];
    }
}
