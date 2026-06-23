<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Paymos\Client;
use Paymos\Exception\ApiException;

/**
 * Flow A — checkout to invoice. Framework-agnostic: the Shopware payment
 * handler hands it a plain order array (transaction id, order number, amount,
 * currency, customer id), it talks to the Paymos API through the SDK, snapshots
 * the invoice on the order, and returns the hosted payment URL to redirect to.
 *
 * The crypto-critical path (signing, retry) is 100% SDK. This class only owns
 * the Shopware-to-Paymos payload translation, the deterministic external order
 * id, and the snapshot.
 */
final class CheckoutProcessor
{
    /** @var InvoiceStoreInterface */
    private $store;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(InvoiceStoreInterface $store, callable $clientFactory = null)
    {
        $this->store = $store;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $settings
     * @return array<string, string> invoice_id, payment_url, reused
     */
    public function start(array $order, array $settings)
    {
        $config = Config::fromSettings($settings);

        $transactionId = $this->field($order, 'transaction_id');
        if ($transactionId === '') {
            throw new \RuntimeException('Shopware order transaction id is missing.');
        }

        $orderNumber = $this->field($order, 'order_number');
        if ($orderNumber === '') {
            throw new \RuntimeException('Shopware order number is missing.');
        }

        // The order transaction amount is the figure the buyer must pay; it is
        // already denominated in the order currency.
        $amount = $this->formatAmount($this->field($order, 'amount'));
        $currency = strtoupper($this->field($order, 'currency'));
        if ($currency === '') {
            throw new \RuntimeException('Shopware order currency is missing.');
        }
        if ($amount === '' || (float) $amount <= 0.0) {
            throw new \RuntimeException('Shopware order amount must be greater than zero.');
        }

        $existing = $this->store->findByTransactionId($transactionId);
        if (is_array($existing) && $this->snapshotMatches($existing, $amount, $currency, $config)) {
            // Reuse the existing invoice, but refresh the Shopware return URL:
            // an afterOrderEnabled retry calls pay() again with a FRESH
            // _sw_payment_token, and the return bridge must hand back the latest
            // token. Re-save the row (upsert key external_order_id) with the new
            // return_url only when it actually changed.
            $returnUrl = $this->field($order, 'return_url');
            if ($returnUrl !== '' && $returnUrl !== (isset($existing['return_url']) ? (string) $existing['return_url'] : '')) {
                $refreshed = $existing;
                $refreshed['return_url'] = $returnUrl;
                $this->store->save($refreshed);
            }

            return array(
                'invoice_id' => (string) $existing['paymos_invoice_id'],
                'payment_url' => (string) $existing['payment_url'],
                'reused' => '1',
            );
        }

        // Deterministic, version-bumped external order id: reuse while the
        // amount snapshot holds, bump a suffix when the order changed so a
        // changed order gets a fresh invoice (the server keys on this id).
        $renewCount = is_array($existing) && isset($existing['renew_count']) ? ((int) $existing['renew_count'] + 1) : 0;
        $externalOrderId = $orderNumber . '_' . $renewCount;

        $payload = $this->createPayload($order, $config, $amount, $currency, $externalOrderId);

        try {
            $response = $this->client($config)->invoices()->create($payload);
        } catch (ApiException $e) {
            // Surface the server's own message (detail/field), never a generic
            // "payment failed", so the merchant can act on a real validation
            // error (e.g. wrong currency, project mismatch).
            $detail = $e->detail();
            $field = $e->field();
            $message = 'Paymos could not create the invoice: '
                . ($detail !== null && $detail !== '' ? $detail : $e->getMessage());
            if ($field !== null && $field !== '') {
                $message .= ' (field: ' . $field . ')';
            }
            throw new \RuntimeException($message, 0, $e);
        }

        $paymosInvoiceId = $this->responseField($response, array('invoice_id'));
        if ($paymosInvoiceId === '') {
            $paymosInvoiceId = $this->responseField($response, array('id'));
        }

        $paymentUrl = $this->responseField($response, array('payment_url'));
        if ($paymentUrl === '') {
            $paymentUrl = $this->responseField($response, array('checkout_url'));
        }
        if ($paymentUrl === '') {
            $paymentUrl = $this->responseField($response, array('url'));
        }

        if ($paymosInvoiceId === '' || $paymentUrl === '') {
            throw new \RuntimeException('Paymos invoice create response is missing invoice id or payment URL.');
        }

        $this->store->save(array(
            'transaction_id' => $transactionId,
            'paymos_invoice_id' => $paymosInvoiceId,
            'external_order_id' => $externalOrderId,
            'environment' => $config->environment(),
            'project_id' => $config->projectId(),
            'amount' => $amount,
            'currency' => $currency,
            'payment_url' => $paymentUrl,
            // Snapshot the Shopware async return URL (carries the
            // _sw_payment_token) so the storefront return bridge can hand the
            // buyer back to Shopware's finalize flow. NEVER sent to Paymos.
            'return_url' => $this->field($order, 'return_url'),
            'status' => $this->responseField($response, array('status')) ?: 'created',
            'renew_count' => $renewCount,
        ));

        return array(
            'invoice_id' => $paymosInvoiceId,
            'payment_url' => $paymentUrl,
            'reused' => '0',
        );
    }

    /**
     * @param array<string, mixed> $order
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function createPayload(array $order, Config $config, $amount, $currency, $externalOrderId)
    {
        // Only the fields CreateInvoiceRequest accepts. MerchantId is NEVER
        // sent (the server derives it from project_id). No lifetime/TTL field
        // (server-side config only).
        $payload = array(
            'project_id' => $config->projectId(),
            'amount' => $amount,
            'currency' => $currency,
            'external_order_id' => $externalOrderId,
            'allow_multiple_payments' => true,
        );

        $clientId = $this->field($order, 'customer_id');
        if ($clientId !== '' && $clientId !== '0') {
            // Native Shopware customer id — never the email.
            $payload['client_id'] = $clientId;
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function snapshotMatches(array $row, $amount, $currency, Config $config)
    {
        return (string) $row['amount'] === (string) $amount
            && strtoupper((string) $row['currency']) === strtoupper((string) $currency)
            && (string) $row['project_id'] === $config->projectId()
            && (string) $row['environment'] === $config->environment()
            && trim((string) $row['payment_url']) !== '';
    }

    private function client(Config $config)
    {
        if ($this->clientFactory !== null) {
            return call_user_func($this->clientFactory, $config);
        }

        return new Client($config->clientConfig());
    }

    private function formatAmount($amount)
    {
        return number_format((float) $amount, 2, '.', '');
    }

    /**
     * @param array<string, mixed> $source
     */
    private function field(array $source, $key)
    {
        return isset($source[$key]) && is_scalar($source[$key]) ? trim((string) $source[$key]) : '';
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $path
     */
    private function responseField(array $source, array $path)
    {
        $current = $source;
        foreach ($path as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return '';
            }
            $current = $current[$segment];
        }

        return is_scalar($current) ? (string) $current : '';
    }
}
