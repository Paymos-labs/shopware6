<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Paymos\ClientConfig;

/** Runtime configuration assembled from Shopware's encrypted credential store. */
final class Config
{
    /** @var array<string, mixed> */
    private static $testConfig = array();

    /** @var array<string, mixed> */
    private $settings;

    /** @var array<string, mixed> */
    private $generated;

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $generated
     */
    private function __construct(array $settings, array $generated)
    {
        $this->settings = $settings;
        $this->generated = $generated;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function fromSettings(array $settings)
    {
        $generated = isset($settings['environments']) && is_array($settings['environments'])
            ? array('environments' => $settings['environments'])
            : self::$testConfig;
        return new self($settings, $generated);
    }

    /**
     * Build directly from an explicit generated-config array. Used by unit
     * tests so they never touch the on-disk config file.
     *
     * @param array<string, mixed> $generated
     * @param array<string, mixed> $settings
     */
    public static function fromArray(array $generated, array $settings = array())
    {
        return new self($settings, $generated);
    }

    public static function resetForTests()
    {
        self::$testConfig = array();
    }

    /** @param array<string, mixed> $config */
    public static function useConfigForTests(array $config)
    {
        self::$testConfig = $config;
    }

    public function environment()
    {
        $mode = $this->scalar($this->settings, 'mode');
        if ($mode === '') {
            $mode = $this->scalar($this->generated, 'mode');
        }

        return $mode === 'live' ? 'live' : 'sandbox';
    }

    public function projectId()
    {
        return $this->environmentValue($this->environment(), 'project_id');
    }

    public function clientConfig()
    {
        return $this->clientConfigForEnvironment($this->environment());
    }

    public function clientConfigForEnvironment($environment)
    {
        $environment = $environment === 'live' ? 'live' : 'sandbox';
        $apiKey = $this->environmentValue($environment, 'api_key');
        $apiSecret = $this->environmentValue($environment, 'api_secret');
        $this->assertCredentialEnvironment($environment, $apiKey, $apiSecret);

        return new ClientConfig($apiKey, $apiSecret, $this->apiBaseUrl($environment), 30);
    }

    /**
     * @return array<string, string>
     */
    public function webhookSecrets()
    {
        $secrets = array();
        foreach (array('sandbox', 'live') as $environment) {
            $secret = $this->environmentValue($environment, 'webhook_secret', false);
            if ($secret !== '') {
                $secrets[$environment] = $secret;
            }
        }

        if (count($secrets) === 0) {
            throw new \InvalidArgumentException('Paymos connected credentials must contain at least one webhook secret.');
        }

        return $secrets;
    }

    public function debugLogging()
    {
        $value = strtolower($this->scalar($this->settings, 'debug_logging'));
        return $value === '1' || $value === 'true' || $value === 'yes' || $value === 'on';
    }

    /**
     * Whether a usable connected credential set is present (at least one environment
     * carries both an API key and a webhook secret). The payment handler uses
     * this to fail checkout cleanly instead of throwing deep in the SDK.
     */
    public function isConfigured()
    {
        foreach (array('sandbox', 'live') as $environment) {
            $apiKey = $this->environmentValue($environment, 'api_key', false);
            $secret = $this->environmentValue($environment, 'webhook_secret', false);
            if ($apiKey !== '' && $secret !== '') {
                return true;
            }
        }

        return false;
    }

    private function apiBaseUrl($environment)
    {
        // The dashboard generator nests base_url inside each environment block;
        // there is no top-level api_base_url. Default to the public host.
        $url = $this->environmentValue($environment, 'base_url', false);
        return $url !== '' ? $url : 'https://api.paymos.io';
    }

    private function environmentValue($environment, $key, $required = true)
    {
        $environments = isset($this->generated['environments']) && is_array($this->generated['environments'])
            ? $this->generated['environments']
            : array();

        $config = isset($environments[$environment]) && is_array($environments[$environment])
            ? $environments[$environment]
            : array();

        $value = $this->scalar($config, $key);
        if ($required && $value === '') {
            throw new \InvalidArgumentException('Paymos connected credentials are missing ' . $key . ' for ' . $environment . '.');
        }

        return $value;
    }

    private function assertCredentialEnvironment($environment, $apiKey, $apiSecret)
    {
        if ($environment === 'sandbox') {
            if (strpos($apiKey, '_test_') === false || strpos($apiSecret, '_test_') === false) {
                throw new \InvalidArgumentException('Sandbox mode requires *_test_* API credentials.');
            }
            return;
        }

        if (strpos($apiKey, '_live_') === false || strpos($apiSecret, '_live_') === false) {
            throw new \InvalidArgumentException('Live mode requires *_live_* API credentials.');
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function scalar(array $source, $key)
    {
        return isset($source[$key]) && is_scalar($source[$key]) ? trim((string) $source[$key]) : '';
    }

}
