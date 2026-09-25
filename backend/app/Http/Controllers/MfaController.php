<?php

namespace App\Http\Controllers;

use App\Application\Identity\MfaPolicy;
use App\Application\Identity\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MfaController extends Controller
{
    public function __construct(private MfaService $mfa, private MfaPolicy $policy) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json(['required' => $this->policy->required($request->user()), 'enabled' => $request->user()->mfa_enabled, 'recovery_codes_remaining' => count($request->user()->mfa_recovery_codes ?? [])]);
    }

    public function setup(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:256']]);

        return response()->json($this->mfa->setup($request->user()->id, $data['current_password']));
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/D']]);

        return response()->json($this->mfa->confirm($request->user()->id, $data['code']));
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:256'], 'code' => ['required', 'string', 'max:64']]);
        $this->mfa->disable($request->user()->id, $data['current_password'], $data['code']);

        return response()->json(['message' => 'Verificación desactivada. Inicia sesión nuevamente.']);
    }
}
