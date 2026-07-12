<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shopware 6.5/6.6 async payment handler. Creates a Paymos invoice during
 * checkout (via the framework-agnostic {@see CheckoutProcessor}) and redirects
 * the buyer to the hosted Paymos payment URL.
 *
 * The transaction's terminal paid/failed/cancelled state is set by the signed,
 * reverse-verified webhook — that is the SOURCE OF TRUTH and finalize() never
 * fights it. But the async contract still requires the buyer to be returned to
 * Shopware's finalize flow: the per-transaction Shopware return URL
 * ($transaction->getReturnUrl(), carrying the _sw_payment_token) is snapshotted
 * in pay() and replayed by the storefront return bridge
 * ({@see \PaymosPayments\Storefront\Controller\PaymosReturnController}) when the
 * buyer comes back via the project-level Paymos SuccessUrl/FailUrl. finalize()
 * then reconciles the return outcome against the current transaction state via
 * {@see FinalizeDecision} — completing, leaving open (webhook still in flight),
 * or throwing the contract-mandated PaymentException on cancel/fail.
 *
 * Registered with the `shopware.payment.method.async` service tag (the 6.5/6.6
 * tag; 6.7's single `shopware.payment.method` is out of scope for this build).
 */
final class PaymosPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    /** SystemConfigService key prefix for this plugin's admin settings. */
    private const CONFIG_DOMAIN = 'PaymosPayments.config.';

    /** @var CheckoutProcessor */
    private $checkoutProcessor;

    /** @var ShopwareGatewayInterface */
    private $gateway;

    /** @var SystemConfigService */
    private $systemConfig;

    /** @var LoggerInterface */
    private $logger;
    private $credentialStore;

    public function __construct(
        CheckoutProcessor $checkoutProcessor,
        ShopwareGatewayInterface $gateway,
        SystemConfigService $systemConfig,
        LoggerInterface $logger,
        CredentialStore $credentialStore
    ) {
        $this->checkoutProcessor = $checkoutProcessor;
        $this->gateway = $gateway;
        $this->systemConfig = $systemConfig;
        $this->logger = $logger;
        $this->credentialStore = $credentialStore;
    }

    public function pay(
        AsyncPaymentTransactionStruct $transaction,
        RequestDataBag $dataBag,
        SalesChannelContext $salesChannelContext
    ): RedirectResponse {
        $orderTransactionId = $transaction->getOrderTransaction()->getId();
        $salesChannelId = $salesChannelContext->getSalesChannelId();

        try {
            $order = $this->buildOrderArray($transaction);
            $result = $this->checkoutProcessor->start($order, $this->settings($salesChannelId));
        } catch (\Throwable $e) {
            $this->logger->error('[Paymos] Could not start checkout.', array('error' => $e->getMessage()));
            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                'Paymos could not create the payment. ' . $e->getMessage()
            );
        }

        $paymentUrl = isset($result['payment_url']) ? (string) $result['payment_url'] : '';
        if ($paymentUrl === '') {
            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                'Paymos did not return a payment URL.'
            );
        }

        return new RedirectResponse($paymentUrl);
    }

    public function finalize(
        AsyncPaymentTransactionStruct $transaction,
        Request $request,
        SalesChannelContext $salesChannelContext
    ): void {
        $orderTransactionId = $transaction->getOrderTransaction()->getId();

        // Reconcile the (unauthenticated) browser return against the CURRENT
        // transaction state — the signed webhook is the source of truth. The
        // decision never marks paid here and never throws while the webhook is
        // still in flight (that would cancel a payment about to be confirmed).
        $cancel = $request->query->getBoolean('cancel');
        $currentState = $this->gateway->transactionState($orderTransactionId);
        $decision = FinalizeDecision::decide($cancel, $currentState);

        switch ($decision) {
            case FinalizeDecision::CANCEL:
                // Buyer cancelled, or the invoice is already cancelled/expired.
                // Shopware performs the state transition off this exception.
                throw PaymentException::customerCanceled(
                    $orderTransactionId,
                    'The customer cancelled the Paymos payment.'
                );

            case FinalizeDecision::FAIL:
                // The webhook already marked the transaction failed (e.g.
                // underpaid). Surface it so Shopware routes to the error page.
                throw PaymentException::asyncFinalizeInterrupted(
                    $orderTransactionId,
                    'The Paymos payment did not complete.'
                );

            case FinalizeDecision::COMPLETE:
                // The webhook already confirmed payment. Return without throwing
                // so Shopware routes the buyer to the order-confirmation page.
                // Do NOT re-mark paid — the return is unauthenticated.
                return;

            case FinalizeDecision::LEAVE_OPEN:
            default:
                // The webhook has not landed yet. Leave the transaction
                // open/in-progress and return without throwing; the order flips
                // to paid the moment the verified webhook arrives.
                return;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOrderArray(AsyncPaymentTransactionStruct $transaction): array
    {
        $order = $transaction->getOrder();

        return array(
            'transaction_id' => $transaction->getOrderTransaction()->getId(),
            'order_number' => (string) $order->getOrderNumber(),
            'amount' => $transaction->getOrderTransaction()->getAmount()->getTotalPrice(),
            'currency' => $this->currencyIso($order),
            'customer_id' => $this->customerId($order),
            // The Shopware async return URL (carries the _sw_payment_token). Sent
            // to the snapshot only — NEVER to Paymos. The return bridge replays
            // it to drive Shopware's finalize flow when the buyer comes back.
            'return_url' => $transaction->getReturnUrl(),
        );
    }

    private function currencyIso(OrderEntity $order): string
    {
        $currency = $order->getCurrency();
        if ($currency !== null && $currency->getIsoCode() !== null) {
            return (string) $currency->getIsoCode();
        }

        return '';
    }

    private function customerId(OrderEntity $order): string
    {
        $orderCustomer = $order->getOrderCustomer();
        if ($orderCustomer === null) {
            return '';
        }

        // Registered customers carry a customer id; guest checkout does not.
        return (string) ($orderCustomer->getCustomerId() ?? '');
    }

    /**
     * Admin slice of the config. CredentialStore adds locally encrypted secrets.
     *
     * @return array<string, mixed>
     */
    private function settings(?string $salesChannelId): array
    {
        return $this->credentialStore->settings(array(
            'mode' => (string) $this->systemConfig->getString(self::CONFIG_DOMAIN . 'mode', $salesChannelId),
            'debug_logging' => $this->systemConfig->getBool(self::CONFIG_DOMAIN . 'debugLogging', $salesChannelId) ? '1' : '0',
        ));
    }
}
