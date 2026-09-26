<?php

namespace App\Repositories\Eloquent;

use App\Models\QuoteFollowup;
use App\Models\User;
use App\Repositories\Contracts\QuoteFollowupRepository;
use Illuminate\Support\Collection;

final class EloquentQuoteFollowupRepository implements QuoteFollowupRepository
{
    public function create(array $attributes): void
    {
        QuoteFollowup::query()->create($attributes);
    }

    public function forQuote(string $quoteId): Collection
    {
        $followups = QuoteFollowup::query()->where('quote_id', $quoteId)->orderBy('id')->get();
        $names = User::query()->whereIn('id', $followups->pluck('created_by')->unique())->pluck('name', 'id');

        return $followups->map(fn (QuoteFollowup $followup): array => [
            'id' => $followup->id,
            'type' => $followup->type,
            'channel' => $followup->channel,
            'occurred_at' => $followup->occurred_at->toIso8601String(),
            'note' => $followup->note,
            'created_by' => $names[$followup->created_by] ?? null,
            'created_at' => $followup->created_at->toIso8601String(),
        ])->values();
    }
}
