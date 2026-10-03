<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<title>Cotización {{ $quote['quote_number'] }} - {{ $quote['version_label'] }}</title>
<style>
@page { margin: 96px 38px 62px; }
body { font-family: 'DejaVu Sans', sans-serif; font-size: 9px; color: #243c3a; line-height: 1.5; }
header { position: fixed; top: -72px; left: 0; right: 0; height: 54px; border-bottom: 2px solid #17685d; }
.brand { font-size: 20px; font-weight: bold; letter-spacing: 2px; color: #17685d; }
.internal { float: right; text-align: right; font-size: 8px; color: #735520; padding-top: 5px; }
footer { position: fixed; bottom: -38px; left: 0; right: 0; font-size: 7px; color: #526a67; border-top: 1px solid #ccd9d5; padding-top: 7px; }
h1 { font-size: 23px; margin: 5px 0; font-weight: normal; }
h2 { font-size: 11px; color: #17685d; margin: 19px 0 7px; page-break-after: avoid; }
p { margin: 0 0 8px; }
.label { font-size: 8px; color: #617874; text-transform: uppercase; letter-spacing: 1px; }
.notice { background: #f7f0dc; border-left: 3px solid #ac893f; padding: 10px 12px; margin: 13px 0 17px; }
.metadata { width: 100%; border-collapse: collapse; margin-bottom: 13px; }
.metadata td { width: 33%; vertical-align: top; padding: 7px 10px 7px 0; }
.value { font-weight: bold; }
.text { white-space: pre-wrap; overflow-wrap: break-word; word-wrap: break-word; }
.items { width: 100%; border-collapse: collapse; table-layout: fixed; font-size: 8px; }
.items thead { display: table-header-group; }
.items th { background: #17685d; color: #fff; padding: 8px 5px; text-align: left; font-weight: normal; }
.items td { border-bottom: 1px solid #dce5e1; padding: 9px 5px; vertical-align: top; word-wrap: break-word; }
.items tr { page-break-inside: avoid; }
.num { text-align: right !important; }
.muted { color: #617874; font-size: 7px; }
.totals { width: 59%; margin: 16px 0 12px auto; border-collapse: collapse; page-break-inside: avoid; }
.totals td { padding: 4px 7px; }
.totals .total td { background: #eaf2ee; font-size: 13px; color: #17685d; font-weight: bold; padding: 9px 7px; }
.conditions { margin-top: 12px; }
.reference { font-size: 7px; color: #617874; }
</style>
</head>
<body>
@php
    // Formatting uses strings and integer cents only; no monetary float conversion.
    $money = static function ($amount) {
        [$whole, $fraction] = array_pad(explode('.', (string) $amount, 2), 2, '00');
        return '$ '.preg_replace('/\B(?=(\d{3})+(?!\d))/', '.', $whole).','.str_pad($fraction, 2, '0');
    };
    $unitMoney = static fn ($cents) => $money(intdiv((int) $cents, 100).'.'.str_pad((string) ((int) $cents % 100), 2, '0', STR_PAD_LEFT));
    $percent = static fn ($bps) => intdiv((int) $bps, 100).','.str_pad((string) ((int) $bps % 100), 2, '0', STR_PAD_LEFT).' %';
    $versionLabel = $quote['version_label'] ?? ('V'.$quote['revision_number']);
    $reference = $quote['quote_number'].' · '.$versionLabel;
    $accountTypes = ['savings' => 'Ahorros', 'checking' => 'Corriente'];
    $issuer = $quote['issuer'] ?? [];
@endphp


<p class="label">Cotización oficial / Versión {{ $quote['revision_number'] }} / COP</p>
@if (! empty($issuer['legal_name']))
<p class="reference">{{ $issuer['legal_name'] }}{{ $issuer['nit'] ? ' · NIT '.$issuer['nit'] : '' }}<br>{{ collect([$issuer['address'] ?? null, $issuer['phone'] ?? null, $issuer['email'] ?? null, $issuer['website'] ?? null])->filter()->implode(' · ') }}</p>
@endif
<h1>{{ $quote['client_name'] ?: 'Cliente registrado' }}</h1>
@if (! empty($quote['site_name']))
<p>{{ $quote['site_name'] }}</p>
@endif
<table class="metadata"><tr><td><span class="label">Fecha de emisión</span><br><span class="value">{{ substr($issuedAt, 0, 10) }}</span></td><td><span class="label">Válida hasta</span><br><span class="value">{{ $quote['valid_until'] }}</span></td><td><span class="label">Moneda</span><br><span class="value">Pesos colombianos (COP)</span></td></tr></table>
<p class="reference">{{ $reference }}</p>
<h2>Alcance</h2>
<p class="text">{{ $quote['scope'] }}</p>
<h2>Partidas de la propuesta</h2>
<table class="items">
<colgroup><col style="width: 33%"><col style="width: 9%"><col style="width: 18%"><col style="width: 13%"><col style="width: 27%"></colgroup>
<tbody>
@foreach ($quote['lines'] as $line)
<tr><td style="width: 33%">{{ $line['description'] }}<br><span class="muted">{{ $line['unit'] }}</span></td><td style="width: 9%" class="num"><span class="muted">Cant.</span><br>{{ $line['quantity'] }}</td><td style="width: 18%" class="num"><span class="muted">Precio unitario</span><br>{{ $unitMoney($line['price_cents']) }}</td><td style="width: 13%" class="num"><span class="muted">Dto. / Imp.</span><br>{{ $percent($line['discount_bps']) }}<br>{{ $percent($line['tax_bps']) }}</td><td style="width: 27%" class="num"><span class="muted">Total con impuesto</span><br>{{ $money($line['amounts']['total']) }}</td></tr>
@endforeach
</tbody></table>
<table class="totals">
<tr><td>Importe antes de descuentos</td><td class="num">{{ $money($quote['totals']['gross']) }}</td></tr>
<tr><td>Descuentos</td><td class="num">{{ $money($quote['totals']['discount']) }}</td></tr>
<tr><td>Subtotal</td><td class="num">{{ $money($quote['totals']['subtotal']) }}</td></tr>
<tr><td>Impuestos</td><td class="num">{{ $money($quote['totals']['tax']) }}</td></tr>
<tr><td>Total COP</td><td class="num">{{ $money($quote['totals']['total']) }}</td></tr>
@if (! empty($quote['vat_withholding']['applied']))
<tr><td>ReteIVA ({{ $percent($quote['vat_withholding']['rate_bps']) }} sobre IVA)</td><td class="num">-{{ $money($quote['totals']['vat_withholding']) }}</td></tr>
@endif
<tr class="total"><td>Total a pagar COP</td><td class="num">{{ $money($quote['totals']['payable']) }}</td></tr>
</table>
<div class="conditions"><h2>Exclusiones</h2><p class="text">{{ $quote['exclusions'] }}</p>
<h2>Forma de pago</h2><p class="text">{{ $quote['payment_terms'] }}</p>
<h2>Garantía</h2><p class="text">{{ $quote['warranty'] }}</p>
@if (! empty($quote['validity_terms']))
<h2>Vigencia</h2><p class="text">{{ $quote['validity_terms'] }}</p>
@endif
@if (! empty($quote['observations']))
<h2>Observaciones</h2><p class="text">{{ $quote['observations'] }}</p>
@endif
</div>
<h2>Datos para el pago</h2>
<p class="text">Banco: {{ $bank['bank_name'] }}<br>Tipo de cuenta: {{ $accountTypes[$bank['account_type']] ?? $bank['account_type'] }}<br>Número de cuenta: {{ $bank['account_number'] }}@if (! empty($bank['account_holder']))<br>Titular: {{ $bank['account_holder'] }}@endif</p>
<h2>Firma</h2>
<p><span class="value">{{ $issuer['signer_name'] ?? '' }}</span><br>{{ $issuer['signer_title'] ?? '' }}<br>{{ $issuer['legal_name'] ?? '' }}</p>
<p class="reference">Id de verificación: {{ $verificationId }}</p>
</body>
</html>
