@php
    /** @var \App\Models\Invoice $invoice */
    /** @var \App\Models\InvoiceTransaction $transaction */
@endphp

@extends('emails.invoices.layout')

@section('content')
    <div style="font-size: 14px; color: #111827;">
        Hi {{ filled($memberName) ? $memberName : 'there' }},
    </div>

    <div style="font-size: 14px; color: #111827; margin-top: 12px;">
        We received your payment for invoice <strong>{{ $invoice->number }}</strong>. The updated invoice PDF is attached.
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top: 18px; border-top: 1px solid #e5e7eb;">
        <tr>
            <td style="padding: 14px 0 0; font-size: 13px; color: #6b7280;">Payment</td>
            <td style="padding: 14px 0 0; font-size: 13px; color: #111827; font-weight: 800;" align="right">{{ \App\Helpers\Helpers::formatCurrency((float) ($transaction->amount ?? 0)) }}</td>
        </tr>
        <tr>
            <td style="padding: 10px 0 0; font-size: 13px; color: #6b7280;">Paid at</td>
            <td style="padding: 10px 0 0; font-size: 13px; color: #111827; font-weight: 700;" align="right">{{ optional($transaction->occurred_at)->timezone(config('app.timezone'))->format('d M Y, h:i A') }}</td>
        </tr>
        <tr>
            <td style="padding: 10px 0 0; font-size: 13px; color: #6b7280;">Remaining due</td>
            <td style="padding: 10px 0 0; font-size: 13px; color: #111827; font-weight: 800;" align="right">{{ \App\Helpers\Helpers::formatCurrency((float) ($invoice->due_amount ?? 0)) }}</td>
        </tr>
    </table>

    @if (filled($staffNote))
        <div style="margin-top: 14px; padding: 12px 14px; border: 1px solid #d1fae5; background: #ecfdf5;">
            <div style="font-size: 11px; letter-spacing: 1px; font-weight: 700; color: #065f46; text-transform: uppercase;">Note</div>
            <div style="margin-top: 6px; font-size: 13px; color: #111827; line-height: 1.4;">
                {{ $staffNote }}
            </div>
        </div>
    @endif

    <div style="margin-top: 18px; font-size: 12px; color: #6b7280;">
        If you have questions, reply to this email.
    </div>
@endsection
