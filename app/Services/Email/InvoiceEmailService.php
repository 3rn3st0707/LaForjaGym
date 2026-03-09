<?php

namespace App\Services\Email;

use App\Contracts\SettingsRepository;
use App\Helpers\Helpers;
use App\Jobs\SendInvoiceIssuedEmail;
use App\Jobs\SendInvoicePaymentReceiptEmail;
use App\Mail\InvoiceIssuedMail;
use App\Mail\InvoicePaymentReceiptMail;
use App\Models\Invoice;
use App\Models\InvoiceTransaction;
use App\Support\Invoices\InvoiceDocument;
use App\Support\Invoices\InvoicePdfRenderer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Invoice email service.
 *
 * This service is responsible for:
 * - building invoice/payment email payloads
 * - rendering PDF attachments
 * - configuring Reply-To from gym settings
 * - rendering safe, token-based subject templates
 */
final class InvoiceEmailService
{
    public function __construct(
        private readonly InvoicePdfRenderer $renderer,
        private readonly SettingsRepository $settingsRepository,
    ) {}

    /**
     * Queue an "invoice issued" email.
     */
    public function queueInvoiceIssuedEmail(int $invoiceId, string $toEmail, ?string $note = null, ?int $actorId = null): void
    {
        SendInvoiceIssuedEmail::dispatch(
            invoiceId: $invoiceId,
            toEmail: $toEmail,
            note: $note,
            actorId: $actorId,
        )->afterCommit();
    }

    /**
     * Queue a "payment received" receipt email.
     */
    public function queuePaymentReceiptEmail(int $invoiceId, int $transactionId, string $toEmail, ?string $note = null, ?int $actorId = null): void
    {
        SendInvoicePaymentReceiptEmail::dispatch(
            invoiceId: $invoiceId,
            invoiceTransactionId: $transactionId,
            toEmail: $toEmail,
            note: $note,
            actorId: $actorId,
        )->afterCommit();
    }

    /**
     * Send an "invoice issued" email with PDF attached.
     */
    public function sendInvoiceIssuedEmail(int $invoiceId, string $toEmail, ?string $note = null): void
    {
        $invoice = InvoiceDocument::loadForRendering(Invoice::query()->findOrFail($invoiceId));
        $memberName = (string) ($invoice->subscription?->member?->name ?? '');

        if (! $this->isValidRecipientEmail($toEmail, [
            'invoice_id' => $invoiceId,
        ])) {
            return;
        }

        $settings = $this->settingsRepository->get();
        $gym = $this->gymIdentityFromSettings($settings);
        $subjectTemplate = (string) data_get($settings,
            'notifications.email.invoice_subject_template',
            'Invoice {invoice_number} - {status}',
        );

        $pdfBytes = $this->renderer->render($invoice);
        $subject = $this->renderSubjectTemplate(
            $subjectTemplate,
            $this->invoiceSubjectTokens($invoice, $gym['name'], $memberName),
        );

        $mailable = new InvoiceIssuedMail(
            invoice: $invoice,
            subjectLine: $subject,
            gymName: $gym['name'],
            gymEmail: $gym['email'],
            gymContact: $gym['contact'],
            memberName: $memberName,
            staffNote: $note,
            pdfBytes: $pdfBytes,
        );

        if (filled($gym['email'])) {
            $mailable->replyTo($gym['email'], $gym['name']);
        }

        Mail::to($toEmail)->send($mailable);
    }

    /**
     * Send a payment receipt email with PDF attached.
     */
    public function sendPaymentReceiptEmail(int $invoiceId, int $transactionId, string $toEmail, ?string $note = null): void
    {
        $invoice = InvoiceDocument::loadForRendering(Invoice::query()->findOrFail($invoiceId));
        $transaction = InvoiceTransaction::query()
            ->whereKey($transactionId)
            ->where('invoice_id', $invoice->getKey())
            ->firstOrFail();

        $memberName = (string) ($invoice->subscription?->member?->name ?? '');

        if (! $this->isValidRecipientEmail($toEmail, [
            'invoice_id' => $invoiceId,
            'invoice_transaction_id' => $transactionId,
        ])) {
            return;
        }

        $settings = $this->settingsRepository->get();
        $gym = $this->gymIdentityFromSettings($settings);
        $subjectTemplate = (string) data_get($settings,
            'notifications.email.receipt_subject_template',
            'Payment received - {invoice_number}',
        );

        $pdfBytes = $this->renderer->render($invoice);
        $subject = $this->renderSubjectTemplate(
            $subjectTemplate,
            [
                ...$this->invoiceSubjectTokens($invoice, $gym['name'], $memberName),
                'payment_amount' => Helpers::formatCurrency((float) ($transaction->amount ?? 0)),
            ],
        );

        $mailable = new InvoicePaymentReceiptMail(
            invoice: $invoice,
            transaction: $transaction,
            subjectLine: $subject,
            gymName: $gym['name'],
            gymEmail: $gym['email'],
            gymContact: $gym['contact'],
            memberName: $memberName,
            staffNote: $note,
            pdfBytes: $pdfBytes,
        );

        if (filled($gym['email'])) {
            $mailable->replyTo($gym['email'], $gym['name']);
        }

        Mail::to($toEmail)->send($mailable);
    }

    /**
     * Validate an email address and log a warning when invalid.
     *
     * @param  array<string, mixed>  $context
     */
    private function isValidRecipientEmail(string $toEmail, array $context): bool
    {
        if (filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return true;
        }

        Log::warning('Skipping invoice email: invalid recipient email.', [
            ...$context,
            'to_email' => $toEmail,
        ]);

        return false;
    }

    /**
     * Derive gym identity fields from settings.
     *
     * @param  array<string, mixed>  $settings
     * @return array{name: string, email: string, contact: string}
     */
    private function gymIdentityFromSettings(array $settings): array
    {
        return [
            'name' => (string) data_get($settings, 'general.gym_name', config('app.name')),
            'email' => (string) data_get($settings, 'general.gym_email', ''),
            'contact' => (string) data_get($settings, 'general.gym_contact', ''),
        ];
    }

    /**
     * Build the common subject tokens used by invoice + receipt emails.
     *
     * @return array<string, string>
     */
    private function invoiceSubjectTokens(Invoice $invoice, string $gymName, string $memberName): array
    {
        return [
            'invoice_number' => (string) ($invoice->number ?? ''),
            'status' => $invoice->getDisplayStatusLabel(),
            'total' => Helpers::formatCurrency((float) ($invoice->total_amount ?? 0)),
            'paid' => Helpers::formatCurrency((float) ($invoice->paid_amount ?? 0)),
            'due' => Helpers::formatCurrency((float) ($invoice->due_amount ?? 0)),
            'gym_name' => $gymName,
            'member_name' => $memberName,
        ];
    }

    /**
     * Render a subject template using a small, safe token set.
     *
     * Allowed tokens are the keys of the `$tokens` array and must be used as
     * `{token_name}`. Unknown tokens are preserved unchanged.
     *
     * @param  array<string, string>  $tokens
     */
    private function renderSubjectTemplate(string $template, array $tokens): string
    {
        $rendered = preg_replace_callback('/\{([a-z_]+)\}/i', function (array $matches) use ($tokens): string {
            $key = strtolower((string) $matches[1]);

            return array_key_exists($key, $tokens) ? $tokens[$key] : (string) $matches[0];
        }, $template) ?? $template;

        $rendered = trim($rendered);

        if ($rendered === '') {
            return 'Invoice';
        }

        return (string) Str::of($rendered)->limit(150, '…');
    }
}
