<?php

namespace App\Repositories\Eloquent;

use App\Models\Client;
use App\Models\Contact;
use App\Models\Site;
use App\Repositories\Contracts\ClientRepository;
use Illuminate\Support\Collection;

final class EloquentClientRepository implements ClientRepository
{
    public function directory(): Collection
    {
        return Client::query()->with(['sites', 'contacts'])->orderBy('name')->get()
            ->map(fn (Client $client): array => array_merge($client->getAttributes(), [
                'sites' => $client->sites->map(fn (Site $site): array => $site->getAttributes())->all(),
                'contacts' => $client->contacts->map(fn (Contact $contact): array => $contact->getAttributes())->all(),
            ]));
    }

    public function exists(string $id): bool
    {
        return Client::query()->whereKey($id)->exists();
    }

    public function nitExists(string $nit): bool
    {
        return Client::query()->where('nit', $nit)->exists();
    }

    public function createClient(array $attributes): array
    {
        return Client::query()->create($attributes)->fresh()->getAttributes();
    }

    public function createSite(string $clientId, array $attributes): array
    {
        return Site::query()->create(array_merge($attributes, ['client_id' => $clientId]))->fresh()->getAttributes();
    }

    public function createContact(string $clientId, array $attributes): array
    {
        return Contact::query()->create(array_merge($attributes, ['client_id' => $clientId]))->fresh()->getAttributes();
    }

    public function lockedTaxProfile(string $clientId): ?bool
    {
        $client = Client::query()->whereKey($clientId)->sharedLock()->first();

        return $client ? (bool) $client->withholds_vat : null;
    }

    public function updateTaxProfile(string $clientId, bool $withholds): ?array
    {
        $client = Client::query()->whereKey($clientId)->lockForUpdate()->first();
        if (! $client) {
            return null;
        }
        $previous = (bool) $client->withholds_vat;
        $client->update(['withholds_vat' => $withholds]);

        return ['previous' => $previous, 'withholds_vat' => $withholds];
    }
}
