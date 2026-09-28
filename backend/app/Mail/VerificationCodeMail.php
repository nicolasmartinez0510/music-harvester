<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class VerificationCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Código para confirmar tu cuenta');
    }

    public function content(): Content
    {
        $code = e($this->code);

        return new Content(
            htmlString: '<p>Tu código de verificación de Music Harvester es <strong>'.$code.'</strong>.</p><p>Vence en 30 minutos.</p>',
        );
    }
}
