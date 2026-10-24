<?php

declare(strict_types=1);

use PaymosPayments\Service\EventStore;

/** Database double for the additive owner-token migration; no database is opened. */
final class OwnerTokenMigrationConnection extends \Doctrine\DBAL\Connection
{
    public $columnExists = false;
    public $alterCalls = 0;
    public $metadataError;
    public $alterError;

    public function __construct() {}

    public function fetchOne($sql, array $params = array(), array $types = array())
    {
        assertContainsValue('information_schema.COLUMNS', $sql, 'inspect the schema before altering it.');
        assertContainsValue('TABLE_SCHEMA = DATABASE()', $sql, 'inspect only the current database.');
        assertContainsValue("TABLE_NAME = 'paymos_payment_event'", $sql, 'inspect the event table.');
        assertContainsValue("COLUMN_NAME = 'owner_token'", $sql, 'inspect the owner column.');
        if ($this->metadataError !== null) {
            throw $this->metadataError;
        }
        return $this->columnExists ? 1 : 0;
    }

    public function executeStatement($sql, array $params = array(), array $types = array())
    {
        $this->alterCalls++;
        if ($this->alterError !== null) {
            throw $this->alterError;
        }
        if ($this->columnExists) {
            throw new \RuntimeException('duplicate column');
        }
        assertContainsValue('ADD COLUMN `owner_token`', $sql, 'add the owner column.');
        $this->columnExists = true;
        return 0;
    }
}

function test_sw_owner_migration_existing_column_is_a_noop()
{
    $connection = new OwnerTokenMigrationConnection();
    $connection->columnExists = true;
    (new \PaymosPayments\Migration\Migration1781784300AddEventOwnerToken())->update($connection);
    assertSameValue(0, $connection->alterCalls, 'fresh installs already have the column; do not issue ALTER.');
}

function test_sw_owner_migration_upgrade_adds_once_and_is_retryable()
{
    $connection = new OwnerTokenMigrationConnection();
    $migration = new \PaymosPayments\Migration\Migration1781784300AddEventOwnerToken();
    $migration->update($connection);
    assertTrueValue($connection->columnExists, 'an existing installation gains the column.');
    $migration->update($connection);
    assertSameValue(1, $connection->alterCalls, 'a retry after success does not ALTER again.');
}

function test_sw_owner_migration_ddl_failure_propagates_and_can_retry()
{
    $connection = new OwnerTokenMigrationConnection();
    $failure = new \RuntimeException('ALTER permission denied');
    $connection->alterError = $failure;
    $migration = new \PaymosPayments\Migration\Migration1781784300AddEventOwnerToken();
    $observed = null;
    try {
        $migration->update($connection);
    } catch (\Throwable $error) {
        $observed = $error;
    }
    assertSameValue($failure, $observed, 'do not report a failed upgrade as successful.');
    assertFalseValue($connection->columnExists, 'failure leaves the column missing.');
    $connection->alterError = null;
    $migration->update($connection);
    assertTrueValue($connection->columnExists, 'a later successful retry adds the column.');
}

function test_sw_owner_migration_metadata_failure_propagates()
{
    $connection = new OwnerTokenMigrationConnection();
    $failure = new \RuntimeException('database unavailable');
    $connection->metadataError = $failure;
    $observed = null;
    try {
        (new \PaymosPayments\Migration\Migration1781784300AddEventOwnerToken())->update($connection);
    } catch (\Throwable $error) {
        $observed = $error;
    }
    assertSameValue($failure, $observed, 'metadata failure is not evidence that the column exists.');
    assertSameValue(0, $connection->alterCalls, 'do not ALTER after a failed schema read.');
}

/**
 * Locks in the race-proof dedup contract of the DBAL-backed EventStore:
 * remember() reserves with a SHORT TTL, commit() extends to the full window,
 * release() drops the in-flight row, and a duplicate event id is rejected.
 */

function test_sw_event_store_remembers_new_event()
{
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    assertTrueValue($store->remember('evt_1', 604800), 'A new event must be remembered.');
    assertTrueValue(isset($connection->rows['evt_1']), 'The dedup row must be inserted.');
    assertTrueValue((int) $connection->rows['evt_1']['expires_at'] > 0, 'The reservation must carry an expiry.');
}

function test_sw_event_store_rejects_duplicate_event()
{
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    assertTrueValue($store->remember('evt_dup', 604800), 'First insert must succeed.');
    assertFalseValue($store->remember('evt_dup', 604800), 'A duplicate event id must return false.');
}

function test_sw_event_store_empty_event_id_is_not_remembered()
{
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    assertFalseValue($store->remember('', 604800), 'An empty event id must not be remembered.');
    assertSameValue(0, count($connection->rows), 'No row is written for an empty event id.');
}

function test_sw_event_store_remember_reserves_short_then_commit_extends()
{
    // The headline durability guarantee: remember() must NOT insert with the
    // full 7-day TTL (a crashed callback would then blacklist the event for a
    // week). It reserves short; commit() extends to the full window only after
    // the order mutation succeeds.
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    $store->remember('evt_commit', 604800);
    $reservedExpiry = (int) $connection->rows['evt_commit']['expires_at'];

    // The reservation must be far shorter than the full dedup window, so a
    // killed process frees the id quickly.
    assertTrueValue($reservedExpiry < time() + 100000, 'remember() must reserve with a SHORT ttl, not the full window.');

    $store->commit();

    assertTrueValue(isset($connection->rows['evt_commit']), 'commit() must keep the durable dedup row.');
    assertTrueValue(
        (int) $connection->rows['evt_commit']['expires_at'] > $reservedExpiry,
        'commit() must extend the reservation to the full dedup window.'
    );
}

function test_sw_event_store_release_drops_in_flight_lock()
{
    // release() must DELETE the row so the server can retry an event whose
    // order mutation threw (or whose process was killed).
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    $store->remember('evt_release', 604800);
    $store->release();

    assertFalseValue(isset($connection->rows['evt_release']), 'release() must delete the in-flight row.');
}

function test_sw_event_store_prunes_expired_reservation_before_insert()
{
    // A stale reservation from a crashed callback (already past its expiry) must
    // be reclaimed so the same id can be retried.
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    $connection->rows['evt_stale'] = array('event_id' => 'evt_stale', 'expires_at' => 1, 'created_at' => 1);

    assertTrueValue($store->remember('evt_fresh', 604800), 'A fresh event must be remembered after pruning expired rows.');
    assertFalseValue(isset($connection->rows['evt_stale']), 'remember() must prune reservations whose expiry has passed.');
}

function test_sw_event_store_commit_without_remember_is_noop()
{
    // A defensive guard: commit()/release() with nothing pending must not throw
    // or touch the table (e.g. a duplicate short-circuits before remember()
    // marks anything pending).
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    $store->commit();
    $store->release();

    assertSameValue(0, count($connection->rows), 'commit()/release() with no pending event must be no-ops.');
}

function test_sw_event_store_tells_a_locked_event_from_a_committed_one()
{
    // BUG-103: remember() says "seen" for both; isCommitted() must not.
    $connection = new FakeDbalConnection();
    $first = new EventStore($connection);
    assertTrueValue($first->remember('evt_lock', 604800), 'first delivery takes the lock.');

    $retry = new EventStore($connection);
    assertFalseValue($retry->remember('evt_lock', 604800), 'a retry while the lock is held is not new.');
    assertFalseValue($retry->isCommitted('evt_lock'), 'a locked, uncommitted event is not committed.');

    $first->commit();
    assertTrueValue($retry->isCommitted('evt_lock'), 'after commit the event is committed.');
}

/**
 * A10-02: a delivery whose reservation expired must not delete the successor's
 * row, and while the successor is still busy a third delivery must be rejected
 * as in-flight until the successor commits.
 */
function test_sw_stale_owner_release_does_not_touch_the_successor()
{
    $connection = new FakeDbalConnection();
    $ownerA = new EventStore($connection);
    assertTrueValue($ownerA->remember('evt_stale_release', 604800), 'delivery A reserves the event.');

    // Delivery A is stalled past its 300s reservation: rewind the row's expiry
    // so the next remember() prunes it and takes the id over.
    $connection->rows['evt_stale_release']['expires_at'] = time() - 1;

    $ownerB = new EventStore($connection);
    assertTrueValue($ownerB->remember('evt_stale_release', 604800), 'delivery B takes over the expired reservation.');
    $tokenB = (string) $connection->rows['evt_stale_release']['owner_token'];
    assertTrueValue($tokenB !== '', 'the successor row must carry its own owner token.');

    // A wakes up and fails: its release() must complete silently and leave B's row.
    $ownerA->release();
    assertTrueValue(isset($connection->rows['evt_stale_release']), 'a stale release must not delete the successor reservation.');
    assertSameValue($tokenB, (string) $connection->rows['evt_stale_release']['owner_token'], 'the successor row must keep its own token.');

    // While B is busy, a third delivery is rejected as in-flight, not duplicate.
    $ownerC = new EventStore($connection);
    assertFalseValue($ownerC->remember('evt_stale_release', 604800), 'a third delivery while B is busy must lose the race.');
    assertFalseValue($ownerC->isCommitted('evt_stale_release'), 'the event is not committed while B is still processing.');

    assertTrueValue($ownerB->commit(), 'the successor commits its own reservation.');
    assertTrueValue($ownerC->isCommitted('evt_stale_release'), 'after B commits the event is a duplicate.');
}

/**
 * A10-02: a stale delivery's commit() must report lost ownership (false) and
 * must not extend the successor's short reservation into a committed one.
 */
function test_sw_stale_owner_commit_does_not_confirm_the_successor()
{
    $connection = new FakeDbalConnection();
    $ownerA = new EventStore($connection);
    assertTrueValue($ownerA->remember('evt_stale_commit', 604800), 'delivery A reserves the event.');

    $connection->rows['evt_stale_commit']['expires_at'] = time() - 1;

    $ownerB = new EventStore($connection);
    assertTrueValue($ownerB->remember('evt_stale_commit', 604800), 'delivery B takes over the expired reservation.');
    $reservedByB = (int) $connection->rows['evt_stale_commit']['expires_at'];

    assertFalseValue($ownerA->commit(), 'a stale commit must report lost ownership, not success.');
    assertSameValue($reservedByB, (int) $connection->rows['evt_stale_commit']['expires_at'], "a stale commit must not extend the successor's reservation.");
    assertFalseValue($ownerB->isCommitted('evt_stale_commit'), "B's row must still look busy, not committed.");

    $ownerC = new EventStore($connection);
    assertFalseValue($ownerC->remember('evt_stale_commit', 604800), 'C is still rejected while B is busy.');

    assertTrueValue($ownerB->commit(), 'the successor commits its own reservation.');
    assertTrueValue($ownerC->isCommitted('evt_stale_commit'), 'after B commits the event is a duplicate.');
}

/**
 * A10-02: owner tokens come from random_bytes, not the clock — two reservations
 * taken within the same second can never share a token.
 */
function test_sw_owner_tokens_do_not_collide_within_the_same_second()
{
    $connection = new FakeDbalConnection();
    $first = new EventStore($connection);
    $second = new EventStore($connection);

    assertTrueValue($first->remember('evt_token_a', 604800), 'first reservation.');
    assertTrueValue($second->remember('evt_token_b', 604800), 'second reservation in the same second.');

    $tokenA = (string) $connection->rows['evt_token_a']['owner_token'];
    $tokenB = (string) $connection->rows['evt_token_b']['owner_token'];

    assertTrueValue((bool) preg_match('/^[0-9a-f]{32}$/', $tokenA), 'owner token must be 16 random bytes as hex.');
    assertTrueValue((bool) preg_match('/^[0-9a-f]{32}$/', $tokenB), 'owner token must be 16 random bytes as hex.');
    assertTrueValue($tokenA !== $tokenB, 'two reservations in the same second must not share an owner token.');
    assertSameValue(
        (int) $connection->rows['evt_token_a']['created_at'],
        (int) $connection->rows['evt_token_b']['created_at'],
        'precondition: both rows were written in the same second.'
    );
}

/**
 * A10-02: commit() with nothing pending, or against a row taken over by a
 * successor, is a quiet non-success (zero affected rows), never an error.
 */
function test_sw_zero_row_commit_is_reported_as_not_owned()
{
    $connection = new FakeDbalConnection();
    $store = new EventStore($connection);

    assertFalseValue($store->commit(), 'commit without a pending reservation must report non-success.');

    assertTrueValue($store->remember('evt_zero_rows', 604800), 'reserve before losing the row.');
    $connection->rows['evt_zero_rows']['expires_at'] = time() - 1;
    $successor = new EventStore($connection);
    assertTrueValue($successor->remember('evt_zero_rows', 604800), 'the successor takes the expired id over.');
    assertFalseValue($store->commit(), 'a commit that matched zero rows must report lost ownership.');
    assertTrueValue(isset($connection->rows['evt_zero_rows']), 'the failed commit must leave the successor row in place.');
}
