<?php

namespace App\Http\Controllers;

use App\Application\Company\PublishCompanyProfile;
use App\Domain\CompanyProfile;
use App\Http\Requests\PublishCompanyRequest;
use App\Repositories\Contracts\CompanyRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Arr;

final class CompanyController extends Controller
{
    /** @var list<string> */
    private const PUBLIC_FIELDS = [
        'legal_name', 'trade_name', 'nit', 'address', 'phone', 'email', 'website', 'signer_name', 'signer_title',
    ];

    public function __construct(private CompanyRepository $companies) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->present($this->companies->currentProfile())]);
    }

    public function publish(PublishCompanyRequest $request, PublishCompanyProfile $publish): JsonResponse
    {
        $profile = $publish->execute($request->validated(), $request->user());

        return response()->json(['data' => $this->present($profile)], 201);
    }

    /**
     * @param  array<string, mixed>|null  $profile
     * @return array<string, mixed>
     */
    private function present(?array $profile): array
    {
        $missingInput = $profile === null ? null : array_merge(
            Arr::only($profile, self::PUBLIC_FIELDS),
            ['bank_account' => $profile['bank_account_configured'] ?? false]
        );
        $missing = CompanyProfile::missing($missingInput);
        $publicFields = Arr::only($profile ?? array_fill_keys(self::PUBLIC_FIELDS, null), self::PUBLIC_FIELDS);

        return array_merge([
            'configured' => $profile !== null,
            'complete' => $missing === [],
            'missing' => $missing,
            'version' => $profile['version'] ?? null,
            'origin' => $profile['origin'] ?? null,
        ], $publicFields, [
            'bank_account_configured' => $profile['bank_account_configured'] ?? false,
            'bank_account_summary' => $profile['bank_account_summary'] ?? null,
            'updated_at' => $profile['updated_at'] ?? null,
            'updated_by_name' => $profile['updated_by_name'] ?? null,
        ]);
    }
}
