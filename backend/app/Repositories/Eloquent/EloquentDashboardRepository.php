<?php

namespace App\Repositories\Eloquent;

use App\Models\Quote;
use App\Models\User;
use App\Repositories\Contracts\DashboardRepository;
use Illuminate\Database\Eloquent\Builder;

final class EloquentDashboardRepository implements DashboardRepository
{
    private function visible(User $user): Builder
    {
        return Quote::query()->when($user->role === 'quoter', fn ($query) => $query->where('created_by', $user->id));
    }

    public function summary(User $user, string $asOf): array
    {
        $statuses = $this->visible($user)->selectRaw('status, COUNT(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $counts = ['total' => (int) $statuses->sum()];
        foreach (['draft', 'in_review', 'approved'] as $status) {
            $counts[$status] = (int) ($statuses[$status] ?? 0);
        }
        // Validity includes the final day in America/Bogota; expiry never changes the stored status.
        $counts['expired'] = $this->visible($user)->where('snapshot->valid_until', '<', $asOf)->count();
        $pending = $this->visible($user)->whereIn('status', ['draft', 'in_review'])
            ->leftJoin('clients', 'clients.id', '=', 'quotes.client_id')
            ->orderByDesc('quotes.created_at')->orderBy('quotes.id')->limit(5)
            ->get(['quotes.id', 'quotes.quote_number', 'quotes.revision_number', 'quotes.status', 'quotes.created_at', 'quotes.snapshot', 'clients.name as current_client_name'])
            ->map(function ($quote) use ($asOf) {
                $snapshot = json_decode($quote->snapshot, true, flags: JSON_THROW_ON_ERROR);
                $validUntil = $snapshot['valid_until'] ?? null;

                return [
                    'id' => $quote->id,
                    'quote_number' => $quote->quote_number,
                    'revision_number' => (int) $quote->revision_number,
                    'client_name' => $snapshot['client_name'] ?? $quote->current_client_name,
                    'scope' => $snapshot['scope'],
                    'status' => $quote->status,
                    'created_at' => $quote->created_at,
                    'valid_until' => $validUntil,
                    'is_expired' => $validUntil !== null && $validUntil < $asOf,
                ];
            })->all();

        return ['scope' => $user->role === 'quoter' ? 'own' : 'all', 'as_of' => $asOf, 'counts' => $counts, 'pending_quotes' => $pending];
    }
}
