<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Paymos\ClientConfig;

/**
 * Two-tier configuration, secrets read-only.
 *
 *  - The dashboard injects a generated `paymos-config.php` into the plugin ZIP
 *    (v2 shape: `config_version` + `environments.{sandbox,live}.{api_key,
 *    api_secret,project_id,webhook_secret,base_url}`). It is the source of
 *    truth for credentials and overrides anything entered in the admin.
 *  - The Shopware admin (SystemConfigService) only carries the `mode`
 *    (sandbox/live) toggle, the debug flag, and presentation. The merchant
 *    never types a secret.
 *
 * `$settings` is the admin slice (a plain array lifted from SystemConfigService
 * so the core stays Shopware-free). `$generated` is the loaded config file.
 */
final class Config
{
    /** @var array<string, mixed>|null */
    private static $generatedConfig;

    /** @var string|null */
    private static $generatedConfigPath;

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
        return new self($settings, self::generatedConfig());
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
        self::$generatedConfig = null;
        self::$generatedConfigPath = null;
    }

    /**
     * Override the on-disk config path (tests only).
     *
     * @param string $path
     */
    public static function useConfigPathForTests($path)
    {
        self::$generatedConfig = null;
        self::$generatedConfigPath = (string) $path;
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
            throw new \InvalidArgumentException('Paymos generated config must contain at least one webhook secret.');
        }

        return $secrets;
    }

    public function debugLogging()
    {
        $value = strtolower($this->scalar($this->settings, 'debug_logging'));
        return $value === '1' || $value === 'true' || $value === 'yes' || $value === 'on';
    }

    /**
     * Whether a usable generated config is present (at least one environment
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
            throw new \InvalidArgumentException('Paymos generated config is missing ' . $key . ' for ' . $environment . '.');
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

    /**
     * @return array<string, mixed>
     */
    private static function generatedConfig()
    {
        if (self::$generatedConfig !== null) {
            return self::$generatedConfig;
        }

        $path = self::$generatedConfigPath !== null
            ? self::$generatedConfigPath
            : dirname(__DIR__, 2) . '/paymos-config.php';

        if (is_file($path)) {
            $config = require $path;
            if (is_array($config)) {
                self::$generatedConfig = $config;
                return self::$generatedConfig;
            }
        }

        self::$generatedConfig = array(
            'mode' => 'sandbox',
            'environments' => array(),
        );

        return self::$generatedConfig;
    }
}
