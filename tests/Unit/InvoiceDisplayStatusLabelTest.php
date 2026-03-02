<?php

use App\Models\Invoice;

it('shows issued label for issued invoices', function (): void {
    $invoice = new Invoice([
        'status' => 'issued',
    ]);

    expect($invoice->getDisplayStatusLabel())->toBe('Issued');
});

it('shows paid label for paid invoices', function (): void {
    $invoice = new Invoice([
        'status' => 'paid',
    ]);

    expect($invoice->getDisplayStatusLabel())->toBe('Paid');
});
