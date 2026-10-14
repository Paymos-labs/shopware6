<?php

declare(strict_types=1);

use PaymosPayments\Migration\Migration1781784200AddPaidLateNoted;
use PaymosPayments\Service\PaymosInvoiceStore;

/**
 * invoice-late-payment, late-44: the paid-late marker lives in the shop's own table, so a redelivery after a
 * crash (note written, dedup not yet committed) still sees it. Runs the real store and migration against a
 * recording connection.
 */
final class FakeShopwareStoreConnection extends \Doctrine\DBAL\Connection
{
    /** @var array<int, string> */
    public $statements = array();
    /** @var array<int, array<string, mixed>> */
    public $updates = array();
    /** @var array<int, array<string, mixed>> */
    public $inserts = array();
    /** @var bool */
    public $hasMarkerColumn = false;
    /** @var array<string, mixed>|false */
    public $existing = false;

    public function __construct()
    {
    }

    public function fetchOne($sql, array $params = array(), array $types = array())
    {
        return stripos((string) $sql, 'SHOW COLUMNS') !== false && $this->hasMarkerColumn ? 'paid_late_noted' : false;
    }

    public function fetchAssociative($sql, array $params = array(), array $types = array())
    {
        return $this->existing;
    }

    public function executeStatement($sql, array $params = array(), array $types = array())
    {
        $this->statements[] = (string) $sql;
        return 0;
    }

    public function update($table, array $data, array $criteria = array(), array $types = array())
    {
        $this->updates[] = $data;
        return 1;
    }

    public function insert($table, array $data, array $types = array())
    {
        $this->inserts[] = $data;
        return 1;
    }
}

function paymos_shopware_store_row(array $extra = array())
{
    return $extra + array(
        'transaction_id' => 'tx_7',
        'paymos_invoice_id' => 'inv_late',
        'external_order_id' => 'sw-7',
        'environment' => 'live',
        'project_id' => 'prj_1',
        'amount' => '100.00',
        'currency' => 'EUR',
        'payment_url' => 'https://pay.paymos.test/inv_late',
        'status' => 'expired',
        'renew_count' => 0,
    );
}

function test_shopware_migration_adds_the_paid_late_column_once()
{
    $old = new FakeShopwareStoreConnection();
    (new Migration1781784200AddPaidLateNoted())->update($old);
    assertSameValue(1, count($old->statements), 'an old table gains the marker column.');
    assertContainsValue('ADD COLUMN `paid_late_noted`', $old->statements[0], 'the ALTER adds the marker column.');

    $current = new FakeShopwareStoreConnection();
    $current->hasMarkerColumn = true;
    (new Migration1781784200AddPaidLateNoted())->update($current);
    assertSameValue(array(), $current->statements, 'a table that has the column is not altered again.');
}

function test_shopware_store_persists_the_paid_late_marker()
{
    $connection = new FakeShopwareStoreConnection();
    $connection->existing = paymos_shopware_store_row();
    (new PaymosInvoiceStore($connection))->save(paymos_shopware_store_row(array('paid_late_noted' => 'evt_late_1')));
    assertSameValue('evt_late_1', $connection->updates[0]['paid_late_noted'], 'the marker is written on update.');

    $connection = new FakeShopwareStoreConnection();
    (new PaymosInvoiceStore($connection))->save(paymos_shopware_store_row());
    assertSameValue(false, array_key_exists('paid_late_noted', $connection->inserts[0]), 'a save without the marker does not touch it.');
}
