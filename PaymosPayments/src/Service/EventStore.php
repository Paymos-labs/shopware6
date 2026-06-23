<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Doctrine\DBAL\Connection;
use Paymos\Webhook\EventStoreInterface;

/**
 * Race-proof webhook dedup over the Shopware DBAL connection. The
 * `paymos_payment_event` table has `event_id` as PRIMARY KEY, so a concurrent
 * re-delivery loses the INSERT and {@see remember()} returns false.
 *
 * The SDK's EventStoreInterface only defines remember(). commit()/release() are
 * the plugin-side transactional half (called by the processor via method_exists,
 * matching the OpenCart/PrestaShop/Magento2 reference plugins):
 *   remember() — INSERT the event id with a SHORT reservation TTL; a duplicate-key
 *                failure means "already seen" and returns false. The row is the
 *                in-flight lock, and a crashed callback frees the id quickly so
 *                the server's retry is not blacklisted for the whole dedup window.
 *   commit()   — extend the reservation to the full dedup window once the order
 *                mutation has succeeded, so a re-delivery is suppressed for the
 *                whole window.
 *   release()  — DELETE the in-flight row so a processing failure does NOT block
 *                the server's retry of the same event.
 */
final class EventStore implements EventStoreInterface
{
    /** Reservation window before commit(); a crashed callback frees the id quickly. */
    private const RESERVATION_TTL_SECONDS = 300;

    /** @var Connection */
    private $connection;

    /** @var string */
    private $pendingEventId = '';

    /** @var int */
    private $pendingTtlSeconds = 0;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function remember($eventId, $ttlSeconds)
    {
        $eventId = (string) $eventId;
        if ($eventId === '') {
            return false;
        }

        $now = time();

        // Purge expired locks so a re-delivery after the dedup window is not
        // falsely rejected (and the table does not grow unbounded).
        try {
            $this->connection->executeStatement(
                'DELETE FROM `paymos_payment_event` WHERE `expires_at` < :now',
                array('now' => $now)
            );
        } catch (\Throwable $e) {
            // A GC failure must not block dedup; the INSERT below is the real
            // guard.
        }

        // Insert with a SHORT reservation TTL only. commit() extends it to the
        // full dedup window after the order mutation succeeds; this way a
        // callback that is killed (fatal/OOM) between remember() and release()
        // frees the event id within RESERVATION_TTL_SECONDS instead of
        // blacklisting the server's retry for the entire dedup window.
        try {
            $this->connection->insert('paymos_payment_event', array(
                'event_id' => $eventId,
                'expires_at' => $now + self::RESERVATION_TTL_SECONDS,
                'created_at' => $now,
            ));
        } catch (\Throwable $e) {
            // Duplicate primary key (already seen) or a race we lost.
            return false;
        }

        $this->pendingEventId = $eventId;
        $this->pendingTtlSeconds = (int) $ttlSeconds;

        return true;
    }

    public function commit()
    {
        if ($this->pendingEventId === '') {
            return;
        }

        // Extend the reservation to the full dedup window now the order
        // mutation has succeeded, so a re-delivery of this event is suppressed
        // for the whole window.
        try {
            $this->connection->update(
                'paymos_payment_event',
                array('expires_at' => time() + $this->pendingTtlSeconds),
                array('event_id' => $this->pendingEventId)
            );
        } catch (\Throwable $e) {
            // Best effort: if the extend fails the row still expires via the
            // reservation TTL and the server's retry would re-process it.
        }

        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }

    public function release()
    {
        if ($this->pendingEventId === '') {
            return;
        }

        try {
            $this->connection->delete('paymos_payment_event', array('event_id' => $this->pendingEventId));
        } catch (\Throwable $e) {
            // Best effort: if the delete fails the row simply expires via the
            // reservation TTL.
        }

        $this->pendingEventId = '';
        $this->pendingTtlSeconds = 0;
    }
}
