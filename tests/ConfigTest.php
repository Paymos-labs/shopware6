<?php

declare(strict_types=1);

use PaymosPayments\Service\Config;

function test_sw_config_resolves_environment_and_secrets()
{
    $config = Config::fromArray(sw_generated_config(array(
        'environments' => array(
            'live' => array(
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_live_1',
                'api_secret' => 'sk_live_1',
                'project_id' => 'prj_live',
                'webhook_secret' => 'whsec_live',
            ),
        ),
    )), sw_settings());

    assertSameValue('sandbox', $config->environment(), 'Default mode is sandbox.');
    assertSameValue('prj_123', $config->projectId(), 'Sandbox project id resolves.');

    $secrets = $config->webhookSecrets();
    assertSameValue('whsec_sandbox', $secrets['sandbox'], 'Sandbox webhook secret resolves.');
    assertSameValue('whsec_live', $secrets['live'], 'Live webhook secret resolves.');
}

function test_sw_config_live_mode_uses_live_block()
{
    $config = Config::fromArray(sw_generated_config(array(
        'environments' => array(
            'live' => array(
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_live_1',
                'api_secret' => 'sk_live_1',
                'project_id' => 'prj_live',
                'webhook_secret' => 'whsec_live',
            ),
        ),
    )), sw_settings(array('mode' => 'live')));

    assertSameValue('live', $config->environment(), 'Live mode resolves.');
    assertSameValue('prj_live', $config->projectId(), 'Live project id resolves.');

    $clientConfig = $config->clientConfigForEnvironment('live');
    assertSameValue('https://api.paymos.io', $clientConfig->baseUrl(), 'Live base url resolves.');
}

function test_sw_config_defaults_base_url_to_public_host()
{
    $config = Config::fromArray(array(
        'config_version' => 2,
        'environments' => array(
            'sandbox' => array(
                'api_key' => 'pk_test_1',
                'api_secret' => 'sk_test_1',
                'project_id' => 'prj_1',
                'webhook_secret' => 'whsec_1',
            ),
        ),
    ), sw_settings());

    assertSameValue('https://api.paymos.io', $config->clientConfig()->baseUrl(), 'Missing base_url defaults to api.paymos.io.');
}

function test_sw_config_rejects_mismatched_credential_environment()
{
    $config = Config::fromArray(array(
        'config_version' => 2,
        'environments' => array(
            'live' => array(
                'base_url' => 'https://api.paymos.io',
                'api_key' => 'pk_test_1', // test key in live block
                'api_secret' => 'sk_test_1',
                'project_id' => 'prj_1',
                'webhook_secret' => 'whsec_1',
            ),
        ),
    ), sw_settings(array('mode' => 'live')));

    $threw = false;
    try {
        $config->clientConfig();
    } catch (\InvalidArgumentException $e) {
        $threw = true;
    }

    assertTrueValue($threw, 'Live mode with test credentials must throw.');
}

function test_sw_config_is_configured_reports_readiness()
{
    $ready = Config::fromArray(sw_generated_config(), sw_settings());
    assertTrueValue($ready->isConfigured(), 'A complete sandbox block is configured.');

    $empty = Config::fromArray(array('config_version' => 2, 'environments' => array()), sw_settings());
    assertFalseValue($empty->isConfigured(), 'An empty config is not configured.');
}

function test_sw_config_debug_logging_toggle()
{
    $on = Config::fromArray(sw_generated_config(), sw_settings(array('debug_logging' => '1')));
    assertTrueValue($on->debugLogging(), 'debug_logging=1 enables logging.');

    $off = Config::fromArray(sw_generated_config(), sw_settings(array('debug_logging' => '0')));
    assertFalseValue($off->debugLogging(), 'debug_logging=0 disables logging.');
}
