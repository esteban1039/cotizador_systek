<?php

namespace App\Application\Quotes;

use App\Domain\Quotes\FollowupPolicy;
use App\Models\User;
use App\Repositories\Contracts\QuoteFollowupRepository;
use App\Repositories\Contracts\QuoteRepository;

final class ListQuoteFollowups
{
    public function __construct(
        private QuoteRepository $quotes,
        private QuoteFollowupRepository $followups,
        private FollowupPolicy $policy,
    ) {}

    /**
     * @return array{commercial_status: ?string, can_record_followup: bool, followups: list<array<string, mixed>>}
     */
    public function execute(string $id, User $actor): array
    {
        $quote = $this->quotes->findVisible($id, $actor);
        abort_unless($quote, 404);
        $issued = $quote->status === 'issued';
        $events = $issued ? $this->followups->forQuote($id) : collect();

        return [
            'commercial_status' => $issued ? $this->policy->commercialStatus($events->pluck('type')->all()) : null,
            'can_record_followup' => $issued && $this->policy->mayRecord($actor->role, $actor->id, $quote->created_by === null ? null : (int) $quote->created_by),
            'followups' => $events->all(),
        ];
    }
}
