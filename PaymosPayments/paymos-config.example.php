<?php

// Example of the dashboard-generated paymos-config.php. The real file is
// injected into the plugin ZIP by the Paymos dashboard and overrides the admin
// settings. The merchant never edits it by hand. Shape matches the generator:
// config_version + per-environment base_url and read-only credentials.
// (The sandbox/live mode is an admin setting, not part of this file.)

return array(
    'config_version' => 2,
    'environments' => array(
        'sandbox' => array(
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_test_xxxxxxxxxxxx',
            'api_secret' => 'sk_test_xxxxxxxxxxxx',
            'project_id' => 'prj_xxxxxxxxxxxx',
            'webhook_secret' => 'whsec_xxxxxxxxxxxx',
        ),
        'live' => array(
            'base_url' => 'https://api.paymos.io',
            'api_key' => 'pk_live_xxxxxxxxxxxx',
            'api_secret' => 'sk_live_xxxxxxxxxxxx',
            'project_id' => 'prj_xxxxxxxxxxxx',
            'webhook_secret' => 'whsec_xxxxxxxxxxxx',
        ),
    ),
);
