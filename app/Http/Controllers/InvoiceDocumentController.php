<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Support\Invoices\InvoiceDocument;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class InvoiceDocumentController extends Controller
{
    /**
     * Render an invoice preview as an inline PDF.
     */
    public function preview(Invoice $invoice): Response
    {
        $invoice = InvoiceDocument::loadForRendering($invoice);

        $this->authorize('view', $invoice);

        $data = InvoiceDocument::viewData($invoice);

        if ($data['missing'] !== []) {
            return response()
                ->view('invoices.error', $data)
                ->setStatusCode(200);
        }

        $this->ensurePdfFontCacheDirectoryExists();

        $pdf = Pdf::loadView('invoices.document', $data)
            ->setPaper('a4');

        return $pdf->stream($this->filename($invoice));
    }

    /**
     * Download the invoice as a PDF document.
     */
    public function download(Invoice $invoice): Response|BinaryFileResponse
    {
        $invoice = InvoiceDocument::loadForRendering($invoice);

        $this->authorize('view', $invoice);

        $data = InvoiceDocument::viewData($invoice);

        if ($data['missing'] !== []) {
            return response()
                ->view('invoices.error', $data)
                ->setStatusCode(422);
        }

        $this->ensurePdfFontCacheDirectoryExists();

        $pdf = Pdf::loadView('invoices.document', $data)
            ->setPaper('a4');

        return $pdf->download($this->filename($invoice));
    }

    /**
     * Ensure DomPDF can write generated font metric files.
     */
    private function ensurePdfFontCacheDirectoryExists(): void
    {
        File::ensureDirectoryExists(storage_path('fonts'));
    }

    /**
     * Create a safe invoice PDF filename.
     */
    private function filename(Invoice $invoice): string
    {
        $safeNumber = preg_replace('/[^A-Za-z0-9_-]+/', '-', (string) $invoice->number);
        $safeNumber = trim((string) $safeNumber, '-');

        return filled($safeNumber) ? "invoice-{$safeNumber}.pdf" : 'invoice.pdf';
    }
}
