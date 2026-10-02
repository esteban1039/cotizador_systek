<?php

namespace Tests\Feature;

use App\Models\QuoteKnowledge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class QuoteLineSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    private const TEXT = 'se necesita canalizacion 20 mts, cableado utp 30 mts, 8 camaras analagos 2mpx, dvr 8 canels, disco duro 1tb, mano de obra';

    /** @param array<string, mixed> $extra */
    private function seedLine(string $description, ?int $cents, string $status = 'active', string $currency = 'COP', array $extra = []): void
    {
        QuoteKnowledge::factory()->create($extra + [
            'status' => $status,
            'lines' => [['sku' => null, 'description' => $description, 'unit' => 'unidad', 'quantity' => null, 'family' => 'cctv', 'reference_price_cents' => $cents, 'currency' => $currency, 'unit_cost_cents' => 123]],
            'lines_text' => $description,
        ]);
    }

    private function seedAll(): void
    {
        $this->seedLine('Canalización tubería EMT 3/4', 150000);
        $this->seedLine('Cable UTP Cat6 por metro', 3200);
        $this->seedLine('Cámara análoga bala 2MP', 37500000);
        $this->seedLine('DVR 8 canales 1080p', 45000000);
        $this->seedLine('Disco duro 1TB vigilancia', 28000000);
        $this->seedLine('Mano de obra instalación', 12000000);
    }

    public function test_returns_matches_per_fragment_from_knowledge_only(): void
    {
        $this->seedAll();
        Sanctum::actingAs(User::factory()->create(['role' => 'quoter']));
        $res = $this->getJson('/api/v1/quotes/line-suggestions?'.http_build_query(['q' => self::TEXT]))->assertOk();
        $res->assertHeader('Cache-Control');
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $data = $res->json('data');
        $this->assertCount(6, $data);
        $by = fn (string $needle): array => collect($data)->first(fn ($d) => str_contains($d['fragment'], $needle))['matches'];
        $this->assertStringContainsString('Cámara', $by('camaras')[0]['description']);
        $this->assertSame('375000.00', $by('camaras')[0]['reference_price']);
        $this->assertStringContainsString('DVR', $by('dvr')[0]['description']);
        $this->assertStringContainsString('Disco', $by('disco')[0]['description']);
        $this->assertStringContainsString('UTP', $by('utp')[0]['description']);
        $this->assertStringContainsString('Canalización', $by('canalizacion')[0]['description']);
        $this->assertStringContainsString('Mano de obra', $by('mano')[0]['description']);
        $json = json_encode($data);
        foreach (['cost', 'quote_id', 'client', 'source_ref', 'unit_cost', 'REF-'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $json);
        }
        foreach (['fragment', 'matches'] as $k) {
            $this->assertArrayHasKey($k, $data[0]);
        }
        foreach (['description', 'unit', 'family', 'quantity', 'reference_price', 'currency', 'source', 'score'] as $k) {
            $this->assertArrayHasKey($k, $data[0]['matches'][0] ?? $by('camaras')[0]);
        }
    }

    public function test_only_active_entries_and_usd_is_marked(): void
    {
        $this->seedLine('Cámara análoga pendiente', 100, 'needs_review');
        $this->seedLine('Cámara análoga excluida', 100, 'excluded');
        $this->seedLine('Cámara domo importada', 729000, 'active', 'USD');
        Sanctum::actingAs(User::factory()->create(['role' => 'approver']));
        $matches = $this->getJson('/api/v1/quotes/line-suggestions?q=camaras')->assertOk()->json('data.0.matches');
        $this->assertCount(1, $matches);
        $this->assertSame('USD', $matches[0]['currency']);
        $this->assertSame('7290.00', $matches[0]['reference_price']);
    }

    public function test_roles_validation_and_throttle(): void
    {
        $this->getJson('/api/v1/quotes/line-suggestions?q=camaras')->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create(['role' => 'admin']));
        $this->getJson('/api/v1/quotes/line-suggestions?q=camaras')->assertOk();
        $this->getJson('/api/v1/quotes/line-suggestions?q=ab')->assertUnprocessable();
        $this->getJson('/api/v1/quotes/line-suggestions?q=camaras&family=xx')->assertUnprocessable();
        for ($i = 0; $i < 30; $i++) {
            $last = $this->getJson('/api/v1/quotes/line-suggestions?q=camaras');
        }
        $last->assertStatus(429);
    }
}
