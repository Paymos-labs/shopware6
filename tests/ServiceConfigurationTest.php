<?php

declare(strict_types=1);

function test_sw_service_configuration_nullable_factory_keeps_constructor_positions()
{
    $file = PAYMOS_SW_SRC_DIR . 'Resources/config/services.xml';
    $document = new DOMDocument();
    assertTrueValue($document->load($file), 'Service XML must be well formed.');
    $xpath = new DOMXPath($document);
    $xpath->registerNamespace('s', 'http://symfony.com/schema/dic/services');
    $arguments = $xpath->query('//s:service[@id="PaymosPayments\Service\WebhookProcessor"]/s:argument');
    assertSameValue(5, $arguments->length, 'Webhook constructor argument count must remain fixed.');
    $expected = array('PaymosPayments\Service\ShopwareGateway', 'PaymosPayments\Service\PaymosInvoiceStore',
        'PaymosPayments\Service\EventStore', null, 'PaymosPayments\Service\RefundAdapter');
    foreach ($expected as $index => $id) {
        $argument = $arguments->item($index);
        if ($id === null) {
            assertSameValue('', $argument->getAttribute('type'), 'Symfony null uses untyped literal, not unsupported type=null.');
            assertSameValue('null', trim($argument->textContent), 'The nullable factory remains real PHP null.');
        } else {
            assertSameValue('service', $argument->getAttribute('type'), 'Adjacent constructor arguments remain service references.');
            assertSameValue($id, $argument->getAttribute('id'), 'Adjacent dependency order remains unchanged.');
        }
    }
    assertSameValue(0, $xpath->query('//s:argument[@type="null"]')->length, 'No invalid null types may remain.');
}
