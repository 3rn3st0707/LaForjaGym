<?php

namespace App\Models;

use App\Enums\Status;
use App\Helpers\Helpers;
use App\Support\Billing\InvoiceCalculator;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Invoice extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'number',
        'subscription_id',
        'date',
        'due_date',
        'payment_method',
        'status',
        'tax',
        'discount',
        'discount_amount',
        'discount_note',
        'paid_amount',
        'total_amount',
        'due_amount',
        'subscription_fee',
    ];

    /**
     * The attributes that should be cast to native types.
     *
     * @var array
     */
    protected $casts = [
        'date' => 'date',
        'due_date' => 'date',
        'status' => Status::class,
    ];

    /**
     * The subscription this invoice is for.
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * Get a human-friendly label for the invoice status.
     *
     * This is useful for tables and UI elements where you want a consistent
     * display label regardless of whether the attribute is currently cast.
     */
    public function getDisplayStatusLabel(): string
    {
        $status = $this->status;

        if ($status instanceof Status) {
            return $status->getLabel();
        }

        if (is_string($status) && filled($status)) {
            return ucfirst($status);
        }

        return '';
    }

    /**
     * Get the transactions for the invoice.
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(InvoiceTransaction::class);
    }

    /**
     * Sync the invoice's paid_amount, due_amount, and status based on its transactions.
     */
    public function syncFromTransactions(): void
    {
        $paymentsTotal = (float) $this->transactions()
            ->where('type', 'payment')
            ->sum('amount');

        $refundsTotal = (float) $this->transactions()
            ->where('type', 'refund')
            ->sum('amount');

        $paymentsTotal = max($paymentsTotal, 0);
        $refundsTotal = min(max($refundsTotal, 0), $paymentsTotal);

        $total = (float) ($this->total_amount ?? 0);
        $total = max($total, 0);

        $netPaid = max($paymentsTotal - $refundsTotal, 0);
        $netPaid = min($netPaid, $total);

        $status = $this->status?->value ?? 'issued';
        $due = max($total - $netPaid, 0);

        if ($status === 'cancelled') {
            $due = 0;
        } elseif ($refundsTotal > 0) {
            $status = 'refund';
            $due = 0;
        } elseif ($due <= 0 && $netPaid > 0) {
            $status = 'paid';
            $due = 0;
        } elseif ($netPaid > 0) {
            $status = 'partial';
        } else {
            $status = 'issued';
        }

        $isDueOver = $due > 0
            && $this->due_date
            && Carbon::parse($this->due_date)->lt(Carbon::today(config('app.timezone')));

        if ($isDueOver) {
            $status = 'overdue';
        }

        $this->newQuery()
            ->whereKey($this->getKey())
            ->update([
                'paid_amount' => $netPaid,
                'due_amount' => $due,
                'status' => $status,
            ]);

        $this->refresh();
    }

    /**
     * Boot the model and handle invoice calculations on saving.
     */
    protected static function boot(): void
    {
        parent::boot();

        static::saving(function (self $invoice): void {
            if (! $invoice->number) {
                $invoice->number = Helpers::generateLastNumber('invoice', Invoice::class, $invoice->date);
            }
            Helpers::updateLastNumber('invoice', $invoice->number, $invoice->date);

            $taxRate = Helpers::getTaxRate() ?: 0;
            $summary = InvoiceCalculator::summary(
                fee: (float) ($invoice->subscription_fee ?? 0),
                taxRatePercent: $taxRate,
                discountAmount: (float) ($invoice->discount_amount ?? 0),
                paidAmount: (float) ($invoice->paid_amount ?? 0),
            );

            $invoice->subscription_fee = $summary['fee'];
            $invoice->discount_amount = $summary['discount_amount'];
            $invoice->tax = $summary['tax'];
            $invoice->total_amount = $summary['total'];
            $invoice->paid_amount = $summary['paid'];
        });

        static::created(function (self $invoice): void {
            $paid = (float) ($invoice->paid_amount ?? 0);
            $paid = max($paid, 0);
            $paid = min($paid, (float) ($invoice->total_amount ?? 0));

            if ($paid > 0) {
                $transaction = new InvoiceTransaction([
                    'type' => 'payment',
                    'amount' => $paid,
                    'occurred_at' => now()->timezone(config('app.timezone')),
                    'payment_method' => $invoice->payment_method,
                    'note' => 'Initial payment',
                    'created_by' => auth()->id(),
                ]);

                $transaction->invoice()->associate($invoice);
                $transaction->saveQuietly();
            }

            $invoice->syncFromTransactions();
        });

        static::updated(function (self $invoice): void {
            $invoice->syncFromTransactions();
        });
    }
}
