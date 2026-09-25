<?php

namespace App\Http\Controllers;

use App\Repositories\Contracts\DashboardRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DashboardController extends Controller
{
    public function __construct(private DashboardRepository $dashboard) {}

    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->dashboard->summary($request->user(), now('America/Bogota')->toDateString())])
            ->header('Cache-Control', 'private, no-store');
    }
}
