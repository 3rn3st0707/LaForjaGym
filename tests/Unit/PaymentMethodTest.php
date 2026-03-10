<?php

use App\Support\Billing\PaymentMethod;

it('normalizes and classifies payment methods', function (): void {
    expect(PaymentMethod::normalize(' Stripe '))->toBe('stripe');
    expect(PaymentMethod::isOnline('stripe'))->toBeTrue();
    expect(PaymentMethod::isOnline('online'))->toBeTrue();
    expect(PaymentMethod::isOnline('cash'))->toBeFalse();
    expect(PaymentMethod::channelLabel('stripe'))->toBe('Online');
    expect(PaymentMethod::channelLabel('cash'))->toBe('Offline');
});

it('exposes stable payment method options for forms', function (): void {
    expect(PaymentMethod::options())->toMatchArray([
        'cash' => 'Offline',
        'online' => 'Online',
        'cheque' => 'Cheque (legacy)',
    ]);
});
