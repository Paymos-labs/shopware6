<?php

declare(strict_types=1);

namespace PaymosPayments\Storefront\Controller;

use PaymosPayments\Service\WebhookProcessor;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives signed Paymos webhooks. Storefront route scope; no Shopware CSRF
 * (webhooks are server-to-server and authenticated by the Paymos HMAC
 * signature, verified inside the SDK via {@see WebhookProcessor}).
 *
 * `Symfony\Component\Routing\Attribute\Route` is used deliberately: it exists in
 * both Symfony 6.4 (Shopware 6.5) and Symfony 7 (Shopware 6.6), so a single
 * codebase runs on both. The 6.4-only `Annotation\Route` alias was removed in
 * Symfony 7 and must not be used here.
 *
 * @internal Plugin entry point; registered as a public service with setContainer.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
final class WebhookController extends StorefrontController
{
    /** SystemConfigService key prefix for this plugin's admin settings. */
    private const CONFIG_DOMAIN = 'PaymosPayments.config.';

    /** @var WebhookProcessor */
    private $webhookProcessor;

    /** @var SystemConfigService */
    private $systemConfig;

    public function __construct(WebhookProcessor $webhookProcessor, SystemConfigService $systemConfig)
    {
        $this->webhookProcessor = $webhookProcessor;
        $this->systemConfig = $systemConfig;
    }

    #[Route(
        path: '/paymos/webhook',
        name: 'frontend.paymos.webhook',
        methods: ['POST']
    )]
    public function handle(Request $request): Response
    {
        $rawBody = $request->getContent();
        // Symfony header lookup is case-insensitive; the server sends
        // X-Webhook-Signature (never Paymos-Signature).
        $signatureHeader = (string) $request->headers->get('X-Webhook-Signature', '');

        $result = $this->webhookProcessor->handle($rawBody, $signatureHeader, $this->settings());

        return new Response($result->message(), $result->statusCode());
    }

    /**
     * Admin slice of the config (mode + debug). The webhook is not bound to a
     * single sales channel, so global config is read (null sales-channel id).
     *
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return array(
            'mode' => (string) $this->systemConfig->getString(self::CONFIG_DOMAIN . 'mode'),
            'debug_logging' => $this->systemConfig->getBool(self::CONFIG_DOMAIN . 'debugLogging') ? '1' : '0',
        );
    }
}
