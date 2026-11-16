<?php

namespace App\Services;

/**
 * PayHere (payhere.lk) checkout + payment notification verification.
 * See https://support.payhere.lk/api-&-mobile-sdk/checkout-api
 */
class PayHere
{
    public function merchantId(): ?string
    {
        return config('tuitionpro.payhere.merchant_id');
    }

    public function configured(): bool
    {
        return (bool) ($this->merchantId() && config('tuitionpro.payhere.merchant_secret'));
    }

    public function checkoutUrl(): string
    {
        return config('tuitionpro.payhere.sandbox')
            ? 'https://sandbox.payhere.lk/pay/checkout'
            : 'https://www.payhere.lk/pay/checkout';
    }

    private function secretHash(): string
    {
        return strtoupper(md5((string) config('tuitionpro.payhere.merchant_secret')));
    }

    public function checkoutHash(string $orderId, float $amount, string $currency = 'LKR'): string
    {
        return strtoupper(md5($this->merchantId().$orderId.number_format($amount, 2, '.', '').$currency.$this->secretHash()));
    }

    /** Verify the md5sig sent to notify_url. */
    public function verifyNotification(array $p): bool
    {
        $expected = strtoupper(md5(
            ($p['merchant_id'] ?? '').($p['order_id'] ?? '').($p['payhere_amount'] ?? '')
            .($p['payhere_currency'] ?? '').($p['status_code'] ?? '').$this->secretHash()
        ));

        return isset($p['md5sig']) && hash_equals($expected, strtoupper((string) $p['md5sig']));
    }
}
