<?php

declare(strict_types=1);

namespace PaymosPayments\Api\Controller;

use Paymos\Connect\DeviceConnectClient;
use PaymosPayments\Service\CredentialStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route(defaults: ['_routeScope' => ['api'], '_acl' => ['system_config:read', 'system_config:update']])]
final class ConnectController extends AbstractController
{
    private $credentialStore;

    public function __construct(CredentialStore $credentialStore)
    {
        $this->credentialStore = $credentialStore;
    }

    #[Route(path: '/api/_action/paymos/connect/start', name: 'api.action.paymos.connect.start', methods: ['POST'])]
    public function start(Request $request): JsonResponse
    {
        try {
            $sourceUrl = $this->sourceUrl($request);
            $state = (new DeviceConnectClient('https://app.paymos.io'))->start('shopware6', $sourceUrl);
            $this->credentialStore->saveState($state);
            return new JsonResponse([
                'verification_url' => $state['verification_url'],
                'user_code' => $state['user_code'],
                'interval' => $state['interval'],
            ]);
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 400);
        }
    }

    #[Route(path: '/api/_action/paymos/connect/poll', name: 'api.action.paymos.connect.poll', methods: ['POST'])]
    public function poll(Request $request): JsonResponse
    {
        try {
            $sourceUrl = $this->sourceUrl($request);
            $state = $this->credentialStore->loadState();
            if (!isset($state['device_code'])) {
                throw new \RuntimeException('No active Paymos connection request.');
            }
            $result = (new DeviceConnectClient('https://app.paymos.io'))->poll((string) $state['device_code']);
            if ($result['status'] === 'connected') {
                if ($result['plugin'] !== 'shopware6' || rtrim((string) $result['source_url'], '/') !== $sourceUrl) {
                    throw new \RuntimeException('Paymos connection response does not match this Shopware store.');
                }
                $this->credentialStore->saveCredentials($result['credentials']);
                $this->credentialStore->clearState();
                return new JsonResponse(['status' => 'connected']);
            }
            if (in_array($result['status'], ['authorization_pending', 'slow_down'], true)) {
                return new JsonResponse(['status' => $result['status']]);
            }
            $this->credentialStore->clearState();
            throw new \RuntimeException('Paymos connection was denied or expired.');
        } catch (\Throwable $exception) {
            return new JsonResponse(['error' => $exception->getMessage()], 400);
        }
    }

    private function sourceUrl(Request $request): string
    {
        $url = rtrim($request->getSchemeAndHttpHost() . $request->getBasePath(), '/');
        if (stripos($url, 'https://') !== 0) {
            throw new \RuntimeException('Shopware base URL must use HTTPS.');
        }
        return $url;
    }
}
