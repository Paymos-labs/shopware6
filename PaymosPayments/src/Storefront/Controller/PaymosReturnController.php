<?php

declare(strict_types=1);

namespace PaymosPayments\Storefront\Controller;

use PaymosPayments\Service\InvoiceStoreInterface;
use PaymosPayments\Service\PaymosPaymentHandler;
use PaymosPayments\Service\ReturnBridgeResolver;
use PaymosPayments\Service\ShopwareGatewayInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Storefront return bridge for the hosted Paymos checkout.
 *
 * Paymos cannot bounce the buyer to a per-transaction URL (the create-invoice
 * API has no return-URL field; the buyer-return destination is the static
 * PROJECT-level SuccessUrl/FailUrl). The merchant points that project URL at
 * this route. When the buyer clicks "Back to {store}" on the hosted page they
 * land here; this controller resolves which pending Paymos transaction they
 * belong to (via the Shopware session — the only signal available, since the
 * SuccessUrl is static), fetches that transaction's snapshotted Shopware return
 * URL (which carries the _sw_payment_token), and 302s the buyer there so
 * Shopware validates the token and runs the payment handler's finalize().
 *
 * The signed webhook remains the source of truth for the order state; this
 * bridge is best-effort UX only. A buyer who never clicks the button still has
 * their order completed by the webhook (the transaction stays open until then).
 * Guests with an expired session, or buyers with no pending Paymos transaction,
 * fall back to the account order overview.
 *
 * `Symfony\Component\Routing\Attribute\Route` is used deliberately: it exists in
 * both Symfony 6.4 (Shopware 6.5) and Symfony 7 (Shopware 6.6).
 *
 * @internal Plugin entry point; registered as a public service with setContainer.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
final class PaymosReturnController extends StorefrontController
{
    /** @var InvoiceStoreInterface */
    private $invoiceStore;

    /** @var ShopwareGatewayInterface */
    private $gateway;

    /** @var ReturnBridgeResolver */
    private $resolver;

    public function __construct(InvoiceStoreInterface $invoiceStore, ShopwareGatewayInterface $gateway)
    {
        $this->invoiceStore = $invoiceStore;
        $this->gateway = $gateway;
        $this->resolver = new ReturnBridgeResolver();
    }

    #[Route(
        path: '/paymos/return',
        name: 'frontend.paymos.return',
        methods: ['GET']
    )]
    public function handle(Request $request, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        $cancel = $request->query->getBoolean('cancel');

        // A logged-in buyer is the only case we can map to a specific order, and
        // the account order overview requires authentication — so use it as the
        // fallback only for logged-in customers; guests (and any resolution
        // failure) fall back to the storefront home, never an auth-walled page.
        $isLoggedIn = $salesChannelContext->getCustomer() !== null;

        $returnUrl = $this->resolveReturnUrl($salesChannelContext);
        $fallbackUrl = $this->safeFallbackUrl($isLoggedIn);

        $target = $this->resolver->resolveTarget($returnUrl, $cancel, $fallbackUrl);

        return new RedirectResponse($target);
    }

    /**
     * The snapshotted Shopware return URL of the returning buyer's most recent
     * pending Paymos transaction, or '' when it cannot be resolved (guest,
     * expired session, no pending transaction, or any lookup failure). Never
     * throws — the bridge is best-effort UX and must not 500 a buyer whose order
     * the webhook has already completed.
     */
    private function resolveReturnUrl(SalesChannelContext $salesChannelContext): string
    {
        try {
            $customer = $salesChannelContext->getCustomer();
            if ($customer === null) {
                return '';
            }

            $transactionId = $this->gateway->findLatestPendingTransactionIdForCustomer(
                $customer->getId(),
                PaymosPaymentHandler::class
            );
            if ($transactionId === '') {
                return '';
            }

            $row = $this->invoiceStore->findByTransactionId($transactionId);
            if (!is_array($row) || !isset($row['return_url'])) {
                return '';
            }

            return (string) $row['return_url'];
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * A redirect target that is always reachable: the account order overview for
     * a logged-in buyer, otherwise the storefront home. URL generation can throw
     * (route/context edge cases); fall back to the site root rather than 500.
     */
    private function safeFallbackUrl(bool $isLoggedIn): string
    {
        try {
            return $this->generateUrl($isLoggedIn ? 'frontend.account.order.page' : 'frontend.home.page');
        } catch (\Throwable $e) {
            return '/';
        }
    }
}
