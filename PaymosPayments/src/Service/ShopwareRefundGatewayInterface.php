<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Paymos\Plugin\RefundIntent;
use Shopware\Core\Framework\Context;

interface ShopwareRefundGatewayInterface
{
    public function payment(string $paymentKey): array;
    public function refund(string $refundId): array;
    public function prepareFull(string $paymentKey, string $nativeId, array $invoice): array;
    public function validate(RefundIntent $intent, bool $projected): ?string;
    public function transition(RefundIntent $intent, string $state, Context $context): void;
}
