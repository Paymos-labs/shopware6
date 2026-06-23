<?php

declare(strict_types=1);

namespace PaymosPayments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Adds the `return_url` column to `paymos_payment_invoice`.
 *
 * The Shopware async checkout hands the payment handler a per-transaction
 * return URL (it carries the encrypted _sw_payment_token). The plugin snapshots
 * it here so the storefront return bridge ({@see \PaymosPayments\Storefront\Controller\PaymosReturnController})
 * can send the buyer back into Shopware's finalize flow after they pay on the
 * hosted Paymos page. It is NEVER sent to Paymos (the create-invoice API has no
 * URL field) — snapshot only.
 *
 * A NEW migration (timestamp strictly greater than the table-create migration
 * 1781784000) is required because Shopware records executed migrations by
 * timestamp and never re-runs an already-applied class — editing the original
 * migration would not reach installed shops.
 */
class Migration1781784100AddReturnUrl extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781784100;
    }

    public function update(Connection $connection): void
    {
        // Idempotent: skip when the column already exists (fresh install where a
        // future table-create already carries it, or a re-run). SHOW COLUMNS is
        // portable across MySQL and MariaDB and avoids information_schema branching.
        $exists = $connection->fetchOne(
            "SHOW COLUMNS FROM `paymos_payment_invoice` LIKE 'return_url'"
        );
        if ($exists !== false) {
            return;
        }

        // TEXT NULL mirrors `payment_url` and tolerates the long Shopware return
        // URL with its JWT token.
        $connection->executeStatement(
            'ALTER TABLE `paymos_payment_invoice` ADD COLUMN `return_url` TEXT NULL'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // The column is dropped with the table in the plugin's uninstall() when
        // the merchant opts out of keeping data; nothing destructive at migrate
        // time.
    }
}
