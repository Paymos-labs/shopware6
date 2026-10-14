<?php

declare(strict_types=1);

namespace PaymosPayments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds the `paid_late_noted` column to `paymos_payment_invoice`: the marker that the late-payment note
 * (invoice-late-payment) is already in the order log, so a redelivery does not log it twice. A new migration,
 * because Shopware never re-runs an applied one.
 */
class Migration1781784200AddPaidLateNoted extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781784200;
    }

    public function update(Connection $connection): void
    {
        $exists = $connection->fetchOne(
            "SHOW COLUMNS FROM `paymos_payment_invoice` LIKE 'paid_late_noted'"
        );
        if ($exists !== false) {
            return;
        }

        $connection->executeStatement(
            'ALTER TABLE `paymos_payment_invoice` ADD COLUMN `paid_late_noted` VARCHAR(128) NULL'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Dropped with the table on uninstall when the merchant opts out of keeping data.
    }
}
