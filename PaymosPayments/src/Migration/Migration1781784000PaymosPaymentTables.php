<?php

declare(strict_types=1);

namespace PaymosPayments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/**
 * Creates the two Paymos plugin tables:
 *   paymos_payment_invoice — one snapshot per invoice (amount/currency the
 *     invoice was created for, plus lookup keys).
 *   paymos_payment_event   — webhook dedup ledger; event_id is the PRIMARY KEY
 *     so a concurrent re-delivery loses the INSERT (race-proof dedup).
 *
 * The class-name numeric prefix is the real Unix creation timestamp Shopware
 * orders migrations by (getCreationTimestamp must return the same value).
 */
class Migration1781784000PaymosPaymentTables extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1781784000;
    }

    public function update(Connection $connection): void
    {
        // `id` is an auto-increment surrogate key: the store inserts the
        // business columns and lets the database generate the id (portable
        // across MySQL and MariaDB). Lookups go through the unique keys.
        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS `paymos_payment_invoice` (
                `id` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                `transaction_id` VARCHAR(64) NOT NULL,
                `paymos_invoice_id` VARCHAR(128) NOT NULL,
                `external_order_id` VARCHAR(191) NOT NULL,
                `environment` VARCHAR(16) NOT NULL,
                `project_id` VARCHAR(128) NOT NULL,
                `amount` VARCHAR(64) NOT NULL,
                `currency` VARCHAR(16) NOT NULL,
                `payment_url` TEXT NOT NULL,
                `status` VARCHAR(64) NOT NULL,
                `renew_count` INT(11) UNSIGNED NOT NULL DEFAULT 0,
                `created_at` DATETIME NULL,
                `updated_at` DATETIME NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `uniq.paymos_invoice_id` (`paymos_invoice_id`),
                UNIQUE KEY `uniq.external_order_id` (`external_order_id`),
                KEY `idx.transaction_id` (`transaction_id`),
                KEY `idx.environment` (`environment`),
                KEY `idx.status` (`status`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
        );

        $connection->executeStatement(
            'CREATE TABLE IF NOT EXISTS `paymos_payment_event` (
                `event_id` VARCHAR(128) NOT NULL,
                `expires_at` INT(11) UNSIGNED NOT NULL,
                `created_at` INT(11) UNSIGNED NOT NULL,
                PRIMARY KEY (`event_id`),
                KEY `idx.expires_at` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
        );
    }

    public function updateDestructive(Connection $connection): void
    {
        // Tables are dropped in the plugin's uninstall() when the merchant opts
        // out of keeping data; nothing destructive to do at migrate time.
    }
}
