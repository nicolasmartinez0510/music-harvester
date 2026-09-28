<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class PasswordResetMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Recuperar contraseña');
    }

    public function content(): Content
    {
        $url = e($this->url);

        return new Content(
            htmlString: '<p>Para elegir una nueva contraseña abrí este enlace. Vence en 15 minutos.</p><p><a href="'.$url.'">'.$url.'</a></p>',
        );
    }
}
