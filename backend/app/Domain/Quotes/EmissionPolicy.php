<?php

namespace App\Domain\Quotes;

/**
 * Reglas puras de emisión oficial (docs/diseno-emision-oficial.md §1 y §1.1).
 * La aprobación interna previa siempre es obligatoria; lo configurable es el
 * segundo paso al emitir (`emission_requires_authorization`).
 */
final class EmissionPolicy
{
    /**
     * Rol y autoría. Con autorización activada emiten admin y approver, y
     * quien emite debe ser distinto del autor. Desactivada, emiten admin y el
     * cotizador dueño (puede ser el autor).
     */
    public function mayIssue(string $role, int $actorId, ?int $authorId, bool $requiresAuthorization): bool
    {
        if ($requiresAuthorization) {
            return in_array($role, ['admin', 'approver'], true) && $authorId !== $actorId;
        }

        return $role === 'admin' || ($role === 'quoter' && $authorId === $actorId);
    }

    public function denialMessage(bool $requiresAuthorization): string
    {
        return $requiresAuthorization
            ? 'La emisión requiere autorización: solo un administrador o aprobador distinto del autor puede emitir.'
            : 'Solo un administrador o el cotizador dueño de la cotización puede emitir.';
    }

    /**
     * El aprobador de la revisión debe existir y ser distinto del autor. Excepción decidida por el dueño del
     * producto: la auto-aprobación de un administrador (`auto_approved`, fijada solo en servidor) es válida.
     */
    public function approvalIsIndependent(?int $approverId, ?int $authorId, bool $autoApproved = false): bool
    {
        if ($autoApproved && $approverId !== null && $approverId === $authorId) {
            return true;
        }

        return $approverId !== null && $authorId !== null && $approverId !== $authorId;
    }

    /**
     * Bloqueos legibles para `GET /quotes/{id}`.
     *
     * @param  array{status: string, role: string, actor_id: int, author_id: ?int, requires_authorization: bool, is_latest: bool, company_missing: list<string>, valid_until: string, today: string, approver_id: ?int, quote_number: ?string, approval_auto?: bool}  $facts
     * @return list<string>
     */
    public function blockers(array $facts): array
    {
        $blockers = [];
        if ($facts['status'] === 'issued') {
            return ['La cotización ya fue emitida.'];
        }
        if ($facts['status'] !== 'approved') {
            $blockers[] = 'La cotización debe estar aprobada internamente.';
        }
        if (! $facts['is_latest']) {
            $blockers[] = 'Existe una revisión posterior; solo se emite la más reciente.';
        }
        if (! $this->mayIssue($facts['role'], $facts['actor_id'], $facts['author_id'], $facts['requires_authorization'])) {
            $blockers[] = $this->denialMessage($facts['requires_authorization']);
        }
        if ($facts['status'] === 'approved' && ! $this->approvalIsIndependent($facts['approver_id'], $facts['author_id'], (bool) ($facts['approval_auto'] ?? false))) {
            $blockers[] = 'La aprobación debe ser de una persona distinta del autor.';
        }
        if ($facts['company_missing'] !== []) {
            $blockers[] = 'Empresa emisora incompleta: faltan '.implode(', ', $facts['company_missing']).'.';
        }
        if ($facts['quote_number'] === null) {
            $blockers[] = 'La cotización no tiene número.';
        }
        if ($facts['valid_until'] < $facts['today']) {
            $blockers[] = 'La vigencia terminó; crea una nueva revisión.';
        }

        return $blockers;
    }
}
