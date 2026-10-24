<?php

declare(strict_types=1);

namespace PaymosPayments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * A10-02: adds the `owner_token` column to `paymos_payment_event` for
 * installations whose base migration ran before it existed there.
 *
 * Every remember() mints a random owner token and commit()/release() match it,
 * so a delivery whose reservation expired can no longer delete or commit the
 * reservation a newer delivery now owns. Rows written before this column keep
 * '' and never match a live token. Existing reservation and committed expiry
 * times are preserved, so no data migration is needed.
 *
 * Fresh installs already create the column in
 * Migration1781784000PaymosPaymentTables; those installations need no ALTER.
 */
class Migration1781784300AddEventOwnerToken extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781784300;
    }

    public function update(Connection $connection): void
    {
        $exists = $connection->fetchOne(
            "SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE()
                  AND TABLE_NAME = 'paymos_payment_event'
                  AND COLUMN_NAME = 'owner_token'"
        );
        if ((int) $exists > 0) {
            return;
        }

        // Let schema/DDL failures reach the migration runner. A failed ALTER
        // must remain retryable instead of being recorded as a successful upgrade.
        $connection->executeStatement(
            'ALTER TABLE `paymos_payment_event`
                ADD COLUMN `owner_token` CHAR(32) NOT NULL DEFAULT \'\' AFTER `event_id`;'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Nothing destructive: the column is additive and old rows age out by TTL.
    }
}
