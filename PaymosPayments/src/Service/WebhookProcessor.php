<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Paymos\Client;
use Paymos\Exception\DuplicateEventException;
use Paymos\Exception\SignatureMismatchException;
use Paymos\Exception\TimestampSkewException;
use Paymos\Plugin\InvoiceReverseVerifier;
use Paymos\Plugin\StatusMapper;
use Paymos\Webhook\EventStoreInterface;
use Paymos\Webhook\MultiEnvironmentWebhookVerifier;
use Paymos\Webhook\WebhookEvent;

/**
 * Flow B — webhook callback to order update. Framework-agnostic. Verifies the
 * HMAC across both environments and dedups (SDK), asserts the payload
 * environment, finds the order by the stored external order id, reverse-verifies
 * terminal events against the live invoice, then drives the Shopware
 * transaction state through {@see OrderMapper}.
 *
 * Two DIFFERENT signature algorithms exist (base64 inbound API vs hex webhook);
 * webhook verification is wholly the SDK's job here — never reimplemented.
 *
 * HTTP-code contract the server's retry logic depends on:
 *   duplicate -> 200 (stop retrying); signature mismatch -> 401;
 *   timestamp skew -> 401; config error -> 500; anything else -> 400.
 * There is no 202.
 */
final class WebhookProcessor
{
    /** @var ShopwareGatewayInterface */
    private $gateway;

    /** @var InvoiceStoreInterface */
    private $invoiceStore;

    /** @var EventStoreInterface */
    private $eventStore;

    /** @var callable|null */
    private $clientFactory;

    public function __construct(
        ShopwareGatewayInterface $gateway,
        InvoiceStoreInterface $invoiceStore,
        EventStoreInterface $eventStore,
        callable $clientFactory = null
    ) {
        $this->gateway = $gateway;
        $this->invoiceStore = $invoiceStore;
        $this->eventStore = $eventStore;
        $this->clientFactory = $clientFactory;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public function handle($rawBody, $signatureHeader, array $settings, $now = null)
    {
        try {
            $config = Config::fromSettings($settings);
            $verified = (new MultiEnvironmentWebhookVerifier($config->webhookSecrets(), $this->eventStore))
                ->process($signatureHeader, $rawBody, $now);
            $environment = $verified->environment();
            $event = $verified->event();

            if (!$event->isInvoiceEvent()) {
                $this->commitEvent();
                return new CallbackResult(200, 'OK');
            }

            $this->assertPayloadEnvironment($event, $environment);
            $this->applyVerifiedEvent($event, $environment, $settings, true);
            $this->commitEvent();

            return new CallbackResult(200, 'OK');
        } catch (DuplicateEventException $e) {
            $this->debugLog($settings, 'Paymos duplicate webhook ignored.', array('duplicate' => true));
            return new CallbackResult(200, 'OK', true);
        } catch (SignatureMismatchException $e) {
            return new CallbackResult(401, 'Bad signature');
        } catch (TimestampSkewException $e) {
            return new CallbackResult(401, 'Bad timestamp');
        } catch (\InvalidArgumentException $e) {
            $this->releaseEvent();
            $this->gateway->log('Paymos Shopware configuration error.', array('error' => $e->getMessage()));
            return new CallbackResult(500, 'Configuration error');
        } catch (\Throwable $e) {
            // Any failure during the mutation (including a PHP Error from a
            // third-party state-machine subscriber) must still release the
            // in-flight dedup lock, or the event is durably marked seen and the
            // order is never retried.
            $this->releaseEvent();
            $this->gateway->log('Paymos Shopware webhook processing failed.', array('error' => $e->getMessage()));
            return new CallbackResult(400, 'Processing failed');
        }
    }

    /**
     * Reconcile path: apply an invoice pulled from the API (already trusted —
     * it came from an authenticated GET, so no signature/dedup), funnelled
     * through the SAME mapper so reverse-verify-equivalent snapshot matching,
     * AmountGuard and the roll-back guard all still apply.
     *
     * @param array<string, mixed> $invoice
     * @param array<string, mixed> $row
     * @param array<string, mixed> $settings
     */
    public function applyTrustedInvoice(array $invoice, array $row, array $settings, $now)
    {
        $invoiceId = $this->field($invoice, array('invoice_id'));
        $status = $this->field($invoice, array('status'));
        $event = new WebhookEvent(array(
            'event_id' => 'reconcile_' . $invoiceId . '_' . $status,
            'event_type' => $this->eventTypeForStatus($status),
            'occurred_at' => (int) $now,
            'data' => $invoice,
        ));

        return $this->applyEvent($event, (string) $row['environment'], $row, $settings, false);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function applyVerifiedEvent(WebhookEvent $event, $environment, array $settings, $reverseVerify)
    {
        $externalOrderId = $event->externalOrderId();
        if ($externalOrderId === '') {
            throw new \RuntimeException('Paymos webhook payload is missing external order id.');
        }

        $row = $this->invoiceStore->findByExternalOrderId($externalOrderId);
        if (!is_array($row)) {
            throw new \RuntimeException('Paymos Shopware invoice snapshot was not found.');
        }

        return $this->applyEvent($event, $environment, $row, $settings, $reverseVerify);
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $settings
     */
    private function applyEvent(WebhookEvent $event, $environment, array $row, array $settings, $reverseVerify)
    {
        $config = Config::fromSettings($settings);
        $this->assertRowMatchesEvent($row, $event, $environment);

        if ($reverseVerify && $this->requiresReverseVerify($event)) {
            $result = (new InvoiceReverseVerifier($this->client($environment, $settings)))->verify($event, array(
                'project_id' => (string) $row['project_id'],
                'external_order_id' => (string) $row['external_order_id'],
                'amount' => (string) $row['amount'],
                'currency' => (string) $row['currency'],
            ));

            if (!$result->isVerified()) {
                throw new \RuntimeException('Paymos reverse verification failed: ' . $result->reason());
            }
        }

        $this->invoiceStore->updateStatus($event->invoiceId(), $event->status());

        return (new OrderMapper($this->gateway))->apply($event, $row, $config->debugLogging());
    }

    /**
     * @param array<string, mixed> $row
     */
    private function assertRowMatchesEvent(array $row, WebhookEvent $event, $environment)
    {
        if ((string) $row['environment'] !== (string) $environment) {
            throw new \RuntimeException('Paymos event environment does not match Shopware invoice snapshot.');
        }
        if ((string) $row['project_id'] !== '' && $event->projectId() !== '' && (string) $row['project_id'] !== $event->projectId()) {
            throw new \RuntimeException('Paymos event project does not match Shopware invoice snapshot.');
        }
        if ((string) $row['external_order_id'] !== '' && $event->externalOrderId() !== '' && (string) $row['external_order_id'] !== $event->externalOrderId()) {
            throw new \RuntimeException('Paymos event external order does not match Shopware invoice snapshot.');
        }
        if ((string) $row['paymos_invoice_id'] !== '' && $event->invoiceId() !== '' && (string) $row['paymos_invoice_id'] !== $event->invoiceId()) {
            throw new \RuntimeException('Paymos event invoice id does not match Shopware invoice snapshot.');
        }
    }

    private function assertPayloadEnvironment(WebhookEvent $event, $environment)
    {
        $isTest = $event->isTest();
        if ($isTest === null) {
            return;
        }

        if ($environment === 'sandbox' && $isTest !== true) {
            throw new \RuntimeException('Sandbox webhook payload is not marked as test.');
        }
        if ($environment === 'live' && $isTest !== false) {
            throw new \RuntimeException('Live webhook payload is marked as test.');
        }
    }

    private function requiresReverseVerify(WebhookEvent $event)
    {
        $action = StatusMapper::invoiceAction($event->type(), $event->status());
        return in_array($action, array(
            StatusMapper::ACTION_PAYMENT_COMPLETE,
            StatusMapper::ACTION_FAIL_ORDER,
            StatusMapper::ACTION_CANCEL_ORDER,
        ), true);
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

    private function eventTypeForStatus($status)
    {
        switch (StatusMapper::invoiceAction('', $status)) {
            case StatusMapper::ACTION_CONFIRMING:
                return 'invoice.confirming';
            case StatusMapper::ACTION_AWAITING_PAYMENT:
                return ((string) $status === 'awaiting_payment') ? 'invoice.awaiting_payment' : 'invoice.underpaid_waiting';
            case StatusMapper::ACTION_PAYMENT_COMPLETE:
                return ((string) $status === 'paid_over') ? 'invoice.paid_over' : 'invoice.paid';
            case StatusMapper::ACTION_FAIL_ORDER:
                return 'invoice.underpaid';
            case StatusMapper::ACTION_CANCEL_ORDER:
                return ((string) $status === 'expired') ? 'invoice.expired' : 'invoice.cancelled';
        }

        return '';
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $context
     */
    private function debugLog(array $settings, $message, array $context = array())
    {
        try {
            if (!Config::fromSettings($settings)->debugLogging()) {
                return;
            }
        } catch (\Exception $e) {
            return;
        }

        $this->gateway->log($message, $context);
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

    private function commitEvent()
    {
        if (method_exists($this->eventStore, 'commit')) {
            $this->eventStore->commit();
        }
    }

    private function releaseEvent()
    {
        if (method_exists($this->eventStore, 'release')) {
            $this->eventStore->release();
        }
    }
}
