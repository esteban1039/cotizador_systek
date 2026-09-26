<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class PasswordResetMail extends Mailable
{
    public function __construct(public string $name, public string $url, public int $minutes) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Restablece tu contraseña de JARVIS Cotizador');
    }

    public function content(): Content
    {
        return new Content(text: 'mail.password-reset');
    }
}
