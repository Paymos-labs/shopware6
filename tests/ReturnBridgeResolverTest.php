<?php

declare(strict_types=1);

use PaymosPayments\Service\ReturnBridgeResolver;

function test_sw_bridge_redirects_to_return_url_when_resolved()
{
    $resolver = new ReturnBridgeResolver();
    $returnUrl = 'https://shop.test/payment/finalize-transaction?_sw_payment_token=tok123';

    assertSameValue(
        $returnUrl,
        $resolver->resolveTarget($returnUrl, false, 'https://shop.test/account/order'),
        'A resolved return URL is returned unchanged on a normal return.'
    );
}

function test_sw_bridge_appends_cancel_flag_with_ampersand()
{
    $resolver = new ReturnBridgeResolver();
    // Shopware return URLs always carry the _sw_payment_token query, so cancel
    // is appended with '&'.
    $returnUrl = 'https://shop.test/payment/finalize-transaction?_sw_payment_token=tok123';

    assertSameValue(
        $returnUrl . '&cancel=1',
        $resolver->resolveTarget($returnUrl, true, 'https://shop.test/account/order'),
        'Cancel appends cancel=1 with & when the return URL already has a query.'
    );
}

function test_sw_bridge_appends_cancel_flag_with_question_mark()
{
    $resolver = new ReturnBridgeResolver();
    // Defensive: a query-less return URL gets cancel via '?'.
    $returnUrl = 'https://shop.test/payment/finalize-transaction';

    assertSameValue(
        $returnUrl . '?cancel=1',
        $resolver->resolveTarget($returnUrl, true, 'https://shop.test/account/order'),
        'Cancel appends cancel=1 with ? when the return URL has no query.'
    );
}

function test_sw_bridge_falls_back_to_account_when_no_return_url()
{
    $resolver = new ReturnBridgeResolver();

    assertSameValue(
        'https://shop.test/account/order',
        $resolver->resolveTarget('', false, 'https://shop.test/account/order'),
        'An empty return URL falls back to the account order page.'
    );
}

function test_sw_bridge_falls_back_even_on_cancel_when_no_return_url()
{
    $resolver = new ReturnBridgeResolver();

    assertSameValue(
        'https://shop.test/account/order',
        $resolver->resolveTarget('', true, 'https://shop.test/account/order'),
        'A cancel return with no known return URL still falls back to the account page.'
    );
}
