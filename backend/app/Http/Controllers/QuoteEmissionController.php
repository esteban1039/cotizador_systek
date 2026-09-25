<?php

namespace App\Http\Controllers;

use App\Application\Quotes\DownloadOfficialPdf;
use App\Application\Quotes\IssueQuote;
use App\Http\Requests\IssueQuoteRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class QuoteEmissionController extends Controller
{
    public function issue(IssueQuoteRequest $request, string $id, IssueQuote $issue): JsonResponse
    {
        $emission = $issue->execute($id, $request->user(), $request->validated('reason'));

        return response()->json(['data' => ['status' => 'issued', 'emission' => $emission]], 201);
    }

    public function officialPdf(Request $request, string $id, DownloadOfficialPdf $download): Response
    {
        $document = $download->execute($id, $request->user());

        return response($document['bytes'], 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$document['filename'].'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
