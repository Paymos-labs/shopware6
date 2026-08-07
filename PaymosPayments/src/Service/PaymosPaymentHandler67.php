<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Psr\Log\LoggerInterface;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Shopware 6.7 payment handler.
 *
 * 6.7 removed AsynchronousPaymentHandlerInterface and AsyncPaymentTransactionStruct
 * and replaced both with AbstractPaymentHandler, which did not exist before 6.7 —
 * the two APIs never overlap, so the plugin ships one handler per era and registers
 * only the applicable one (see PaymosPayments::build()). PHP loads a class only when
 * it is referenced, so the handler for the other era never touches its missing parent.
 *
 * This class is deliberately only an adapter: every payment decision lives in
 * {@see CheckoutProcessor} and {@see FinalizeDecision}, shared verbatim with
 * {@see PaymosPaymentHandler}. Keep it that way — two copies of the decisions would
 * be two places to fix.
 */
final class PaymosPaymentHandler67 extends AbstractPaymentHandler
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

    /** @var CredentialStore */
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

    /**
     * Stablecoin payments settle on-chain and cannot be reversed programmatically —
     * Paymos exposes no refund API — and there is no recurring support, so every
     * optional capability is declined rather than silently half-implemented.
     */
    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return false;
    }

    public function pay(
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context,
        ?Struct $validateStruct
    ): ?RedirectResponse {
        $orderTransactionId = $transaction->getOrderTransactionId();

        // 6.7 hands over an id and nothing else, so the order the older API passed
        // as a struct is loaded here instead.
        $orderContext = $this->gateway->orderContext($orderTransactionId);
        if ($orderContext === null) {
            throw PaymentException::asyncProcessInterrupted(
                $orderTransactionId,
                'Paymos could not load the order for this transaction.'
            );
        }

        try {
            $order = array(
                'transaction_id' => $orderTransactionId,
                'order_number' => $orderContext['order_number'],
                'amount' => $orderContext['amount'],
                'currency' => $orderContext['currency'],
                'customer_id' => $orderContext['customer_id'],
                // Shopware's own return URL, carrying the _sw_payment_token. Stored in
                // the snapshot only — NEVER sent to Paymos. The return bridge replays
                // it to drive Shopware's finalize flow when the buyer comes back.
                'return_url' => $transaction->getReturnUrl(),
            );

            $result = $this->checkoutProcessor->start($order, $this->settings($orderContext['sales_channel_id']));
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
        Request $request,
        PaymentTransactionStruct $transaction,
        Context $context
    ): void {
        $orderTransactionId = $transaction->getOrderTransactionId();

        // Reconcile the (unauthenticated) browser return against the CURRENT
        // transaction state — the signed webhook is the source of truth. The
        // decision never marks paid here and never throws while the webhook is
        // still in flight (that would cancel a payment about to be confirmed).
        $cancel = $request->query->getBoolean('cancel');
        $currentState = $this->gateway->transactionState($orderTransactionId);
        $decision = FinalizeDecision::decide($cancel, $currentState);

        switch ($decision) {
            case FinalizeDecision::CANCEL:
                throw PaymentException::customerCanceled(
                    $orderTransactionId,
                    'The customer cancelled the Paymos payment.'
                );

            case FinalizeDecision::FAIL:
                throw PaymentException::asyncFinalizeInterrupted(
                    $orderTransactionId,
                    'The Paymos payment did not complete.'
                );

            case FinalizeDecision::COMPLETE:
            case FinalizeDecision::LEAVE_OPEN:
            default:
                // Either the webhook already confirmed payment, or it has not landed
                // yet. Both return without throwing: the order flips to paid only on
                // the verified webhook, never on this unauthenticated return.
                return;
        }
    }

    /**
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
