<?php

declare(strict_types=1);

namespace PaymosPayments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1793100000AddRefundIntents extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1793100000; }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS paymos_refund_installation (
            entity_id INT NOT NULL PRIMARY KEY, installation_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS paymos_refund_intent (
            operation_id VARCHAR(64) NOT NULL PRIMARY KEY,
            installation_id VARCHAR(64) NOT NULL, request_token VARCHAR(64) NOT NULL,
            payment_key VARCHAR(64) NOT NULL, external_order_id VARCHAR(200) NOT NULL,
            withdrawal_id VARCHAR(128) NULL, native_refund_id CHAR(32) NULL, payload LONGTEXT NOT NULL,
            created_at BIGINT NOT NULL, next_due BIGINT NOT NULL, poll_count INT NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_paymos_refund_token (installation_id, request_token),
            UNIQUE KEY uniq_paymos_refund_external (external_order_id),
            UNIQUE KEY uniq_paymos_refund_withdrawal (withdrawal_id),
            UNIQUE KEY uniq_paymos_refund_native (native_refund_id),
            KEY idx_paymos_refund_payment (payment_key), KEY idx_paymos_refund_due (next_due, operation_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS paymos_refund_projection (
            operation_id VARCHAR(64) NOT NULL PRIMARY KEY, payment_key VARCHAR(64) NOT NULL,
            result LONGTEXT NOT NULL, note VARCHAR(255) NOT NULL,
            KEY idx_paymos_refund_projection_payment (payment_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
        $connection->executeStatement('CREATE TABLE IF NOT EXISTS paymos_refund_observation (
            withdrawal_id VARCHAR(128) NOT NULL PRIMARY KEY, payment_key VARCHAR(64) NOT NULL,
            payload LONGTEXT NOT NULL, note VARCHAR(255) NULL, review_reason VARCHAR(128) NULL,
            next_due BIGINT NOT NULL, KEY idx_paymos_refund_observation_due (next_due)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin');
    }

    public function updateDestructive(Connection $connection): void {}
}
