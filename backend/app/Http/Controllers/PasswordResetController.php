<?php

namespace App\Http\Controllers;

use App\Application\Identity\PasswordResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PasswordResetController extends Controller
{
    public function __construct(private PasswordResetService $service) {}

    public function forgot(Request $request): JsonResponse
    {
        $input = $request->validate(['email' => ['required', 'email', 'max:255']]);
        $this->service->request($input['email']);

        return response()->json(['message' => 'Si el correo está registrado, te enviamos un enlace para restablecer la contraseña. Vale 30 minutos.'], 202);
    }

    public function reset(Request $request): JsonResponse
    {
        $input = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', 'min:12', 'max:128', 'confirmed'],
            'code' => ['nullable', 'string', 'max:64'],
        ]);
        $this->service->reset($input['email'], $input['token'], $input['password'], $input['code'] ?? null);

        return response()->json(['message' => 'Contraseña restablecida. Inicia sesión con la nueva.']);
    }
}
