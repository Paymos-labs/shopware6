<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Paymos\Client;
use Paymos\Plugin\AmountGuard;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\InMemoryEventStore;

/**
 * Recovery for missed webhooks. Selects order transactions still open past a
 * grace window, pulls each invoice from the API, and funnels the result through
 * the SAME {@see WebhookProcessor}/{@see OrderMapper} path so reverse-verify
 * snapshot matching, AmountGuard and the roll-back guard all still apply.
 *
 * Throttled by the caller (a Shopware scheduled task); terminal snapshots are
 * skipped by the store query.
 */
final class Reconciler
{
    /** @var ShopwareGatewayInterface */
    private $gateway;

    /** @var InvoiceStoreInterface */
    private $store;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(
        ShopwareGatewayInterface $gateway,
        InvoiceStoreInterface $store,
        callable $clientFactory = null
    ) {
        $this->gateway = $gateway;
        $this->store = $store;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param array<string, mixed> $settings
     * @return int Number of orders moved to paid.
     */
    public function run(array $settings, $now = null)
    {
        $now = $now === null ? time() : (int) $now;
        $count = 0;

        // Only invoices older than a 30-minute grace window (younger ones are
        // still in their normal payment flow and the webhook is expected).
        foreach ($this->store->findUnpaidRecent(50, $now - 1800) as $row) {
            try {
                $invoice = $this->client((string) $row['environment'], $settings)
                    ->invoices()
                    ->get((string) $row['paymos_invoice_id']);

                if (!$this->snapshotMatches($row, $invoice)) {
                    $this->gateway->log('Paymos reconcile skipped: invoice snapshot mismatch.', array(
                        'paymos_invoice_id' => (string) $row['paymos_invoice_id'],
                    ));
                    continue;
                }

                // The reconcile path bypasses signature verification and dedup
                // (the GET is already authenticated), so the in-flight event
                // store is never exercised; any EventStoreInterface satisfies
                // the WebhookProcessor constructor.
                $applied = (new WebhookProcessor(
                    $this->gateway,
                    $this->store,
                    new InMemoryEventStore(),
                    $this->clientFactory
                ))->applyTrustedInvoice($invoice, $row, $settings, $now);

                if ($applied) {
                    $count++;
                }
            } catch (\Throwable $e) {
                $this->gateway->log('Paymos reconcile failed.', array(
                    'error' => $e->getMessage(),
                    'paymos_invoice_id' => isset($row['paymos_invoice_id']) ? (string) $row['paymos_invoice_id'] : '',
                ));
            }
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $invoice
     */
    private function snapshotMatches(array $row, array $invoice)
    {
        return $this->matches((string) $row['project_id'], $this->field($invoice, array('project_id')))
            && $this->matches((string) $row['external_order_id'], $this->field($invoice, array('order', 'external_id')))
            && $this->amountMatches((string) $row['amount'], $this->field($invoice, array('order', 'amount')))
            && $this->matches(strtoupper((string) $row['currency']), strtoupper($this->field($invoice, array('order', 'currency'))))
            && StatusMapper::invoiceAction('', $this->field($invoice, array('status'))) !== StatusMapper::ACTION_IGNORE;
    }

    private function matches($expected, $actual)
    {
        $expected = trim((string) $expected);
        $actual = trim((string) $actual);

        // Amounts are compared by the OrderMapper/AmountGuard later; here a
        // loose presence check is enough to decide "worth applying".
        return $expected === '' || $actual === '' || $expected === $actual;
    }

    /**
     * Decimal-safe amount pre-screen. The server trims trailing zeros on the
     * wire ("100.00" -> "100"), so a raw string compare would reject almost
     * every snapshot (which is always formatted to 2dp) and skip the reconcile.
     * Compare exactly as AmountGuard/InvoiceReverseVerifier do; absence on
     * either side is a non-blocking pass (the OrderMapper enforces AmountGuard).
     */
    private function amountMatches($expected, $actual)
    {
        $expected = trim((string) $expected);
        $actual = trim((string) $actual);

        return $expected === '' || $actual === '' || AmountGuard::amountsEqual($expected, $actual);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function client($environment, array $settings)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $environment);
        }

        return new Client(Config::fromSettings($settings)->clientConfigForEnvironment($environment));
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, string> $path
     */
    private function field(array $payload, array $path)
    {
        $current = $payload;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }

            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }
}
