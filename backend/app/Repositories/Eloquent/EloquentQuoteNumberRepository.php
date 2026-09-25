<?php

namespace App\Repositories\Eloquent;

use App\Repositories\Contracts\QuoteNumberRepository;
use Illuminate\Support\Facades\DB;

final class EloquentQuoteNumberRepository implements QuoteNumberRepository
{
    public function allocate(int $year): int
    {
        DB::table('quote_number_sequences')->insertOrIgnore([
            'year' => $year, 'last_number' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $current = (int) DB::table('quote_number_sequences')->where('year', $year)->lockForUpdate()->value('last_number');
        $next = $current + 1;
        DB::table('quote_number_sequences')->where('year', $year)->update(['last_number' => $next, 'updated_at' => now()]);

        return $next;
    }
}
