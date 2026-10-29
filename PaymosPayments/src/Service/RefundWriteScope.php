<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Shopware\Core\Framework\Context;

final class RefundWriteScope
{
    private $contexts;

    public function __construct()
    {
        $this->contexts = new \SplObjectStorage();
    }

    public function run(Context $context, callable $write)
    {
        // Object identity cannot be supplied by an API payload or a context state.
        $owned = clone $context;
        $this->contexts->attach($owned);
        try {
            return $write($owned);
        } finally {
            $this->contexts->detach($owned);
        }
    }

    public function allows(Context $context): bool
    {
        return $this->contexts->contains($context);
    }
}
