<?php

namespace App\Repositories\Eloquent;

use App\Models\Quote;
use App\Models\User;
use App\Repositories\Contracts\QuoteRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class EloquentQuoteRepository implements QuoteRepository
{
    public function lockRevisionRoot(string $id): void
    {
        Quote::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    public function nextRevisionNumber(string $rootId): int
    {
        return (int) Quote::query()->where(fn ($query) => $query->where('id', $rootId)->orWhere('root_quote_id', $rootId))->max('revision_number') + 1;
    }

    public function latestRevisionNumber(string $rootId): int
    {
        return $this->nextRevisionNumber($rootId) - 1;
    }

    public function approvingReview(string $id): ?object
    {
        return DB::table('quote_reviews')->where('quote_id', $id)->where('decision', 'approve')->orderByDesc('id')->first(['id', 'user_id']);
    }

    public function markIssued(string $id): void
    {
        Quote::query()->whereKey($id)->update(['status' => 'issued', 'updated_at' => now()]);
    }

    public function rootNumber(string $rootId): ?string
    {
        return Quote::query()->whereKey($rootId)->value('quote_number');
    }

    public function visibleRevisions(string $rootId, User $user): Collection
    {
        return $this->visible($user)->where(fn ($query) => $query->where('id', $rootId)->orWhere('root_quote_id', $rootId))
            ->orderBy('revision_number')->toBase()->get(['id', 'revision_number', 'status', 'created_at']);
    }

    public function create(array $record): void
    {
        Quote::query()->insert($record);
    }

    private function visible(User $user): Builder
    {
        return Quote::query()->when($user->role === 'quoter', fn ($query) => $query->where('created_by', $user->id));
    }

    public function visiblePage(User $user): LengthAwarePaginator
    {
        return $this->visible($user)->orderByDesc('created_at')->orderBy('id')->toBase()->paginate(20);
    }

    public function findVisible(string $id, User $user, bool $lock = false): ?object
    {
        $query = $this->visible($user)->where('id', $id);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->toBase()->first();
    }

    public function findForReview(string $id): ?object
    {
        return Quote::query()->whereKey($id)->lockForUpdate()->toBase()->first();
    }

    public function clientName(string $id): ?string
    {
        return DB::table('clients')->where('id', $id)->value('name');
    }

    public function siteName(string $id): ?string
    {
        return DB::table('sites')->where('id', $id)->value('name');
    }

    public function reviews(string $id): Collection
    {
        return DB::table('quote_reviews')->join('users', 'users.id', '=', 'quote_reviews.user_id')->where('quote_id', $id)->select('quote_reviews.decision', 'quote_reviews.reason', 'quote_reviews.created_at', 'users.name as user_name')->orderBy('quote_reviews.id')->get();
    }

    public function transition(string $id, string $status, array $review): void
    {
        Quote::query()->whereKey($id)->update(['status' => $status, 'updated_at' => now()]);
        DB::table('quote_reviews')->insert($review);
    }
}
