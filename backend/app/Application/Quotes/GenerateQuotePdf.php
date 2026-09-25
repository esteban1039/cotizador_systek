<?php

namespace App\Application\Quotes;

use App\Domain\Quotes\SnapshotCompatibility;
use App\Models\User;
use App\Repositories\Contracts\CompanyRepository;
use App\Repositories\Contracts\QuoteRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

final class GenerateQuotePdf
{
    public function __construct(private QuoteRepository $quotes, private CompanyRepository $companies) {}

    public function execute(string $id, User $actor): array
    {
        $record = $this->quotes->findVisible($id, $actor);
        abort_unless($record, 404);
        $snapshot = json_decode($record->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $snapshot = SnapshotCompatibility::normalize($snapshot, $record);
        $quote = Arr::only($snapshot, [
            'created_at', 'valid_until', 'currency', 'client_name', 'site_name',
            'scope', 'exclusions', 'payment_terms', 'warranty', 'validity_days',
            'validity_terms', 'observations', 'family', 'quote_number',
        ]);
        $quote['client_name'] ??= $this->quotes->clientName($record->client_id) ?? '';
        $quote['site_name'] ??= $this->quotes->siteName($record->site_id) ?? '';
        $quote['id'] = $record->id;
        $quote['status'] = $record->status;
        $quote['revision_number'] = (int) $record->revision_number;
        $quote['version_label'] = 'V'.$quote['revision_number'];
        $quote['totals'] = Arr::only($snapshot['totals'], ['gross', 'discount', 'subtotal', 'tax', 'total', 'vat_withholding', 'payable']);
        $quote['vat_withholding'] = $snapshot['vat_withholding'];
        $quote['lines'] = array_map(function (array $line): array {
            $safe = Arr::only($line, ['description', 'unit', 'quantity', 'price_cents', 'tax_bps', 'discount_bps']);
            $safe['amounts'] = Arr::only($line['amounts'], ['gross', 'discount', 'subtotal', 'tax', 'total']);

            return $safe;
        }, $snapshot['lines']);

        $profile = $this->companies->currentProfile();
        // Membrete público del borrador: nunca firmante ni datos bancarios.
        $quote['issuer'] = [
            'legal_name' => $profile['legal_name'] ?? null,
            'trade_name' => $profile['trade_name'] ?? null,
            'nit' => $profile['nit'] ?? null,
            'address' => $profile['address'] ?? null,
            'phone' => $profile['phone'] ?? null,
            'email' => $profile['email'] ?? null,
            'website' => $profile['website'] ?? null,
        ];

        $fontCache = storage_path('app/private/pdf-fonts');
        $tempDirectory = storage_path('app/private/pdf-temp');
        File::ensureDirectoryExists($fontCache, 0700);
        File::ensureDirectoryExists($tempDirectory, 0700);
        $options = new Options;
        $options->setFontCache($fontCache);
        $options->setTempDir($tempDirectory);
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot([resource_path('fonts')]);
        $pdf = new Dompdf($options);
        $pdf->setPaper('A4');
        $pdf->loadHtml(view('quotes.draft-pdf', ['quote' => $quote])->render(), 'UTF-8');
        $pdf->render();
        $pdf->getCanvas()->page_script(function (int $number, int $count, $canvas, $fonts): void {
            $normal = $fonts->getFont('DejaVu Sans', 'normal');
            $bold = $fonts->getFont('DejaVu Sans', 'bold');
            $green = [0.09, 0.41, 0.36];
            $muted = [0.32, 0.42, 0.40];
            $canvas->text(29, 26, 'SYSTEK', $bold, 16, $green);
            $canvas->text(452, 27, 'BORRADOR INTERNO', $bold, 7, [0.45, 0.33, 0.12]);
            $canvas->text(463, 38, 'No válido para envío', $normal, 6, $muted);
            $canvas->line(29, 59, 566, 59, $green, 1.4);
            $canvas->line(29, 808, 566, 808, [0.8, 0.85, 0.83], 0.5);
            $canvas->text(29, 815, 'Documento de trabajo. No constituye una propuesta comercial emitida.', $normal, 5.5, $muted);
            $canvas->text(430, 820, "Página {$number} de {$count}", $normal, 8, $muted);
        });

        $baseName = $quote['quote_number']
            ? $quote['quote_number'].'-'.$quote['version_label']
            : 'quote-'.$record->id.'-v'.$quote['revision_number'];

        return [
            'bytes' => $pdf->output(),
            'filename' => $baseName.'-borrador.pdf',
        ];
    }
}
