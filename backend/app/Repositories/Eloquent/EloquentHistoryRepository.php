<?php

namespace App\Repositories\Eloquent;

use App\Models\Client;
use App\Models\HistoricalDocument;
use App\Repositories\Contracts\HistoryRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class EloquentHistoryRepository implements HistoryRepository
{
    public function paginate(?string $search, ?string $status): LengthAwarePaginator
    {
        return HistoricalDocument::query()
            ->select(['id', 'source_id', 'title', 'client_name', 'client_nit', 'family', 'issued_on', 'status', 'linked_client_id', 'created_at'])
            ->when($status, fn ($query) => $query->where('status', $status))
            ->when($search, fn ($query) => $query->where(function ($query) use ($search) {
                $query->whereLike('title', '%'.$search.'%')->orWhereLike('client_name', '%'.$search.'%')->orWhereLike('source_id', '%'.$search.'%');
            }))->orderByDesc('created_at')->orderBy('id')->paginate(20);
    }

    public function find(string $id, bool $lock = false): ?array
    {
        $query = HistoricalDocument::query()->whereKey($id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first()?->toArray();
    }

    public function findSource(string $sourceId): ?array
    {
        $id = DB::table('historical_document_sources')->where('source_id', $sourceId)->value('document_id');

        return $id ? $this->find($id) : null;
    }

    public function registerSource(array $attributes): void
    {
        DB::table('historical_document_sources')->insert([...$attributes, 'created_at' => now()]);
    }

    public function sources(string $documentId): array
    {
        return DB::table('historical_document_sources')->where('document_id', $documentId)->orderBy('created_at')->orderBy('source_id')->get()->map(fn ($source) => (array) $source)->all();
    }

    public function findHash(string $hash): ?array
    {
        return HistoricalDocument::query()->where('content_hash', $hash)->first()?->toArray();
    }

    public function create(array $attributes): array
    {
        return HistoricalDocument::query()->create($attributes)->toArray();
    }

    public function review(string $id, array $attributes): array
    {
        $document = HistoricalDocument::query()->findOrFail($id);
        $document->update($attributes);

        return $document->fresh()->toArray();
    }

    public function candidates(?string $nit, ?string $name): array
    {
        $normalizedNit = preg_replace('/\D/u', '', $nit ?? '');
        $normalizeName = static fn (string $value): string => mb_strtolower(preg_replace('/\s+/u', ' ', trim($value)));
        $normalizedName = $normalizeName($name ?? '');
        $matches = [];
        foreach (Client::query()->select(['id', 'name', 'nit'])->orderBy('name')->cursor() as $client) {
            $nitMatch = $normalizedNit !== '' && $normalizedNit === preg_replace('/\D/u', '', $client->nit ?? '');
            $nameMatch = $normalizedName !== '' && $normalizedName === $normalizeName($client->name);
            if ($nitMatch || $nameMatch) {
                $matches[] = [...$client->toArray(), 'match' => $nitMatch ? 'nit' : 'name'];
            }
        }

        return $matches;
    }
}
