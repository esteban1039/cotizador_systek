<?php

namespace App\Application\Quotes;

use App\Repositories\Contracts\QuoteRepository;
use Composer\InstalledVersions;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;

/**
 * Render de PDF con dompdf, compartido por el borrador y el documento oficial.
 */
final class QuotePdfRenderer
{
    public const OFFICIAL_TEMPLATE_VERSION = 'official-1';

    public function __construct(private QuoteRepository $quotes) {}

    /**
     * Datos seguros de la cotización (sin costos, márgenes ni autores).
     *
     * @param  array<string, mixed>  $snapshot  Ya normalizada.
     * @return array<string, mixed>
     */
    public function quoteData(object $record, array $snapshot): array
    {
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

        return $quote;
    }

    /**
     * @param  array<string, mixed>  $quote
     */
    public function draft(array $quote): string
    {
        $pdf = $this->dompdf(view('quotes.draft-pdf', ['quote' => $quote])->render());
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

        return $pdf->output();
    }

    /**
     * @param  array<string, mixed>  $quote
     * @param  array<string, mixed>  $bank  Cuenta completa: solo para el bloque de pago del PDF.
     */
    public function official(array $quote, #[\SensitiveParameter] array $bank, string $verificationId, string $issuedAt): string
    {
        $pdf = $this->dompdf(view('quotes.official-pdf', [
            'quote' => $quote, 'bank' => $bank, 'verificationId' => $verificationId, 'issuedAt' => $issuedAt,
        ])->render());
        $reference = $quote['quote_number'].' · '.$quote['version_label'];
        $pdf->getCanvas()->page_script(function (int $number, int $count, $canvas, $fonts) use ($reference, $verificationId): void {
            $normal = $fonts->getFont('DejaVu Sans', 'normal');
            $bold = $fonts->getFont('DejaVu Sans', 'bold');
            $green = [0.09, 0.41, 0.36];
            $muted = [0.32, 0.42, 0.40];
            $canvas->text(29, 26, 'SYSTEK', $bold, 16, $green);
            $canvas->text(440, 27, $reference, $bold, 8, $green);
            $canvas->line(29, 59, 566, 59, $green, 1.4);
            $canvas->line(29, 808, 566, 808, [0.8, 0.85, 0.83], 0.5);
            $canvas->text(29, 815, 'Id de verificación: '.$verificationId, $normal, 5.5, $muted);
            $canvas->text(430, 820, "Página {$number} de {$count}", $normal, 8, $muted);
        });
        $pdf->addInfo('CreationDate', $issuedAt);

        return $pdf->output();
    }

    public function rendererVersion(): string
    {
        return 'dompdf/'.(InstalledVersions::getPrettyVersion('dompdf/dompdf') ?? 'unknown');
    }

    private function dompdf(string $html): Dompdf
    {
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
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();

        return $pdf;
    }
}
