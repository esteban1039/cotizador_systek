<?php

namespace App\Http\Controllers;

use App\Application\Quotes\GenerateQuotePdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class QuotePdfController extends Controller
{
    public function __construct(private GenerateQuotePdf $generate) {}

    public function show(Request $request, string $id): Response
    {
        $document = $this->generate->execute($id, $request->user());

        return response($document['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document['filename'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
