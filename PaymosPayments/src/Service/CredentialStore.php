<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Paymos\Plugin\AesGcmEnvelope;
use Paymos\Plugin\CredentialSet;
use Shopware\Core\System\SystemConfig\SystemConfigService;

final class CredentialStore
{
    private const PREFIX = 'PaymosPayments.config.';
    private $systemConfig;
    private $kernelSecret;

    public function __construct(SystemConfigService $systemConfig, string $kernelSecret)
    {
        $this->systemConfig = $systemConfig;
        $this->kernelSecret = $kernelSecret;
    }

    public function settings(array $settings): array
    {
        $payload = $this->load('credentialsEnvelope', 'paymos-shopware6-credentials-v1');
        if (count($payload) === 0) {
            return $settings;
        }
        if (!isset($payload['schema'], $payload['environments'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['environments'])) {
            throw new \RuntimeException('Stored Paymos credentials have an invalid schema.');
        }
        $settings['environments'] = CredentialSet::normalize($payload['environments']);
        return $settings;
    }

    public function saveCredentials(array $environments): void
    {
        $this->save('credentialsEnvelope', 'paymos-shopware6-credentials-v1', [
            'schema' => 1,
            'environments' => CredentialSet::normalize($environments),
        ]);
    }

    public function saveState(array $state): void
    {
        $this->save('connectStateEnvelope', 'paymos-shopware6-connect-state-v1', [
            'schema' => 1,
            'expires_at' => time() + (int) $state['expires_in'],
            'state' => $state,
        ]);
    }

    public function loadState(): array
    {
        $payload = $this->load('connectStateEnvelope', 'paymos-shopware6-connect-state-v1');
        if (!isset($payload['schema'], $payload['expires_at'], $payload['state'])
            || (int) $payload['schema'] !== 1
            || !is_array($payload['state'])
            || time() >= (int) $payload['expires_at']) {
            $this->clearState();
            return [];
        }
        return $payload['state'];
    }

    public function clearState(): void
    {
        $this->systemConfig->delete(self::PREFIX . 'connectStateEnvelope');
    }

    private function load(string $key, string $aad): array
    {
        $encoded = trim((string) $this->systemConfig->get(self::PREFIX . $key));
        return $encoded === '' ? [] : AesGcmEnvelope::open($encoded, $this->kernelSecret, $aad);
    }

    private function save(string $key, string $aad, array $payload): void
    {
        $this->systemConfig->set(
            self::PREFIX . $key,
            AesGcmEnvelope::seal($payload, $this->kernelSecret, $aad)
        );
    }
}
