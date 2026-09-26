<?php

namespace App\Domain\Quotes;

/**
 * Reglas puras del seguimiento comercial de cotizaciones emitidas. El estado
 * comercial se deriva de los eventos; `quotes.status` no cambia.
 */
final class FollowupPolicy
{
    public const TYPES = ['sent', 'response', 'accepted', 'rejected', 'note'];

    public const CHANNELS = ['whatsapp', 'email', 'in_person', 'other'];

    private const FINAL = ['accepted', 'rejected'];

    /** Registran admin y el cotizador dueño; el aprobador solo consulta. */
    public function mayRecord(string $role, int $actorId, ?int $authorId): bool
    {
        return $role === 'admin' || ($role === 'quoter' && $authorId !== null && $authorId === $actorId);
    }

    /**
     * Último evento relevante (las notas no cuentan), en orden de registro.
     *
     * @param  list<string>  $types
     */
    public function commercialStatus(array $types): string
    {
        $status = 'not_sent';
        foreach ($types as $type) {
            $status = match ($type) {
                'sent' => 'sent',
                'response' => 'responded',
                'accepted' => 'accepted',
                'rejected' => 'rejected',
                default => $status,
            };
        }

        return $status;
    }

    /**
     * Mensaje de conflicto si `$type` no es válido tras `$existing`; null si procede.
     *
     * @param  list<string>  $existing
     */
    public function conflict(string $type, array $existing): ?string
    {
        $status = $this->commercialStatus($existing);
        if ($type === 'note') {
            return null;
        }
        if (in_array($status, self::FINAL, true)) {
            return 'La cotización ya tiene un resultado final; solo se admiten notas.';
        }
        if ($type !== 'sent' && ! in_array('sent', $existing, true)) {
            return 'Primero debe registrarse el envío de la cotización.';
        }

        return null;
    }

    /** Fecha del evento dentro de rango; devuelve el mensaje de error o null. */
    public function dateError(\DateTimeInterface $occurredAt, \DateTimeInterface $issuedAt, \DateTimeInterface $now): ?string
    {
        if ($occurredAt->getTimestamp() > $now->getTimestamp()) {
            return 'La fecha del evento no puede ser futura.';
        }
        if ($occurredAt->getTimestamp() < $issuedAt->getTimestamp()) {
            return 'La fecha del evento no puede ser anterior a la emisión.';
        }

        return null;
    }
}
