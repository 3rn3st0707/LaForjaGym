@php
    /** @var \App\Models\Invoice $invoice */
    $statusLabel = method_exists($invoice, 'getDisplayStatusLabel')
        ? $invoice->getDisplayStatusLabel()
        : (string) ($invoice->status?->value ?? $invoice->status ?? 'Issued');
@endphp

<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-family: Arial, sans-serif; background: #f6f7fb; padding: 24px 0;">
    <tr>
        <td align="center">
            <table role="presentation" width="640" cellpadding="0" cellspacing="0" style="background: #ffffff; border: 1px solid #e5e7eb;">
                <tr>
                    <td style="padding: 20px 22px; background: #0b152d; color: #ffffff;">
                        <div style="font-size: 18px; font-weight: 700; line-height: 1.2;">{{ $gymName }}</div>
                        <div style="font-size: 12px; color: #cbd5e1; margin-top: 6px;">
                            @if (filled($gymEmail))
                                {{ $gymEmail }}
                            @endif
                            @if (filled($gymEmail) && filled($gymContact))
                                &nbsp;|&nbsp;
                            @endif
                            @if (filled($gymContact))
                                {{ $gymContact }}
                            @endif
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding: 22px;">
                        <div style="font-size: 14px; color: #111827;">
                            Hi {{ filled($memberName) ? $memberName : 'there' }},
                        </div>

                        <div style="font-size: 14px; color: #111827; margin-top: 12px;">
                            Your invoice <strong>{{ $invoice->number }}</strong> is <strong>{{ strtolower($statusLabel) }}</strong>. The PDF is attached.
                        </div>

                        @if (filled($staffNote))
                            <div style="margin-top: 14px; padding: 12px 14px; border: 1px solid #d1fae5; background: #ecfdf5;">
                                <div style="font-size: 11px; letter-spacing: 1px; font-weight: 700; color: #065f46; text-transform: uppercase;">Note</div>
                                <div style="margin-top: 6px; font-size: 13px; color: #111827; line-height: 1.4;">
                                    {{ $staffNote }}
                                </div>
                            </div>
                        @endif

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top: 18px; border-top: 1px solid #e5e7eb;">
                            <tr>
                                <td style="padding: 14px 0 0; font-size: 13px; color: #6b7280;">Total</td>
                                <td style="padding: 14px 0 0; font-size: 13px; color: #111827; font-weight: 700;" align="right">{{ \App\Helpers\Helpers::formatCurrency((float) ($invoice->total_amount ?? 0)) }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 10px 0 0; font-size: 13px; color: #6b7280;">Paid</td>
                                <td style="padding: 10px 0 0; font-size: 13px; color: #111827; font-weight: 700;" align="right">{{ \App\Helpers\Helpers::formatCurrency((float) ($invoice->paid_amount ?? 0)) }}</td>
                            </tr>
                            <tr>
                                <td style="padding: 10px 0 0; font-size: 13px; color: #6b7280;">Due</td>
                                <td style="padding: 10px 0 0; font-size: 13px; color: #111827; font-weight: 800;" align="right">{{ \App\Helpers\Helpers::formatCurrency((float) ($invoice->due_amount ?? 0)) }}</td>
                            </tr>
                            @if (filled($invoice->due_date))
                                <tr>
                                    <td style="padding: 10px 0 0; font-size: 13px; color: #6b7280;">Due date</td>
                                    <td style="padding: 10px 0 0; font-size: 13px; color: #111827; font-weight: 700;" align="right">{{ optional($invoice->due_date)->format('d M Y') }}</td>
                                </tr>
                            @endif
                        </table>

                        <div style="margin-top: 18px; font-size: 12px; color: #6b7280;">
                            If you have questions, reply to this email.
                        </div>
                    </td>
                </tr>

                <tr>
                    <td style="padding: 14px 22px; background: #f9fafb; font-size: 11px; color: #6b7280;">
                        Sent by {{ $gymName }} via Gymie.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
