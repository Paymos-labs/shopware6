<?php

declare(strict_types=1);

namespace PaymosPayments\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

/** Expose payment observations on the native order custom-fields card. */
class Migration1794528000PaymentReviewFields extends MigrationStep
{
    public function getCreationTimestamp(): int { return 1794528000; }

    public function update(Connection $connection): void
    {
        $setId = hex2bin('fe679118f650479bb08f004e93e0e5d1');
        $connection->executeStatement(
            'INSERT IGNORE INTO custom_field_set (id, name, config, active, created_at) VALUES (:id, :name, :config, 1, NOW(3))',
            ['id' => $setId, 'name' => 'paymos_payment_observation', 'config' => json_encode(['label' => ['en-GB' => 'Paymos', 'de-DE' => 'Paymos']])]
        );
        $connection->executeStatement(
            'INSERT IGNORE INTO custom_field_set_relation (id, set_id, entity_name, created_at) VALUES (:id, :set, :entity, NOW(3))',
            ['id' => hex2bin('fe679118f650479bb08f004e93e0e5d2'), 'set' => $setId, 'entity' => 'order']
        );
        foreach (['paymos_invoice_status' => 'Invoice status', 'paymos_payment_review' => 'Payment review'] as $name => $label) {
            $connection->executeStatement(
                'INSERT IGNORE INTO custom_field (id, name, type, config, active, set_id, created_at) VALUES (:id, :name, :type, :config, 1, :set, NOW(3))',
                ['id' => hex2bin(md5($name)), 'name' => $name, 'type' => 'text', 'set' => $setId,
                    'config' => json_encode(['label' => ['en-GB' => $label], 'componentName' => 'sw-textarea-field',
                        'customFieldType' => 'text', 'disabled' => true])]
            );
        }
    }

    public function updateDestructive(Connection $connection): void {}
}
