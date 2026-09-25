<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Paymos\Plugin\InvoiceReplacementBlockedException;
use Shopware\Core\Checkout\Payment\PaymentException;

/**
 * The exception pay() hands Shopware when the checkout could not start. Shared
 * by both handler eras.
 *
 * Shopware fails the transaction on it and redirects the buyer to the edit-order
 * page with ?error-code= set to its getErrorCode() (6.5 PaymentService::
 * handlePaymentByOrder, 6.6/6.7 PaymentProcessor::pay). The stock page answers
 * every code with "change the payment method or try again" and a "Complete
 * payment" button. That is right when the invoice could not be created, and
 * wrong when the replacement was blocked (BUG-166): the old invoice may already
 * be paid, so the buyer must not be asked to pay again (BUG-189). The blocked
 * case therefore carries its own code, which the plugin's
 * Resources/views/storefront/page/account/order/index.html.twig answers with
 * the SDK buyer message and no way to pay.
 */
final class CheckoutFailure
{
    /** Read by the storefront template; keep the two in step. */
    public const REVIEW_REQUIRED = 'PAYMOS__ORDER_NEEDS_REVIEW';

    public static function paymentException(string $orderTransactionId, \Throwable $e): PaymentException
    {
        if ($e instanceof InvoiceReplacementBlockedException) {
            return new PaymentException(
                400,
                self::REVIEW_REQUIRED,
                $e->getMessage(),
                array('orderTransactionId' => $orderTransactionId),
                $e
            );
        }

        return PaymentException::asyncProcessInterrupted(
            $orderTransactionId,
            'Paymos could not create the payment. ' . $e->getMessage(),
            $e
        );
    }
}
