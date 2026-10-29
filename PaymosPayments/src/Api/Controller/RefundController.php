<?php

declare(strict_types=1);

namespace PaymosPayments\Api\Controller;

use PaymosPayments\Service\RefundAdapter;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api'], '_acl' => ['order_refund.editor', 'order:read']])]
final class RefundController extends AbstractController
{
    private $adapter;

    public function __construct(RefundAdapter $adapter) { $this->adapter = $adapter; }

    #[Route(path: '/api/_action/paymos/refund/{paymentKey}/{mode}', name: 'api.action.paymos.refund', methods: ['POST'])]
    public function submit(string $paymentKey, string $mode, Request $request, Context $context): JsonResponse
    {
        try {
            $body = json_decode($request->getContent(), true, 512, JSON_THROW_ON_ERROR);
            if ($context->getVersionId() !== Defaults::LIVE_VERSION || !is_array($body)
                || array_keys($body) !== ['request_token'] || !is_string($body['request_token'])
                || !in_array($mode, ['full', 'overpayment'], true)) {
                throw new \InvalidArgumentException('refund_mode_and_token_only');
            }
            $result = $mode === 'full' ? $this->adapter->full($paymentKey, $body['request_token'])
                : $this->adapter->overpayment($paymentKey, $body['request_token']);
            return new JsonResponse($result, 202);
        } catch (\Throwable $error) {
            return new JsonResponse(['error' => $error->getMessage(), 'message' => 'TODO-COPY'], 409);
        }
    }

    #[Route(path: '/api/_action/paymos/refund/{paymentKey}', name: 'api.action.paymos.refund.status', methods: ['GET'])]
    public function status(string $paymentKey): JsonResponse
    {
        try { return new JsonResponse(['operations' => $this->adapter->operations($paymentKey),
            'observations' => $this->adapter->observations($paymentKey), 'summary' => $this->adapter->summary($paymentKey)]); }
        catch (\Throwable $error) { return new JsonResponse(['error' => $error->getMessage(), 'message' => 'TODO-COPY'], 409); }
    }
}
