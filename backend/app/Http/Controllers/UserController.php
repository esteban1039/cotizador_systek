<?php

namespace App\Http\Controllers;

use App\Domain\Audit;
use App\Repositories\Contracts\AuditRepository;
use App\Repositories\Contracts\IdentityRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class UserController extends Controller
{
    public function __construct(private IdentityRepository $identities, private AuditRepository $audits) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->identities->allByName()]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->merge(['email' => strtolower(trim($request->input('email', '')))]);
        $input = $request->validate([
            'name' => ['required', 'string', 'max:255'], 'email' => ['required', 'email', 'max:255', function (string $attribute, mixed $value, \Closure $fail): void {
                if ($this->identities->emailExists($value)) {
                    $fail('Este correo ya está registrado.');
                }
            }],
            'password' => ['required', 'string', 'min:12', 'max:128'],
            'role' => ['required', Rule::in(['admin', 'quoter', 'approver'])],
        ]);

        return $this->identities->transaction(function () use ($request, $input) {
            $user = $this->identities->create($input['name'], $input['email'], $input['password'], $input['role']);
            Audit::record($request->user()->id, 'user.created', (string) $user->id, ['role' => $user->role]);

            return response()->json(['data' => $user], 201);
        });
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $input = $request->validate(['active' => ['required', 'boolean'], 'role' => ['required', Rule::in(['admin', 'quoter', 'approver'])]]);
        abort_if($request->user()->id === $id, 422, 'No puedes cambiar tu propio rol o desactivar tu propia cuenta.');

        return $this->identities->transaction(function () use ($request, $id, $input) {
            // Serialize access changes so two administrators cannot disable each other concurrently.
            $this->identities->lockAdministrators();
            $actor = $this->identities->find($request->user()->id);
            abort_unless($actor?->active && $actor->role === 'admin', 403);
            $user = $this->identities->findForUpdate($id);
            $this->identities->updateAccess($user, $input['role'], (bool) $input['active']);
            $this->identities->revokeAllTokens($user);
            Audit::record($request->user()->id, 'user.access_changed', (string) $id, $input);

            return response()->json(['data' => $user]);
        });
    }

    public function audit(): JsonResponse
    {
        return response()->json($this->audits->paginateWithUserNames());
    }
}
