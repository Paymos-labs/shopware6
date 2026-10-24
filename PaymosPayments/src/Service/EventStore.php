<?php

declare(strict_types=1);

namespace PaymosPayments\Service;

use Doctrine\DBAL\Connection;
use Paymos\Webhook\CommitAwareEventStoreInterface;

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
final class EventStore implements CommitAwareEventStoreInterface
{
    /** Reservation window before commit(); a crashed callback frees the id quickly. */
    private const RESERVATION_TTL_SECONDS = 300;

    /** @var Connection */
    private $connection;

    /** @var string */
    private $pendingEventId = '';

    /**
     * Owner token of the reservation remember() took (A10-02). Each successful
     * remember() mints a fresh random token; commit()/release() match it in
     * their WHERE clause, so a delivery whose reservation expired (and whose
     * event id was taken over by a newer delivery) can no longer delete or
     * commit the successor's row.
     *
     * @var string
     */
    private $pendingOwnerToken = '';

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
        $ownerToken = bin2hex(random_bytes(16));

        try {
            $this->connection->insert('paymos_payment_event', array(
                'event_id' => $eventId,
                'owner_token' => $ownerToken,
                'expires_at' => $now + self::RESERVATION_TTL_SECONDS,
                'created_at' => $now,
            ));
        } catch (\Throwable $e) {
            // Duplicate primary key (already seen) or a race we lost.
            return false;
        }

        $this->pendingEventId = $eventId;
        $this->pendingOwnerToken = $ownerToken;
        $this->pendingTtlSeconds = (int) $ttlSeconds;

        return true;
    }

    /**
     * Whether the event was processed and committed — as opposed to merely
     * locked by a delivery that has not finished (BUG-103: that one must be
     * answered non-2xx, or a retry arriving mid-processing marks it delivered).
     * A committed row lives past its reservation; a lock does not.
     */
    public function isCommitted($eventId)
    {
        $eventId = (string) $eventId;
        if ($eventId === '') {
            return false;
        }

        try {
            $row = $this->connection->fetchAssociative(
                'SELECT `expires_at`, `created_at` FROM `paymos_payment_event` WHERE `event_id` = :id',
                array('id' => $eventId)
            );
        } catch (\Throwable $e) {
            // Cannot tell: treat as not committed, so the server retries rather
            // than being told the event was delivered.
            return false;
        }
        if (!is_array($row)) {
            return false;
        }

        $expiresAt = isset($row['expires_at']) ? (int) $row['expires_at'] : 0;
        $createdAt = isset($row['created_at']) ? (int) $row['created_at'] : 0;

        return $expiresAt > time() && $expiresAt > $createdAt + self::RESERVATION_TTL_SECONDS;
    }

    /**
     * Extend the reservation to the full dedup window — but only if this store
     * instance still owns it. Zero affected rows mean a newer delivery took the
     * event id over after this reservation expired (A10-02): that successor's
     * row must stay untouched, and the commit is reported as not owned (false),
     * not as an error.
     *
     * @return bool True when this owner's row was extended; false when nothing
     *              was pending or the reservation was lost to a successor.
     */
    public function commit()
    {
        if ($this->pendingEventId === '') {
            return false;
        }

        // Extend the reservation to the full dedup window now the order
        // mutation has succeeded, so a re-delivery of this event is suppressed
        // for the whole window.
        try {
            $affected = $this->connection->update(
                'paymos_payment_event',
                array('expires_at' => time() + $this->pendingTtlSeconds),
                array(
                    'event_id' => $this->pendingEventId,
                    'owner_token' => $this->pendingOwnerToken,
                )
            );
        } catch (\Throwable $e) {
            // Best effort: if the extend fails the row still expires via the
            // reservation TTL and the server's retry would re-process it.
            $affected = 0;
        }

        $this->pendingEventId = '';
        $this->pendingOwnerToken = '';
        $this->pendingTtlSeconds = 0;

        return (int) $affected > 0;
    }

    /**
     * Drop the in-flight lock so the server can retry a failed processing run.
     * Only this owner's row is deleted: when the reservation expired and a
     * newer delivery owns the event id now, release() completes silently and
     * leaves the successor's row alone (A10-02).
     */
    public function release()
    {
        if ($this->pendingEventId === '') {
            return;
        }

        try {
            $this->connection->delete('paymos_payment_event', array(
                'event_id' => $this->pendingEventId,
                'owner_token' => $this->pendingOwnerToken,
            ));
        } catch (\Throwable $e) {
            // Best effort: if the delete fails the row simply expires via the
            // reservation TTL.
        }

        $this->pendingEventId = '';
        $this->pendingOwnerToken = '';
        $this->pendingTtlSeconds = 0;
    }
}
