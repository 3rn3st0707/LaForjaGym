<?php

namespace App\Mail;

use App\Models\Invoice;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invoice issued email (member-facing).
 *
 * Sends an invoice summary and attaches the invoice PDF.
 */
class InvoiceIssuedMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  non-empty-string  $gymName
     * @param  string|null  $gymEmail
     * @param  string|null  $gymContact
     * @param  string|null  $memberName
     */
    public function __construct(
        public readonly Invoice $invoice,
        public readonly string $subjectLine,
        public readonly string $gymName,
        public readonly string $gymEmail,
        public readonly string $gymContact,
        public readonly string $memberName,
        public readonly ?string $staffNote,
        public readonly string $pdfBytes,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->subjectLine,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.invoices.issued',
            with: [
                'invoice' => $this->invoice,
                'gymName' => $this->gymName,
                'gymEmail' => $this->gymEmail,
                'gymContact' => $this->gymContact,
                'memberName' => $this->memberName,
                'staffNote' => $this->staffNote,
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        $safeNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $this->invoice->number);
        $safeNumber = trim((string) $safeNumber, '-');
        $filename = filled($safeNumber) ? "invoice-{$safeNumber}.pdf" : 'invoice.pdf';

        return [
            Attachment::fromData(fn () => $this->pdfBytes, $filename)
                ->withMime('application/pdf'),
        ];
    }
}
